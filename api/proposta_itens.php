<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();
$method = resolve_http_method();

function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') { return $method; }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_POST['_method'])) {
        $override = $_POST['_method'];
        unset($_POST['_method']);
    }
    if (!$override && isset($_GET['_method'])) { $override = $_GET['_method']; }
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
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return $cached = (is_array($data) ? $data : []);
    }
    if (!empty($_POST)) { return $cached = $_POST; }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $tmp = [];
        parse_str($raw, $tmp);
        if ($tmp) { return $cached = $tmp; }
    }
    return $cached = [];
}

try {
    switch ($method) {
        case 'GET':
            $proposta_id = $_GET['proposta_id'] ?? null;
            if (!$proposta_id) { echo json_encode([]); break; }
            $stmt = $db->prepare('SELECT pi.*, p.nome, p.unidade FROM proposta_itens pi JOIN produtos p ON pi.produto_id = p.id WHERE pi.proposta_id = ? ORDER BY pi.id ASC');
            $stmt->execute([$proposta_id]);
            echo json_encode($stmt->fetchAll());
            break;
        case 'POST':
            $data = read_request_payload();
            $proposta_id = $data['proposta_id'] ?? null;
            $produto_id  = $data['produto_id'] ?? null;
            $preco       = $data['preco_unitario'] ?? null;
            $quantidade  = $data['quantidade'] ?? null;
            if (!$proposta_id || !$produto_id || $preco === null || $quantidade === null) {
                http_response_code(400);
                echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios ausentes']);
                break;
            }
            $proposta_id = (int)$proposta_id;
            $produto_id = (int)$produto_id;
            $preco = (float)$preco;
            $quantidade = (float)$quantidade;
            // Impede duplicidade do mesmo produto na mesma proposta: se já existir, apenas atualiza
            $stmtSel = $db->prepare('SELECT id FROM proposta_itens WHERE proposta_id=? AND produto_id=? LIMIT 1');
            $stmtSel->execute([$proposta_id, $produto_id]);
            $existingId = (int)($stmtSel->fetchColumn() ?: 0);
            if ($existingId) {
                $stmtUpd = $db->prepare('UPDATE proposta_itens SET preco_unitario=?, quantidade=? WHERE id=?');
                $ok = $stmtUpd->execute([$preco, $quantidade, $existingId]);
                echo json_encode(['success'=>$ok,'id'=>$existingId,'action'=>'updated']);
            } else {
                $stmt = $db->prepare('INSERT INTO proposta_itens (proposta_id, produto_id, preco_unitario, quantidade) VALUES (?,?,?,?)');
                $ok = $stmt->execute([$proposta_id, $produto_id, $preco, $quantidade]);
                echo json_encode(['success'=>$ok,'id'=>$db->lastInsertId(),'action'=>'created']);
            }
            break;
        case 'PUT':
            $data = read_request_payload();
            $id = $data['id'] ?? null;
            if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
            $campos=[]; $params=[];
            if(isset($data['produto_id'])){ $campos[]='produto_id=?'; $params[]=$data['produto_id']; }
            if(isset($data['preco_unitario'])){ $campos[]='preco_unitario=?'; $params[]=$data['preco_unitario']; }
            if(isset($data['quantidade'])){ $campos[]='quantidade=?'; $params[]=$data['quantidade']; }
            if(!$campos){ echo json_encode(['success'=>false,'erro'=>'Nada para atualizar']); break; }
            $params[]=$id;
            $sql='UPDATE proposta_itens SET '.implode(',', $campos).' WHERE id=?';
            $stmt = $db->prepare($sql);
            $ok = $stmt->execute($params);
            echo json_encode(['success'=>$ok]);
            break;
        case 'DELETE':
            $data = read_request_payload();
            $id = $data['id'] ?? null; if(!$id){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
            $stmt = $db->prepare('DELETE FROM proposta_itens WHERE id=?');
            $ok = $stmt->execute([$id]);
            echo json_encode(['success'=>$ok]);
            break;
        default:
            http_response_code(405);
            echo json_encode(['erro'=>'Método não permitido']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'erro'=>$e->getMessage()]);
}