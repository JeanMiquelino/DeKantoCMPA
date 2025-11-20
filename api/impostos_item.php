<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services/impostos_service.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo json_encode(['erro'=>'Método não permitido']); exit; }

// Simple rate limit per IP on query workload
try { $db = get_db_connection(); rate_limit_enforce($db, 'api/impostos_item.php:GET', 120, 60, true); } catch (Throwable $e) { /* fail-open */ }

$ncm = $_GET['ncm'] ?? '';
$valor = isset($_GET['valor']) ? (float)$_GET['valor'] : 0.0;
$origem = $_GET['origem'] ?? 'nacional';
if(!$ncm || $valor<=0){ http_response_code(400); echo json_encode(['erro'=>'Parâmetros inválidos']); exit; }
if(!in_array($origem,['nacional','importado'])) $origem='nacional';
$calc = calcula_imposto_item($ncm, $valor, $origem);
if(!$calc){
    // Cacheable 404 for 1 hour to reduce repeated misses
    http_response_code(404);
    header('Cache-Control: public, max-age=3600');
    echo json_encode(['erro'=>'NCM não encontrado']);
    exit;
}
echo json_encode($calc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
