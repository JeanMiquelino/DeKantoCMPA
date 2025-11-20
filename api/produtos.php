<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

function resolve_http_method_produtos(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        return $method;
    }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
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

function resolve_effective_method_produtos(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $method = resolve_http_method_produtos();
    if ($method === 'POST' && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
        $actionOverride = $_POST['_action'] ?? $_REQUEST['_action'] ?? null;
        if ($actionOverride) {
            $map = [
                'update' => 'PUT',
                'editar' => 'PUT',
                'delete' => 'DELETE',
                'excluir' => 'DELETE'
            ];
            $key = strtolower(trim((string)$actionOverride));
            if (isset($map[$key])) {
                $method = $map[$key];
            }
        }
    }
    return $cached = $method;
}

function read_request_payload_produtos(): array {
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
    if (isset($data['_method'])) unset($data['_method']);
    if (isset($data['_action'])) unset($data['_action']);
    $cached = $data;
    return $cached;
}

$db = get_db_connection();
$method = resolve_effective_method_produtos();

// Rate limiting per method
try {
    $rota = 'api/produtos.php:' . $method;
    // Read-heavy GETs: higher threshold; mutations: lower
    if ($method === 'GET') rate_limit_enforce($db, $rota, 120, 60, true);
    else rate_limit_enforce($db, $rota, 30, 60, true);
} catch (Throwable $e) { /* fail-open */ }

try {
    switch ($method) {
        case 'GET':
            // Added lightweight search for autocomplete: ?q=term (busca por nome ou ncm, mínimo 2 chars) e fetch único ?id=123
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            if ($q !== '') {
                if (mb_strlen($q) < 2) { echo json_encode([]); break; }
                $like = '%' . $q . '%';
                $stmt = $db->prepare('SELECT id, nome, ncm, unidade, preco_base FROM produtos WHERE nome LIKE ? OR ncm LIKE ? ORDER BY nome ASC LIMIT 30');
                $stmt->execute([$like, $like]);
                echo json_encode($stmt->fetchAll());
                break;
            }
            if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
                $id = (int)$_GET['id'];
                $stmt = $db->prepare('SELECT * FROM produtos WHERE id=?');
                $stmt->execute([$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if(!$row){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); }
                else echo json_encode($row);
                break;
            }
            $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
            $buscaParam = $_GET['busca'] ?? null;
            $unidadeParam = $_GET['unidade'] ?? null;
            $hasPagination = ($mode === 'ids')
                || isset($_GET['page'])
                || isset($_GET['per_page'])
                || $buscaParam !== null
                || $unidadeParam !== null;

            if ($mode === 'ids') {
                $params = [];
                $filters = produtos_build_filters($buscaParam, $unidadeParam, $params);
                $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
                $sql = 'SELECT id FROM produtos' . $whereSql . ' ORDER BY id DESC';
                $st = $db->prepare($sql);
                $st->execute($params);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                echo json_encode(['ids' => $ids, 'total' => count($ids)]);
                break;
            }

            if ($hasPagination) {
                $params = [];
                $filters = produtos_build_filters($buscaParam, $unidadeParam, $params);
                $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
                $pageNumber = max(1, (int)($_GET['page'] ?? 1));
                $perPage = min(200, max(5, (int)($_GET['per_page'] ?? 20)));
                $offset = ($pageNumber - 1) * $perPage;

                $dataSql = "SELECT id, nome, descricao, ncm, unidade, preco_base FROM produtos $whereSql ORDER BY id DESC LIMIT $perPage OFFSET $offset";
                $stData = $db->prepare($dataSql);
                $stData->execute($params);
                $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

                $countSql = 'SELECT COUNT(*) FROM produtos' . $whereSql;
                $stCount = $db->prepare($countSql);
                $stCount->execute($params);
                $total = (int)$stCount->fetchColumn();

                echo json_encode([
                    'data' => $rows,
                    'meta' => [
                        'page' => $pageNumber,
                        'per_page' => $perPage,
                        'total' => $total,
                        'total_pages' => max(1, (int)ceil($total / ($perPage ?: 1))),
                        'unidade_options' => produtos_unit_options($db)
                    ]
                ]);
                break;
            }
            // Fallback original (lista completa) - manter compatibilidade com chamadas existentes
            $stmt = $db->query('SELECT * FROM produtos ORDER BY id DESC');
            echo json_encode($stmt->fetchAll());
            break;
        case 'POST': {
            $data = read_request_payload_produtos();
            $nome = trim($data['nome'] ?? '');
            $descricao = $data['descricao'] ?? null;
            $ncm = trim($data['ncm'] ?? '');
            $unidade = trim($data['unidade'] ?? '');
            $preco = isset($data['preco_base']) ? (float)$data['preco_base'] : null;
            if ($nome === '' || $ncm === '' || $unidade === '' || $preco === null) {
                http_response_code(422);
                echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios: nome, ncm, unidade, preco_base']);
                break;
            }
            $stmt = $db->prepare('INSERT INTO produtos (nome, descricao, ncm, unidade, preco_base) VALUES (?, ?, ?, ?, ?)');
            $ok = $stmt->execute([$nome, $descricao, $ncm, $unidade, $preco]);
            echo json_encode(['success' => (bool)$ok, 'id' => $ok ? (int)$db->lastInsertId() : null]);
            break; }
        case 'PUT': {
            $data = read_request_payload_produtos();
            $id = isset($data['id']) ? (int)$data['id'] : 0;
            if ($id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'ID inválido']); break; }
            $nome = trim($data['nome'] ?? '');
            $descricao = $data['descricao'] ?? null;
            $ncm = trim($data['ncm'] ?? '');
            $unidade = trim($data['unidade'] ?? '');
            $preco = isset($data['preco_base']) ? (float)$data['preco_base'] : null;
            if ($nome === '' || $ncm === '' || $unidade === '' || $preco === null) {
                http_response_code(422);
                echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios: nome, ncm, unidade, preco_base']);
                break;
            }
            $stmt = $db->prepare('UPDATE produtos SET nome=?, descricao=?, ncm=?, unidade=?, preco_base=? WHERE id=?');
            $ok = $stmt->execute([$nome, $descricao, $ncm, $unidade, $preco, $id]);
            echo json_encode(['success' => (bool)$ok]);
            break; }
        case 'DELETE': {
            $data = read_request_payload_produtos();
            $id = isset($data['id']) ? (int)$data['id'] : 0;
            if ($id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'ID inválido']); break; }
            $stmt = $db->prepare('DELETE FROM produtos WHERE id=?');
            $ok = $stmt->execute([$id]);
            echo json_encode(['success' => (bool)$ok]);
            break; }
        default:
            http_response_code(405);
            echo json_encode(['erro' => 'Método não permitido']);
    }
} catch (PDOException $e) {
    $msg = $e->getMessage();
    $codigo = $e->getCode();
    $erroInfo = $e->errorInfo ?? [];
    $isConstraint = ($codigo === '23000')
        || (isset($erroInfo[1]) && (int)$erroInfo[1] === 1451)
        || (stripos($msg, 'foreign key') !== false)
        || (stripos($msg, 'constraint') !== false);

    if ($isConstraint) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'erro' => 'Não foi possível remover este produto porque existem registros vinculados (ex.: pedidos ou propostas). Remova os vínculos antes de tentar novamente.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'erro' => 'Não conseguimos concluir a operação nos produtos. Tente novamente em instantes.'
        ]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'erro' => 'Não conseguimos concluir a operação nos produtos. Tente novamente em instantes.'
    ]);
}

function produtos_build_filters($buscaRaw, $unidadeRaw, array &$params): array {
    $where = [];
    $busca = trim((string)($buscaRaw ?? ''));
    if ($busca !== '') {
        $buscaLower = function_exists('mb_strtolower') ? mb_strtolower($busca, 'UTF-8') : strtolower($busca);
        $like = '%' . $buscaLower . '%';
        $where[] = '(LOWER(nome) LIKE ? OR LOWER(ncm) LIKE ? OR LOWER(descricao) LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if (is_array($unidadeRaw)) {
        $validUnits = [];
        foreach ($unidadeRaw as $unit) {
            $unit = trim((string)$unit);
            if ($unit !== '') {
                $validUnits[$unit] = true;
            }
        }
        if ($validUnits) {
            $placeholders = implode(',', array_fill(0, count($validUnits), '?'));
            $where[] = 'unidade IN (' . $placeholders . ')';
            foreach (array_keys($validUnits) as $unit) {
                $params[] = $unit;
            }
        }
    } else {
        $unit = trim((string)($unidadeRaw ?? ''));
        if ($unit !== '') {
            $where[] = 'unidade = ?';
            $params[] = $unit;
        }
    }

    return $where;
}

function produtos_unit_options(PDO $db): array {
    try {
        $rows = $db->query("SELECT DISTINCT unidade FROM produtos WHERE unidade IS NOT NULL AND unidade<>'' ORDER BY unidade ASC")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $options = [];
        foreach ($rows as $unit) {
            $options[] = [
                'value' => $unit,
                'label' => $unit
            ];
        }
        return $options;
    } catch (Throwable $e) {
        return [];
    }
}