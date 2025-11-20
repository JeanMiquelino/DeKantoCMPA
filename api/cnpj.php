<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/services/cnpj_service.php';
require_once __DIR__ . '/../includes/rate_limit.php';
header('Content-Type: application/json; charset=utf-8');
// Security headers for public endpoint
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// Permitir apenas GET
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') { http_response_code(405); header('Allow: GET'); echo json_encode(['erro'=>'Método não suportado']); exit; }

// Normalizar CNPJ (apenas dígitos)
$cnpjRaw = (string)($_GET['cnpj'] ?? '');
$cnpj = preg_replace('/\D/', '', $cnpjRaw);
if (!$cnpj || strlen($cnpj)!==14) { http_response_code(400); echo json_encode(['erro'=>'CNPJ inválido']); exit; }

$full = isset($_GET['full']) && $_GET['full'] === '1';

try {
    $db = get_db_connection();
    // Rate limit público geral (IP/rota)
    rate_limit_enforce($db, 'public.cnpj', 120, 300, true);
    // Bucket adicional por CNPJ (IP/rota+param) para mitigar scraping focado num alvo
    rate_limit_enforce($db, 'public.cnpj:' . $cnpj, 40, 300, true);
    // Se solicitar payload completo, aplicar buckets mais restritivos
    if ($full) {
        rate_limit_enforce($db, 'public.cnpj.full', 30, 300, true);
        rate_limit_enforce($db, 'public.cnpj.full:' . $cnpj, 10, 300, true);
    }
} catch (Throwable $e) { /* fail-open em erro de DB */ }

try {
    $dados = cnpj_lookup($cnpj);
} catch (Throwable $e) {
    http_response_code(502);
    header('Retry-After: 60');
    header('Cache-Control: no-store');
    echo json_encode(['erro'=>'Falha ao consultar serviço','retry_after'=>60]);
    exit;
}

if (!$dados) {
    http_response_code(404);
    header('Cache-Control: public, max-age=3600');
    echo json_encode(['erro'=>'Não encontrado']);
    exit;
}

// Reduzir payload por padrão (whitelist de campos comuns)
if (!$full) {
    $permitidos = [
        'cnpj','razao_social','nome_fantasia','situacao','abertura','natureza_juridica',
        // Atividade principal pode ser objeto/array pequeno
        'atividade_principal',
        // Endereço básico
        'logradouro','numero','complemento','bairro','municipio','uf','cep',
        // Contato
        'telefone','email'
    ];
    $filtrado = [];
    foreach ($permitidos as $k) { if (array_key_exists($k, $dados)) { $filtrado[$k] = $dados[$k]; } }
    // Se nada do whitelist existir (estrutura diferente), mantém mínimo seguro
    if ($filtrado) { $dados = $filtrado; }
}

// ETag/Cache para otimizar chamadas repetidas
$etag = 'W/"'.sha1(json_encode($dados, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'"';
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $etag) { http_response_code(304); exit; }
header('ETag: '.$etag);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=600');

echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
