<?php
// Helper para registrar eventos de timeline/auditoria de requisicoes
// Uso (canônico): log_requisicao_event($db, $requisicao_id, 'requisicao_status_alterado', 'Status alterado de X para Y', ['status'=>'X'], ['status'=>'Y']);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Normalização e catálogo de eventos de timeline
if(!function_exists('normalize_event_type')){
    function normalize_event_type(string $tipo): string {
        $t = strtolower(trim($tipo));
        $t = str_replace([' ', '-'], '_', $t);
        // Aliases conhecidos -> tipo canônico
        static $aliases = [
            // Aceite cliente (sintético em api/timeline)
            'pedido_cliente_aceite_status' => 'pedido_aceite_status',
            // Convites / Cotações
            'convite_enviado' => 'cotacao_convite_enviado',
            'convites_enviados' => 'cotacao_convite_enviado',
            'convites_resumo' => 'cotacao_convites_resumo',
            'resposta_recebida' => 'cotacao_resposta_recebida',
            // Pedido enviado para aceite (variações)
            'pedido_emitido_cliente' => 'pedido_enviado_cliente',
            'pedido_enviado' => 'pedido_enviado_cliente',
            // Ranking
            'ranking' => 'ranking_gerado',
            'ranking_decisao_aprovar' => 'ranking_decisao',
            // Tokens públicos/tracking
            'token_publico_gerado' => 'tracking_token_gerado',
            // Requisição status genérico
            'status_change' => 'requisicao_status_alterado',
            // Pedidos (variações históricas)
            'pedido_emitido' => 'pedido_criado',
            // Followups genéricos
            'followup' => 'followup_alerta',
        ];
        if(isset($aliases[$t])){ $t = $aliases[$t]; }
        // Garantir tamanho e charset seguros
        return substr(preg_replace('/[^a-z0-9_]+/','_', $t), 0, 64);
    }
}

if(!function_exists('log_requisicao_event')){
    function log_requisicao_event(PDO $db, int $requisicao_id, string $tipo_evento, string $descricao, $dadosAntes=null, $dadosDepois=null, ?int $usuario_id=null): void {
        // Normaliza tipo
        $tipo_evento = normalize_event_type($tipo_evento);
        // Tenta detectar usuário se não fornecido
        if($usuario_id === null){
            try { $u = auth_usuario(); if($u && isset($u['id'])){ $usuario_id = (int)$u['id']; } } catch(Throwable $e) { /* ignore */ }
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        // Normalizar JSON
        $jsonAntes = $dadosAntes===null ? null : json_encode($dadosAntes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $jsonDepois = $dadosDepois===null ? null : json_encode($dadosDepois, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        try {
            // Verificar rapidamente se tabela existe (evita erro em ambientes sem migration aplicada ainda)
            static $checked = false; static $tableExists = false;
            if(!$checked){
                try {
                    $r = $db->query("SHOW TABLES LIKE 'requisicoes_timeline'");
                    $tableExists = (bool)$r->fetch();
                } catch(Throwable $e){ $tableExists = false; }
                $checked = true;
            }
            if(!$tableExists){ return; }
            $st = $db->prepare('INSERT INTO requisicoes_timeline (requisicao_id, tipo_evento, descricao, dados_antes, dados_depois, usuario_id, ip_origem) VALUES (?,?,?,?,?,?,?)');
            $st->execute([
                $requisicao_id,
                $tipo_evento,
                mb_substr($descricao,0,255),
                $jsonAntes,
                $jsonDepois,
                $usuario_id,
                $ip
            ]);
            // Após inserir timeline, tentar enfileirar notificações somente para tipos relevantes (canônicos)
            $tiposNotificar = [
                'requisicao_status_alterado',
                'proposta_aprovada',
                'pedido_criado',
                'tracking_token_gerado',
                'proposta_criada',
                'anexo_enviado',
                'cotacao_resposta_recebida',
                'cotacao_convite_enviado',
                'proposta_atualizada',
                'pedido_enviado_cliente',
                'pedido_aceito',
                'pedido_rejeitado',
                'ranking_gerado',
                'ranking_decisao'
            ];
            if(in_array($tipo_evento,$tiposNotificar,true)){
                enqueue_requisicao_notifications($db, $requisicao_id, $tipo_evento, $descricao, $dadosDepois);
            }
        } catch(Throwable $e){
            error_log('Falha log_requisicao_event: '.$e->getMessage());
        }
    }
}

if(!function_exists('build_notificacao_template')){
    function build_notificacao_template(string $tipo_evento, string $descricao, $dadosDepois): array {
        $assuntoMap = [
            // Requisições
            'requisicao_criada' => 'Requisição criada',
            'requisicao_atualizada' => 'Requisição atualizada',
            'requisicao_status_alterado' => 'Status da requisição atualizado',
            'requisicao_removida' => 'Requisição removida',
            'responsavel_atribuido' => 'Responsável atribuído à requisição',
            'aprovacao_pendente' => 'Requisição aguardando aprovação',
            'aprovacao_aprovada' => 'Requisição aprovada',
            'aprovacao_rejeitada' => 'Requisição rejeitada',
            // Propostas
            'proposta_criada' => 'Nova proposta',
            'proposta_atualizada' => 'Proposta atualizada',
            'proposta_aprovada' => 'Proposta aprovada',
            'proposta_removida' => 'Proposta removida',
            // Pedidos
            'pedido_criado' => 'Pedido criado',
            'pedido_atualizado' => 'Pedido atualizado',
            'pedido_removido' => 'Pedido removido',
            'pedido_enviado_cliente' => 'Pedido enviado para aceite do cliente',
            'pedido_aceito' => 'Pedido aceito pelo cliente',
            'pedido_rejeitado' => 'Pedido rejeitado pelo cliente',
            'pedido_aceite_status' => 'Status de aceite do pedido',
            // Tokens / Links públicos
            'tracking_token_gerado' => 'Link público de acompanhamento gerado',
            'cotacao_token_regenerado' => 'Token de cotação regenerado',
            // Anexos
            'anexo_enviado' => 'Novo anexo',
            'anexo_download' => 'Download de anexo',
            // Itens
            'item_adicionado' => 'Item adicionado',
            'item_atualizado' => 'Item atualizado',
            'item_removido' => 'Item removido',
            // Cotações
            'cotacao_criada' => 'Cotação criada',
            'cotacao_excluida' => 'Cotação excluída',
            'cotacao_resposta_recebida' => 'Resposta de cotação recebida',
            'cotacao_convite_enviado' => 'Convite de cotação enviado',
            'cotacao_status_alterado' => 'Status da cotação alterado',
            'cotacao_encerrada' => 'Cotação encerrada',
            'cotacao_rodada_alterada' => 'Rodada da cotação alterada',
            'cotacao_tipo_frete_alterado' => 'Tipo de frete da cotação alterado',
            'cotacao_convite_cancelado' => 'Convite de cotação cancelado',
            'cotacao_convite_tokens_expostos' => 'Tokens de convites expostos (API)',
            // Resumos / Destaques
            'cotacao_convites_resumo' => 'Resumo de convites de cotação',
            'propostas_resumo' => 'Resumo de propostas',
            // Ranking
            'ranking_gerado' => 'Ranking de cotações gerado',
            'ranking_decisao' => 'Decisão de ranking registrada',
            // Followups
            'followup_alerta' => 'Alerta de acompanhamento (SLA)'
        ];
        $assuntoBase = $assuntoMap[$tipo_evento] ?? $tipo_evento;
        $assunto = '[Requisicao] '.$assuntoBase;
        $corpo = $descricao; // simples inicialmente
        if(is_array($dadosDepois)){
            $corpo .= "\n\nDados:".json_encode($dadosDepois, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        }
        return [$assunto,$corpo];
    }
}

if(!function_exists('enqueue_requisicao_notifications')){
    function enqueue_requisicao_notifications(PDO $db, int $requisicao_id, string $tipo_evento, string $descricao, $dadosDepois=null): void {
        // Verifica se tabelas existem, senão ignora silenciosamente
        static $checked=false,$hasNotif=false,$hasInsc=false;
        if(!$checked){
            try { $hasNotif = (bool)$db->query("SHOW TABLES LIKE 'notificacoes'")->fetch(); } catch(Throwable $e){ $hasNotif=false; }
            try { $hasInsc  = (bool)$db->query("SHOW TABLES LIKE 'notificacoes_inscricoes'")->fetch(); } catch(Throwable $e){ $hasInsc=false; }
            $checked=true;
        }
        if(!$hasNotif) return;
        // Seleciona inscrições ativas que casem requisicao e tipo
        try {
            $subs = [];
            if($hasInsc){
                $st = $db->prepare('SELECT * FROM notificacoes_inscricoes WHERE ativo=1 AND (requisicao_id IS NULL OR requisicao_id=?) AND (tipo_evento IS NULL OR tipo_evento=?)');
                $st->execute([$requisicao_id,$tipo_evento]);
                $subs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            list($assunto,$corpo) = build_notificacao_template($tipo_evento,$descricao,$dadosDepois);

            // Enviar também ao responsável da requisicao para eventos críticos (sem depender de inscrição)
            $eventosCriticos = ['pedido_enviado_cliente','pedido_aceito','pedido_rejeitado','cotacao_resposta_recebida'];
            $destExtra = [];
            if(in_array($tipo_evento, $eventosCriticos, true)){
                try {
                    $stR = $db->prepare('SELECT u.email FROM requisicoes r JOIN usuarios u ON u.id=r.responsavel_id WHERE r.id=? AND u.email<>""');
                    $stR->execute([$requisicao_id]);
                    $emailResp = $stR->fetchColumn();
                    if($emailResp){ $destExtra[] = ['canal'=>'email','destinatario'=>$emailResp]; }
                } catch(Throwable $e){ /* ignore */ }
            }

            // Enfileira emails para inscrições
            foreach($subs as $sub){
                $destinatario = $sub['email'] ?? null;
                if(!$destinatario && !empty($sub['usuario_id'])){
                    $stU = $db->prepare('SELECT email FROM usuarios WHERE id=?');
                    $stU->execute([$sub['usuario_id']]);
                    $destinatario = $stU->fetchColumn();
                }
                if(!$destinatario) continue;
                $canal = $sub['canal'] ?: 'email';
                $insSt = $db->prepare('INSERT INTO notificacoes (requisicao_id, tipo_evento, canal, destinatario, assunto, corpo) VALUES (?,?,?,?,?,?)');
                try { $insSt->execute([$requisicao_id,$tipo_evento,$canal,$destinatario,mb_substr($assunto,0,200),$corpo]); } catch(Throwable $ie){ /* pode gerar duplicata, ignorar */ }
            }
            // Enfileira emails extras (responsável)
            foreach($destExtra as $dx){
                $insSt = $db->prepare('INSERT INTO notificacoes (requisicao_id, tipo_evento, canal, destinatario, assunto, corpo) VALUES (?,?,?,?,?,?)');
                try { $insSt->execute([$requisicao_id,$tipo_evento,$dx['canal'],$dx['destinatario'],mb_substr($assunto,0,200),$corpo]); } catch(Throwable $ie){ /* ignore */ }
            }
        } catch(Throwable $e){ error_log('Falha enqueue_notif: '.$e->getMessage()); }
    }
}

// Novo: log de falhas de token/segurança (idempotente, ignora se tabela não existir)
if(!function_exists('seg_log_token_fail')){
    function seg_log_token_fail(PDO $db, string $rota, ?string $tokenHash, ?string $ip=null, ?string $userAgent=null, ?int $requisicao_id=null): void {
        try {
            static $checked=false,$hasTable=false;
            if(!$checked){
                try { $hasTable = (bool)$db->query("SHOW TABLES LIKE 'seg_token_fail'")->fetch(); } catch(Throwable $e){ $hasTable=false; }
                $checked=true;
            }
            if($hasTable){
                $st = $db->prepare('INSERT INTO seg_token_fail (rota, token_hash, ip, user_agent, requisicao_id, criado_em) VALUES (?,?,?,?,?,NOW())');
                $st->execute([mb_substr($rota,0,100), $tokenHash, $ip, mb_substr((string)$userAgent,0,255), $requisicao_id]);
            } else {
                // fallback: logar no error_log
                error_log('[seg_token_fail] rota='.$rota.' tokenHash='.($tokenHash?:'null').' ip='.($ip?:''). ' ua='.($userAgent?:''));
            }
        } catch(Throwable $e){ /* swallow */ }
    }
}
