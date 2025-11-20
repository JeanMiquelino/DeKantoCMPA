<?php
// Dedicated handler for OPTIONS preflight requests on shared hosting
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
if ($origin && parse_url($origin, PHP_URL_HOST) === $host) {
    $allowedOrigin = $origin;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $allowedOrigin = $scheme . $host;
}

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-HTTP-Method-Override, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Max-Age: 600');
header('Content-Length: 0');
http_response_code(204);
exit;
