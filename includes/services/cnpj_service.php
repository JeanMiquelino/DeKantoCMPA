<?php
require_once __DIR__ . '/http_client.php';

if (!function_exists('cnpj_lookup')) {
    function cnpj_lookup(string $cnpj): ?array {
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) !== 14) return null;
        $cacheKey = 'cnpj_' . $cnpj;
        if ($cached = api_cache_get($cacheKey)) return $cached;
        $url = 'https://brasilapi.com.br/api/cnpj/v1/' . $cnpj;
        $data = http_get_json($url);
        if ($data) api_cache_set($cacheKey, $data, 86400); // 1 dia
        return $data ?: null;
    }
}
