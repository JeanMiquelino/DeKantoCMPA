<?php
// Serviço de criação de notificações (email + SSE) a partir de eventos de negócio
// Funções principais:
//   notif_registrar_evento($tipo, array $dados)
//   notif_requisicao_status($requisicaoId, $status, $descricao, array $opts=[])

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../email.php';

if (!function_exists('notif_registrar_evento')) {
    /**
     * Registra evento genérico disparando envio imediato para inscrições de canal email
     * e (provisoriamente) apenas logando inscrições de outros canais.
     * $dados pode conter: requisicao_id, status, descricao etc.
     */
    function notif_registrar_evento(string $tipo, array $dados): void {
        $db = get_db_connection();
        $reqId = $dados['requisicao_id'] ?? null;
        $sql = "SELECT ni.*, u.email AS user_email FROM notificacoes_inscricoes ni
                LEFT JOIN usuarios u ON u.id=ni.usuario_id
                WHERE ni.ativo=1
                AND (ni.tipo_evento IS NULL OR ni.tipo_evento=?)
                AND (ni.requisicao_id IS NULL OR ni.requisicao_id <=> ?)`";
        // Corrigir possível acento grave extra (caso edição anterior inclua). Usar prepare normal.
        $sql = str_replace('`"', '"', $sql);
        $st = $db->prepare($sql);
        $st->execute([$tipo, $reqId]);
        $inscricoes = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$inscricoes) return;
        $assuntoBase = 'Evento: ' . $tipo;
        $corpoBase = '<p>Evento: <strong>' . htmlspecialchars($tipo) . '</strong></p>';
        if ($reqId) $corpoBase .= '<p>Requisição #' . (int)$reqId . '</p>';
        if (isset($dados['descricao'])) {
            $corpoBase .= '<p>' . nl2br(htmlspecialchars($dados['descricao'])) . '</p>';
        }
        // Preparar insert somente para canais não-email (ex: SSE futuro)
        $ins = $db->prepare('INSERT INTO notificacoes (requisicao_id, tipo_evento, canal, destinatario, assunto, corpo, status) VALUES (?,?,?,?,?,?,?)');
        foreach ($inscricoes as $i) {
            $destEmail = $i['email'] ?: $i['user_email'];
            if (!$destEmail) continue;
            $canal = $i['canal'];
            $assunto = $assuntoBase;
            $corpo = $corpoBase;
            if ($canal === 'email') {
                // Envio imediato + log através de email_queue (já atualiza status na tabela notificacoes)
                email_queue($destEmail, $assunto, $corpo, [
                    'tipo_evento'   => $tipo,
                    'requisicao_id' => $reqId
                ]);
            } else {
                // Outros canais apenas log (status pendente para possível processamento futuro)
                $ins->execute([
                    $reqId !== null ? (int)$reqId : null,
                    $tipo,
                    $canal,
                    substr($destEmail,0,190),
                    substr($assunto,0,200),
                    $corpo,
                    'pendente'
                ]);
            }
        }
    }
}

if (!function_exists('notif_requisicao_status')) {
    function notif_requisicao_status(int $requisicaoId, string $status, string $descricao = '', array $opts = []): void {
        $db = get_db_connection();
        $tipo = 'requisicao_status';
        notif_registrar_evento($tipo, [ 'requisicao_id'=>$requisicaoId, 'status'=>$status, 'descricao'=>$descricao ]);
        if (!empty($opts['emails_extra'])) {
            $html = email_render_template('requisicao_status', [
                'requisicao_id' => $requisicaoId,
                'status' => $status,
                'descricao' => $descricao
            ]);
            email_queue($opts['emails_extra'], 'Requisição #' . $requisicaoId . ' atualizada', $html, [ 'tipo_evento'=>$tipo, 'requisicao_id'=>$requisicaoId ]);
        }
    }
}
