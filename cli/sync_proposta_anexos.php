<?php
// Sincroniza anexos legados (coluna imagem_url em propostas) com a tabela `anexos`

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/legacy_anexos.php';

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "Este script deve ser executado via CLI.\n");
	exit(1);
}

$dryRun = false;
$limit = null;
foreach ($argv as $arg) {
	if ($arg === '--dry-run') {
		$dryRun = true;
	} elseif (strpos($arg, '--limit=') === 0) {
		$limit = (int)substr($arg, 8);
	}
}

$db = get_db_connection();
if (!legacy_anexos_table_ready($db)) {
	fwrite(STDERR, "Tabela 'anexos' inexistente; abortando.\n");
	exit(1);
}

$sql = "SELECT p.id AS proposta_id, p.imagem_url, c.id AS cotacao_id, c.requisicao_id
		FROM propostas p
		JOIN cotacoes c ON c.id = p.cotacao_id
		LEFT JOIN anexos ax ON ax.tipo_ref='proposta' AND ax.ref_id = p.id
		WHERE p.imagem_url IS NOT NULL AND p.imagem_url <> '' AND ax.id IS NULL
		ORDER BY p.id ASC";
if ($limit && $limit > 0) {
	$sql .= ' LIMIT ' . (int)$limit;
}

$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
	echo "Nenhuma proposta pendente de sincronização encontrada.\n";
	exit(0);
}

$total = count($rows);
$synced = 0;
echo "Encontradas {$total} propostas com imagem_url sem registro em anexos." . ($dryRun ? " (dry-run)" : '') . "\n";

foreach ($rows as $row) {
	$propostaId = (int)$row['proposta_id'];
	$cotacaoId = (int)$row['cotacao_id'];
	$reqId = (int)$row['requisicao_id'];
	$img = $row['imagem_url'];
	$meta = ['nome_original' => basename($img)];
	if ($dryRun) {
		echo "#{$propostaId} -> pronto para sincronizar ({$img})\n";
		$synced++;
		continue;
	}
	$anexoId = legacy_sync_proposta_anexo($db, $propostaId, $cotacaoId, $reqId, $img, $meta);
	if ($anexoId) {
		$synced++;
		echo "#{$propostaId} sincronizado (anexo {$anexoId}).\n";
	} else {
		echo "#{$propostaId} falhou ao sincronizar.\n";
	}
}

echo "Total sincronizado: {$synced}" . ($dryRun ? ' (dry-run)' : '') . "\n";
