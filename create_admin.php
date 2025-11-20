<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

function random_password(int $length = 12): string {
    $base = preg_replace('/[^A-Za-z0-9]/', '', base64_encode(random_bytes(max(8, $length))));
    if (!$base) {
        $base = bin2hex(random_bytes($length));
    }
    return substr($base, 0, $length);
}

function ensure_admin_role(PDO $db): int {
    $stmt = $db->prepare("SELECT id FROM roles WHERE nome='admin' LIMIT 1");
    $stmt->execute();
    $roleId = (int)$stmt->fetchColumn();
    if ($roleId > 0) {
        return $roleId;
    }
    $db->prepare("INSERT INTO roles (nome, descricao) VALUES ('admin', 'Administrador do sistema')")->execute();
    return (int)$db->lastInsertId();
}

$messages = [];
$errors = [];
$resultUser = null;
$passwordPlain = null;
$passwordWasGenerated = false;

$defaultEmail = 'admin@example.com';
if (defined('APP_URL')) {
    $host = parse_url(APP_URL, PHP_URL_HOST);
    if (!empty($host)) {
        $defaultEmail = 'admin@' . preg_replace('/[^A-Za-z0-9.-]/', '', $host);
    }
}

$nome = trim($_POST['nome'] ?? $_GET['nome'] ?? 'Administrador Master');
$email = trim($_POST['email'] ?? $_GET['email'] ?? $defaultEmail);
$senhaInformada = (string)($_POST['senha'] ?? $_GET['senha'] ?? '');
$force = isset($_POST['force']) ? (bool)$_POST['force'] : (isset($_GET['force']) && $_GET['force'] == '1');
$shouldRun = ($_SERVER['REQUEST_METHOD'] === 'POST') || isset($_GET['run']);

if ($shouldRun) {
    if ($nome === '') {
        $errors[] = 'Informe um nome.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail inválido.';
    }
    if (!$errors) {
        $passwordPlain = $senhaInformada !== '' ? $senhaInformada : random_password(12);
        $passwordWasGenerated = ($senhaInformada === '');
        try {
            $db = get_db_connection();
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT id FROM usuarios WHERE email=? LIMIT 1');
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
            $tipo = 'interno';

            if ($row) {
                if (!$force) {
                    throw new RuntimeException('Já existe um usuário com este e-mail. Habilite "Sobrescrever" para atualizar a senha.');
                }
                $userId = (int)$row['id'];
                $upd = $db->prepare('UPDATE usuarios SET nome=?, senha=?, tipo=?, ativo=1 WHERE id=?');
                $upd->execute([$nome, $hash, $tipo, $userId]);
                $messages[] = 'Usuário existente atualizado.';
            } else {
                $ins = $db->prepare('INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?,?,?,?,1)');
                $ins->execute([$nome, $email, $hash, $tipo]);
                $userId = (int)$db->lastInsertId();
                $messages[] = 'Novo usuário criado.';
            }

            $adminRoleId = ensure_admin_role($db);
            $db->prepare('INSERT IGNORE INTO usuario_role (usuario_id, role_id) VALUES (?, ?)')->execute([$userId, $adminRoleId]);
            $db->commit();

            $resultUser = [
                'id' => $userId,
                'nome' => $nome,
                'email' => $email
            ];
            $messages[] = 'Perfil de administrador atribuído.';
        } catch (Throwable $e) {
            if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }
            $errors[] = 'Falha ao criar usuário: ' . $e->getMessage();
        }
    }
}

$appName = defined('APP_NAME') ? APP_NAME : 'Atlas';
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <title>Criar administrador - <?php echo htmlspecialchars($appName); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 mb-3">Criar usuário administrador</h1>
                    <p class="text-muted">Este utilitário cria (ou atualiza) um usuário com permissões de administrador. Após o uso, <strong>remova este arquivo</strong> do servidor para evitar acessos indevidos.</p>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $err): ?>
                                    <li><?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($messages && !$errors): ?>
                        <div class="alert alert-success">
                            <ul class="mb-0">
                                <?php foreach ($messages as $msg): ?>
                                    <li><?php echo htmlspecialchars($msg); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($resultUser && $passwordPlain && !$errors): ?>
                        <div class="alert alert-warning">
                            <p class="mb-1">Credenciais do usuário:</p>
                            <ul class="mb-0">
                                <li><strong>Nome:</strong> <?php echo htmlspecialchars($resultUser['nome']); ?></li>
                                <li><strong>E-mail:</strong> <?php echo htmlspecialchars($resultUser['email']); ?></li>
                                <li><strong>Senha:</strong> <code><?php echo htmlspecialchars($passwordPlain); ?></code> <?php echo $passwordWasGenerated ? '(gerada automaticamente)' : ''; ?></li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="row g-3">
                        <div class="col-12">
                            <label for="nome" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="nome" name="nome" value="<?php echo htmlspecialchars($nome); ?>" required>
                        </div>
                        <div class="col-12">
                            <label for="email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        <div class="col-12">
                            <label for="senha" class="form-label">Senha (deixe em branco para gerar automaticamente)</label>
                            <input type="text" class="form-control" id="senha" name="senha" placeholder="********" autocomplete="new-password">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" id="force" name="force" <?php echo $force ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="force">
                                    Sobrescrever se o e-mail já existir
                                </label>
                            </div>
                        </div>
                        <div class="col-12 d-flex justify-content-between align-items-center">
                            <small class="text-muted">Execute apenas em ambientes seguros. Depois de criar o administrador, delete este arquivo.</small>
                            <button type="submit" class="btn btn-primary">Criar/Atualizar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
