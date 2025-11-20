<?php
// Lookup público de fornecedor por CNPJ (somente dados mínimos)
header('Content-Type: application/json; charset=utf-8');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$cnpj = $_GET['cnpj'] ?? '';
$cnpj_digits = preg_replace('/\D/','', $cnpj);
if (strlen($cnpj_digits) !== 14) {
    http_response_code(422);
    echo json_encode(['found'=>false,'erro'=>'CNPJ inválido']);
    exit;
}

try {
    $db = get_db_connection();
    // Rate limit por IP nesta rota (pública) + bucket parametrizado por CNPJ
    rate_limit_enforce($db, 'public.fornecedor_lookup', 120, 300, true);
    rate_limit_enforce($db, 'public.fornecedor_lookup:' . $cnpj_digits, 40, 300, true);
    $sql = "SELECT id, razao_social, nome_fantasia, cnpj, status FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/', ''),' ', '') = ? LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([$cnpj_digits]);
    $row = $st->fetch();
    if ($row) {
        echo json_encode(['found'=>true,'fornecedor'=>$row]);
    } else {
        header('Cache-Control: public, max-age=3600');
        echo json_encode(['found'=>false]);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['found'=>false,'erro'=>'Erro interno']);
}
