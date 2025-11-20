<?php
// SSE stream de notificações (tempo real)
// Uso: GET /api/notificacoes_stream.php (autenticado)
// Params opcionais: last_id=123 para retomar a partir de um id já recebido
// Mantém conexão ~25s; cliente deve reconectar automaticamente.

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Para Nginx desativar buffer (quando aplicável)
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/rate_limit.php';

$u = auth_usuario();
if(!$u){ http_response_code(401); echo "event: erro\ndata: {\"erro\":\"nao_autenticado\"}\n\n"; flush(); exit; }

// Identificador do destinatário para canal SSE: usar email do usuário
$destEmail = $u['email'] ?? null;
if(!$destEmail){ echo "event: erro\ndata: {\"erro\":\"usuario_sem_email\"}\n\n"; flush(); exit; }

$db = get_db_connection();
// Limitar tentativas de conexão SSE por usuário/IP por janela
try { rate_limit_enforce($db, 'api/notificacoes_stream:connect:uid:'.(int)$u['id'], 30, 300, false); } catch (Throwable $e) { /* ignore rate limit errors */ }

$lastId = (int)($_GET['last_id'] ?? 0);
$inicio = time();
$timeout = 25; // segundos
$pollInterval = 2; // segundos

function sse_send($event, $data, $id=null){
    if($id!==null) echo 'id: '.$id."\n";
    echo 'event: '.$event."\n";
    // Garantir linha única sem quebras cruas; encode JSON para segurança
    if(!is_string($data)) $data = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $lines = preg_split('/\r?\n/', $data);
    foreach($lines as $l){ echo 'data: '.$l."\n"; }
    echo "\n"; flush();
}

// Primeiro envio de boas vindas (heartbeat inicial)
sse_send('heartbeat', ['ts'=>time(),'last_id'=>$lastId]);

while(!connection_aborted() && (time()-$inicio) < $timeout){
    try {
        $st = $db->prepare("SELECT id, requisicao_id, tipo_evento, assunto, corpo, criado_em, enviado_em FROM notificacoes WHERE canal='sse' AND destinatario=? AND id>? AND status='enviado' ORDER BY id ASC LIMIT 50");
        $st->execute([$destEmail,$lastId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $r){
            $lastId = (int)$r['id'];
            sse_send('notificacao', $r, $lastId);
        }
    } catch(Throwable $e){
        sse_send('erro',['msg'=>'falha_consulta']);
    }
    // Heartbeat periódico para manter conexão viva
    sse_send('heartbeat',['ts'=>time(),'last_id'=>$lastId]);
    for($i=0;$i<$pollInterval;$i++){
        if(connection_aborted()) break 2;
        usleep(500000); // 0.5s x2
    }
}

sse_send('close',['motivo'=>'timeout','last_id'=>$lastId]);
