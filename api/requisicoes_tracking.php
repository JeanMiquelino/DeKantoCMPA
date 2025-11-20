<?php
// Gera ou retorna token de tracking público para requisicao
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/timeline.php';
$u = auth_requer_login();
$db = get_db_connection();
$input = json_decode(file_get_contents('php://input'), true);
$id = (int)($input['id'] ?? 0);
if($id<=0){ echo json_encode(['success'=>false,'erro'=>'ID inválido']); exit; }

// Verificar se colunas existem; se não existir tentar criar (opcional)
try {
    $cols = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'tracking_token'")->fetch();
    if(!$cols){
        // cria colunas
        $db->exec("ALTER TABLE requisicoes ADD COLUMN tracking_token VARCHAR(64) NULL, ADD COLUMN tracking_token_expira DATETIME NULL");
    }
} catch(Throwable $e){ echo json_encode(['success'=>false,'erro'=>'Sem coluna tracking_token']); exit; }

// Ver se já existe e ainda válido (< 30 dias para expirar renovamos)
$st = $db->prepare('SELECT tracking_token, tracking_token_expira FROM requisicoes WHERE id=?');
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if(!$row){ echo json_encode(['success'=>false,'erro'=>'Requisição não encontrada']); exit; }
$token = $row['tracking_token'];
$expira = $row['tracking_token_expira'];
$needNew = true;
if($token && $expira && strtotime($expira) > time()+86400){ $needNew=false; }
if($needNew){
    $token = bin2hex(random_bytes(20));
    $expira = date('Y-m-d H:i:s', strtotime('+90 days'));
    $up = $db->prepare('UPDATE requisicoes SET tracking_token=?, tracking_token_expira=? WHERE id=?');
    $up->execute([$token,$expira,$id]);
    log_requisicao_event($db, $id, 'tracking_token_gerado', 'Token público de acompanhamento gerado/renovado', null, ['token_prefix'=>substr($token,0,6),'expira'=>$expira]);
}

echo json_encode(['success'=>true,'token'=>$token,'expira'=>$expira]);
