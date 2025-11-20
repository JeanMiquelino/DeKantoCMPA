<?php
session_start();
header('Content-Type: application/json');
// Security headers (consistência com endpoints públicos)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/auth.php'; // novo: para checar roles/permissões

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
    if (!$override) {
        $override = $_POST['_method'] ?? $_GET['_method'] ?? null;
    }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['DELETE', 'PUT', 'PATCH'], true)) {
            return $override;
        }
    }
    return $method;
}

$method = resolve_http_method();

$convitesQueryAction = isset($_GET['convites']) ? strtolower(trim((string)$_GET['convites'])) : null;

if ($method === 'GET' && $convitesQueryAction === 'list') {
    try {
        $cotId = isset($_GET['cotacao_id']) ? (int)$_GET['cotacao_id'] : null;
        $reqId = isset($_GET['requisicao_id']) ? (int)$_GET['requisicao_id'] : null;
        $rows = cotacoes_convites_list($db, $__user ?? [], $cotId, $reqId);
        echo json_encode($rows);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['erro' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        if ($msg === 'Não encontrado' || stripos($msg, 'não encontrada') !== false) {
            http_response_code(404);
        } else {
            http_response_code(500);
        }
        echo json_encode(['erro' => $msg]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['erro' => 'Falha ao carregar convites']);
    }
    exit;
}

$sharedPayload = null;
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    $sharedPayload = read_request_payload();
}

if ($method === 'POST') {
    $convitesPostAction = isset($sharedPayload['convites']) ? strtolower(trim((string)$sharedPayload['convites'])) : null;
    if ($convitesPostAction === 'create') {
        try {
            $result = cotacoes_convites_create($db, $sharedPayload, $__user ?? []);
            echo json_encode($result);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao criar convites']);
        }
        exit;
    }
    if ($convitesPostAction === 'cancel') {
        try {
            $result = cotacoes_convites_cancel($db, $sharedPayload, $__user ?? []);
            echo json_encode($result);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['erro' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(500);
            echo json_encode(['erro' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao cancelar convite']);
        }
        exit;
    }
    if ($convitesPostAction !== null) {
        unset($sharedPayload['convites']);
    }
}
if ($method !== 'POST' && isset($_POST['_method'])) {
    unset($_POST['_method']);
}

// Determinar privilégios básicos
$__user = auth_usuario();
$__roles = $__user['roles'] ?? [];
$__isAdmin = is_array($__roles) && in_array('admin', $__roles, true);
$__canViewToken = $__isAdmin || auth_can('cotacoes_ver_token') || auth_can('cotacoes.ver_token');
// Auxiliares de escopo/permite
$__tipo = $__user['tipo'] ?? null;
$__clienteId = isset($__user['cliente_id']) ? (int)$__user['cliente_id'] : null;
$__canList   = $__isAdmin || auth_can('cotacoes.listar')    || auth_can('cotacoes_listar');
$__canCreate = $__isAdmin || auth_can('cotacoes.criar')     || auth_can('cotacoes_criar');
$__canUpdate = $__isAdmin || auth_can('cotacoes.atualizar') || auth_can('cotacoes_atualizar');
$__canDelete = $__isAdmin || auth_can('cotacoes.excluir')   || auth_can('cotacoes_excluir');

// Fallback: colaboradores internos sem roles específicos ainda podem operar
$__isInternalStaff = !in_array($__tipo, ['cliente','fornecedor'], true);
if ($__isInternalStaff) {
    $__canList   = $__canList   || true;
    $__canCreate = $__canCreate || true;
    $__canUpdate = $__canUpdate || true;
    $__canDelete = $__canDelete || true;
    $__canViewToken = true;
}

function gerar_token($len = 32) {
    return bin2hex(random_bytes($len/2));
}

// Helper: checar existência de coluna e se é anulável
function coluna_info(PDO $db, $tabela, $coluna) {
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `{$tabela}` LIKE ?");
        $stmt->execute([$coluna]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'exists' => true,
            'nullable' => (isset($row['Null']) && strtoupper($row['Null']) === 'YES')
        ];
    } catch (Throwable $e) { return null; }
}

function read_request_payload(): array {
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $data = [];
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        } else {
            $tmp = [];
            parse_str($raw, $tmp);
            if (is_array($tmp) && $tmp) {
                $data = $tmp;
            }
        }
    }
    if (!$data) {
        if (!empty($_POST) && is_array($_POST)) {
            $data = $_POST;
        } elseif (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && !empty($_GET) && is_array($_GET)) {
            $data = $_GET;
        } else {
            $data = [];
        }
    }
    if (isset($data['_method'])) {
        unset($data['_method']);
    }
    if (!is_array($data)) {
        $data = [];
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
        $cache[$table] = ($res && $res->fetch(PDO::FETCH_NUM)) ? true : false;
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
    $info = coluna_info($db, $table, $column);
    $exists = is_array($info) && !empty($info['exists']);
    $cache[$key] = $exists;
    return $exists;
}

function build_placeholders(int $count): string {
    return implode(',', array_fill(0, $count, '?'));
}

function collect_numeric_ids(array $values): array {
    $ids = [];
    foreach ($values as $value) {
        $value = (int)$value;
        if ($value > 0) {
            $ids[$value] = true;
        }
    }
    return $ids ? array_keys($ids) : [];
}

function cotacoes_status_options(PDO $db): array {
    try {
        $rows = $db->query("SELECT DISTINCT status FROM cotacoes WHERE status IS NOT NULL AND status<>'' ORDER BY status")
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

function cotacoes_normalize_row(array $row, bool $includeToken): array {
    if (array_key_exists('token_hash', $row)) {
        unset($row['token_hash']);
    }
    if (!$includeToken && array_key_exists('token', $row)) {
        unset($row['token']);
    }
    return $row;
}

function cotacoes_build_filters($buscaRaw, $statusRaw, array &$params, ?int $clienteRestrito): array {
    $filters = [];
    if ($clienteRestrito) {
        $filters[] = 'r.cliente_id = ?';
        $params[] = $clienteRestrito;
    }

    $busca = trim((string)($buscaRaw ?? ''));
    if ($busca !== '') {
        $buscaLower = function_exists('mb_strtolower') ? mb_strtolower($busca, 'UTF-8') : strtolower($busca);
        $like = '%' . $buscaLower . '%';
    $filters[] = "(CAST(c.id AS CHAR) LIKE ? OR CAST(c.requisicao_id AS CHAR) LIKE ? OR LOWER(IFNULL(c.status, '')) LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;

        $digits = preg_replace('/\D+/', '', $busca);
        if ($digits !== '') {
            $num = (int)$digits;
            $filters[] = '(c.id = ? OR c.requisicao_id = ?)';
            $params[] = $num;
            $params[] = $num;
        }
    }

    $statusNorm = trim((string)($statusRaw ?? ''));
    if ($statusNorm !== '') {
        $filters[] = 'LOWER(c.status) = ?';
        $params[] = strtolower($statusNorm);
    }

    return $filters;
}

switch ($method) {
    case 'GET':
        if (!$__canList) { http_response_code(403); echo json_encode(['erro'=>'Sem permissão']); break; }
        try {
            rate_limit_enforce($db, 'api/cotacoes:get', 300, 300, true);
            $wantsToken = (!empty($_GET['include_token']) && $_GET['include_token'] == '1');
            if ($wantsToken) { rate_limit_enforce($db, 'api/cotacoes:get_include_token', 20, 300, true); }
            if ($__tipo === 'cliente' && $wantsToken) {
                http_response_code(404);
                echo json_encode(['erro' => 'Não encontrado']);
                break;
            }
            $includeToken = ($wantsToken && $__canViewToken && $__tipo !== 'cliente');

            $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
            $buscaParam = array_key_exists('busca', $_GET) ? $_GET['busca'] : null;
            $statusParam = array_key_exists('status', $_GET) ? $_GET['status'] : null;
            if ($statusParam === 'abertas') { $statusParam = 'aberta'; }
            if ($statusParam === 'todas') { $statusParam = ''; }
            $clienteRestrito = ($__tipo === 'cliente' && $__clienteId) ? $__clienteId : null;

            $fromSql = ' FROM cotacoes c LEFT JOIN requisicoes r ON r.id = c.requisicao_id';
            $params = [];
            $filters = [];
            if (!empty($_GET['id'])) {
                $filters[] = 'c.id = ?';
                $params[] = (int)$_GET['id'];
            }
            if (!empty($_GET['requisicao_id'])) {
                $filters[] = 'c.requisicao_id = ?';
                $params[] = (int)$_GET['requisicao_id'];
            }
            $filters = array_merge($filters, cotacoes_build_filters($buscaParam, $statusParam, $params, $clienteRestrito));
            $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
            $wantsAdvanced = ($mode !== null && $mode !== '') || isset($_GET['page']) || isset($_GET['per_page']) || $buscaParam !== null || $statusParam !== null;

            if ($mode === 'ids') {
                $sql = 'SELECT c.id' . $fromSql . $whereSql . ' ORDER BY c.id DESC';
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

                $dataSql = 'SELECT c.*' . $fromSql . $whereSql . ' ORDER BY c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
                $stmt = $db->prepare($dataSql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $rows = array_map(function($row) use ($includeToken) {
                    return cotacoes_normalize_row($row, $includeToken);
                }, $rows);

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
                        'total_pages' => max(1, (int)ceil($total / max(1, $perPage))),
                        'status_options' => cotacoes_status_options($db)
                    ]
                ]);
                break;
            }

            $sql = 'SELECT c.*' . $fromSql . $whereSql . ' ORDER BY c.id DESC';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows = array_map(function($row) use ($includeToken) {
                return cotacoes_normalize_row($row, $includeToken);
            }, $rows);
            echo json_encode($rows);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['erro' => 'Falha ao listar cotações']);
        }
        break;
    case 'POST':
        if (!$__canCreate) { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Sem permissão']); break; }
        // Clientes não podem criar cotações diretamente
        if ($__tipo === 'cliente') { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Clientes não podem criar cotações']); break; }
        // Limitar criação em massa
    rate_limit_enforce($db, 'api/cotacoes:post', 60, 300, true);
    $data = is_array($sharedPayload) ? $sharedPayload : read_request_payload();
        $reqId = (int)($data['requisicao_id'] ?? 0);
        if ($reqId <= 0) { http_response_code(400); echo json_encode(['success'=>false,'erro'=>'requisicao_id inválido']); break; }
        $status = $data['status'] ?? 'aberta';
        try {
            $token = gerar_token(32); // token raw só será retornado quando aplicável
            $expira = date('Y-m-d H:i:s', strtotime('+2 days'));
            // Detectar colunas e anulabilidade
            $infoHash = coluna_info($db, 'cotacoes', 'token_hash');
            $infoTok  = coluna_info($db, 'cotacoes', 'token');
            $hasHashCol = is_array($infoHash) && !empty($infoHash['exists']);
            $tokenNullable = is_array($infoTok) && !empty($infoTok['nullable']);

            if ($hasHashCol && $tokenNullable) {
                // hash-only: não persistir token raw
                $stmt = $db->prepare('INSERT INTO cotacoes (requisicao_id, token, token_expira_em, rodada, status, token_hash) VALUES (?, NULL, ?, ?, ?, ?)');
                $ok = $stmt->execute([$reqId, $expira, 1, $status, hash('sha256',$token)]);
            } elseif ($hasHashCol && !$tokenNullable) {
                // token não permite NULL: persistir token e token_hash
                $stmt = $db->prepare('INSERT INTO cotacoes (requisicao_id, token, token_expira_em, rodada, status, token_hash) VALUES (?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$reqId, $token, $expira, 1, $status, hash('sha256',$token)]);
            } else {
                // Sem coluna de hash: legacy
                $stmt = $db->prepare('INSERT INTO cotacoes (requisicao_id, token, token_expira_em, rodada, status) VALUES (?, ?, ?, ?, ?)');
                $ok = $stmt->execute([$reqId, $token, $expira, 1, $status]);
            }
            $cotacaoId = (int)$db->lastInsertId();
            $retToken = ($hasHashCol && $tokenNullable) ? null : $token; // Minimizar exposição quando hash-only suportado pelo schema
            if($ok && $reqId){
                try { log_requisicao_event($db, $reqId, 'cotacao_criada', 'Cotação criada', null, ['cotacao_id'=>$cotacaoId]); } catch(Throwable $e){}
            }
            // AVISO: Envio direto de emails será substituído por convites individuais (/api/cotacoes_convites)
            // Desabilitado por padrão para evitar vazamento de tokens legacy
            if (false && $ok && !empty($data['emails_fornecedores']) && is_array($data['emails_fornecedores'])) {
                foreach ($data['emails_fornecedores'] as $emailF) {
                    $emailF = trim($emailF);
                    if ($emailF !== '') {
                        try { email_send_cotacao_link($emailF, $cotacaoId, $token, false); } catch (Throwable $e) { }
                    }
                }
            }
            echo json_encode(['success' => $ok, 'id' => $cotacaoId, 'token' => $retToken, 'expira' => $expira]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success'=>false,'erro'=>'Falha ao criar cotação']);
        }
        break;
    case 'PUT':
        if (!$__canUpdate) { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Sem permissão']); break; }
        // Clientes não podem alterar cotações
        if ($__tipo === 'cliente') { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Clientes não podem alterar cotações']); break; }
        $data = read_request_payload();
        $id = (int)($data['id'] ?? 0);
        if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        // Detectar colunas e anulabilidade
        $infoHash = coluna_info($db, 'cotacoes', 'token_hash');
        $infoTok  = coluna_info($db, 'cotacoes', 'token');
        $hasHashCol = is_array($infoHash) && !empty($infoHash['exists']);
        $tokenNullable = is_array($infoTok) && !empty($infoTok['nullable']);

        if (isset($data['regenerar_token']) && $data['regenerar_token'] == '1') {
            // Rate limit específico para regeneração de token
            rate_limit_enforce($db, 'api/cotacoes:put_regen', 10, 300, true);
            try {
                $token = gerar_token(32);
                $expira = date('Y-m-d H:i:s', strtotime('+2 days'));
                if($hasHashCol && $tokenNullable){
                    // hash-only: zera token raw
                    $stmt = $db->prepare('UPDATE cotacoes SET token=NULL, token_expira_em=?, token_hash=? WHERE id=?');
                    $ok = $stmt->execute([$expira, hash('sha256',$token), $id]);
                } elseif($hasHashCol && !$tokenNullable) {
                    // token não permite NULL: atualizar ambos
                    $stmt = $db->prepare('UPDATE cotacoes SET token=?, token_expira_em=?, token_hash=? WHERE id=?');
                    $ok = $stmt->execute([$token, $expira, hash('sha256',$token), $id]);
                } else {
                    $stmt = $db->prepare('UPDATE cotacoes SET token=?, token_expira_em=? WHERE id=?');
                    $ok = $stmt->execute([$token, $expira, $id]);
                }
                // Registrar evento de regeneração
                try {
                    $sel = $db->prepare('SELECT requisicao_id FROM cotacoes WHERE id=?'); $sel->execute([$id]); $reqId = (int)$sel->fetchColumn();
                    if($reqId){ log_requisicao_event($db, $reqId, 'cotacao_token_regenerado', 'Token da cotação regenerado', null, ['cotacao_id'=>$id,'expira'=>$expira]); }
                } catch(Throwable $e){}
                // Sempre retornar o token raw ao regenerar (usuário autenticado)
                echo json_encode(['success' => $ok, 'token' => $token, 'expira' => $expira]);
            } catch(Throwable $e){
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Falha ao regenerar token']);
            }
        } else {
            // rate limit geral de updates
            rate_limit_enforce($db, 'api/cotacoes:put', 120, 300, true);
            try {
                // Obter estado anterior para logs
                $old = $db->prepare('SELECT requisicao_id, status, rodada, tipo_frete FROM cotacoes WHERE id=?');
                $old->execute([$id]);
                $before = $old->fetch(PDO::FETCH_ASSOC) ?: [];
                $campos=[]; $params=[];
                if(isset($data['status'])){ $campos[]='status=?'; $params[]=$data['status']; }
                if(isset($data['rodada'])){ $campos[]='rodada=?'; $params[]=$data['rodada']; }
                // tipo_frete mantido se enviado
                if(isset($data['tipo_frete'])){ $campos[]='tipo_frete=?'; $params[]=$data['tipo_frete']; }
                if(!$campos){ echo json_encode(['success'=>false,'erro'=>'Nada para atualizar']); break; }
                $params[]=$id;
                $sql='UPDATE cotacoes SET '.implode(',', $campos).' WHERE id=?';
                $stmt = $db->prepare($sql);
                $ok = $stmt->execute($params);
                echo json_encode(['success' => $ok]);
                // Logs na timeline
                try {
                    $reqId = (int)($before['requisicao_id'] ?? 0);
                    if ($reqId) {
                        if (isset($data['status']) && ($before['status'] ?? null) !== $data['status']) {
                            $novo = (string)$data['status'];
                            $tipo = (in_array($novo, ['fechada','encerrada']) ? 'cotacao_encerrada' : 'cotacao_status_alterado');
                            $msg  = ($tipo==='cotacao_encerrada' ? 'Cotação encerrada' : ('Status da cotação alterado: '.($before['status'] ?? '—').' → '.$novo));
                            log_requisicao_event($db, $reqId, $tipo, $msg, null, ['cotacao_id'=>$id,'de'=>$before['status'] ?? null,'para'=>$novo]);
                        }
                        if (isset($data['rodada']) && (string)($before['rodada'] ?? '') !== (string)$data['rodada']) {
                            log_requisicao_event($db, $reqId, 'cotacao_rodada_alterada', 'Rodada da cotação alterada', null, ['cotacao_id'=>$id,'de'=>$before['rodada'] ?? null,'para'=>$data['rodada']]);
                        }
                        if (isset($data['tipo_frete']) && ($before['tipo_frete'] ?? null) !== $data['tipo_frete']) {
                            log_requisicao_event($db, $reqId, 'cotacao_tipo_frete_alterado', 'Tipo de frete da cotação alterado', null, ['cotacao_id'=>$id,'de'=>$before['tipo_frete'] ?? null,'para'=>$data['tipo_frete']]);
                        }
                    }
                } catch(Throwable $e){}
            } catch(Throwable $e){
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Falha ao atualizar cotação']);
            }
        }
        break;
    case 'DELETE':
        if (!$__canDelete) { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Sem permissão']); break; }
        // Clientes não podem excluir cotações
        if ($__tipo === 'cliente') { http_response_code(403); echo json_encode(['success'=>false,'erro'=>'Clientes não podem excluir cotações']); break; }
        // Proteção contra deleção abusiva
        rate_limit_enforce($db, 'api/cotacoes:delete', 30, 300, true);
        $data = read_request_payload();
        $id = (int)($data['id'] ?? 0);
        if(!$id){ http_response_code(400); echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        try {
            $db->beginTransaction();

            $sel = $db->prepare('SELECT requisicao_id FROM cotacoes WHERE id=?');
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['success'=>false,'erro'=>'Cotação não encontrada.']);
                break;
            }
            $reqId = (int)($row['requisicao_id'] ?? 0);
            $deleted = [];

            $propIds = [];
            if (table_exists($db, 'propostas') && column_exists($db, 'propostas', 'cotacao_id')) {
                $stProp = $db->prepare('SELECT id FROM propostas WHERE cotacao_id=?');
                $stProp->execute([$id]);
                $propIds = collect_numeric_ids($stProp->fetchAll(PDO::FETCH_COLUMN));
            }

            $pedidoIds = [];
            if ($propIds && table_exists($db, 'pedidos') && column_exists($db, 'pedidos', 'proposta_id')) {
                $placeProps = build_placeholders(count($propIds));
                $stPedidos = $db->prepare("SELECT id FROM pedidos WHERE proposta_id IN ($placeProps)");
                $stPedidos->execute($propIds);
                $pedidoIds = collect_numeric_ids($stPedidos->fetchAll(PDO::FETCH_COLUMN));
            }

            $convitesPlural = [];
            if (table_exists($db, 'cotacoes_convites') && column_exists($db, 'cotacoes_convites', 'cotacao_id')) {
                $stConvPlural = $db->prepare('SELECT id FROM cotacoes_convites WHERE cotacao_id=?');
                $stConvPlural->execute([$id]);
                $convitesPlural = collect_numeric_ids($stConvPlural->fetchAll(PDO::FETCH_COLUMN));
            }

            $convitesLegacy = [];
            if (table_exists($db, 'cotacao_convites') && column_exists($db, 'cotacao_convites', 'cotacao_id')) {
                $stConvLegacy = $db->prepare('SELECT id FROM cotacao_convites WHERE cotacao_id=?');
                $stConvLegacy->execute([$id]);
                $convitesLegacy = collect_numeric_ids($stConvLegacy->fetchAll(PDO::FETCH_COLUMN));
            }

            $conviteIds = collect_numeric_ids(array_merge($convitesPlural, $convitesLegacy));

            if (table_exists($db, 'followup_logs')) {
                $totalFollow = 0;
                $delCotFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='cotacao' AND entidade_id=?");
                $delCotFollow->execute([$id]);
                $totalFollow += $delCotFollow->rowCount();
                if ($propIds) {
                    $placeProps = build_placeholders(count($propIds));
                    $delPropFollow = $db->prepare("DELETE FROM followup_logs WHERE entidade='proposta' AND entidade_id IN ($placeProps)");
                    $delPropFollow->execute($propIds);
                    $totalFollow += $delPropFollow->rowCount();
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
                if ($totalFollow > 0) {
                    $deleted['followup_logs'] = $totalFollow;
                }
            }

            if ($pedidoIds) {
                $placePed = build_placeholders(count($pedidoIds));
                $delPed = $db->prepare("DELETE FROM pedidos WHERE id IN ($placePed)");
                $delPed->execute($pedidoIds);
                if ($delPed->rowCount() > 0) { $deleted['pedidos'] = $delPed->rowCount(); }
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

            if (table_exists($db, 'anexos') && column_exists($db, 'anexos', 'cotacao_id')) {
                $delAnexosCot = $db->prepare('DELETE FROM anexos WHERE cotacao_id=?');
                $delAnexosCot->execute([$id]);
                if ($delAnexosCot->rowCount() > 0) { $deleted['anexos'] = ($deleted['anexos'] ?? 0) + $delAnexosCot->rowCount(); }
            }

            if ($propIds) {
                $placeProps = build_placeholders(count($propIds));
                $delProps = $db->prepare("DELETE FROM propostas WHERE id IN ($placeProps)");
                $delProps->execute($propIds);
                if ($delProps->rowCount() > 0) { $deleted['propostas'] = $delProps->rowCount(); }
            }

            if (table_exists($db, 'cotacoes_convites') && column_exists($db, 'cotacoes_convites', 'cotacao_id')) {
                $delConvPlural = $db->prepare('DELETE FROM cotacoes_convites WHERE cotacao_id=?');
                $delConvPlural->execute([$id]);
                if ($delConvPlural->rowCount() > 0) { $deleted['cotacoes_convites'] = $delConvPlural->rowCount(); }
            }

            if (table_exists($db, 'cotacao_convites') && column_exists($db, 'cotacao_convites', 'cotacao_id')) {
                $delConvLegacy = $db->prepare('DELETE FROM cotacao_convites WHERE cotacao_id=?');
                $delConvLegacy->execute([$id]);
                if ($delConvLegacy->rowCount() > 0) { $deleted['cotacao_convites'] = $delConvLegacy->rowCount(); }
            }

            if (table_exists($db, 'cotacoes_ranking') && column_exists($db, 'cotacoes_ranking', 'cotacao_id')) {
                $delRank = $db->prepare('DELETE FROM cotacoes_ranking WHERE cotacao_id=?');
                $delRank->execute([$id]);
                if ($delRank->rowCount() > 0) { $deleted['cotacoes_ranking'] = $delRank->rowCount(); }
            }

            $stmt = $db->prepare('DELETE FROM cotacoes WHERE id=?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['success'=>false,'erro'=>'Cotação não encontrada.']);
                break;
            }
            $deleted['cotacoes'] = $stmt->rowCount();

            $db->commit();

            if($reqId){ try { log_requisicao_event($db, $reqId, 'cotacao_excluida', 'Cotação excluída', null, ['cotacao_id'=>$id]); } catch(Throwable $e){} }
            echo json_encode(['success'=>true,'removidos'=>$deleted]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $code = (string)($e->getCode() ?? '');
            if ($code === '23000') {
                http_response_code(409);
                echo json_encode(['success'=>false,'erro'=>'Não foi possível excluir a cotação devido a vínculos existentes.']);
            } else {
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Não foi possível excluir a cotação.']);
            }
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['erro' => 'Método não permitido']);
}

function cotacoes_convites_table_info(PDO $db): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $exists = table_exists($db, 'cotacao_convites');
    $cache = [
        'exists' => $exists,
        'has_cotacao_column' => $exists ? column_exists($db, 'cotacao_convites', 'cotacao_id') : false
    ];
    return $cache;
}

function cotacoes_convites_hash_token(string $token): string {
    return hash('sha256', $token);
}

function cotacoes_convites_generate_token(int $len = 48): string {
    return bin2hex(random_bytes(max(16, (int)($len / 2))));
}

function cotacoes_convites_list(PDO $db, array $user, ?int $cotacaoId, ?int $requisicaoId): array {
    $info = cotacoes_convites_table_info($db);
    if (!$info['exists']) {
        throw new RuntimeException('Tabela de convites ausente');
    }
    if ($cotacaoId) {
        $stCot = $db->prepare('SELECT id, requisicao_id FROM cotacoes WHERE id=?');
        $stCot->execute([$cotacaoId]);
        $cotRow = $stCot->fetch(PDO::FETCH_ASSOC);
        if (!$cotRow) {
            throw new RuntimeException('Cotação não encontrada');
        }
        $requisicaoId = (int)($cotRow['requisicao_id'] ?? 0);
    }
    if (!$requisicaoId) {
        throw new InvalidArgumentException('Identificador inválido');
    }
    if (($user['tipo'] ?? null) === 'cliente') {
        $chk = $db->prepare('SELECT 1 FROM requisicoes WHERE id=? AND cliente_id=?');
        $chk->execute([$requisicaoId, (int)($user['cliente_id'] ?? 0)]);
        if (!$chk->fetch()) {
            throw new RuntimeException('Não encontrado');
        }
    }
    $selectExtras = $info['has_cotacao_column'] ? ', cc.cotacao_id' : '';
    if ($info['has_cotacao_column'] && $cotacaoId) {
        $stmt = $db->prepare(
            'SELECT cc.id, cc.fornecedor_id, cc.status, cc.expira_em, cc.enviado_em, cc.respondido_em' . $selectExtras . ',
                    COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome,
                    f.cnpj AS fornecedor_cnpj
             FROM cotacao_convites cc
             LEFT JOIN fornecedores f ON f.id = cc.fornecedor_id
             WHERE cc.cotacao_id=?
             ORDER BY cc.id ASC'
        );
        $stmt->execute([$cotacaoId]);
    } else {
        $stmt = $db->prepare(
            'SELECT cc.id, cc.fornecedor_id, cc.status, cc.expira_em, cc.enviado_em, cc.respondido_em' . $selectExtras . ',
                    COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome,
                    f.cnpj AS fornecedor_cnpj
             FROM cotacao_convites cc
             LEFT JOIN fornecedores f ON f.id = cc.fornecedor_id
             WHERE cc.requisicao_id=?
             ORDER BY cc.id ASC'
        );
        $stmt->execute([$requisicaoId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cotacoes_convites_create(PDO $db, array $payload, array $user): array {
    if (($user['tipo'] ?? '') === 'cliente') {
        throw new RuntimeException('Clientes não podem gerar convites.');
    }
    $info = cotacoes_convites_table_info($db);
    if (!$info['exists']) {
        throw new RuntimeException('Tabela de convites ausente');
    }
    try {
        rate_limit_enforce($db, 'api.cotacoes_convites:post', 120, 300, true);
    } catch (Throwable $e) {
        // prossegue se rate limit indisponível
    }
    $cotacaoId = (int)($payload['cotacao_id'] ?? 0);
    $requisicaoIdPayload = (int)($payload['requisicao_id'] ?? 0);
    $fornecedores = $payload['fornecedores'] ?? [];
    $diasValidade = max(1, (int)($payload['dias_validade'] ?? 5));
    $includeRaw = !empty($payload['include_raw']);
    if (!$cotacaoId || !is_array($fornecedores) || !count($fornecedores)) {
        throw new InvalidArgumentException('cotacao_id e fornecedores obrigatórios');
    }
    $stCot = $db->prepare('SELECT id, requisicao_id FROM cotacoes WHERE id=?');
    $stCot->execute([$cotacaoId]);
    $cotRow = $stCot->fetch(PDO::FETCH_ASSOC);
    if (!$cotRow) {
        throw new RuntimeException('Cotação não encontrada');
    }
    $requisicaoId = (int)($cotRow['requisicao_id'] ?? 0);
    if (!$requisicaoId && $requisicaoIdPayload) {
        $requisicaoId = $requisicaoIdPayload;
    }
    if (!$requisicaoId) {
        throw new RuntimeException('Requisição vinculada inválida');
    }
    $chkReq = $db->prepare('SELECT id, cliente_id FROM requisicoes WHERE id=?');
    $chkReq->execute([$requisicaoId]);
    $reqRow = $chkReq->fetch(PDO::FETCH_ASSOC);
    if (!$reqRow) {
        throw new RuntimeException('Requisição não encontrada');
    }
    $expiraEm = (new DateTimeImmutable('+' . $diasValidade . ' days'))->format('Y-m-d H:i:s');
    $created = [];
    $skipped = [];
    $errors = [];
    $createdCount = 0;
    foreach ($fornecedores as $f) {
        $fornecedorId = (int)($f['fornecedor_id'] ?? 0);
        if (!$fornecedorId) {
            continue;
        }
        $tokenRaw = cotacoes_convites_generate_token();
        $tokenHash = cotacoes_convites_hash_token($tokenRaw);
        $conviteId = null;
        $reemitido = false;
        if ($info['has_cotacao_column']) {
            $st = $db->prepare('SELECT id, status FROM cotacao_convites WHERE cotacao_id=? AND fornecedor_id=?');
            $st->execute([$cotacaoId, $fornecedorId]);
        } else {
            $st = $db->prepare('SELECT id, status FROM cotacao_convites WHERE requisicao_id=? AND fornecedor_id=?');
            $st->execute([$requisicaoId, $fornecedorId]);
        }
        $existing = $st->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if (($existing['status'] ?? '') !== 'cancelado') {
                $skipped[] = ['fornecedor_id' => $fornecedorId, 'motivo' => 'já existe', 'status' => $existing['status']];
                continue;
            }
            try {
                if ($info['has_cotacao_column']) {
                    $upd = $db->prepare("UPDATE cotacao_convites SET cotacao_id=?, requisicao_id=?, token_hash=?, expira_em=?, status='enviado', enviado_em=NULL WHERE id=?");
                    $upd->execute([$cotacaoId, $requisicaoId, $tokenHash, $expiraEm, (int)$existing['id']]);
                } else {
                    $upd = $db->prepare("UPDATE cotacao_convites SET token_hash=?, expira_em=?, status='enviado', enviado_em=NULL WHERE id=?");
                    $upd->execute([$tokenHash, $expiraEm, (int)$existing['id']]);
                }
                $conviteId = (int)$existing['id'];
                $reemitido = true;
            } catch (Throwable $e) {
                $errors[] = ['fornecedor_id' => $fornecedorId, 'erro' => $e->getMessage()];
                continue;
            }
        } else {
            try {
                if ($info['has_cotacao_column']) {
                    $ins = $db->prepare('INSERT INTO cotacao_convites (cotacao_id, requisicao_id, fornecedor_id, token_hash, expira_em) VALUES (?,?,?,?,?)');
                    $ins->execute([$cotacaoId, $requisicaoId, $fornecedorId, $tokenHash, $expiraEm]);
                } else {
                    $ins = $db->prepare('INSERT INTO cotacao_convites (requisicao_id, fornecedor_id, token_hash, expira_em) VALUES (?,?,?,?)');
                    $ins->execute([$requisicaoId, $fornecedorId, $tokenHash, $expiraEm]);
                }
                $conviteId = (int)$db->lastInsertId();
            } catch (Throwable $e) {
                $errors[] = ['fornecedor_id' => $fornecedorId, 'erro' => $e->getMessage()];
                continue;
            }
        }
        if (!$conviteId) {
            continue;
        }
        $createdCount++;
        $email = trim($f['email'] ?? '');
        if (!$email) {
            $stE = $db->prepare('SELECT email FROM fornecedores WHERE id=?');
            $stE->execute([$fornecedorId]);
            $email = (string)$stE->fetchColumn();
        }
        $canExposeToken = (($user['tipo'] ?? '') !== 'cliente');
        $shouldExposeToken = $includeRaw;
        if ($email) {
            unset($GLOBALS['EMAIL_LAST_ERROR']);
            try {
                $sent = email_send_cotacao_convite(
                    $email,
                    $requisicaoId,
                    $tokenRaw,
                    false,
                    [
                        'convite_id' => $conviteId,
                        'fornecedor_id' => $fornecedorId,
                        'expira_em' => $expiraEm,
                        'cotacao_id' => $cotacaoId
                    ]
                );
                if ($sent === false || (is_array($sent) && empty($sent))) {
                    $shouldExposeToken = true;
                    $errors[] = [
                        'fornecedor_id' => $fornecedorId,
                        'erro' => ($GLOBALS['EMAIL_LAST_ERROR'] ?? 'Falha ao enviar email'),
                        'token_raw' => $canExposeToken ? $tokenRaw : null,
                        'tipo' => 'email'
                    ];
                }
            } catch (Throwable $e) {
                $shouldExposeToken = true;
                $errors[] = [
                    'fornecedor_id' => $fornecedorId,
                    'erro' => 'Falha ao enviar email: ' . $e->getMessage(),
                    'token_raw' => $canExposeToken ? $tokenRaw : null,
                    'tipo' => 'email'
                ];
            }
        } else {
            $shouldExposeToken = true;
            $errors[] = [
                'fornecedor_id' => $fornecedorId,
                'erro' => 'Fornecedor sem e-mail cadastrado',
                'token_raw' => $canExposeToken ? $tokenRaw : null,
                'tipo' => 'sem_email'
            ];
        }
        log_requisicao_event(
            $db,
            $requisicaoId,
            $reemitido ? 'cotacao_convite_reemitido' : 'cotacao_convite_enviado',
            $reemitido ? 'Convite de cotação reemitido' : 'Convite de cotação enviado',
            ['fornecedor_id' => $fornecedorId, 'cotacao_id' => $cotacaoId],
            ['fornecedor_id' => $fornecedorId, 'convite_id' => $conviteId, 'cotacao_id' => $cotacaoId]
        );
        $payloadConvite = [
            'convite_id' => $conviteId,
            'fornecedor_id' => $fornecedorId,
            'expira_em' => $expiraEm,
            'cotacao_id' => $cotacaoId,
            'requisicao_id' => $requisicaoId
        ];
        if ($reemitido) {
            $payloadConvite['reemitido'] = true;
        }
        if ($shouldExposeToken && $canExposeToken) {
            $payloadConvite['token_raw'] = $tokenRaw;
        }
        $created[] = $payloadConvite;
    }
    if ($includeRaw && (($user['tipo'] ?? '') !== 'cliente') && $createdCount > 0) {
        log_requisicao_event($db, $requisicaoId, 'cotacao_convite_tokens_expostos', 'Tokens de convites retornados na API', null, ['qtd' => $createdCount, 'cotacao_id' => $cotacaoId]);
    }
    return ['success' => true, 'criados' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

function cotacoes_convites_cancel(PDO $db, array $payload, array $user): array {
    if (($user['tipo'] ?? '') === 'cliente') {
        throw new RuntimeException('Clientes não podem cancelar convites.');
    }
    $info = cotacoes_convites_table_info($db);
    if (!$info['exists']) {
        throw new RuntimeException('Tabela de convites ausente');
    }
    try {
        rate_limit_enforce($db, 'api.cotacoes_convites:delete', 60, 300, true);
    } catch (Throwable $e) {}
    $id = (int)($payload['id'] ?? 0);
    if (!$id) {
        throw new InvalidArgumentException('ID obrigatório');
    }
    $select = $info['has_cotacao_column'] ? 'id, requisicao_id, cotacao_id, status' : 'id, requisicao_id, status';
    $st = $db->prepare("SELECT $select FROM cotacao_convites WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Convite não encontrado');
    }
    if ($row['status'] === 'cancelado') {
        return ['success' => true, 'status' => 'cancelado'];
    }
    $upd = $db->prepare("UPDATE cotacao_convites SET status='cancelado' WHERE id=? AND status='enviado'");
    $upd->execute([$id]);
    $logMeta = ['id' => $id, 'status' => 'cancelado'];
    if ($info['has_cotacao_column'] && isset($row['cotacao_id'])) {
        $logMeta['cotacao_id'] = (int)$row['cotacao_id'];
    }
    log_requisicao_event(
        $db,
        (int)$row['requisicao_id'],
        'cotacao_convite_cancelado',
        'Convite de cotação cancelado',
        ['id' => $id, 'cotacao_id' => $logMeta['cotacao_id'] ?? null],
        $logMeta
    );
    return ['success' => true, 'status' => 'cancelado'];
}