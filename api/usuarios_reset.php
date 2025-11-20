<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rate_limit.php';

function json_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$u = auth_requer_login();
if (!auth_can('usuarios.reset_senha')) {
    json_out(['error' => 'Sem acesso'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Método não suportado'], 405);
}

$db = get_db_connection();
rate_limit_enforce($db, 'api/usuarios_reset', 30, 120, true);
$id = (int)($_POST['id'] ?? 0);
$nova = trim($_POST['nova'] ?? '');

if ($id <= 0) {
    json_out(['error' => 'ID inválido'], 422);
}
if ($nova === '') {
    json_out(['error' => 'Informe a nova senha'], 422);
}

try {
    $hash = password_hash($nova, PASSWORD_DEFAULT);
    $st = $db->prepare('UPDATE usuarios SET senha=? WHERE id=?');
    $st->execute([$hash, $id]);
    auditoria_log($u['id'], 'usuario_reset_senha_manual', 'usuarios', $id, null);
    json_out(['ok' => true]);
} catch (Throwable $e) {
    json_out(['error' => 'Falha ao redefinir senha', 'detalhe' => $e->getMessage()], 500);
}
