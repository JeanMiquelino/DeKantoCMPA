<?php
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/branding.php';
require_once __DIR__.'/../includes/email.php';
$db = get_db_connection();
$msg=''; $ok=false; $debugInfo=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
    $email = trim($_POST['email'] ?? '');
    if($email===''){ $msg='Informe seu email.'; }
    else {
        try {
            // Envio imediato (fila desativada)
            $r = email_send_recuperacao_senha($email, false);
            // Por segurança não revelar se o email existe. Sempre retornar mensagem de sucesso ao usuário.
            $ok = true;
            // Logar internamente quando houver falha real para diagnóstico (não exibimos ao usuário em prod)
            if ($r === false) {
                $err = $GLOBALS['EMAIL_LAST_ERROR'] ?? 'Falha desconhecida';
                error_log('[recuperar_senha] email_send_recuperacao_senha falhou para '.$email.' - '.$err);
                $debugInfo = $err; // mantemos em variável para QA local, mas não será exibida em produção
            }
            $msg = 'Se o email existir, enviaremos instruções em instantes.';
        } catch(Throwable $e){
            // Em caso de exceção, logamos e mostramos uma mensagem genérica
            error_log('[recuperar_senha] excecao: '.$e->getMessage());
            $msg='Falha ao solicitar. Tente mais tarde.'; 
            $debugInfo = $e->getMessage();
        }
    }
}
$app_name = $branding['app_name'] ?? 'Aplicação';
$favicon_url = $branding['logo_url'] ?? '../assets/images/logo.png';
?><!doctype html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Recuperar Senha - <?=$app_name?></title><link rel="icon" type="image/png" href="<?=htmlspecialchars($favicon_url, ENT_QUOTES)?>"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"><style>.alert-force-light{background:#f4fff6 !important;border:1px solid #cbe7d2 !important;color:#1f5132 !important;box-shadow:0 4px 14px rgba(0,0,0,0.08);} .alert-force-light .text-warning{color:#b97820 !important;}</style></head><body class="container py-5"><div class="card-matrix p-4 mx-auto" style="max-width:520px;">
<h3 class="mb-3">Recuperar Senha</h3>
<?php if($msg): ?>
    <div class="alert alert-success py-2 alert-force-light"><?=htmlspecialchars($msg)?>
        <?php if(!empty($debugInfo) && defined('QA_ENV') && QA_ENV): ?>
            <div class="mt-1 small text-warning">Debug: <?=htmlspecialchars($debugInfo)?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<form method="post" autocomplete="off">
<div class="mb-3"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" required></div>
<button class="btn btn-matrix-primary w-100">Enviar Instruções</button>
</form>
<a href="login.php" class="btn btn-outline-light btn-sm mt-3 small">Voltar ao login</a>
</div></body></html>
