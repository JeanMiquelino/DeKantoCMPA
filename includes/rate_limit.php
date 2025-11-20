<?php
require_once __DIR__ . '/db.php';

/**
 * Enforce a simple DB-backed rate limit per IP and route over a fixed window.
 * - Creates a window bucket aligned to windowSeconds (e.g., 5 min = 300s).
 * - Uses INSERT ... ON DUPLICATE KEY UPDATE to increment.
 * - Exits with HTTP 429 if over the limit.
 */
function rate_limit_enforce(PDO $db, string $rota, int $maxHits, int $windowSeconds, bool $outputJson = false): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if ($windowSeconds <= 0) { $windowSeconds = 300; }
    $startTs = (int)(time() - (time() % $windowSeconds));
    $janelaInicio = date('Y-m-d H:i:s', $startTs);

    // Verifica se a tabela existe; se não existir, não bloqueia (fail-open)
    static $checked = false; static $hasTable = false;
    if (!$checked) {
        try { $hasTable = (bool)$db->query("SHOW TABLES LIKE 'rate_limit_hits'")->fetch(); } catch (Throwable $e) { $hasTable = false; }
        $checked = true;
    }
    if (!$hasTable) return;

    try {
        $ins = $db->prepare('INSERT INTO rate_limit_hits (rota, ip, janela_inicio, contagem) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE contagem = contagem + 1, atualizado_em = CURRENT_TIMESTAMP');
        $ins->execute([$rota, $ip, $janelaInicio]);
        $sel = $db->prepare('SELECT contagem FROM rate_limit_hits WHERE rota = ? AND ip = ? AND janela_inicio = ?');
        $sel->execute([$rota, $ip, $janelaInicio]);
        $count = (int)($sel->fetchColumn() ?: 0);
        if ($count > $maxHits) {
            http_response_code(429);
            // Sinaliza quando o cliente deve tentar novamente
            header('Retry-After: ' . $windowSeconds);
            if ($outputJson) {
                header('Content-Type: application/json');
                echo json_encode(['erro' => 'Too Many Requests', 'retry_after' => $windowSeconds]);
            } else {
                echo 'Too Many Requests';
            }
            exit;
        }
    } catch (Throwable $e) {
        // Em caso de erro de DB, não bloquear
        return;
    }
}
