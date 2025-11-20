<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    http_response_code(403);
    echo json_encode(['erro'=>'Acesso restrito a fornecedores']);
    exit;
}
$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_cotacoes',240,300,true);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
// Fallback para usuários antigos sem fornecedor_id preenchido na tabela usuarios
if($fornecedorId<=0){
    try {
        $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid = (int)$stF->fetchColumn();
        if($fid>0){
            $fornecedorId = $fid;
            // Opcional: atualizar em memoria para outras requisições no mesmo request
            $u['fornecedor_id'] = $fid;
        }
    } catch(Throwable $e){ /* ignora */ }
}
$method = $_SERVER['REQUEST_METHOD'];
if($method!=='GET'){
    http_response_code(405); echo json_encode(['erro'=>'Método não permitido']); exit;
}
$cotacaoId = isset($_GET['cotacao_id']) ? (int)$_GET['cotacao_id'] : 0;
try {
    if($cotacaoId>0){
        $cot = null;
        try {
            $st = $db->prepare("SELECT c.id,c.requisicao_id,c.status,c.rodada,c.tipo_frete,c.token_expira_em,c.criado_em FROM cotacoes c WHERE c.id=? LIMIT 1");
            $st->execute([$cotacaoId]);
            $cot = $st->fetch(PDO::FETCH_ASSOC);
        } catch(Throwable $eSel){
            // Fallback sem a coluna tipo_frete
            try {
                $st2 = $db->prepare("SELECT c.id,c.requisicao_id,c.status,c.rodada,c.token_expira_em,c.criado_em FROM cotacoes c WHERE c.id=? LIMIT 1");
                $st2->execute([$cotacaoId]);
                $cot = $st2->fetch(PDO::FETCH_ASSOC);
                if($cot) $cot['tipo_frete'] = null;
            } catch(Throwable $eSel2){ throw $eSel2; }
        }
        if(!$cot){ http_response_code(404); echo json_encode(['erro'=>'Cotação não encontrada']); exit; }
        $proposta = null; $jaEnviou = false;
        if($fornecedorId>0){
            // Select da proposta com fallback se colunas created_at/updated_at não existirem
            try {
            $jaSt = $db->prepare("SELECT p.id,p.valor_total,p.prazo_entrega,p.pagamento_dias,p.observacoes,p.status,p.created_at,p.updated_at,p.imagem_url FROM propostas p WHERE p.cotacao_id=? AND p.fornecedor_id=? ORDER BY p.id DESC LIMIT 1");
                $jaSt->execute([$cotacaoId,$fornecedorId]);
                $proposta = $jaSt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch(Throwable $eProp){
                try {
                $jaSt2 = $db->prepare("SELECT p.id,p.valor_total,p.prazo_entrega,p.pagamento_dias,p.observacoes,p.status,p.imagem_url FROM propostas p WHERE p.cotacao_id=? AND p.fornecedor_id=? ORDER BY p.id DESC LIMIT 1");
                    $jaSt2->execute([$cotacaoId,$fornecedorId]);
                    $proposta = $jaSt2->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch(Throwable $eProp2){ /* mantém null */ }
            }
            if($proposta){
                $jaEnviou = true;
                // Garante campo tipo_frete para o front (usa o da cotação pois está unificado lá)
                if(!isset($proposta['tipo_frete'])){
                    $proposta['tipo_frete'] = $cot['tipo_frete'] ?? null;
                }
            }
        }
        echo json_encode([
            'cotacao'=>$cot,
            'ja_enviou'=>$jaEnviou,
            'proposta'=>$proposta
        ]);
        exit;
    }
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $statusFiltro = trim((string)($_GET['status'] ?? ''));
    $envioFiltro = strtolower(trim((string)($_GET['envio'] ?? '')));

    $where = ["c.status='aberta'"];
    $params = [];
    if($statusFiltro !== ''){
        $where[] = 'LOWER(c.status)=?';
        $params[] = strtolower($statusFiltro);
    }
    if($busca !== ''){
        $like = '%' . $busca . '%';
        $where[] = '(CAST(c.id AS CHAR) LIKE ? OR CAST(c.requisicao_id AS CHAR) LIKE ? OR LOWER(c.status) LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = '%' . strtolower($busca) . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $baseJoin = "LEFT JOIN (SELECT DISTINCT cotacao_id FROM propostas WHERE fornecedor_id=?) pf ON pf.cotacao_id=c.id";
    $envioClause = '';
    if($envioFiltro === 'sim'){
        $envioClause = ' AND pf.cotacao_id IS NOT NULL';
    } elseif($envioFiltro === 'nao'){
        $envioClause = ' AND pf.cotacao_id IS NULL';
    }

    $countSql = "SELECT COUNT(*) FROM cotacoes c $baseJoin $whereSql" . $envioClause;
    $countParams = array_merge([$fornecedorId], $params);
    $stCount = $db->prepare($countSql);
    $stCount->execute($countParams);
    $total = (int)$stCount->fetchColumn();

    $rows = [];
    $limitClause = ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $dataParams = array_merge([$fornecedorId], $params);
    try {
        $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.tipo_frete,c.criado_em,(pf.cotacao_id IS NOT NULL) AS ja_enviou
                FROM cotacoes c
                $baseJoin
                $whereSql" . $envioClause . "
                ORDER BY c.id DESC" . $limitClause;
        $stData = $db->prepare($sql);
        $stData->execute($dataParams);
        $rows = $stData->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $eList){
        $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.criado_em,(pf.cotacao_id IS NOT NULL) AS ja_enviou
                FROM cotacoes c
                $baseJoin
                $whereSql" . $envioClause . "
                ORDER BY c.id DESC" . $limitClause;
        $stData = $db->prepare($sql);
        $stData->execute($dataParams);
        $rows = $stData->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){ if(!array_key_exists('tipo_frete',$r)) $r['tipo_frete']=null; }
    }

    $statusOptions = [];
    try {
        $statusClause = "WHERE c.status='aberta'";
        $statusExtra = '';
        if($envioFiltro === 'sim'){
            $statusExtra = ' AND pf.cotacao_id IS NOT NULL';
        } elseif($envioFiltro === 'nao'){
            $statusExtra = ' AND pf.cotacao_id IS NULL';
        }
    $statusSql = "SELECT DISTINCT LOWER(c.status) AS value,c.status AS label
                      FROM cotacoes c
                      $baseJoin
              $statusClause" . $statusExtra . "
              ORDER BY c.status";
        $stStatus = $db->prepare($statusSql);
        $stStatus->execute([$fornecedorId]);
        $statusRows = $stStatus->fetchAll(PDO::FETCH_ASSOC);
        foreach($statusRows as $row){
            if(($row['value'] ?? '') !== ''){
                $statusOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $eStatus){
        // Em caso de erro, mantém lista vazia sem quebrar a resposta
    }

    $response = [
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'status_options' => $statusOptions
        ]
    ];
    echo json_encode($response);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao listar cotações','detalhe'=>$e->getCode()?:null,'mensagem'=>$e->getMessage()]);
}
