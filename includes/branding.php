<?php
require_once __DIR__ . '/db.php';
$db = get_db_connection();
$branding = [
    'app_name' => 'DeKanto',
    'primary_color' => '#B5A886',
    'logo' => null
];
$stmt = $db->query('SELECT chave, valor FROM configuracoes');
foreach ($stmt->fetchAll() as $row) {
    $branding[$row['chave']] = $row['valor'];
}

// Migração suave de marca: normaliza app_name
$val = isset($branding['app_name']) ? trim((string)$branding['app_name']) : '';
if ($val === '') {
    $branding['app_name'] = 'DeKanto';
} elseif (stripos($val, 'nexus') !== false) {
    // Substitui apenas a palavra Nexus preservando sufixos/prefixos (ex.: "Nexus CMPA" -> "DeKanto CMPA")
    $branding['app_name'] = preg_replace('/nexus/i', 'DeKanto', $val);
}

// Resolver logo com fallback absoluto para funcionar em qualquer página
$logoKeys = ['logo', 'pdf.logo_path', 'app_logo', 'logo_path'];
$logoRaw = null;
foreach ($logoKeys as $k) {
    if (!empty($branding[$k])) { $logoRaw = trim((string)$branding[$k]); break; }
}
if (!$logoRaw) { $logoRaw = 'assets/images/logo.png'; }

// Se for caminho local, validar existência e montar URL absoluta usando APP_URL
$logoCandidate = $logoRaw;
if (!preg_match('#^https?://#i', $logoCandidate)) {
    $local = __DIR__ . '/../' . ltrim($logoCandidate, '/');
    if (!file_exists($local)) {
        $logoCandidate = 'assets/images/logo.png';
    }
    $branding['logo_url'] = rtrim(APP_URL, '/') . '/' . ltrim($logoCandidate, '/');
} else {
    $branding['logo_url'] = $logoCandidate;
}
// Sempre manter também o valor bruto
$branding['logo'] = $logoRaw;

// Variáveis globais para uso nas páginas
global $branding;