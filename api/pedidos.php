<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers for authenticated API endpoints
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// Prevent caching of sensitive data
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/email.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();

function pedidos_log_error(string $message, array $context = []): void {
    $file = __DIR__ . '/../pedidos_error.log';
    $line = '[' . date('c') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";
    @file_put_contents($file, $line, FILE_APPEND);
}

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

$method = resolve_http_method();

try {
    switch ($method) {
        case 'GET':
            $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
            $fornecedorParam = (isset($_GET['fornecedor_id']) && $_GET['fornecedor_id'] !== '') ? (int)$_GET['fornecedor_id'] : null;
            $statusParam = array_key_exists('status', $_GET) ? trim((string)$_GET['status']) : null;
            $dataParam = array_key_exists('data', $_GET) ? trim((string)$_GET['data']) : null;

            $fromSql = ' FROM pedidos p JOIN propostas pr ON p.proposta_id = pr.id';
            $filters = [];
            $params = [];
            if (!empty($_GET['id'])) {
                $filters[] = 'p.id = ?';
                $params[] = (int)$_GET['id'];
            }
            if (!empty($_GET['proposta_id'])) {
                $filters[] = 'p.proposta_id = ?';
                $params[] = (int)$_GET['proposta_id'];
            }
            if ($fornecedorParam) {
                $filters[] = 'pr.fornecedor_id = ?';
                $params[] = $fornecedorParam;
            }
            if ($statusParam !== null && $statusParam !== '') {
                $filters[] = 'LOWER(p.status) = ?';
                $params[] = strtolower($statusParam);
            }
            if ($dataParam !== null && $dataParam !== '') {
                $filters[] = 'DATE(p.criado_em) = ?';
                $params[] = $dataParam;
            }
            $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
            $wantsAdvanced = ($mode !== null && $mode !== '')
                || isset($_GET['page']) || isset($_GET['per_page'])
                || ($fornecedorParam)
                || ($statusParam !== null && $statusParam !== '')
                || ($dataParam !== null && $dataParam !== '');

            if ($mode === 'ids') {
                $sql = 'SELECT p.id' . $fromSql . $whereSql . ' ORDER BY p.id DESC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                echo json_encode(['ids' => $ids, 'total' => count($ids)]);
                break;
            }

            if ($wantsAdvanced) {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 10)));
                $offset = ($page - 1) * $perPage;
                $dataSql = 'SELECT p.*, pr.valor_total, pr.fornecedor_id, pr.prazo_entrega, pr.pagamento_dias'
                         . $fromSql . $whereSql . ' ORDER BY p.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
                $stmt = $db->prepare($dataSql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $countSql = 'SELECT COUNT(*)' . $fromSql . $whereSql;
                $stmtCount = $db->prepare($countSql);
                $stmtCount->execute($params);
                $total = (int)$stmtCount->fetchColumn();

                echo json_encode([
                    'data' => $rows,
                    'meta' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => max(1, (int)ceil($total / max(1, $perPage)))
                    ]
                ]);
                break;
            }

            $sql = 'SELECT p.*, pr.valor_total, pr.fornecedor_id, pr.prazo_entrega, pr.pagamento_dias'
                 . $fromSql . $whereSql . ' ORDER BY p.id DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
        case 'POST':
            $data = read_request_payload();
            $status = $data['status'] ?? 'aguardando_aprovacao_cliente';
            $stmt = $db->prepare('INSERT INTO pedidos (proposta_id, pdf_url, status) VALUES (?, ?, ?)');
            $ok = $stmt->execute([
                $data['proposta_id'], $data['pdf_url'] ?? '', $status
            ]);
            $novoId = (int)$db->lastInsertId();
            if($ok){
                // Se aguardando aprovação, marcar aceite pendente
                if(strtolower($status)==='aguardando_aprovacao_cliente'){
                    try { $db->prepare('UPDATE pedidos SET cliente_aceite_status="pendente" WHERE id=?')->execute([$novoId]); } catch(Throwable $e){ pedidos_log_error('Falha ao marcar aceite pendente', ['id'=>$novoId,'erro'=>$e->getMessage()]); }
                }
                $stR = $db->prepare('SELECT c.requisicao_id FROM pedidos pd JOIN propostas p ON p.id=pd.proposta_id JOIN cotacoes c ON c.id=p.cotacao_id WHERE pd.id=?');
                $stR->execute([$novoId]);
                $reqId = (int)($stR->fetchColumn() ?: 0);
                if($reqId){ log_requisicao_event($db, $reqId, 'pedido_criado', strtolower($status)==='aguardando_aprovacao_cliente' ? 'Pedido criado (aguardando aprovação do cliente)' : 'Pedido criado', null, ['pedido_id'=>$novoId,'status'=>$status]); }
                // Não enviar email de confirmação automaticamente quando aguardando aprovação do cliente
                if(strtolower($status)!=='aguardando_aprovacao_cliente' && function_exists('email_send_pedido_confirmacao')){
                    try { email_send_pedido_confirmacao($novoId, false); } catch (Throwable $e) { pedidos_log_error('Falha ao enviar email pedido', ['pedido_id'=>$novoId,'erro'=>$e->getMessage()]); }
                }
                if(strtolower($status)!=='aguardando_aprovacao_cliente' && function_exists('email_send_pedido_status')){
                    try { email_send_pedido_status($novoId, $status, false); } catch (Throwable $e) { pedidos_log_error('Falha email status (POST)', ['pedido_id'=>$novoId,'erro'=>$e->getMessage()]); }
                }
            }
            echo json_encode(['success' => $ok, 'id' => $novoId]);
            break;
        case 'PUT':
            $data = read_request_payload();
            $id = $data['id'] ?? null; if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
            // --- Regra de negócio: só emitir após aceite do cliente ---
            $statusAntes = null;
            if(isset($data['status'])){
                try {
                    $stCur = $db->prepare('SELECT status, cliente_aceite_status FROM pedidos WHERE id=?');
                    $stCur->execute([$id]);
                    $cur = $stCur->fetch(PDO::FETCH_ASSOC);
                    if(!$cur){ echo json_encode(['success'=>false,'erro'=>'Pedido não encontrado']); break; }
                    $statusAntes = $cur['status'] ?? null;
                    $desired = strtolower((string)$data['status']);
                    $aceite  = strtolower((string)($cur['cliente_aceite_status'] ?? ''));
                    $curStatus = strtolower((string)($cur['status'] ?? ''));
                    if($desired === 'emitido' && $aceite !== 'aceito'){
                        http_response_code(400);
                        echo json_encode(['success'=>false,'erro'=>'Pedido só pode ser emitido após aceite do cliente']);
                        break;
                    }
                    if($curStatus === 'aguardando_aprovacao_cliente' && $aceite !== 'aceito' && !in_array($desired, ['aguardando_aprovacao_cliente','cancelado'], true)){
                        http_response_code(400);
                        echo json_encode(['success'=>false,'erro'=>'Pedido aguardando aprovação do cliente']);
                        break;
                    }
                } catch(Throwable $e){ pedidos_log_error('Falha ao verificar aceite', ['pedido_id'=>$id,'erro'=>$e->getMessage()]); }
            }
            $campos=[]; $params=[];
            if(isset($data['status'])){ $campos[]='status=?'; $params[]=$data['status']; }
            if(isset($data['pdf_url'])){ $campos[]='pdf_url=?'; $params[]=$data['pdf_url']; }
            if(!$campos){ echo json_encode(['success'=>false,'erro'=>'Nada para atualizar']); break; }
            $params[]=$id;
            $sql='UPDATE pedidos SET '.implode(',', $campos).' WHERE id=?';
            $stmt = $db->prepare($sql);
            $ok = $stmt->execute($params);
            if($ok){
                // Se status mudou para emitido após aceite do cliente, podemos enviar confirmação
                if(isset($data['status']) && strtolower($data['status'])==='emitido' && function_exists('email_send_pedido_confirmacao')){
                    try { email_send_pedido_confirmacao((int)$id, false); } catch (Throwable $e) { pedidos_log_error('Falha email emitido', ['pedido_id'=>$id,'erro'=>$e->getMessage()]); }
                }
                // Obter requisicao vinculada
                $stR = $db->prepare('SELECT c.requisicao_id FROM pedidos pd JOIN propostas p ON p.id=pd.proposta_id JOIN cotacoes c ON c.id=p.cotacao_id WHERE pd.id=?');
                $stR->execute([$id]);
                $reqId = (int)($stR->fetchColumn() ?: 0);
                if($reqId){ log_requisicao_event($db, $reqId, 'pedido_atualizado', 'Pedido atualizado', null, ['pedido_id'=>$id,'status'=>$data['status'] ?? null]); }
                // Nova regra: ao entregar ou cancelar o pedido, fechar a requisicao automaticamente
                $novoStatusLower = isset($data['status']) ? strtolower((string)$data['status']) : null;
                if($reqId && $novoStatusLower && in_array($novoStatusLower, ['entregue','cancelado'], true)){
                    try {
                        $stQ = $db->prepare('SELECT status FROM requisicoes WHERE id=?');
                        $stQ->execute([$reqId]);
                        $statusAnterior = strtolower((string)$stQ->fetchColumn());
                        if($statusAnterior && $statusAnterior !== 'fechada'){
                            $upReq = $db->prepare('UPDATE requisicoes SET status="fechada" WHERE id=?');
                            $upReq->execute([$reqId]);
                            // Registrar na timeline (antes/depois)
                            log_requisicao_event($db, $reqId, 'requisicao_status_alterado', 'Requisição encerrada (pedido '.$novoStatusLower.')', ['status'=>$statusAnterior], ['status'=>'fechada']);
                        }
                    } catch(Throwable $e){ pedidos_log_error('Falha ao encerrar requisicao', ['requisicao_id'=>$reqId,'erro'=>$e->getMessage()]); }
                }
                if(isset($data['status']) && function_exists('email_send_pedido_status')){
                    $novoStatus = (string)$data['status'];
                    if($statusAntes === null || strtolower($novoStatus) !== strtolower((string)$statusAntes)){
                        try { email_send_pedido_status((int)$id, $novoStatus, false); } catch (Throwable $e) { pedidos_log_error('Falha email status (PUT)', ['pedido_id'=>$id,'erro'=>$e->getMessage()]); }
                    }
                }
            }
            echo json_encode(['success' => $ok]);
            break;
        case 'DELETE':
            $data = read_request_payload();
            $stmt = $db->prepare('DELETE FROM pedidos WHERE id=?');
            $ok = $stmt->execute([$data['id']]);
            if($ok){
                $stR = $db->prepare('SELECT c.requisicao_id FROM pedidos pd JOIN propostas p ON p.id=pd.proposta_id JOIN cotacoes c ON c.id=p.cotacao_id WHERE pd.id=?');
                $stR->execute([$data['id']]);
                $reqId = (int)($stR->fetchColumn() ?: 0);
                if($reqId){ log_requisicao_event($db, $reqId, 'pedido_removido', 'Pedido removido', null, ['pedido_id'=>$data['id']]); }
            }
            echo json_encode(['success' => $ok]);
            break;
        default:
            http_response_code(405);
            echo json_encode(['erro' => 'Método não permitido']);
    }
} catch(Throwable $e) {
    pedidos_log_error('Exceção não tratada', ['erro'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode(['success'=>false,'erro'=>$e->getMessage()]);
}