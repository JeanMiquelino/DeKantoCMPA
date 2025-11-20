<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/branding.php';

$cor = $branding['primary_color'] ?? '#0d6efd';
$logo = $branding['logo'] ? $branding['logo'] : null;
$app_name = $branding['app_name'] ?? 'Dekanto';

// Normalização do caminho do logo (relativo à pasta pages/)
$logo_src = null;
if ($logo) {
    $logo_src = $logo;
    if (!preg_match('#^https?://#', $logo_src)) { // não é URL absoluta
        $logo_src = preg_replace('#^\./#','', $logo_src);
        if (strpos($logo_src, '../') === 0) {
            // já relativo, mantem
        } elseif (strpos($logo_src, '/') === 0) {
            $logo_src = '..' . $logo_src; // raiz
        } else {
            $logo_src = '../' . $logo_src; // caminho simples
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    
    $db = get_db_connection();
    $stmt = $db->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_tipo'] = $usuario['tipo'];
        // Redirecionar fornecedores diretamente para o portal do fornecedor
        $destino = 'index.php';
        if (($usuario['tipo'] ?? '') === 'fornecedor') {
            $destino = 'fornecedor/index.php';
        }
        header('Location: ' . $destino);
        exit;
    } else {
        $erro = 'E-mail ou senha incorretos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo htmlspecialchars($app_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <?php if ($logo_src): ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($logo_src); ?>">
    <?php endif; ?>
    <style>
        /* Ajustes específicos da tela de login alinhados ao design system */
        body { min-height: 100vh; display:flex; align-items:center; }
        .login-wrapper { width:100%; }
        .login-logo-circle { width:86px; height:86px; border:1px solid var(--matrix-border); border-radius:50%; display:flex; align-items:center; justify-content:center; background:rgba(128,114,255,.08); box-shadow:0 0 0 4px rgba(128,114,255,.08); margin:0 auto 1.25rem; position:relative; }
        .login-logo-circle:after { content:''; position:absolute; inset:0; border-radius:50%; background:radial-gradient(circle at 50% 35%, rgba(128,114,255,.35), transparent 65%); opacity:.4; pointer-events:none; }
        .login-title { font-weight:700; letter-spacing:.5px; text-align:center; margin-bottom:1.5rem; color:var(--matrix-primary-light); }
        .small-hint { color:var(--matrix-text-secondary); font-size:.7rem; letter-spacing:.4px; }
        .btn-matrix-primary { width:100%; }
        .toggle-pass-btn { cursor:pointer; color:var(--matrix-text-secondary); transition:color .2s; }
        .toggle-pass-btn:hover { color:var(--matrix-primary-light); }
        .fade-in { animation: fadeIn .7s ease; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(25px);} to { opacity:1; transform:translateY(0);} }
    .input-group-text.toggle-pass-btn { background: #B5A886; color:#1b0f08; }
    </style>
</head>
<body>
<div class="login-wrapper container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-7 col-lg-5 col-xl-4">
            <div class="card-matrix p-4 p-md-5 fade-in">
                <div class="text-center">
                    <?php if ($logo_src): ?>
                        <div class="login-logo-circle overflow-hidden">
                            <img src="<?php echo htmlspecialchars($logo_src); ?>" alt="Logo" class="img-fluid" style="max-height:70%; max-width:70%; object-fit:contain;">
                        </div>
                    <?php else: ?>
                        <div class="login-logo-circle">
                            <span class="fw-bold" style="font-size:1.35rem; color:#fff;">&nbsp;<?php echo strtoupper(substr($app_name,0,2)); ?>&nbsp;</span>
                        </div>
                    <?php endif; ?>
                </div>
                <h4 class="login-title"><i class="bi bi-shield-lock me-2"></i>Acesso ao Sistema</h4>
                <?php if (isset($erro)): ?>
                    <div class="alert alert-danger py-2 mb-4" style="font-size:.8rem;">
                        <i class="bi bi-exclamation-triangle me-1"></i><?php echo $erro; ?>
                    </div>
                <?php endif; ?>
                <form method="post" autocomplete="off" novalidate>
                    <div class="mb-3">
                        <label for="email" class="nx-label">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="usuario@empresa.com" required autofocus>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label for="senha" class="nx-label mb-1">Senha</label>
                        <div class="input-group" id="passwordGroup">
                            <span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="senha" name="senha" placeholder="Senha" required>
                            <span class="input-group-text toggle-pass-btn" id="togglePass" role="button" aria-label="Mostrar senha"><i class="bi bi-eye"></i></span>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-1 mb-3">
                        <a href="recuperar_senha.php" class="btn btn-sm btn-outline-light" style="font-size:.65rem;">Esqueci a senha</a>
                    </div>
                    <div class="d-grid mt-2 mb-2">
                        <button type="submit" class="btn btn-matrix-primary">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                        </button>
                    </div>
                </form>
            </div>
            <p class="text-center mt-3 small-hint" style="font-size:.6rem;">© <?php echo date('Y'); ?> <?php echo htmlspecialchars($app_name); ?>. Segurança e performance.</p>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Foco inicial e toggle de senha interno
(function() {
    const email = document.getElementById('email');
    if (email) email.focus();
    const toggle = document.getElementById('togglePass');
    const pass = document.getElementById('senha');
    if (toggle && pass) {
        toggle.addEventListener('click', () => {
            const is = pass.getAttribute('type') === 'password';
            pass.setAttribute('type', is ? 'text' : 'password');
            toggle.innerHTML = is ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
            toggle.setAttribute('aria-label', is ? 'Ocultar senha' : 'Mostrar senha');
        });
    }
})();
</script>
</body>
</html>