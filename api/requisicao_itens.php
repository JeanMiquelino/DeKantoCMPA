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
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

$usuario = auth_usuario();
$usuarioTipo = $usuario['tipo'] ?? null;
$usuarioClienteId = $usuario['cliente_id'] ?? null;

function requisicaoPertenceCliente(PDO $db, $requisicaoId, $clienteId){
    try{
        $st = $db->prepare('SELECT cliente_id FROM requisicoes WHERE id=?');
        $st->execute([$requisicaoId]);
        $cid = $st->fetchColumn();
        if($cid===false) return false;
        return (int)$cid === (int)$clienteId;
    }catch(Throwable $e){ return false; }
}

// Retorna array [cliente_id, status] da requisição (ou null se não existe)
function requisicaoInfo(PDO $db, $requisicaoId){
    try{
        $st = $db->prepare('SELECT cliente_id, status FROM requisicoes WHERE id=?');
        $st->execute([$requisicaoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }catch(Throwable $e){ return null; }
}

// Cliente pode editar apenas se for dono e status permitido
function clientePodeEditar(PDO $db, $requisicaoId, $clienteId){
    $info = requisicaoInfo($db, $requisicaoId);
    if(!$info) return false;
    if((int)$info['cliente_id'] !== (int)$clienteId) return false;
    $status = (string)($info['status'] ?? '');
    // Ajuste: bloquear após aprovação administrativa (status 'aberta').
    // Permitido somente enquanto estiver 'pendente_aprovacao'.
    return in_array($status, ['pendente_aprovacao'], true);
}

switch ($method) {
    case 'GET':
        $requisicao_id = $_GET['requisicao_id'] ?? null;
        if (!$requisicao_id) { echo json_encode([]); exit; }
        if($usuarioTipo === 'cliente'){
            if(!requisicaoPertenceCliente($db, $requisicao_id, $usuarioClienteId)){
                http_response_code(404);
                echo json_encode([]);
                exit;
            }
        }
        $stmt = $db->prepare('SELECT ri.*, p.nome, p.unidade FROM requisicao_itens ri JOIN produtos p ON ri.produto_id = p.id WHERE requisicao_id = ? ORDER BY ri.id DESC');
        $stmt->execute([$requisicao_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $reqId = $data['requisicao_id'] ?? null;
        if(!$reqId){ http_response_code(422); echo json_encode(['erro'=>'requisicao_id é obrigatório']); break; }
        if($usuarioTipo === 'cliente'){
            // Bloqueia edição após aprovação (ou outros status não permitidos)
            if(!clientePodeEditar($db, $reqId, $usuarioClienteId)){
                http_response_code(403); echo json_encode(['erro'=>'Requisição bloqueada para edição pelo cliente']); break;
            }
        }
        $stmt = $db->prepare('INSERT INTO requisicao_itens (requisicao_id, produto_id, quantidade) VALUES (?, ?, ?)');
        $ok = $stmt->execute([
            $reqId, $data['produto_id'] ?? null, $data['quantidade'] ?? null
        ]);
        $newId = (int)$db->lastInsertId();
        if($ok){
            log_requisicao_event($db, (int)$reqId, 'item_adicionado', 'Item adicionado', null, ['item_id'=>$newId,'produto_id'=>$data['produto_id']??null,'quantidade'=>$data['quantidade']??null]);
        }
        echo json_encode(['success' => $ok, 'id' => $newId]);
        break;
    case 'PUT':
        parse_str(file_get_contents('php://input'), $data);
        $itemId = $data['id'] ?? null;
        if(!$itemId){ http_response_code(422); echo json_encode(['erro'=>'id é obrigatório']); break; }
        // Descobrir a requisicao do item
        $st = $db->prepare('SELECT requisicao_id FROM requisicao_itens WHERE id=?');
        $st->execute([$itemId]);
        $reqId = (int)$st->fetchColumn();
        if(!$reqId){ http_response_code(404); echo json_encode(['erro'=>'Item não encontrado']); break; }
        if($usuarioTipo === 'cliente'){
            if(!clientePodeEditar($db, $reqId, $usuarioClienteId)){
                http_response_code(403); echo json_encode(['erro'=>'Requisição bloqueada para edição pelo cliente']); break;
            }
        }
        $stmt = $db->prepare('UPDATE requisicao_itens SET produto_id=?, quantidade=? WHERE id=?');
        $ok = $stmt->execute([
            $data['produto_id'] ?? null, $data['quantidade'] ?? null, $itemId
        ]);
        if($ok){
            log_requisicao_event($db, (int)$reqId, 'item_atualizado', 'Item atualizado', null, ['item_id'=>$itemId,'produto_id'=>$data['produto_id']??null,'quantidade'=>$data['quantidade']??null]);
        }
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        parse_str(file_get_contents('php://input'), $data);
        $itemId = $data['id'] ?? null;
        if(!$itemId){ http_response_code(422); echo json_encode(['erro'=>'id é obrigatório']); break; }
        // Descobrir a requisicao do item
        $st = $db->prepare('SELECT requisicao_id FROM requisicao_itens WHERE id=?');
        $st->execute([$itemId]);
        $reqId = (int)$st->fetchColumn();
        if(!$reqId){ http_response_code(404); echo json_encode(['erro'=>'Item não encontrado']); break; }
        if($usuarioTipo === 'cliente'){
            if(!clientePodeEditar($db, $reqId, $usuarioClienteId)){
                http_response_code(403); echo json_encode(['erro'=>'Requisição bloqueada para edição pelo cliente']); break;
            }
        }
        $stmt = $db->prepare('DELETE FROM requisicao_itens WHERE id=?');
        $ok = $stmt->execute([$itemId]);
        if($ok){
            log_requisicao_event($db, (int)$reqId, 'item_removido', 'Item removido', null, ['item_id'=>$itemId]);
        }
        echo json_encode(['success' => $ok]);
        break;
    default:
        http_response_code(405);
        echo json_encode(['erro' => 'Método não permitido']);
}