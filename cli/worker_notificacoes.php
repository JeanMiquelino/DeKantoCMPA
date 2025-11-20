<?php
// OBSOLETO: sistema de fila descontinuado. Este script permanece apenas para referência/histórico.
// Para envio de emails agora tudo é imediato via email_queue -> email_send.
// Worker de processamento de notificações (fila de emails / outros canais futuramente)
// Uso:
//   php cli/worker_notificacoes.php --once --debug
//   php cli/worker_notificacoes.php --loop --interval=5 --debug
// Flags:
//   --once       Processa um único ciclo (default se não usar --loop)
//   --loop       Permanece em execução processando continuamente
//   --interval=N Intervalo (segundos) entre ciclos sem trabalho (default 5)
//   --debug      Saída verbosa e ativa SMTPDebug

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;
require_once __DIR__ . '/../includes/email.php';

use PHPMailer\PHPMailer\PHPMailer;

// Polyfill str_starts_with para PHP <8 (embora este ambiente seja 8.2, por segurança)
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) { return $needle === '' || strpos($haystack, $needle) === 0; }
}

// ------------------ PARSE DE ARGUMENTOS ------------------
$argvFlags = $argv; array_shift($argvFlags);
$loop = in_array('--loop', $argvFlags, true);
$debug = in_array('--debug', $argvFlags, true);
$once  = in_array('--once', $argvFlags, true);
$interval = 5;
foreach ($argvFlags as $a) {
    if (str_starts_with($a, '--interval=')) { $v = (int)substr($a, 11); if ($v > 0) $interval = $v; }
}
$batchSize = 20;

// ------------------ FUNÇÕES AUXILIARES ------------------
function schema_ok(PDO $db): array {
    $cols = [];
    try {
        $rs = $db->query('SHOW COLUMNS FROM notificacoes');
        while ($r = $rs->fetch(PDO::FETCH_ASSOC)) { $cols[$r['Field']] = $r; }
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'erro' => $e->getMessage(),
            'cols' => [],
            'faltando' => ['(falha SHOW COLUMNS)']
        ];
    }
    $required = ['id','requisicao_id','tipo_evento','canal','destinatario','assunto','corpo','status','tentativas','erro_msg','criado_em','enviado_em'];
    $faltando = [];
    foreach ($required as $c) if (!isset($cols[$c])) $faltando[] = $c;
    return [
        'ok' => empty($faltando),
        'faltando' => $faltando,
        'cols' => array_keys($cols)
    ];
}

function enviar_email_worker(string $to, string $assunto, string $html, bool $debug=false): bool {
    $mail = new PHPMailer(true);
    try {
        if (function_exists('email_configure_mailer')) email_configure_mailer($mail);
        if ($debug) { $mail->SMTPDebug = 2; $mail->Debugoutput = function($str){ error_log('[SMTP] '.trim($str)); }; }
        $mail->isHTML(true);
        $mail->Subject = $assunto;
        $mail->Body = $html;
        $mail->AltBody = strip_tags($html);
        $mail->addAddress($to);
        $ok = $mail->send();
        if (!$ok) error_log('[ERRO EMAIL WORKER] '.$mail->ErrorInfo);
        return $ok;
    } catch (Throwable $e) {
        error_log('[ERRO EMAIL WORKER EXC] '.$e->getMessage());
        return false;
    }
}

function processar_lote(PDO $db, int $batchSize, bool $debug=false): int {
    $st = $db->prepare("SELECT * FROM notificacoes WHERE status='pendente' ORDER BY id ASC LIMIT :lim");
    $st->bindValue(':lim', $batchSize, PDO::PARAM_INT);
    $st->execute();
    $lista = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$lista) { if ($debug) echo "[INFO] Nenhuma notificacao pendente.\n"; return 0; }
    if ($debug) echo "[INFO] Processando ".count($lista)." notificacoes...\n";
    $proc = 0;
    foreach ($lista as $n) {
        $ok=false; $erro=null;
        try {
            // Lock otimista
            $up = $db->prepare("UPDATE notificacoes SET status='enviando', tentativas=tentativas+1 WHERE id=? AND status='pendente'");
            $up->execute([$n['id']]);
            if ($up->rowCount()===0) { if ($debug) echo "[SKIP] ID {$n['id']} já em processamento.\n"; continue; }
            if ($debug) echo "[ENVIANDO] ID {$n['id']} canal={$n['canal']} dest={$n['destinatario']}\n";
            if ($n['canal'] === 'email') {
                $ok = enviar_email_worker($n['destinatario'], $n['assunto'], $n['corpo'], $debug);
            } elseif ($n['canal'] === 'sse') {
                // Placeholder: canal SSE seria disparado via outro mecanismo
                $ok = true;
            } else {
                $erro = 'Canal desconhecido: '.$n['canal'];
            }
        } catch (Throwable $e) { $erro = $e->getMessage(); }

        if ($ok) {
            $db->prepare("UPDATE notificacoes SET status='enviado', enviado_em=NOW(), erro_msg=NULL WHERE id=?")
               ->execute([$n['id']]);
            if ($debug) echo "[OK] ID {$n['id']} enviado.\n";
        } else {
            $db->prepare("UPDATE notificacoes SET status=CASE WHEN tentativas>=3 THEN 'erro' ELSE 'pendente' END, erro_msg=? WHERE id=?")
               ->execute([$erro ?: 'Falha desconhecida', $n['id']]);
            if ($debug) echo "[FALHA] ID {$n['id']} erro=".($erro ?: 'Falha desconhecida')."\n";
        }
        $proc++;
    }
    return $proc;
}

// ------------------ INICIALIZAÇÃO ------------------
$db = get_db_connection();

if ($debug) {
    echo "[DEBUG] APP_URL=".(defined('APP_URL')?APP_URL:'(nao definido)')."\n";
    $sch = schema_ok($db);
    if (!$sch['ok']) {
        $faltStr = $sch['faltando'] ? implode(', ', $sch['faltando']) : '(nenhuma listada)';
        echo "[ALERTA] Schema notificacoes incompleto. Faltando: $faltStr\n";
        echo "Colunas atuais: ".($sch['cols']?implode(', ', $sch['cols']):'(nenhuma)')."\n";
        if (!empty($sch['erro'])) echo "Erro: {$sch['erro']}\n";
        echo "Execute a migration 20250817_0006_notificacoes.sql se ainda não aplicada.\n";
    } else {
        echo "[DEBUG] Schema notificacoes OK. Colunas: ".implode(', ', $sch['cols'])."\n";
    }
    try {
        $countPend = $db->query("SELECT COUNT(*) FROM notificacoes WHERE status='pendente'")->fetchColumn();
        $countEnv  = $db->query("SELECT COUNT(*) FROM notificacoes WHERE status='enviado'")->fetchColumn();
        $countErr  = $db->query("SELECT COUNT(*) FROM notificacoes WHERE status='erro'")->fetchColumn();
        echo "[DEBUG] Pendentes: $countPend | Enviados: $countEnv | Erro: $countErr\n";
    } catch (Throwable $e) {
        echo "[ERRO] Falha ao contar notificacoes: ".$e->getMessage()."\n";
    }
}

// ------------------ LOOP PRINCIPAL ------------------
$cycled = 0;
DO {
    $qt = processar_lote($db, $batchSize, $debug);
    $cycled++;
    if (!$loop || $once) break;
    if ($qt === 0) { sleep($interval); }
} while (true);

echo "Processamento concluido. Ciclos=$cycled\n";
