<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers for authenticated API endpoints
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();

function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        return $method;
    }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_POST['_method'])) {
        $override = $_POST['_method'];
    }
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['DELETE', 'PUT', 'PATCH'], true)) {
            return $override;
        }
    }
    return $method;
}

function read_request_payload(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $tmp = [];
        parse_str($raw, $tmp);
        if (is_array($tmp) && $tmp) {
            $data = $tmp;
        }
    }
    if (!is_array($data) || !$data) {
        $data = $_POST ?: [];
    }
    if (isset($data['_method'])) {
        unset($data['_method']);
    }
    $cached = $data;
    return $cached;
}

function table_exists(PDO $db, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $tableSafe = preg_replace('/[^a-z0-9_]/i', '', $table);
    if ($tableSafe === '') {
        $cache[$table] = false;
        return false;
    }
    try {
        $res = $db->query("SHOW TABLES LIKE '" . $tableSafe . "'");
        $cache[$table] = $res && $res->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function column_exists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '|' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!table_exists($db, $table)) {
        $cache[$key] = false;
        return false;
    }
    $tableSafe = preg_replace('/[^a-z0-9_]/i', '', $table);
    $columnSafe = preg_replace('/[^a-z0-9_]/i', '', $column);
    if ($tableSafe === '' || $columnSafe === '') {
        $cache[$key] = false;
        return false;
    }
    try {
        $res = $db->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '" . $columnSafe . "'");
        $cache[$key] = $res && $res->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function build_placeholders(int $count): string {
    return implode(',', array_fill(0, $count, '?'));
}

function requisicoes_status_options(PDO $db): array {
    try {
        $rows = $db->query("SELECT DISTINCT status FROM requisicoes WHERE status IS NOT NULL AND status<>'' ORDER BY status")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $options = [];
        foreach ($rows as $status) {
            $label = str_replace('_', ' ', (string)$status);
            $options[] = [
                'value' => $status,
                'label' => ucwords($label)
            ];
        }
        return $options;
    } catch (Throwable $e) {
        return [];
    }
}

function requisicoes_normalize_row(array $row): array {
    if (!array_key_exists('titulo', $row) || $row['titulo'] === null || $row['titulo'] === '') {
        $row['titulo'] = isset($row['id']) ? ('Requisição #' . $row['id']) : 'Requisição';
    }
    return $row;
}

function requisicoes_ensure_tracking_columns(PDO $db): bool {
    static $checked = false;
    if ($checked) {
        return true;
    }
    try {
        $hasToken = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'tracking_token'")->fetch(PDO::FETCH_ASSOC);
        $hasExpiry = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'tracking_token_expira'")->fetch(PDO::FETCH_ASSOC);
        if (!$hasToken || !$hasExpiry) {
            $db->exec("ALTER TABLE requisicoes ADD COLUMN tracking_token VARCHAR(64) NULL, ADD COLUMN tracking_token_expira DATETIME NULL");
        }
        $checked = true;
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function requisicoes_tracking_get_or_create(PDO $db, int $id): array {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido');
    }
    if (!requisicoes_ensure_tracking_columns($db)) {
        throw new RuntimeException('Colunas de tracking indisponíveis');
    }
    $st = $db->prepare('SELECT tracking_token, tracking_token_expira FROM requisicoes WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Requisição não encontrada');
    }
    $token = $row['tracking_token'];
    $expira = $row['tracking_token_expira'];
    $needNew = true;
    if ($token && $expira) {
        $expiraTs = strtotime($expira);
        if ($expiraTs && $expiraTs > (time() + 86400)) {
            $needNew = false;
        }
    }
    if ($needNew) {
        $token = bin2hex(random_bytes(20));
        $expira = date('Y-m-d H:i:s', strtotime('+90 days'));
        $up = $db->prepare('UPDATE requisicoes SET tracking_token=?, tracking_token_expira=? WHERE id=?');
        $up->execute([$token, $expira, $id]);
        log_requisicao_event($db, $id, 'tracking_token_gerado', 'Token público de acompanhamento gerado/renovado', null, [
            'token_prefix' => substr($token, 0, 6),
            'expira' => $expira
        ]);
    }
    return ['token' => $token, 'expira' => $expira];
}

function requisicoes_build_filters($buscaRaw, $statusRaw, array &$params, ?int $clienteRestrito, bool $hasTitulo): array {
    $filters = [];
    if ($clienteRestrito) {
        $filters[] = 'r.cliente_id = ?';
        $params[] = $clienteRestrito;
    }

    $busca = trim((string)($buscaRaw ?? ''));
    if ($busca !== '') {
        $buscaLower = function_exists('mb_strtolower') ? mb_strtolower($busca, 'UTF-8') : strtolower($busca);
        $like = '%' . $buscaLower . '%';
        $likeConditions = [];
        if ($hasTitulo) {
            $likeConditions[] = 'LOWER(r.titulo) LIKE ?';
            $params[] = $like;
        }
        $likeConditions[] = 'CAST(r.id AS CHAR) LIKE ?';
        $params[] = $like;
        $likeConditions[] = 'CAST(r.cliente_id AS CHAR) LIKE ?';
        $params[] = $like;
        $likeConditions[] = 'LOWER(IFNULL(c.razao_social, \'\')) LIKE ?';
        $params[] = $like;
        $likeConditions[] = 'LOWER(IFNULL(c.nome_fantasia, \'\')) LIKE ?';
        $params[] = $like;
        $filters[] = '(' . implode(' OR ', $likeConditions) . ')';

        $digits = preg_replace('/\D+/', '', $busca);
        if ($digits !== '') {
            $number = (int)$digits;
            $filters[] = '(r.id = ? OR r.cliente_id = ?)';
            $params[] = $number;
            $params[] = $number;
        }
    }

    $statusNorm = trim((string)($statusRaw ?? ''));
    if ($statusNorm !== '') {
        $filters[] = 'LOWER(r.status) = ?';
        $params[] = strtolower($statusNorm);
    }

    return $filters;
}

$method = resolve_http_method();
$usuario = auth_usuario();
$usuarioTipo = $usuario['tipo'] ?? null;
$usuarioClienteId = $usuario['cliente_id'] ?? null;

switch ($method) {
    case 'GET':
        $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
        $buscaParam = array_key_exists('busca', $_GET) ? $_GET['busca'] : null;
        $statusParam = array_key_exists('status', $_GET) ? $_GET['status'] : null;
        $clienteRestrito = ($usuarioTipo === 'cliente' && $usuarioClienteId) ? (int)$usuarioClienteId : null;
        $hasTituloCol = column_exists($db, 'requisicoes', 'titulo');
        $hasResponsavelCol = column_exists($db, 'requisicoes', 'responsavel_id');
        $selectCols = ['r.id', 'r.cliente_id', 'r.status', 'r.criado_em'];
        $selectCols[] = $hasTituloCol ? 'r.titulo' : 'NULL AS titulo';
        $selectCols[] = $hasResponsavelCol ? 'r.responsavel_id' : 'NULL AS responsavel_id';
        $selectSqlCols = implode(', ', $selectCols);
        $fromSql = ' FROM requisicoes r LEFT JOIN clientes c ON c.id = r.cliente_id';

        $params = [];
        $filters = requisicoes_build_filters($buscaParam, $statusParam, $params, $clienteRestrito, $hasTituloCol);
        $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
        $wantsAdvanced = ($mode !== null && $mode !== '') || isset($_GET['page']) || isset($_GET['per_page']) || $buscaParam !== null || $statusParam !== null;

        try {
            if ($mode === 'ids') {
                $sql = 'SELECT r.id' . $fromSql . $whereSql . ' ORDER BY r.id DESC';
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
                echo json_encode(['ids' => $ids, 'total' => count($ids)]);
                break;
            }

            if ($wantsAdvanced) {
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 15)));
                $offset = ($page - 1) * $perPage;

                $dataSql = 'SELECT ' . $selectSqlCols . $fromSql . $whereSql . ' ORDER BY r.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
                $stmt = $db->prepare($dataSql);
                $stmt->execute($params);
                $rows = array_map('requisicoes_normalize_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);

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
                        'total_pages' => max(1, (int)ceil($total / $perPage)),
                        'status_options' => requisicoes_status_options($db)
                    ]
                ]);
                break;
            }

            $sql = 'SELECT ' . $selectSqlCols . $fromSql . $whereSql . ' ORDER BY r.id DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = array_map('requisicoes_normalize_row', $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
            echo json_encode($rows);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao carregar requisicoes']);
        }
        break;
    case 'POST':
        $data = read_request_payload();
        $action = $data['_action'] ?? null;
        if ($action === 'tracking_link') {
            $idReq = isset($data['id']) ? (int)$data['id'] : 0;
            if ($usuarioTipo === 'cliente') {
                http_response_code(403);
                echo json_encode(['success' => false, 'erro' => 'Cliente não pode gerar link público.']);
                break;
            }
            if ($idReq <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'erro' => 'ID inválido']);
                break;
            }
            try {
                $tokenInfo = requisicoes_tracking_get_or_create($db, $idReq);
                echo json_encode(['success' => true] + $tokenInfo);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'erro' => 'Falha ao gerar link público.']);
            }
            break;
        }
        unset($data['_action']);
        $tituloRaw = trim($data['titulo'] ?? '');
        $status     = $data['status'] ?? 'aberta';
        if ($usuarioTipo === 'cliente') {
            if(!$usuarioClienteId){ http_response_code(400); echo json_encode(['erro'=>'Cliente sem vínculo.']); break; }
            $cliente_id = (int)$usuarioClienteId;
            // Força aprovação administrativa para requisições criadas pelo cliente
            $status = 'pendente_aprovacao';
        } else {
            $cliente_id = (isset($data['cliente_id']) && $data['cliente_id'] !== '') ? (int)$data['cliente_id'] : null;
        }
        $responsavel_id = null;
        if (!empty($data['responsavel_id']) && $usuarioTipo !== 'cliente') { $responsavel_id = (int)$data['responsavel_id']; }
        $temTitulo=false; $temResp=false;
        try { $chk = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'titulo'"); $temTitulo = (bool)$chk->fetch(); } catch(Throwable $e) {}
        try { $chk = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'responsavel_id'"); $temResp = (bool)$chk->fetch(); } catch(Throwable $e) {}
        if ($temTitulo) {
            if($temResp) {
                $stmt = $db->prepare('INSERT INTO requisicoes (titulo, cliente_id, status, responsavel_id) VALUES (?,?,?,?)');
                $ok = $stmt->execute([$tituloRaw, $cliente_id, $status, $responsavel_id]);
            } else {
                $stmt = $db->prepare('INSERT INTO requisicoes (titulo, cliente_id, status) VALUES (?,?,?)');
                $ok = $stmt->execute([$tituloRaw, $cliente_id, $status]);
            }
        } else {
            if($temResp) {
                $stmt = $db->prepare('INSERT INTO requisicoes (cliente_id, status, responsavel_id) VALUES (?,?,?)');
                $ok = $stmt->execute([$cliente_id, $status, $responsavel_id]);
            } else {
                $stmt = $db->prepare('INSERT INTO requisicoes (cliente_id, status) VALUES (?,?)');
                $ok = $stmt->execute([$cliente_id, $status]);
            }
        }
        $novoId = (int)$db->lastInsertId();
        if ($ok && $temTitulo && ($tituloRaw === '' || $tituloRaw === null)) {
            try { $upd = $db->prepare('UPDATE requisicoes SET titulo=? WHERE id=?'); $upd->execute(['Requisição #'.$novoId, $novoId]); $tituloRaw='Requisição #'.$novoId; } catch(Throwable $e) {}
        }
        if($ok){
            log_requisicao_event($db, $novoId, 'requisicao_criada', 'Requisição criada', null, ['id'=>$novoId,'status'=>$status,'cliente_id'=>$cliente_id,'titulo'=>$tituloRaw]);
            // Marca na timeline que a requisição aguarda aprovação administrativa
            if ($status === 'pendente_aprovacao') {
                log_requisicao_event($db, $novoId, 'aprovacao_pendente', 'Requisição aguardando aprovação', null, ['status'=>$status]);
            }
            if($cliente_id && function_exists('email_send_requisicao_aberta')){
                try { email_send_requisicao_aberta($novoId, (int)$cliente_id, true); } catch(Throwable $e) {}
            }
        }
        echo json_encode(['success'=>$ok,'id'=>$novoId]);
        break;
    case 'PUT':
        if($usuarioTipo==='cliente'){ http_response_code(403); echo json_encode(['erro'=>'Cliente não pode atualizar requisicao.']); break; }
        $data = read_request_payload();
        $id = $data['id'] ?? null; if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        $tituloRaw = trim($data['titulo'] ?? '');
    $cliente_id = (isset($data['cliente_id']) && $data['cliente_id'] !== '') ? (int)$data['cliente_id'] : null;
        $status = $data['status'] ?? 'aberta';
        $temTitulo=false; try { $chk = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'titulo'"); $temTitulo = (bool)$chk->fetch(); } catch(Throwable $e) {}
        $temResp=false; try { $chk = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'responsavel_id'"); $temResp = (bool)$chk->fetch(); } catch(Throwable $e) {}
        // Busca status atual para detectar aprovação
        $statusAntes = null;
        try { $stCur = $db->prepare('SELECT status FROM requisicoes WHERE id=?'); $stCur->execute([$id]); $statusAntes = $stCur->fetchColumn(); } catch(Throwable $e) {}
        if ($temTitulo) {
            if ($tituloRaw === '' || $tituloRaw === null) { $tituloRaw = 'Requisição #'.$id; }
            $stmt = $db->prepare('UPDATE requisicoes SET titulo=?, cliente_id=?, status=? WHERE id=?');
            $ok = $stmt->execute([$tituloRaw, $cliente_id, $status, $id]);
        } else {
            $stmt = $db->prepare('UPDATE requisicoes SET cliente_id=?, status=? WHERE id=?');
            $ok = $stmt->execute([$cliente_id, $status, $id]);
        }
        if($ok){
            log_requisicao_event($db, (int)$id, 'requisicao_atualizada', 'Requisição atualizada', null, ['id'=>$id,'status'=>$status,'cliente_id'=>$cliente_id,'titulo'=>$tituloRaw]);
            log_requisicao_event($db, (int)$id, 'requisicao_status_alterado', 'Status alterado para '.$status, null, ['status'=>$status]);
            // Eventos de aprovação
            if ($statusAntes === 'pendente_aprovacao' && $status !== $statusAntes) {
                if ($status === 'rejeitada') {
                    log_requisicao_event($db, (int)$id, 'aprovacao_rejeitada', 'Requisição rejeitada pelo administrador', ['status_antes'=>$statusAntes], ['status_depois'=>$status]);
                } else {
                    log_requisicao_event($db, (int)$id, 'aprovacao_aprovada', 'Requisição aprovada pelo administrador', ['status_antes'=>$statusAntes], ['status_depois'=>$status]);
                }
            }
            try {
                if ($cliente_id) {
                    $stCli = $db->prepare('SELECT email FROM clientes WHERE id=?');
                    $stCli->execute([$cliente_id]);
                    $cliEmail = $stCli->fetchColumn();
                    if ($cliEmail && function_exists('email_send_tracking_update')) {
                        email_send_tracking_update($cliEmail, (int)$id, $status, 'Status atualizado', false);
                    }
                }
            } catch (Throwable $e) { }
        }
        echo json_encode(['success'=>$ok]);
        break;
    case 'PATCH':
        if($usuarioTipo==='cliente'){ http_response_code(403); echo json_encode(['erro'=>'Cliente não pode atribuir responsável']); break; }
        $data = read_request_payload();
        $id = $data['id'] ?? null; $resp = $data['responsavel_id'] ?? null;
        if(!$id || !$resp){ http_response_code(422); echo json_encode(['erro'=>'id e responsavel_id são obrigatórios']); break; }
        $temResp=false; try { $chk = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'responsavel_id'"); $temResp = (bool)$chk->fetch(); } catch(Throwable $e) {}
        if(!$temResp){ http_response_code(500); echo json_encode(['erro'=>'Coluna responsavel_id ausente (rodar migration)']); break; }
        $stmt = $db->prepare('UPDATE requisicoes SET responsavel_id=? WHERE id=?');
        $ok = $stmt->execute([(int)$resp,(int)$id]);
        if($ok){ log_requisicao_event($db, (int)$id, 'responsavel_atribuido', 'Responsável atribuído', null, ['responsavel_id'=>$resp]); }
        echo json_encode(['success'=>$ok]);
        break;
    case 'DELETE':
        if($usuarioTipo==='cliente'){ http_response_code(403); echo json_encode(['erro'=>'Cliente não pode remover requisicao']); break; }
        $data = read_request_payload();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if(!$id){ http_response_code(400); echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        try {
            $db->beginTransaction();
            $deleted = [];
            $extractIds = static function(array $values): array {
                $ids = [];
                foreach ($values as $value) {
                    $value = (int)$value;
                    if ($value > 0) {
                        $ids[$value] = true;
                    }
                }
                return $ids ? array_keys($ids) : [];
            };

            if (table_exists($db, 'requisicao_itens')) {
                $st = $db->prepare('DELETE FROM requisicao_itens WHERE requisicao_id=?');
                $st->execute([$id]);
                if ($st->rowCount() > 0) { $deleted['requisicao_itens'] = $st->rowCount(); }
            }

            $cotIds = [];
            if (table_exists($db, 'cotacoes') && column_exists($db, 'cotacoes', 'requisicao_id')) {
                $stCot = $db->prepare('SELECT id FROM cotacoes WHERE requisicao_id=?');
                $stCot->execute([$id]);
                $cotIds = $extractIds($stCot->fetchAll(PDO::FETCH_COLUMN));
            }

            $propIds = [];
            if ($cotIds && column_exists($db, 'propostas', 'cotacao_id')) {
                $place = build_placeholders(count($cotIds));
                $stProp = $db->prepare("SELECT id FROM propostas WHERE cotacao_id IN ($place)");
                $stProp->execute($cotIds);
                $propIds = $extractIds($stProp->fetchAll(PDO::FETCH_COLUMN));
            }
            if (!$propIds && column_exists($db, 'propostas', 'requisicao_id')) {
                $stProp = $db->prepare('SELECT id FROM propostas WHERE requisicao_id=?');
                $stProp->execute([$id]);
                $propIds = $extractIds($stProp->fetchAll(PDO::FETCH_COLUMN));
            }

            $pedidoIds = [];
            if ($propIds && table_exists($db, 'pedidos') && column_exists($db, 'pedidos', 'proposta_id')) {
                $placeProps = build_placeholders(count($propIds));
                $stPedidos = $db->prepare("SELECT id FROM pedidos WHERE proposta_id IN ($placeProps)");
                $stPedidos->execute($propIds);
                $pedidoIds = $extractIds($stPedidos->fetchAll(PDO::FETCH_COLUMN));
                if ($pedidoIds) {
                    $placePed = build_placeholders(count($pedidoIds));
                    $delPedidos = $db->prepare("DELETE FROM pedidos WHERE id IN ($placePed)");
                    $delPedidos->execute($pedidoIds);
                    if ($delPedidos->rowCount() > 0) { $deleted['pedidos'] = $delPedidos->rowCount(); }
                }
            }

            if ($propIds && table_exists($db, 'proposta_itens') && column_exists($db, 'proposta_itens', 'proposta_id')) {
                $placeProps = build_placeholders(count($propIds));
                $delItens = $db->prepare("DELETE FROM proposta_itens WHERE proposta_id IN ($placeProps)");
                $delItens->execute($propIds);
                if ($delItens->rowCount() > 0) { $deleted['proposta_itens'] = $delItens->rowCount(); }
            }

            if ($propIds && table_exists($db, 'anexos') && column_exists($db, 'anexos', 'proposta_id')) {
                $placeProps = build_placeholders(count($propIds));
                $delAnexosProp = $db->prepare("DELETE FROM anexos WHERE proposta_id IN ($placeProps)");
                $delAnexosProp->execute($propIds);
                if ($delAnexosProp->rowCount() > 0) { $deleted['anexos'] = ($deleted['anexos'] ?? 0) + $delAnexosProp->rowCount(); }
            }

            if ($propIds && column_exists($db, 'propostas', 'id')) {
                $placeProps = build_placeholders(count($propIds));
                $delProps = $db->prepare("DELETE FROM propostas WHERE id IN ($placeProps)");
                $delProps->execute($propIds);
                if ($delProps->rowCount() > 0) { $deleted['propostas'] = $delProps->rowCount(); }
            }

            $conviteIdsRaw = [];
            if (table_exists($db, 'cotacao_convites') && column_exists($db, 'cotacao_convites', 'requisicao_id')) {
                $stConv = $db->prepare('SELECT id FROM cotacao_convites WHERE requisicao_id=?');
                $stConv->execute([$id]);
                $conviteIdsRaw = array_merge($conviteIdsRaw, $stConv->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $delConv = $db->prepare('DELETE FROM cotacao_convites WHERE requisicao_id=?');
                $delConv->execute([$id]);
                if ($delConv->rowCount() > 0) { $deleted['cotacao_convites'] = $delConv->rowCount(); }
            }
            if ($cotIds && table_exists($db, 'cotacoes_convites') && column_exists($db, 'cotacoes_convites', 'cotacao_id')) {
                $placeCot = build_placeholders(count($cotIds));
                $stConvPlural = $db->prepare("SELECT id FROM cotacoes_convites WHERE cotacao_id IN ($placeCot)");
                $stConvPlural->execute($cotIds);
                $conviteIdsRaw = array_merge($conviteIdsRaw, $stConvPlural->fetchAll(PDO::FETCH_COLUMN) ?: []);
                $delConvPlural = $db->prepare("DELETE FROM cotacoes_convites WHERE cotacao_id IN ($placeCot)");
                $delConvPlural->execute($cotIds);
                if ($delConvPlural->rowCount() > 0) { $deleted['cotacoes_convites'] = $delConvPlural->rowCount(); }
            }
            $conviteIds = $conviteIdsRaw ? $extractIds($conviteIdsRaw) : [];
            if ($cotIds && table_exists($db, 'cotacoes_ranking') && column_exists($db, 'cotacoes_ranking', 'cotacao_id')) {
                $placeCot = build_placeholders(count($cotIds));
                $delRank = $db->prepare("DELETE FROM cotacoes_ranking WHERE cotacao_id IN ($placeCot)");
                $delRank->execute($cotIds);
                if ($delRank->rowCount() > 0) { $deleted['cotacoes_ranking'] = $delRank->rowCount(); }
            }

            if (table_exists($db, 'anexos') && column_exists($db, 'anexos', 'requisicao_id')) {
                $delAnexosReq = $db->prepare('DELETE FROM anexos WHERE requisicao_id=?');
                $delAnexosReq->execute([$id]);
                if ($delAnexosReq->rowCount() > 0) { $deleted['anexos'] = ($deleted['anexos'] ?? 0) + $delAnexosReq->rowCount(); }
            }

            if (table_exists($db, 'notificacoes') && column_exists($db, 'notificacoes', 'requisicao_id')) {
                $delNotif = $db->prepare('DELETE FROM notificacoes WHERE requisicao_id=?');
                $delNotif->execute([$id]);
                if ($delNotif->rowCount() > 0) { $deleted['notificacoes'] = $delNotif->rowCount(); }
            }

            if (table_exists($db, 'followup_logs')) {
                $totalFollow = 0;
                $delReqFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='requisicao' AND entidade_id=?");
                $delReqFollow->execute([$id]);
                $totalFollow += $delReqFollow->rowCount();
                if ($cotIds) {
                    $placeCot = build_placeholders(count($cotIds));
                    $delCotFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='cotacao' AND entidade_id IN ($placeCot)");
                    $delCotFollow->execute($cotIds);
                    $totalFollow += $delCotFollow->rowCount();
                }
                if ($pedidoIds) {
                    $placePed = build_placeholders(count($pedidoIds));
                    $delPedFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='pedido' AND entidade_id IN ($placePed)");
                    $delPedFollow->execute($pedidoIds);
                    $totalFollow += $delPedFollow->rowCount();
                }
                if ($conviteIds) {
                    $placeConv = build_placeholders(count($conviteIds));
                    $delConvFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='cotacao_convite' AND entidade_id IN ($placeConv)");
                    $delConvFollow->execute($conviteIds);
                    $totalFollow += $delConvFollow->rowCount();
                }
                if ($totalFollow > 0) { $deleted['followup_logs'] = $totalFollow; }
            }

            if ($cotIds && column_exists($db, 'cotacoes', 'id')) {
                $placeCot = build_placeholders(count($cotIds));
                $delCot = $db->prepare("DELETE FROM cotacoes WHERE id IN ($placeCot)");
                $delCot->execute($cotIds);
                if ($delCot->rowCount() > 0) { $deleted['cotacoes'] = $delCot->rowCount(); }
            }

            $delReq = $db->prepare('DELETE FROM requisicoes WHERE id=?');
            $delReq->execute([$id]);
            if ($delReq->rowCount() === 0) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['success'=>false,'erro'=>'Requisição não encontrada.']);
                break;
            }
            $deleted['requisicoes'] = $delReq->rowCount();

            $db->commit();

            $mensagem = 'Requisição e vínculos relacionados removidos com sucesso.';
            echo json_encode(['success'=>true,'mensagem'=>$mensagem,'removidos'=>$deleted]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $code = (string)($e->getCode() ?? '');
            if ($code === '23000') {
                http_response_code(409);
                echo json_encode(['success'=>false, 'erro'=>'Não foi possível excluir a requisição devido a vínculos existentes (cotações/propostas/pedidos).']);
            } else {
                http_response_code(500);
                echo json_encode(['success'=>false, 'erro'=>'Não foi possível excluir a requisição. Verifique vínculos (cotações/propostas/pedidos).']);
            }
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['erro' => 'Método não permitido']);
}