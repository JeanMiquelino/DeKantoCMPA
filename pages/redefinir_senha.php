<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/branding.php';
$db = get_db_connection();
$token = $_GET['token'] ?? '';
$etapa = 'form'; $msg=''; $ok=false;
// Valida token já no GET para feedback rápido
if($_SERVER['REQUEST_METHOD']!=='POST' && $token){
    $st=$db->prepare('SELECT id FROM usuarios_recuperacao WHERE token=? AND usado=0 AND expira_em>NOW()');
    $st->execute([$token]);
    if(!$st->fetch()){ $msg='Token inválido ou expirado.'; $etapa='invalid'; }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    $token = $_POST['token'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $conf  = $_POST['senha_conf'] ?? '';
    if($token===''||$senha===''||$conf===''){ $msg='Campos obrigatórios.'; }
    elseif($senha!==$conf){ $msg='Confirmação não confere.'; }
    else {
        $st=$db->prepare('SELECT ur.id,ur.usuario_id FROM usuarios_recuperacao ur WHERE ur.token=? AND ur.usado=0 AND ur.expira_em>NOW()');
        $st->execute([$token]);
        if($rec=$st->fetch(PDO::FETCH_ASSOC)){
            $hash=password_hash($senha,PASSWORD_DEFAULT);
            $db->beginTransaction();
            try {
                $db->prepare('UPDATE usuarios SET senha=? WHERE id=?')->execute([$hash,$rec['usuario_id']]);
                $db->prepare('UPDATE usuarios_recuperacao SET usado=1 WHERE id=?')->execute([$rec['id']]);
                $db->commit();
                $etapa='ok'; $ok=true; $msg='Senha redefinida com sucesso.';
            } catch(Throwable $e){ $db->rollBack(); $msg='Falha ao atualizar.'; }
        } else { $msg='Token inválido ou expirado.'; }
    }
}
$app_name = $branding['app_name'] ?? 'Aplicação';
?><!doctype html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Redefinir Senha - <?=$app_name?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="container py-5"><div class="card-matrix p-4 mx-auto" style="max-width:520px;">
<h3 class="mb-3">Redefinir Senha</h3>
<?php if($etapa==='ok'): ?>
<div class="alert alert-success"><?=htmlspecialchars($msg)?></div>
<a href="login.php" class="btn btn-matrix-primary">Ir para Login</a>
<?php elseif($etapa==='invalid'): ?>
<div class="alert alert-danger py-2"><?=htmlspecialchars($msg)?></div>
<a href="recuperar_senha.php" class="btn btn-secondary">Solicitar novo link</a>
<?php else: ?>
<?php if($msg): ?><div class="alert alert-danger py-2"><?=htmlspecialchars($msg)?></div><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">
<div class="mb-3"><label class="form-label">Nova Senha</label><input type="password" name="senha" class="form-control" required></div>
<div class="mb-3"><label class="form-label">Confirmar Nova Senha</label><input type="password" name="senha_conf" class="form-control" required></div>
<button class="btn btn-matrix-primary w-100">Redefinir</button>
</form>
<?php endif; ?>
</div></body></html>
