<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/branding.php';
$db = get_db_connection();
$token = $_GET['token'] ?? '';
$ok=false;$msg='';
if($token!==''){
    $st=$db->prepare('SELECT id,confirmado_em FROM usuarios WHERE confirm_token=?');
    $st->execute([$token]);
    if($u=$st->fetch(PDO::FETCH_ASSOC)){
        if($u['confirmado_em']){ $msg='Conta já confirmada.'; $ok=true; }
        else {
            $db->prepare('UPDATE usuarios SET confirmado_em=NOW(), confirm_token=NULL WHERE id=?')->execute([$u['id']]);
            $ok=true; $msg='Email confirmado com sucesso! Você já pode acessar o sistema.';
            // Alerta admin (opcional) - envio imediato
            try { email_send_alerta_admin('Usuário confirmou email', 'Usuário ID '.$u['id'].' confirmou o email.', null, false); } catch (Throwable $e) { }
        }
    } else { $msg='Token inválido ou expirado.'; }
} else { $msg='Token ausente.'; }
$app_name = $branding['app_name'] ?? 'Aplicação';
?><!doctype html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Confirmação - <?=$app_name?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="container py-5"><div class="card-matrix p-4 mx-auto" style="max-width:520px;">
<h3 class="mb-3">Confirmação de Email</h3>
<div class="alert <?=$ok?'alert-success':'alert-danger'?>"><?=htmlspecialchars($msg)?></div>
<a href="login.php" class="btn btn-matrix-primary">Ir para Login</a>
</div></body></html>
