<?php
// Camada central de email / notificações por email
// Uso direto: email_send('dest@exemplo.com','Assunto','<p>HTML</p>');
// Uso enfileirado: email_queue('dest@exemplo.com','Assunto','<p>HTML</p>', ['requisicao_id'=>123,'tipo_evento'=>'requisicao_status_alterado']);

require_once __DIR__ . '/db.php';
// Removido require direto de phpmailer.php; usamos autoload do Composer quando disponível
// require_once __DIR__ . '/phpmailer.php';
require_once __DIR__ . '/branding.php';

// Helper para forçar modo claro em emails sensíveis (senha, onboarding)
if(!function_exists('email_force_light_css')){
    function email_force_light_css(): string {
        // Incluímos regras fora de @media para clientes que ignoram prefers-color-scheme
        $css = implode('', [
            ':root{color-scheme:light only !important;}',
            ' body{background:#FFFFFF !important;color:#222 !important;}',
            ' .email-card, .card{background:#FFFFFF !important;color:#222 !important;border-color:rgba(0,0,0,0.08)!important;}',
            ' a{color:#931621 !important;}',
            ' @media (prefers-color-scheme: dark){body, .email-card, .card{background:#FFFFFF !important;color:#222 !important;} .email-card, .card{border-color:rgba(0,0,0,0.08)!important;} }'
        ]);
        return '<style>'.$css.'</style>';
    }
}

// Tentativa de carregar via Composer; fallback para arquivo único legacy se necessário
if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)){
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if(is_file($autoload)) require_once $autoload;
}
if(!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)){
    $legacy = __DIR__ . '/phpmailer.php';
    if(is_file($legacy)) require_once $legacy; // contém apenas classe PHPMailer (sem SMTP/Exception) mas evita fatals em ambientes sem Composer
}

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists('email_configure_mailer')) {
    function email_configure_mailer(PHPMailer $mail): void {
        // Prefer dados do banco (branding); fallback para variáveis de ambiente; por fim, constantes de config.php
        $cfg = $GLOBALS['branding'] ?? [];
        $host   = ($cfg['smtp_host']   ?? null) ?: (getenv('SMTP_HOST')      ?: (defined('SMTP_HOST') ? SMTP_HOST : null));
        $port   = ($cfg['smtp_port']   ?? null) ?: (getenv('SMTP_PORT')      ?: (defined('SMTP_PORT') ? SMTP_PORT : 587));
        $user   = ($cfg['smtp_user']   ?? null) ?: (getenv('SMTP_USER')      ?: (defined('SMTP_USER') ? SMTP_USER : null));
        $pass   = ($cfg['smtp_pass']   ?? null) ?: (getenv('SMTP_PASS')      ?: (defined('SMTP_PASS') ? SMTP_PASS : null));
        $secure = ($cfg['smtp_secure'] ?? null) ?: (getenv('SMTP_SECURE')    ?: (defined('SMTP_SECURE') ? SMTP_SECURE : 'tls'));
        if ($host) {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = is_numeric($port) ? (int)$port : 587;
            if ($user) {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = $pass;
            }
            if ($secure && in_array($secure, ['tls','ssl'], true)) {
                $mail->SMTPSecure = $secure;
            }
        }
        $mail->CharSet = 'UTF-8';
        $from = ($cfg['smtp_from'] ?? null)
            ?: (getenv('SMTP_FROM') ?: (defined('SMTP_FROM') ? SMTP_FROM : ($user ?: 'no-reply@example.test')));
        $fromName = ($cfg['smtp_from_name'] ?? null)
            ?: (getenv('SMTP_FROM_NAME') ?: (defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : (($GLOBALS['branding']['app_name'] ?? null) ?: (defined('APP_NAME') ? APP_NAME : 'Aplicacao'))));
        $mail->setFrom($from, $fromName);
    }
}

if (!function_exists('email_send')) {
    /**
     * Envia email imediato (sincrono). Retorna true/false. Em caso de falha, define $GLOBALS['EMAIL_LAST_ERROR'].
     */
    function email_send($to, string $subject, string $html, ?string $text = null, array $opts = []): bool {
        $mail = new PHPMailer(true);
        try {
            email_configure_mailer($mail);
            // Destinatários
            $destinatarios = is_array($to) ? $to : [$to];
            foreach ($destinatarios as $addr) {
                if ($addr) $mail->addAddress($addr);
            }
            if (empty($mail->getToAddresses())) throw new RuntimeException('Nenhum destinatario valido.');
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text ?: strip_tags(preg_replace('#<br\s*/?>#i', "\n", $html));
            // Attachments
            if (!empty($opts['attachments']) && is_array($opts['attachments'])) {
                foreach ($opts['attachments'] as $att) {
                    if (is_string($att) && is_file($att)) {
                        $mail->addAttachment($att);
                    } elseif (is_array($att) && isset($att['path']) && is_file($att['path'])) {
                        $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
                    }
                }
            }
            if (!empty($opts['reply_to'])) {
                $rt = $opts['reply_to'];
                if (is_array($rt)) { $mail->addReplyTo($rt[0], $rt[1] ?? ''); }
                elseif (is_string($rt)) { $mail->addReplyTo($rt); }
            }
            if (!empty($opts['cc'])) {
                foreach ((array)$opts['cc'] as $cc) { if ($cc) $mail->addCC($cc); }
            }
            if (!empty($opts['bcc'])) {
                foreach ((array)$opts['bcc'] as $bcc) { if ($bcc) $mail->addBCC($bcc); }
            }
            return $mail->send();
        } catch (Throwable $e) {
            $GLOBALS['EMAIL_LAST_ERROR'] = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('email_queue')) {
    /**
     * Enfileira emails na tabela notificacoes (canal=email).
     * MODIFICADO: agora realiza o envio imediato e já atualiza o status (eliminando necessidade do worker).
     * Mantém assinatura retornando array de IDs para compatibilidade.
     */
    function email_queue($to, string $subject, string $html, array $opts = []): array {
        $db = get_db_connection();
        $ids = [];
        $destinatarios = is_array($to) ? $to : [$to];
        $tipo_evento = $opts['tipo_evento'] ?? ($opts['tipo'] ?? 'generico');
        $reqId = $opts['requisicao_id'] ?? null;
        foreach ($destinatarios as $addr) {
            if (!$addr) continue;
            // Insere registro com status 'enviando' (lock pessimista simples)
            $st = $db->prepare('INSERT INTO notificacoes (requisicao_id, tipo_evento, canal, destinatario, assunto, corpo, status, tentativas) VALUES (?,?,?,?,?,?,"enviando",0)');
            $okIns = $st->execute([
                $reqId !== null ? (int)$reqId : null,
                substr($tipo_evento,0,60),
                'email',
                substr($addr,0,190),
                substr($subject,0,200),
                $html
            ]);
            if(!$okIns) continue;
            $id = (int)$db->lastInsertId();
            $ids[] = $id;
            // Tenta envio imediato
            $sucesso = email_send($addr, $subject, $html, null, $opts);
            if($sucesso){
                $db->prepare('UPDATE notificacoes SET status="enviado", enviado_em=NOW(), tentativas=tentativas+1, erro_msg=NULL WHERE id=?')->execute([$id]);
            } else {
                $erro = $GLOBALS['EMAIL_LAST_ERROR'] ?? 'Falha desconhecida';
                $db->prepare('UPDATE notificacoes SET status="erro", tentativas=tentativas+1, erro_msg=? WHERE id=?')->execute([$erro, $id]);
            }
        }
        return $ids;
    }
}

if (!function_exists('email_render_template')) {
    /**
     * Renderiza template simples (placeholders {{chave}}). Busca em includes/email_templates/ nome.html
     */
    function email_render_template(string $name, array $vars = []): ?string {
        $base = __DIR__ . '/email_templates';
        $file = $base . '/' . basename($name) . '.html';
        if (!is_file($file)) return null;
        $html = file_get_contents($file);
        $varsGlobal = [
            'app_name' => $GLOBALS['branding']['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Aplicacao'),
            'app_url'  => $GLOBALS['branding']['app_url'] ?? (defined('APP_URL') ? APP_URL : '')
        ];
        $all = array_merge($varsGlobal, $vars);
        foreach ($all as $k => $v) {
            $html = str_replace('{{' . $k . '}}', (string)$v, $html);
        }
        return $html;
    }
}

if (!function_exists('email_queue_template')) {
    function email_queue_template($to, string $subject, string $template, array $vars = [], array $opts = []): array {
        $html = email_render_template($template, $vars);
        if ($html === null) throw new RuntimeException('Template inexistente: ' . $template);
        return email_queue($to, $subject, $html, $opts);
    }
}

if (!function_exists('email_send_template')) {
    function email_send_template($to, string $subject, string $template, array $vars = [], array $opts = []): bool {
        $html = email_render_template($template, $vars);
        if ($html === null) throw new RuntimeException('Template inexistente: ' . $template);
        return email_send($to, $subject, $html, null, $opts);
    }
}

if (!function_exists('email_make_token')) {
    function email_make_token(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/','-_'),'='); }
}

if (!function_exists('email_send_cotacao_link')) {
    /** Envia (ou enfileira) link público de cotação para fornecedores */
    function email_send_cotacao_link(string $email, int $cotacaoId, string $tokenPublico, bool $queue=true): bool|array {
        $url = (defined('APP_URL')? APP_URL : '').'/pages/cotacao_responder.php?token='.urlencode($tokenPublico);
        $html = email_render_template('generic', [
            'titulo' => 'Nova Cotação Disponível',
            'mensagem' => 'Você foi convidado a responder a cotação #'.$cotacaoId.'.',
            'acao_html' => '<a class="btn" href="'.$url.'" target="_blank">Responder Cotação</a>'
        ]) ?? '<p>Cotação #'.$cotacaoId.'</p><p><a href="'.$url.'">Responder</a></p>';
        $assunto = 'Cotação #'.$cotacaoId.' disponível';
        if($queue) return email_queue($email,$assunto,$html,['tipo_evento'=>'cotacao_link']);
        return email_send($email,$assunto,$html);
    }
}

if (!function_exists('email_send_cotacao_convite')) {
    function email_send_cotacao_convite(string $email, int $requisicaoId, ?string $tokenRaw = null, bool $queue = true, array $opts = []): bool|array {
        $db = get_db_connection();
        $conviteId = isset($opts['convite_id']) ? (int)$opts['convite_id'] : null;
        $fornecedorId = isset($opts['fornecedor_id']) ? (int)$opts['fornecedor_id'] : null;
        $expiraEm = $opts['expira_em'] ?? null;

        if ($tokenRaw === null) {
            if ($conviteId) {
                $tokenRaw = bin2hex(random_bytes(24));
                $tokenHash = hash('sha256', $tokenRaw);
                try {
                    if ($expiraEm) {
                        $st = $db->prepare('UPDATE cotacao_convites SET token_hash=?, expira_em=?, status="pendente", enviado_em=NULL WHERE id=?');
                        $st->execute([$tokenHash, $expiraEm, $conviteId]);
                    } else {
                        $st = $db->prepare('UPDATE cotacao_convites SET token_hash=?, status="pendente", enviado_em=NULL WHERE id=?');
                        $st->execute([$tokenHash, $conviteId]);
                    }
                } catch (Throwable $e) { /* silencioso */ }
            } else {
                $tokenRaw = bin2hex(random_bytes(24));
            }
        }

        $tituloPadrao = 'Requisição #'.$requisicaoId;
        $tituloReq = $tituloPadrao;
        $usaTituloPadrao = true;
        $codigoReq = null;
        try {
            $stReq = $db->prepare('SELECT titulo, codigo FROM requisicoes WHERE id=?');
            $stReq->execute([$requisicaoId]);
            if ($row = $stReq->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['titulo'])) {
                    $tituloReq = $row['titulo'];
                    $usaTituloPadrao = (trim((string)$row['titulo']) === $tituloPadrao);
                } else {
                    $usaTituloPadrao = true;
                }
                $codigoReq = $row['codigo'] ?? null;
            }
        } catch (Throwable $e) { /* ignora */ }

        $appUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        $link = $appUrl.'/pages/cotacao_responder.php?conv='.urlencode($tokenRaw);

        $expiraTexto = '';
        if ($expiraEm) {
            $ts = strtotime((string)$expiraEm);
            if ($ts) { $expiraTexto = date('d/m/Y H:i', $ts); }
        }

        $tituloReqSafe = htmlspecialchars($tituloReq, ENT_QUOTES, 'UTF-8');
        $codigoReqSafe = $codigoReq ? htmlspecialchars($codigoReq, ENT_QUOTES, 'UTF-8') : null;
        $linkSafe = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $appNome = $GLOBALS['branding']['app_name'] ?? (defined('APP_NAME') ? APP_NAME : 'nossa equipe');
        $appLabel = htmlspecialchars($appNome, ENT_QUOTES, 'UTF-8');

        $resumoHtmlParts = [];
        $resumoHtmlParts[] = '<p style="margin:0;">Você foi convidado a enviar sua proposta para <strong>'.$tituloReqSafe.'</strong>.</p>';
        if ($codigoReqSafe) {
            $resumoHtmlParts[] = '<p style="margin:12px 0 0;">Código interno: <strong>'.$codigoReqSafe.'</strong>.</p>';
        }
        if ($expiraTexto) {
            $resumoHtmlParts[] = '<p style="margin:14px 0 0;font-size:13px;color:rgba(30,28,22,0.7);">Convite válido até '.$expiraTexto.'.</p>';
        }
        $resumoHtml = implode('', $resumoHtmlParts);

        $metaRequisicao = '<div class="meta-item"><span class="meta-label">Requisição</span><span class="meta-value">#'.$requisicaoId.'</span><span class="meta-detail">'.$tituloReqSafe.'</span></div>';
        $codigoBloco = '';
        if ($codigoReqSafe) {
            $codigoBloco = '<div class="meta-item"><span class="meta-label">Código interno</span><span class="meta-value">'.$codigoReqSafe.'</span></div>';
        }
        $expiraBloco = '';
        if ($expiraTexto) {
            $expiraBloco = '<div class="meta-item"><span class="meta-label">Expira em</span><span class="meta-value">'.$expiraTexto.'</span><span class="meta-detail">Horário de Brasília</span></div>';
        }

        $ctaLabel = 'Responder cotação';
        $ctaSubtext = $expiraTexto ? 'Convite disponível até '.$expiraTexto : 'Envie sua proposta pelo link acima';
        $observacoesHtml = '<div class="observacoes"><strong>Dica:</strong> compartilhe este link apenas com quem irá preencher a proposta. Em caso de dúvidas, fale com a equipe '.$appLabel.'.</div>';

    $heroSubtitle = $usaTituloPadrao ? '' : $tituloPadrao;

        $html = email_render_template('cotacao_convite', [
            'badge_label' => 'Convite de Cotação',
            'hero_title' => $tituloReqSafe,
            'hero_subtitle' => $heroSubtitle,
            'resumo_html' => $resumoHtml,
            'meta_requisicao' => $metaRequisicao,
            'codigo_bloco' => $codigoBloco,
            'expira_bloco' => $expiraBloco,
            'cta_url' => $linkSafe,
            'cta_label' => $ctaLabel,
            'cta_subtext' => $ctaSubtext,
            'observacoes_html' => $observacoesHtml
        ]);
        if ($html === null) {
            $mensagemFallback = 'Você foi convidado a enviar sua proposta para a requisição #'.$requisicaoId.' ('.$tituloReq.').' . ($expiraTexto ? ' Convite válido até '.$expiraTexto.'.' : '');
            if ($codigoReq) { $mensagemFallback .= ' Código interno: '.$codigoReq.'.'; }
            $acao = '<a class="btn" href="'.$linkSafe.'" target="_blank">'.$ctaLabel.'</a>';
            $htmlBody = '<p>'.$mensagemFallback.'</p>'.$acao;
            $html = email_render_template('generic', [
                'titulo' => 'Convite de Cotação',
                'mensagem' => $mensagemFallback,
                'acao_html' => $acao
            ]) ?: email_wrap_nexus('Convite de Cotação', $htmlBody);
        }

        $assunto = 'Convite de Cotação - '.$tituloReq;
        $meta = array_merge($opts, ['tipo_evento' => 'cotacao_convite', 'requisicao_id' => $requisicaoId]);

        $resultado = $queue ? email_queue($email, $assunto, $html, $meta) : email_send($email, $assunto, $html, null, $meta);
        $sucesso = false;
        if ($queue) {
            $sucesso = is_array($resultado) ? !empty($resultado) : (bool)$resultado;
        } else {
            $sucesso = (bool)$resultado;
        }

        if ($conviteId) {
            try {
                if ($sucesso) {
                    $db->prepare('UPDATE cotacao_convites SET status="enviado", enviado_em=NOW() WHERE id=?')->execute([$conviteId]);
                } else {
                    $db->prepare('UPDATE cotacao_convites SET status="erro_envio" WHERE id=?')->execute([$conviteId]);
                }
            } catch (Throwable $e) { /* ignora */ }
        }

        return $resultado;
    }
}

if (!function_exists('email_send_tracking_update')) {
    /** Notifica cliente sobre atualização de tracking (timeline / status) */
    function email_send_tracking_update(string $email, int $requisicaoId, string $status, string $descricao='', bool $queue=true): bool|array {
        $url = (defined('APP_URL')? APP_URL : '').'/pages/acompanhar_requisicao.php?publico_detalhado=1&token=';
        // Token de tracking deve estar previamente gerado e armazenado na requisicao; buscamos rapidamente se necessário
        try {
            $db = get_db_connection();
            $st = $db->prepare('SELECT tracking_token FROM requisicoes WHERE id=?');
            $st->execute([$requisicaoId]);
            $tk = $st->fetchColumn();
            if($tk){ $url .= urlencode($tk); } else { $url = (defined('APP_URL')? APP_URL : '').'/pages/requisicoes.php?id='.$requisicaoId; }
        } catch(Throwable $e) { /* fallback simples */ }
        $html = email_render_template('requisicao_status', [
            'requisicao_id'=>$requisicaoId,
            'status'=>$status,
            'descricao'=>$descricao
        ]) ?? '<p>Requisição #'.$requisicaoId.' atualizada para <strong>'.htmlspecialchars($status).'</strong></p>';
        $assunto = 'Atualização da Requisição #'.$requisicaoId.' - '.$status;
        if($queue) return email_queue($email,$assunto,$html,['tipo_evento'=>'tracking_update','requisicao_id'=>$requisicaoId]);
        return email_send($email,$assunto,$html);
    }
}

if (!function_exists('email_send_proposta_aprovada')) {
    /** Notifica o cliente quando uma proposta é aprovada e gera pedido */
    function email_send_proposta_aprovada(int $propostaId, bool $queue = true): bool|array {
        try {
            $db = get_db_connection();
            $sql = 'SELECT p.id, p.valor_total, p.prazo_entrega, p.pagamento_dias, c.requisicao_id, r.titulo AS requisicao_titulo,
                           cli.email AS cliente_email, cli.nome_fantasia AS cliente_nome, cli.razao_social AS cliente_razao,
                           f.nome_fantasia AS fornecedor_nome, f.razao_social AS fornecedor_razao
                    FROM propostas p
                    JOIN cotacoes c   ON c.id = p.cotacao_id
                    JOIN requisicoes r ON r.id = c.requisicao_id
                    JOIN clientes cli  ON cli.id = r.cliente_id
                    LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                    WHERE p.id = ? LIMIT 1';
            $st = $db->prepare($sql);
            $st->execute([$propostaId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['cliente_email'])) { return false; }

            $fornecedorNome = $row['fornecedor_nome'] ?: ($row['fornecedor_razao'] ?: 'Fornecedor selecionado');
            $valorTxt = isset($row['valor_total']) ? 'Valor total aprovado: R$ '.number_format((float)$row['valor_total'], 2, ',', '.') : '';
            $prazoTxt = $row['prazo_entrega'] ? 'Prazo de entrega estimado: '.((int)$row['prazo_entrega']).' dia(s).' : '';
            $pagamentoTxt = $row['pagamento_dias'] !== null ? 'Condição de pagamento: '.((int)$row['pagamento_dias']).' dia(s).' : '';
            $descricao = trim("A proposta do fornecedor {$fornecedorNome} foi aprovada.\n{$valorTxt}\n{$prazoTxt}\n{$pagamentoTxt}");
            if ($descricao === '') { $descricao = 'A proposta selecionada foi aprovada.'; }

            $vars = [
                'requisicao_id' => $row['requisicao_id'],
                'status' => 'Proposta aprovada',
                'descricao' => $descricao
            ];
            $html = email_render_template('requisicao_status', $vars)
                ?? email_wrap_nexus('Proposta aprovada', '<p>'.nl2br(htmlspecialchars($descricao)).'</p>');
            $assunto = 'Proposta aprovada - Requisição #'.$row['requisicao_id'];
            $meta = ['tipo_evento' => 'proposta_aprovada', 'requisicao_id' => $row['requisicao_id']];
            if ($queue) {
                return email_queue($row['cliente_email'], $assunto, $html, $meta);
            }
            return email_send($row['cliente_email'], $assunto, $html, null, $meta);
        } catch (Throwable $e) {
            $GLOBALS['EMAIL_LAST_ERROR'] = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('email_send_pedido_status')) {
    /** Notifica o cliente a cada atualização relevante do pedido */
    function email_send_pedido_status(int $pedidoId, string $novoStatus, bool $queue = true): bool|array {
        try {
            $db = get_db_connection();
            $sql = 'SELECT pd.id, pd.status, pd.pdf_url,
                           p.valor_total, p.pagamento_dias, p.prazo_entrega,
                           c.requisicao_id, r.titulo AS requisicao_titulo,
                           cli.email AS cliente_email,
                           f.nome_fantasia AS fornecedor_nome, f.razao_social AS fornecedor_razao
                    FROM pedidos pd
                    JOIN propostas p   ON p.id = pd.proposta_id
                    JOIN cotacoes c    ON c.id = p.cotacao_id
                    JOIN requisicoes r ON r.id = c.requisicao_id
                    JOIN clientes cli  ON cli.id = r.cliente_id
                    LEFT JOIN fornecedores f ON f.id = p.fornecedor_id
                    WHERE pd.id = ? LIMIT 1';
            $st = $db->prepare($sql);
            $st->execute([$pedidoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['cliente_email'])) { return false; }

            $statusLabel = trim('Pedido '.ucwords(str_replace(['_', '-'], ' ', $novoStatus)));
            $parts = [];
            $parts[] = "O pedido #{$pedidoId} foi atualizado para {$statusLabel}.";
            if ($row['valor_total'] !== null) {
                $parts[] = 'Total: R$ '.number_format((float)$row['valor_total'], 2, ',', '.');
            }
            if ($row['pagamento_dias'] !== null) {
                $parts[] = 'Pagamento: '.((int)$row['pagamento_dias']).' dia(s).';
            }
            if ($row['prazo_entrega']) {
                $parts[] = 'Prazo de entrega informado: '.((int)$row['prazo_entrega']).' dia(s).';
            }
            $fornecedorNome = $row['fornecedor_nome'] ?: ($row['fornecedor_razao'] ?: null);
            if ($fornecedorNome) {
                $parts[] = 'Fornecedor: '.$fornecedorNome;
            }
            if (!empty($row['pdf_url'])) {
                $parts[] = 'Documento: '.$row['pdf_url'];
            }
            $descricao = implode("\n", array_filter($parts));

            $vars = [
                'requisicao_id' => $row['requisicao_id'],
                'status' => $statusLabel,
                'descricao' => $descricao ?: 'Pedido atualizado.'
            ];
            $html = email_render_template('requisicao_status', $vars)
                ?? email_wrap_nexus($statusLabel, '<p>'.nl2br(htmlspecialchars($descricao)).'</p>');
            $assunto = $statusLabel.' - Requisição #'.$row['requisicao_id'];
            $meta = ['tipo_evento' => 'pedido_'.$novoStatus, 'requisicao_id' => $row['requisicao_id']];
            if ($queue) {
                return email_queue($row['cliente_email'], $assunto, $html, $meta);
            }
            return email_send($row['cliente_email'], $assunto, $html, null, $meta);
        } catch (Throwable $e) {
            $GLOBALS['EMAIL_LAST_ERROR'] = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('email_send_confirmacao')) {
    /** Envia email de confirmação de conta com token. Deve existir colunas confirm_token, confirmado_em */
    function email_send_confirmacao(int $usuarioId, bool $queue=true): bool|array {
        $db = get_db_connection();
        $st = $db->prepare('SELECT id,nome,email,confirmado_em,confirm_token FROM usuarios WHERE id=?');
        $st->execute([$usuarioId]);
        $u = $st->fetch(PDO::FETCH_ASSOC); if(!$u || empty($u['email'])) return false;
        if(!empty($u['confirmado_em'])) return false; // já confirmado
        $token = $u['confirm_token'];
        if(!$token){
            $token = email_make_token(24);
            $up = $db->prepare('UPDATE usuarios SET confirm_token=? WHERE id=?');
            $up->execute([$token,$usuarioId]);
        }
        $link = (defined('APP_URL')? APP_URL:'').'/pages/confirmar_email.php?token='.urlencode($token);
        $html = email_render_template('generic',[
            'titulo'=>'Confirme seu email',
            'mensagem'=>'Olá '.htmlspecialchars($u['nome']).', confirme seu email para ativar sua conta.',
            'acao_html'=>'<a class="btn" href="'.$link.'" target="_blank">Confirmar Email</a>'
        ]) ?? '<p>Confirme seu email: <a href="'.$link.'">'.$link.'</a></p>';
        $assunto = 'Confirme seu email';
        if($queue) return email_queue($u['email'],$assunto,$html,['tipo_evento'=>'confirmacao_email']);
        return email_send($u['email'],$assunto,$html);
    }
}

if (!function_exists('email_send_recuperacao_senha')) {
    /** Envia link de recuperação de senha; salva hash+expiração em tabela usuarios_recuperacao */
    function email_send_recuperacao_senha(string $email, bool $queue=true): bool|array {
        try {
            $db = get_db_connection();
            $st = $db->prepare('SELECT id,nome,email FROM usuarios WHERE email=? AND ativo=1');
            $st->execute([$email]);
            $u = $st->fetch(PDO::FETCH_ASSOC); if(!$u) return false;
            // Criar tabela se não existir (fallback suave)
            try { $db->exec("CREATE TABLE IF NOT EXISTS usuarios_recuperacao (id INT AUTO_INCREMENT PRIMARY KEY, usuario_id INT NOT NULL, token VARCHAR(190) NOT NULL, expira_em DATETIME NOT NULL, usado TINYINT(1) NOT NULL DEFAULT 0, criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX(token), FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"); } catch(Throwable $e){}
            $token = email_make_token(32);
            $expira = date('Y-m-d H:i:s', time()+3600); // 1h
            $ins = $db->prepare('INSERT INTO usuarios_recuperacao (usuario_id, token, expira_em) VALUES (?,?,?)');
            $ins->execute([$u['id'],$token,$expira]);
            $link = (defined('APP_URL')? APP_URL:'').'/pages/redefinir_senha.php?token='.urlencode($token);
            $html = email_render_template('generic', [
                'titulo' => 'Recuperação de Senha',
                'mensagem' => 'Olá '.htmlspecialchars($u['nome']).', recebemos uma solicitação de redefinição de senha. Se não foi você, ignore.',
                'acao_html' => '<a class="btn" href="'.$link.'" target="_blank">Redefinir Senha</a>',
                'extra_css' => email_force_light_css()
            ]) ?? '<p>Redefinir senha: <a href="'.$link.'">'.$link.'</a></p>';
            $assunto = 'Recuperação de senha';
            if($queue) return email_queue($u['email'],$assunto,$html,['tipo_evento'=>'recuperacao_senha']);
            return email_send($u['email'],$assunto,$html);
        } catch(Throwable $e){
            $GLOBALS['EMAIL_LAST_ERROR'] = $e->getMessage();
            return false;
        }
    }
}

if (!function_exists('email_send_usuario_boas_vindas')) {
    /** Envia boas-vindas para novo usuário (após criação) */
    function email_send_usuario_boas_vindas(int $usuarioId, bool $queue=true): bool|array {
        try {
            $db = get_db_connection();
            $st = $db->prepare('SELECT id,nome,email FROM usuarios WHERE id=? AND ativo=1');
            $st->execute([$usuarioId]);
            $u = $st->fetch(PDO::FETCH_ASSOC); if(!$u || empty($u['email'])) return false;
            $link = (defined('APP_URL')? APP_URL:'').'/pages/login.php';
            $html = email_render_template('generic', [
                'titulo' => 'Bem-vindo(a) '.$u['nome'],
                'mensagem' => 'Sua conta foi criada com sucesso. Acesse o sistema pelo link abaixo.',
                'acao_html' => '<a class="btn" href="'.$link.'" target="_blank">Acessar Sistema</a>',
                'extra_css' => email_force_light_css()
            ]) ?? '<p>Bem-vindo(a) '.$u['nome'].'. Acesse: <a href="'.$link.'">'.$link.'</a></p>';
            $assunto = 'Bem-vindo(a) ao '.(defined('APP_NAME')?APP_NAME:'Sistema');
            if($queue) return email_queue($u['email'],$assunto,$html,['tipo_evento'=>'usuario_boas_vindas']);
            return email_send($u['email'],$assunto,$html);
        } catch(Throwable $e){ $GLOBALS['EMAIL_LAST_ERROR']=$e->getMessage(); return false; }
    }
}

// Adjust onboarding (senha inicial) emails in cliente/fornecedor creation occur below; we patch where template is used.
// We add extra_css when building those HTML blocks further down in the file.
// For simpler implementation we create a small replacement wrapper.
if(!function_exists('email_build_onboarding_html')){
    function email_build_onboarding_html(string $titulo, string $mensagem, string $senha, string $loginLink): string {
        return email_render_template('generic', [
            'titulo' => $titulo,
            'mensagem' => $mensagem.'<br><div style="margin:14px 0;padding:14px 18px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;letter-spacing:.5px;color:#1e293b;display:inline-block">'.htmlspecialchars($senha).'</div><br>Por segurança, altere a senha após o primeiro acesso.',
            'acao_html' => '<a class="btn" href="'.$loginLink.'" target="_blank">Acessar agora</a>',
            'extra_css' => email_force_light_css()
        ]) ?? '';
    }
}

if (!function_exists('email_wrap_nexus')) {
    /**
     * Aplica wrapper Nexus a um conteúdo simples (fallback quando quiser emails rápidos sem template completo).
     * Evita duplicação de estilos inline complexos nos chamadores.
     */
    function email_wrap_nexus(string $titulo, string $htmlBody, string $acaoHtml=''): string {
        $app = $GLOBALS['branding']['app_name'] ?? (defined('APP_NAME')? APP_NAME:'Aplicacao');
        $acao = $acaoHtml ? '<div style="margin:26px 0 10px">'.$acaoHtml.'</div>' : '';
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.htmlspecialchars($app).' - '.htmlspecialchars($titulo).'</title>'
            .'<meta name="color-scheme" content="light dark"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<style>body{margin:0;padding:0;background:#F7F6F2;font-family:Arial,Helvetica,sans-serif;color:#222;-webkit-font-smoothing:antialiased}'
            .'.nx-wrap{padding:32px 14px} .nx-card{max-width:640px;margin:0 auto;background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:16px;overflow:hidden;box-shadow:0 6px 28px rgba(0,0,0,.06)}'
            .'.nx-head{background:linear-gradient(135deg,#B5A886,#C8BEA2);padding:20px 28px} .nx-head h1{margin:0;font-size:20px;letter-spacing:.4px;font-weight:700;color:#222}'
            .'.nx-body{padding:28px 30px 30px;font-size:15px;line-height:1.55} .nx-body p{margin:0 0 16px}'
            .'.nx-btn{display:inline-block;background:#B5A886;color:#222 !important;text-decoration:none;padding:14px 26px;border-radius:10px;font-weight:600;font-size:14px;box-shadow:0 3px 14px rgba(181,168,134,.35);letter-spacing:.3px}'
            .'.nx-btn:hover{background:#C8BEA2}'
            .'.nx-divider{height:1px;background:linear-gradient(90deg,rgba(0,0,0,.07),transparent);margin:26px 0}'
            .'.nx-foot{padding:20px 28px 30px;background:#FAF9F6;font-size:11px;color:#5a5a5a;border-top:1px solid rgba(0,0,0,.06);text-align:center;line-height:1.4}'
            .'a{color:#931621;text-decoration:none}a:hover{text-decoration:underline}'
            .'@media (max-width:600px){.nx-card{border-radius:14px}.nx-body{padding:24px 22px 26px}.nx-head{padding:18px 22px}.nx-btn{display:block;width:100%;text-align:center;padding:14px 22px}}'
            .'@media (prefers-color-scheme: dark){body{background:#1f1f1f;color:#eee}.nx-card{background:#262626;border-color:#333}.nx-head{background:linear-gradient(135deg,#B5A886,#857b5f)}.nx-body{color:#e6e6e6}.nx-foot{background:#202020;color:#888;border-top-color:#333}.nx-divider{background:linear-gradient(90deg,#333,transparent)}}'
            .'</style></head><body><div class="nx-wrap"><div class="nx-card"><div class="nx-head"><h1>'
            .htmlspecialchars($titulo).'</h1></div><div class="nx-body">'.$htmlBody.$acao.'<div class="nx-divider"></div><p style="margin:0;font-size:13px;color:#5a5a5a">Mensagem automática de <strong>'
            .htmlspecialchars($app).'</strong>. Não responda.</p></div><div class="nx-foot">&copy; '.htmlspecialchars($app).'</div></div></div></body></html>';
    }
}
