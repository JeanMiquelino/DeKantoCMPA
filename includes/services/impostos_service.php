<?php
require_once __DIR__ . '/../db.php';

if (!function_exists('imposto_por_ncm')) {
    function imposto_por_ncm(string $ncm, string $origem = 'nacional'): ?array {
        $ncm = preg_replace('/\D/','', $ncm);
        if ($ncm === '') return null;
        $db = get_db_connection();
        $st = $db->prepare('SELECT * FROM ncm_impostos WHERE ncm = ? LIMIT 1');
        $st->execute([$ncm]);
        if (!$row = $st->fetch(PDO::FETCH_ASSOC)) return null;
        $aliq = ($origem === 'importado') ? ($row['aliquota_importado'] ?? $row['aliquota_nacional']) : $row['aliquota_nacional'];
        return [
            'ncm' => $ncm,
            'descricao' => $row['descricao'],
            'origem' => $origem,
            'aliquota' => (float)$aliq,
            'fonte' => $row['fonte'],
            'validade' => $row['validade']
        ];
    }
}

if (!function_exists('calcula_imposto_item')) {
    function calcula_imposto_item(string $ncm, float $valor, string $origem = 'nacional'): ?array {
        $imp = imposto_por_ncm($ncm, $origem);
        if (!$imp) return null;
        $imp['valor_base'] = round($valor, 2);
        $imp['valor_imposto'] = round($valor * ($imp['aliquota'] / 100), 2);
        return $imp;
    }
}
