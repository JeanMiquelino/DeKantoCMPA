<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/rate_limit.php';

// === Configuração de ranking ===
// Prioridade (menor número = melhor). Ajuste conforme política do negócio.
$INCOTERM_PRIORITY = [
    'DDP' => 1,
    'DAP' => 2,
    'CIF' => 3,
    'FOB' => 4,
    'EXW' => 5,
];
// Direção do pagamento_dias agora privilegia MAIOR prazo (mais dias para pagar = melhor)
$PAGAMENTO_DIAS_DIRECTION = 'DESC'; // antes 'ASC'

function raw_request_body(): string {
    static $raw = null;
    if ($raw !== null) { return $raw; }
    $raw = file_get_contents('php://input');
    return $raw !== false ? $raw : '';
}
function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') { return $method; }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_POST['_method'])) {
        $override = $_POST['_method'];
        unset($_POST['_method']);
    }
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['PUT','PATCH','DELETE'], true)) {
            return $override;
        }
    }
    return $method;
}
function read_request_payload(): array {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(raw_request_body(), true);
        if (is_array($decoded)) { return $cached = $decoded; }
    }
    if (!empty($_POST)) { return $cached = $_POST; }
    $raw = raw_request_body();
    if ($raw) {
        $tmp = [];
        parse_str($raw, $tmp);
        if (!empty($tmp)) { return $cached = $tmp; }
    }
    return $cached = [];
}

$db = get_db_connection(); // precisamos cedo para detecção da coluna
// Rate limits autenticados
try { rate_limit_enforce($db, 'api.propostas', 900, 300, true); } catch(Throwable $e){}

$hasTipoFreteCol = false;
try { $hasTipoFreteCol = (bool)$db->query("SHOW COLUMNS FROM cotacoes LIKE 'tipo_frete'")->fetch(); } catch(Throwable $e) { $hasTipoFreteCol = false; }

$INCOTERM_CASE_SQL = null;
if($hasTipoFreteCol){
    $parts=[]; foreach($INCOTERM_PRIORITY as $term=>$ord){ $parts[] = "WHEN '".addslashes($term)."' THEN $ord"; }
    $INCOTERM_CASE_SQL = 'CASE c.tipo_frete '.implode(' ',$parts).' ELSE 99 END';
}

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$method = resolve_http_method();

switch ($method) {
    case 'GET':
        // rate limit adicional para GET listas
        try { rate_limit_enforce($db, 'api.propostas:get', 600, 300, true); } catch(Throwable $e){}
        $cotacao_id = $_GET['cotacao_id'] ?? null;
        $orderParts = [ 'p.valor_total ASC' ];
        if($INCOTERM_CASE_SQL){ $orderParts[] = "$INCOTERM_CASE_SQL ASC"; }
        $orderParts[] = '(p.pagamento_dias IS NULL) ASC';
        $orderParts[] = 'p.pagamento_dias '.$PAGAMENTO_DIAS_DIRECTION;
        $orderParts[] = 'p.prazo_entrega ASC';
        $orderParts[] = 'p.id ASC';
        $orderBy = implode(', ', $orderParts);
        $selectExtra = $hasTipoFreteCol ? ', c.tipo_frete' : '';
        if ($cotacao_id) {
            $stmt = $db->prepare("SELECT p.* $selectExtra, f.razao_social, f.nome_fantasia FROM propostas p JOIN cotacoes c ON c.id = p.cotacao_id JOIN fornecedores f ON f.id = p.fornecedor_id WHERE p.cotacao_id = ? ORDER BY $orderBy");
            $stmt->execute([$cotacao_id]);
            echo json_encode($stmt->fetchAll());
            break;
        }
        $stmt = $db->query("SELECT p.* $selectExtra, f.razao_social, f.nome_fantasia FROM propostas p JOIN cotacoes c ON c.id = p.cotacao_id JOIN fornecedores f ON f.id = p.fornecedor_id ORDER BY $orderBy");
        echo json_encode($stmt->fetchAll());
        break;
    case 'POST':
        // rate limit para criação/POST
        try { rate_limit_enforce($db, 'api.propostas:post', 120, 300, true); } catch(Throwable $e){}
        // Criação manual de proposta
        $data = read_request_payload();
        if(!$data){
            http_response_code(400);
            echo json_encode(['success'=>false,'erro'=>'JSON inválido']);
            break;
        }
        $cotacao_id    = $data['cotacao_id']    ?? null;
        $fornecedor_id = $data['fornecedor_id'] ?? null;
        if(!$cotacao_id || !$fornecedor_id){
            http_response_code(400);
            echo json_encode(['success'=>false,'erro'=>'cotacao_id e fornecedor_id obrigatórios']);
            break;
        }
        $valor_total   = $data['valor_total']   ?? null;
        $prazo_entrega = $data['prazo_entrega'] ?? null;
        $pagamento_dias = $data['pagamento_dias'] ?? null; // novo
        $observacoes   = $data['observacoes']   ?? null;
        $imagem_url    = $data['imagem_url']    ?? null;
        $status        = $data['status']        ?? 'enviada';
        try {
            $stmt = $db->prepare('INSERT INTO propostas (cotacao_id, fornecedor_id, valor_total, prazo_entrega, pagamento_dias, observacoes, imagem_url, status) VALUES (?,?,?,?,?,?,?,?)');
            $ok = $stmt->execute([
                $cotacao_id, $fornecedor_id, $valor_total, $prazo_entrega, $pagamento_dias, $observacoes, $imagem_url, $status
            ]);
            $idNovo = (int)$db->lastInsertId();
            if($ok){
                $stR = $db->prepare('SELECT requisicao_id FROM cotacoes WHERE id=?');
                $stR->execute([$cotacao_id]);
                $reqId = (int)($stR->fetchColumn() ?: 0);
                if($reqId){
                    // Evento resposta recebida de cotação
                    log_requisicao_event($db, $reqId, 'cotacao_resposta_recebida', 'Resposta de cotação recebida', null, ['proposta_id'=>$idNovo,'cotacao_id'=>$cotacao_id,'fornecedor_id'=>$fornecedor_id,'status'=>$status]);
                    // Evento legado proposta_criada (mantém compatibilidade)
                    log_requisicao_event($db, $reqId, 'proposta_criada', 'Proposta criada', null, ['proposta_id'=>$idNovo,'cotacao_id'=>$cotacao_id,'fornecedor_id'=>$fornecedor_id,'status'=>$status]);
                }
                // Se vier token_convite (raw), marcar convite como respondido
                if(!empty($data['token_convite'])){
                    $hash = hash('sha256',$data['token_convite']);
                    try { $upCv = $db->prepare("UPDATE cotacao_convites SET status='respondido', respondido_em=NOW() WHERE token_hash=? AND status='enviado'"); $upCv->execute([$hash]); } catch(Throwable $e){ /* ignore */ }
                }
                // Email proposta enviada (imediato)
                if (function_exists('email_send_proposta_enviada')) {
                    try { email_send_proposta_enviada($idNovo, false); } catch (Throwable $e) { }
                }
            }
            echo json_encode(['success'=>$ok,'id'=>$idNovo]);
        } catch(Throwable $e){
            http_response_code(500);
            echo json_encode(['success'=>false,'erro'=>$e->getMessage()]);
        }
        break;
    case 'PUT':
        // rate limit para updates
        try { rate_limit_enforce($db, 'api.propostas:put', 240, 300, true); } catch(Throwable $e){}
    $data = read_request_payload();
        $id = $data['id'] ?? null;
        if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        $status = $data['status'] ?? null;
        $valor_total = isset($data['valor_total']) && $data['valor_total']!=='' ? $data['valor_total'] : null;
        $prazo_entrega = isset($data['prazo_entrega']) && $data['prazo_entrega']!=='' ? $data['prazo_entrega'] : null;
        $pagamento_dias = isset($data['pagamento_dias']) && $data['pagamento_dias']!=='' ? $data['pagamento_dias'] : null; // novo
        $observacoes = $data['observacoes'] ?? null;
        $fornecedor_id = isset($data['fornecedor_id']) && $data['fornecedor_id']!=='' ? $data['fornecedor_id'] : null;
        $imagem_url = array_key_exists('imagem_url',$data) ? $data['imagem_url'] : null; // pode ser string vazia
        // Novo: status desejado do pedido quando gerar (padrão: aguardando aprovação do cliente)
        $pedido_status = $data['pedido_status'] ?? 'aguardando_aprovacao_cliente';

        // Monta SQL dinamicamente (status sempre atualizado)
        $campos = ['status=?'];
        $params = [$status];
        if($valor_total !== null){ $campos[] = 'valor_total=?'; $params[] = $valor_total; }
        if($prazo_entrega !== null){ $campos[] = 'prazo_entrega=?'; $params[] = $prazo_entrega; }
        if($pagamento_dias !== null){ $campos[] = 'pagamento_dias=?'; $params[] = $pagamento_dias; } // novo
        if($observacoes !== null){ $campos[] = 'observacoes=?'; $params[] = $observacoes; }
        if($fornecedor_id !== null){ $campos[] = 'fornecedor_id=?'; $params[] = $fornecedor_id; }
        if(isset($data['imagem_url'])){ $campos[] = 'imagem_url=?'; $params[] = $imagem_url; }
        $params[] = $id;
        $sql = 'UPDATE propostas SET '.implode(',', $campos).' WHERE id=?';
        $stmt = $db->prepare($sql);
        $ok = $stmt->execute($params);

        // Se status aprovado, criar pedido se não existir
        if ($status === 'aprovada') {
            $stmt2 = $db->prepare('SELECT id FROM pedidos WHERE proposta_id=?');
            $stmt2->execute([$id]);
            if (!$stmt2->fetch()) {
                // Criar pedido com status vindo do parâmetro (padrão: aguardando_aprovacao_cliente)
                $stmt3 = $db->prepare('INSERT INTO pedidos (proposta_id, status) VALUES (?, ?)');
                $stmt3->execute([$id, $pedido_status]);
                $novoPedidoId = (int)$db->lastInsertId();
                // Se aguardando aprovação, marcar aceite do cliente como pendente
                if (strtolower($pedido_status) === 'aguardando_aprovacao_cliente') {
                    try { $db->prepare('UPDATE pedidos SET cliente_aceite_status="pendente" WHERE id=?')->execute([$novoPedidoId]); } catch(Throwable $e) { /* coluna pode não existir em ambientes antigos */ }
                }
                $stR = $db->prepare('SELECT c.requisicao_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=?');
                $stR->execute([$id]);
                $reqId = (int)($stR->fetchColumn() ?: 0);
                if($reqId){
                    $descricao = strtolower($pedido_status)==='aguardando_aprovacao_cliente' ? 'Pedido criado (aguardando aprovação do cliente)' : 'Pedido criado a partir de proposta';
                    log_requisicao_event($db, $reqId, 'pedido_criado', $descricao, null, ['proposta_id'=>$id,'pedido_id'=>$novoPedidoId,'status'=>$pedido_status]);
                }
                // Não enviar email de confirmação neste momento quando aguardando aprovação do cliente.
                // O email de aceite será disparado via endpoint dedicado (api/pedidos_aceite.php) ao enviar para o cliente.
            }
        }
        // Log status change sempre
        $stR2 = $db->prepare('SELECT c.requisicao_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=?');
        $stR2->execute([$id]);
        $reqId2 = (int)($stR2->fetchColumn() ?: 0);
        if($reqId2){
            log_requisicao_event($db, $reqId2, 'proposta_atualizada', 'Proposta atualizada (status: '.$status.')', null, ['proposta_id'=>$id,'status'=>$status]);
            // Log especial de decisão vinda do ranking com justificativa (se houver)
            if($observacoes !== null && trim($observacoes) !== ''){
                $just = mb_substr(trim($observacoes),0,500);
                $descricao = 'Decisão no ranking: '.($status ?: '-');
                log_requisicao_event($db, $reqId2, 'ranking_decisao', $descricao, null, ['proposta_id'=>$id,'status'=>$status,'justificativa'=>$just]);
            }
        }
        if($ok && $status === 'aprovada' && function_exists('email_send_proposta_aprovada')){
            try { email_send_proposta_aprovada((int)$id, false); } catch(Throwable $e) { /* ignora falha de notificação */ }
        }
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        // rate limit para deleções
        try { rate_limit_enforce($db, 'api.propostas:delete', 60, 300, true); } catch(Throwable $e){}
    $data = read_request_payload();
    if(empty($data) && isset($_GET['id'])){ $data = ['id' => $_GET['id']]; }
    $stmt = $db->prepare('DELETE FROM propostas WHERE id=?');
    $ok = $stmt->execute([$data['id']]);
        if($ok){
            $stR = $db->prepare('SELECT c.requisicao_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=?');
            $stR->execute([$data['id']]);
            $reqId = (int)($stR->fetchColumn() ?: 0);
            if($reqId){ log_requisicao_event($db, $reqId, 'proposta_removida', 'Proposta removida', null, ['proposta_id'=>$data['id']]); }
        }
        echo json_encode(['success' => $ok]);
        break;
    default:
        http_response_code(405);
        echo json_encode(['erro' => 'Método não permitido']);
}