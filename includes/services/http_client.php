<?php
// HTTP helper + simple API cache functions
require_once __DIR__ . '/../db.php';

if (!function_exists('http_get_json')) {
    function http_get_json(string $url, int $timeout = 8): ?array {
        // Prefer cURL
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: AtlasApp/1.0 (+https://localhost)'
                ]
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && $body) {
                $data = json_decode($body, true);
                return is_array($data) ? $data : null;
            }
            return null;
        }
        // Fallback
        $ctx = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'header' => "Accept: application/json\r\nUser-Agent: AtlasApp/1.0\r\n"
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body) {
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }
}

if (!function_exists('api_cache_get')) {
    function api_cache_get(string $key): ?array {
        try {
            $db = get_db_connection();
            $st = $db->prepare('SELECT resposta, criado_em, ttl FROM cache_api WHERE chave = ? LIMIT 1');
            $st->execute([$key]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (strtotime($row['criado_em']) + (int)$row['ttl'] > time()) {
                    $decoded = json_decode($row['resposta'], true);
                    return is_array($decoded) ? $decoded : null;
                }
            }
        } catch (Exception $e) { /* silent */ }
        return null;
    }
}

if (!function_exists('api_cache_set')) {
    function api_cache_set(string $key, $data, int $ttl = 86400): void {
        try {
            $db = get_db_connection();
            $st = $db->prepare('REPLACE INTO cache_api (chave, resposta, criado_em, ttl) VALUES (?, ?, NOW(), ?)');
            $st->execute([$key, json_encode($data, JSON_UNESCAPED_UNICODE), $ttl]);
        } catch (Exception $e) { /* silent */ }
    }
}
