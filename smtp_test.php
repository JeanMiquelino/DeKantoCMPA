<?php
// Utilitário simples para testar o envio SMTP com as credenciais atuais.
// Uso: acesse /smtp_test.php?to=destinatario@exemplo.com ou execute via CLI com `php smtp_test.php destinatario@exemplo.com`

session_start();
require_once __DIR__ . '/includes/email.php';

date_default_timezone_set('America/Sao_Paulo');

function respond(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$destinatario = null;
if (PHP_SAPI === 'cli') {
    $destinatario = $argv[1] ?? null;
} else {
    $destinatario = $_GET['to'] ?? $_POST['to'] ?? null;
}

$destinatario = $destinatario ? trim($destinatario) : null;
if (!$destinatario || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
    respond([
        'success' => false,
        'erro' => 'Informe o destinatário via parâmetro `to` (ex.: smtp_test.php?to=email@dominio.com)`.'
    ], 400);
}

$subject = 'Teste SMTP - ' . ($_SERVER['HTTP_HOST'] ?? 'atlas');
$timestamp = date('Y-m-d H:i:s');
$body = '<p>Este é um teste automático enviado em ' . htmlspecialchars($timestamp) . '.</p>' .
        '<p>Se você recebeu este email, as credenciais SMTP estão funcionando.</p>';

try {
    $ok = email_send($destinatario, $subject, $body);
    if ($ok) {
        respond([
            'success' => true,
            'mensagem' => 'Email enviado com sucesso.',
            'destinatario' => $destinatario,
            'timestamp' => $timestamp
        ]);
    }
    $error = $GLOBALS['EMAIL_LAST_ERROR'] ?? 'Falha desconhecida';
    respond([
        'success' => false,
        'erro' => $error,
        'detalhes' => [
            'destinatario' => $destinatario,
            'timestamp' => $timestamp
        ]
    ], 500);
} catch (Throwable $e) {
    respond([
        'success' => false,
        'erro' => 'Exceção ao enviar: ' . $e->getMessage(),
        'detalhes' => [
            'destinatario' => $destinatario,
            'timestamp' => $timestamp,
            'trace' => $e->getTraceAsString()
        ]
    ], 500);
}
