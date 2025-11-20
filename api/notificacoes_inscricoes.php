<?php
// CRUD simples de inscrições de notificações do usuário logado
// GET    /api/notificacoes_inscricoes.php            -> lista
// POST   /api/notificacoes_inscricoes.php JSON       -> cria {requisicao_id?, tipo_evento?, canal(email|sse)?, email?}
// DELETE /api/notificacoes_inscricoes.php?id=123     -> remove

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/timeline.php'; // para normalize_event_type

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Nao autenticado']); exit; }
$db = get_db_connection();

// Verificar tabelas
try {
    $has = $db->query("SHOW TABLES LIKE 'notificacoes_inscricoes'")->fetch();
    if(!$has){ http_response_code(400); echo json_encode(['erro'=>'Tabela notificacoes_inscricoes inexistente']); exit; }
} catch(Throwable $e){ http_response_code(500); echo json_encode(['erro'=>'Falha ao verificar tabelas']); exit; }

$method = $_SERVER['REQUEST_METHOD'];

switch($method){
    case 'GET':
        // rate limit leve de leitura
        rate_limit_enforce($db, 'api/notificacoes_inscricoes:get:uid:'.(int)$u['id'], 120, 300, true);
        $st = $db->prepare('SELECT id, requisicao_id, tipo_evento, canal, email, ativo, criado_em FROM notificacoes_inscricoes WHERE usuario_id=? ORDER BY id DESC');
        $st->execute([$u['id']]);
        echo json_encode(['success'=>true,'data'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
        break;
    case 'POST':
        rate_limit_enforce($db, 'api/notificacoes_inscricoes:post:uid:'.(int)$u['id'], 60, 300, true);
        $data = json_decode(file_get_contents('php://input'), true);
        if(!$data){ http_response_code(400); echo json_encode(['success'=>false,'erro'=>'JSON inválido']); break; }
        $reqId = isset($data['requisicao_id']) && $data['requisicao_id']!=='' ? (int)$data['requisicao_id'] : null;
        $tipoIn = isset($data['tipo_evento']) && $data['tipo_evento']!=='' ? substr($data['tipo_evento'],0,60) : null;
        $tipo  = $tipoIn? normalize_event_type($tipoIn) : null;
        $canal = in_array($data['canal'] ?? 'email',['email','sse'],true)? $data['canal'] : 'email';
        $email = $data['email'] ?? null; if($email!==null){ $email = substr(trim($email),0,190); }
        // Evitar duplicata simples
        $stc = $db->prepare('SELECT id FROM notificacoes_inscricoes WHERE usuario_id=? AND IFNULL(requisicao_id,0)<=>IFNULL(?,0) AND IFNULL(tipo_evento,"")<=>IFNULL(?,"") AND canal=?');
        $stc->execute([$u['id'],$reqId,$tipo,$canal]);
        if($stc->fetch()){ echo json_encode(['success'=>true,'duplicado'=>true]); break; }
        $st = $db->prepare('INSERT INTO notificacoes_inscricoes (usuario_id, email, requisicao_id, tipo_evento, canal) VALUES (?,?,?,?,?)');
        $ok = $st->execute([$u['id'],$email,$reqId,$tipo,$canal]);
        echo json_encode(['success'=>$ok,'id'=>$db->lastInsertId()]);
        break;
    case 'DELETE':
        rate_limit_enforce($db, 'api/notificacoes_inscricoes:delete:uid:'.(int)$u['id'], 60, 300, true);
        $id = (int)($_GET['id'] ?? 0);
        if($id<=0){ parse_str(file_get_contents('php://input'), $form); $id = (int)($form['id'] ?? 0); }
        if($id<=0){ echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
        $st = $db->prepare('DELETE FROM notificacoes_inscricoes WHERE id=? AND usuario_id=?');
        $ok = $st->execute([$id,$u['id']]);
        echo json_encode(['success'=>$ok]);
        break;
    default:
        http_response_code(405); echo json_encode(['erro'=>'Metodo nao permitido']);
}
