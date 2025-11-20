<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers for authenticated API endpoints
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/email.php';

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ http_response_code(403); echo json_encode(['erro'=>'Acesso restrito ao cliente']); exit; }

$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

try {
    if($method === 'GET'){
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $busca = trim((string)($_GET['busca'] ?? ''));
        $statusFiltro = trim((string)($_GET['status'] ?? ''));

        $where = ['r.cliente_id=?'];
        $params = [$clienteId];
        if($statusFiltro !== ''){
            $where[] = 'LOWER(p.cliente_aceite_status)=?';
            $params[] = strtolower($statusFiltro);
        }
        if($busca !== ''){
            $like = '%' . $busca . '%';
            $where[] = '(CAST(p.id AS CHAR) LIKE ? OR CAST(r.id AS CHAR) LIKE ? OR COALESCE(NULLIF(r.titulo,\'\'), CONCAT(\'Requisição #\', r.id)) LIKE ? OR LOWER(p.cliente_aceite_status) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = '%' . strtolower($busca) . '%';
            $params[] = '%' . strtolower($busca) . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $baseFrom = "FROM pedidos p
                JOIN propostas pr ON pr.id=p.proposta_id
                JOIN cotacoes c ON c.id=pr.cotacao_id
                JOIN requisicoes r ON r.id=c.requisicao_id";

        $countSql = "SELECT COUNT(*) $baseFrom $whereSql";
        $stCount = $db->prepare($countSql);
        $stCount->execute($params);
        $total = (int)$stCount->fetchColumn();

    $dataSql = "SELECT p.id, p.cliente_aceite_status, p.cliente_aceite_em, p.criado_em,
               r.id AS requisicao_id,
               COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id)) AS requisicao_titulo,
               pr.valor_total, pr.prazo_entrega AS prazo_dias
                    $baseFrom
                    $whereSql
                    ORDER BY p.id DESC
                    LIMIT $perPage OFFSET $offset";
        $stData = $db->prepare($dataSql);
        $stData->execute($params);
        $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

        $statusOptions = [];
        try {
            $statusSql = "SELECT DISTINCT LOWER(p.cliente_aceite_status) AS value, p.cliente_aceite_status AS label
                          $baseFrom
                          WHERE r.cliente_id= ?
                          ORDER BY label";
            $stStatus = $db->prepare($statusSql);
            $stStatus->execute([$clienteId]);
            $statusRows = $stStatus->fetchAll(PDO::FETCH_ASSOC);
            foreach($statusRows as $row){
                if(($row['value'] ?? '') !== ''){
                    $statusOptions[] = [
                        'value' => $row['value'],
                        'label' => $row['label']
                    ];
                }
            }
        } catch(Throwable $eStatus){ /* ignora */ }

        echo json_encode([
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int)ceil($total / $perPage)),
                'status_options' => $statusOptions
            ]
        ]);
        exit;
    }
    if($method === 'POST'){
        $dataRaw = file_get_contents('php://input');
        $data = json_decode($dataRaw, true);
        if(!is_array($data)) { $data = $_POST; }
        $pedidoId = (int)($data['pedido_id'] ?? 0);
        $acao = strtolower(trim($data['acao'] ?? 'aceitar'));
        if(!$pedidoId){ http_response_code(400); echo json_encode(['erro'=>'pedido_id obrigatório']); exit; }
        if(!in_array($acao, ['aceitar','rejeitar'])){ http_response_code(400); echo json_encode(['erro'=>'acao inválida']); exit; }
        // Verificar ownership do cliente e obter cotacao/rodada
        $sql = "SELECT p.id, p.cliente_aceite_status, c.requisicao_id, c.id AS cotacao_id, c.rodada AS cotacao_rodada\n                FROM pedidos p\n                JOIN propostas pr ON pr.id=p.proposta_id\n                JOIN cotacoes c ON c.id=pr.cotacao_id\n                JOIN requisicoes r ON r.id=c.requisicao_id\n                WHERE p.id=? AND r.cliente_id=?";
        $st = $db->prepare($sql); $st->execute([$pedidoId,$clienteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if(!$row){ http_response_code(404); echo json_encode(['erro'=>'Pedido não encontrado']); exit; }
        if($row['cliente_aceite_status'] !== 'pendente'){ echo json_encode(['erro'=>'Pedido já processado','status'=>$row['cliente_aceite_status']]); exit; }
        $novoStatus = $acao === 'rejeitar' ? 'rejeitado' : 'aceito';
        $db->prepare('UPDATE pedidos SET cliente_aceite_status=?, cliente_aceite_em=NOW() WHERE id=?')->execute([$novoStatus,$pedidoId]);
        // Atualizar status do pedido conforme decisão
        if($novoStatus==='aceito'){
            try { $db->prepare('UPDATE pedidos SET status="pendente" WHERE id=?')->execute([$pedidoId]); } catch(Throwable $e){}
            if(function_exists('email_send_pedido_confirmacao')){
                try { email_send_pedido_confirmacao($pedidoId, false); } catch (Throwable $e) { }
            }
            // NOVO: Encerrar a cotação
            try {
                if(!empty($row['cotacao_id'])){
                    $upCot = $db->prepare('UPDATE cotacoes SET status="encerrada", token_expira_em=LEAST(NOW(), COALESCE(token_expira_em, NOW())) WHERE id=?');
                    $upCot->execute([(int)$row['cotacao_id']]);
                    log_requisicao_event($db,(int)$row['requisicao_id'],'cotacao_encerrada','Cotação encerrada por aceite do cliente',null,['cotacao_id'=>(int)$row['cotacao_id']]);
                }
            } catch(Throwable $e){ /* ignore */ }
        } else {
            try { $db->prepare('UPDATE pedidos SET status="cancelado" WHERE id=?')->execute([$pedidoId]); } catch(Throwable $e){}
            // NOVO: Nova rodada de cotação
            try {
                if(!empty($row['cotacao_id'])){
                    $rodadaAtual = (int)($row['cotacao_rodada'] ?? 1);
                    $novaRodada = $rodadaAtual + 1;
                    $upCot = $db->prepare('UPDATE cotacoes SET status="aberta", rodada=?, token_expira_em=DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE id=?');
                    $upCot->execute([$novaRodada, (int)$row['cotacao_id']]);
                    log_requisicao_event($db,(int)$row['requisicao_id'],'cotacao_rodada_alterada','Nova rodada de cotação iniciada',null,['cotacao_id'=>(int)$row['cotacao_id'],'de'=>$rodadaAtual,'para'=>$novaRodada]);
                }
            } catch(Throwable $e){ /* ignore */ }
        }
        log_requisicao_event($db, (int)$row['requisicao_id'], $novoStatus==='aceito'?'pedido_aceito':'pedido_rejeitado', 'Pedido '.$novoStatus.' pelo cliente via portal', null, ['pedido_id'=>$pedidoId,'status'=>$novoStatus]);
        echo json_encode(['success'=>true,'status'=>$novoStatus]);
        exit;
    }
    http_response_code(405); echo json_encode(['erro'=>'Método não suportado']);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['erro'=>'Falha no serviço de pedidos do cliente']); }
