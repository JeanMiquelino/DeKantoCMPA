<?php
/**
 * Funções auxiliares para sincronizar anexos legados armazenados na coluna imagem_url das propostas
 * com a tabela estrutural `anexos`, permitindo que o portal do fornecedor visualize e manipule os arquivos.
 */

if (!function_exists('legacy_anexos_table_ready')) {
    function legacy_anexos_table_ready(PDO $db): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $tab = $db->query("SHOW TABLES LIKE 'anexos'")->fetch();
            $cache = $tab ? true : false;
        } catch (Throwable $e) {
            $cache = false;
        }
        return $cache;
    }
}

if (!function_exists('legacy_normalize_anexo_path')) {
    function legacy_normalize_anexo_path(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^\.\/#', '', $path);
        while (strpos($path, '../') === 0) {
            $path = substr($path, 3);
        }
        $path = ltrim($path, '/');
        return $path ?: null;
    }
}

if (!function_exists('legacy_sync_proposta_anexo')) {
    /**
     * Cria (ou atualiza) um registro na tabela `anexos` correspondente ao anexo legado de uma proposta.
     * Retorna o ID do anexo sincronizado ou null quando não aplicável.
     */
    function legacy_sync_proposta_anexo(PDO $db, int $propostaId, int $cotacaoId, int $requisicaoId, ?string $imagemUrl, array $meta = []): ?int
    {
        $imagemUrl = trim((string)$imagemUrl);
        if ($imagemUrl === '' || !$propostaId || !$requisicaoId) {
            return null;
        }
        if (!legacy_anexos_table_ready($db)) {
            return null;
        }
        $caminho = legacy_normalize_anexo_path($imagemUrl);
        if (!$caminho) {
            return null;
        }

        $nomeOriginal = $meta['nome_original'] ?? basename($caminho);
        $mime = $meta['mime'] ?? null;
        $tamanho = isset($meta['tamanho']) ? (int)$meta['tamanho'] : null;

        $absolutePath = realpath(__DIR__ . '/../' . $caminho);
        if ($absolutePath && is_file($absolutePath)) {
            if (!$mime && function_exists('mime_content_type')) {
                $detected = @mime_content_type($absolutePath);
                if ($detected) {
                    $mime = $detected;
                }
            }
            if (!$tamanho) {
                $tamanho = @filesize($absolutePath) ?: null;
            }
        }

        try {
            $stChk = $db->prepare('SELECT id FROM anexos WHERE tipo_ref = "proposta" AND ref_id = ? LIMIT 1');
            $stChk->execute([$propostaId]);
            $existingId = (int)($stChk->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $stUpd = $db->prepare('UPDATE anexos SET caminho=?, nome_original=?, mime=?, tamanho=?, requisicao_id=?, publico=0 WHERE id=?');
                $stUpd->execute([$caminho, $nomeOriginal, $mime, $tamanho, $requisicaoId, $existingId]);
                return $existingId;
            }
            $stIns = $db->prepare('INSERT INTO anexos (requisicao_id, tipo_ref, ref_id, nome_original, caminho, mime, tamanho, publico) VALUES (?,?,?,?,?,?,?,0)');
            $stIns->execute([$requisicaoId, 'proposta', $propostaId, $nomeOriginal, $caminho, $mime, $tamanho]);
            return (int)$db->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }
}
