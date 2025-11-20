<?php
// Script utilitário para remover itens duplicados em propostas
// Uso: php cli/fix_proposta_itens_duplicates.php [--dry-run]

require_once __DIR__ . '/../includes/db.php';

date_default_timezone_set('America/Sao_Paulo');

$options = $argv ?? [];
$dryRun = in_array('--dry-run', $options, true) || in_array('-n', $options, true);

$db = get_db_connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "===> Verificando itens duplicados em proposta_itens" . ($dryRun ? " (dry-run)" : '') . "...\n";

$sqlDup = "SELECT proposta_id, produto_id, COUNT(*) AS total
            FROM proposta_itens
            GROUP BY proposta_id, produto_id
            HAVING COUNT(*) > 1";
$dups = $db->query($sqlDup)->fetchAll(PDO::FETCH_ASSOC);

if (!$dups) {
    echo "Nenhuma duplicidade encontrada.\n";
    exit(0);
}

$totalPairs = count($dups);
$totalDeleted = 0;
$details = [];

foreach ($dups as $dup) {
    $propostaId = (int)$dup['proposta_id'];
    $produtoId = (int)$dup['produto_id'];

    $stmt = $db->prepare('SELECT id, preco_unitario, quantidade FROM proposta_itens WHERE proposta_id=? AND produto_id=? ORDER BY id DESC');
    $stmt->execute([$propostaId, $produtoId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) <= 1) { continue; }

    $keep = array_shift($rows); // mantém o registro mais recente (maior id)
    $deleteIds = array_map(fn($r) => (int)$r['id'], $rows);

    if ($dryRun) {
        $details[] = [
            'proposta' => $propostaId,
            'produto' => $produtoId,
            'mantido' => $keep['id'],
            'removidos' => $deleteIds,
        ];
        $totalDeleted += count($deleteIds);
        continue;
    }

    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
    $stmtDel = $db->prepare("DELETE FROM proposta_itens WHERE id IN ($placeholders)");
    $stmtDel->execute($deleteIds);
    $removed = $stmtDel->rowCount();
    $totalDeleted += $removed;
    $details[] = [
        'proposta' => $propostaId,
        'produto' => $produtoId,
        'mantido' => $keep['id'],
        'removidos' => $deleteIds,
    ];
}

echo "Total de combinações duplicadas: $totalPairs\n";
echo "Registros redundantes " . ($dryRun ? 'identificados' : 'removidos') . ": $totalDeleted\n";

echo "--- Detalhes ---\n";
foreach ($details as $info) {
    echo sprintf(
        "Proposta #%d / Produto #%d -> mantido ID %d, %s: [%s]\n",
        $info['proposta'],
        $info['produto'],
        $info['mantido'],
        $dryRun ? 'duplicados' : 'removidos',
        implode(',', $info['removidos'])
    );
}

if ($dryRun) {
    echo "Nenhuma alteração aplicada (dry-run). Execute sem --dry-run para corrigir definitivamente.\n";
}
