<?php
session_start();
header('Content-Type: application/json');
// Security headers for public-capable endpoint
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/email.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/rate_limit.php';
require_once __DIR__.'/../includes/auth.php';

$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

function hash_token($t){ return hash('sha256',$t); }
function gerar_token_raw($len=48){ return bin2hex(random_bytes($len/2)); }

// Bloco interno: envio de pedido para aceite do cliente (gera token)
// Condição: POST com Content-Type application/json (evita colidir com POST público por token)
if($method === 'POST' && isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'],'application/json') !== false){
    // Requer autenticação (interno)
    if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['erro' => 'Não autenticado']); exit; }
    // Limitar abuso deste endpoint interno
    try { rate_limit_enforce($db, 'api.pedidos_aceite:enviar', 60, 300, true); } catch(Throwable $e){}

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $pedido_id = (int)($data['pedido_id'] ?? 0);
    if(!$pedido_id){ http_response_code(400); echo json_encode(['erro'=>'pedido_id obrigatório']); exit; }
    // Recupera pedido + requisicao + status
    $sql = "SELECT p.id, p.status, p.proposta_id, c.requisicao_id, r.cliente_id FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id JOIN requisicoes r ON r.id=c.requisicao_id WHERE p.id=?";
    $st = $db->prepare($sql); $st->execute([$pedido_id]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row){ http_response_code(404); echo json_encode(['erro'=>'Pedido não encontrado']); exit; }
    $curStatus = strtolower((string)$row['status']);
    // Elegibilidade: não permitir enviar se já emitido/cancelado/entregue
    if(in_array($curStatus, ['emitido','cancelado','entregue'], true)){
        http_response_code(400);
        echo json_encode(['erro'=>'Pedido não permite envio para aprovação do cliente']);
        exit;
    }
    // Força status para aguardando aprovação do cliente
    try { $db->prepare('UPDATE pedidos SET status="aguardando_aprovacao_cliente" WHERE id=?')->execute([$pedido_id]); } catch(Throwable $e){}

    $tokenRaw = gerar_token_raw(48); $tokenHash = hash_token($tokenRaw);
    try { $db->prepare('UPDATE pedidos SET cliente_aceite_token_hash=?, cliente_aceite_status="pendente" WHERE id=?')->execute([$tokenHash,$pedido_id]); } catch(Throwable $e){ }
    // Enviar email ao cliente (buscar email cliente)
    try {
        $stc = $db->prepare('SELECT email FROM clientes WHERE id=?');
        $stc->execute([$row['cliente_id']]);
        $emailCli = $stc->fetchColumn();
        if($emailCli){ email_send_pedido_aceite($emailCli,$pedido_id,$tokenRaw,false); }
    } catch(Throwable $e){}
    log_requisicao_event($db,(int)$row['requisicao_id'],'pedido_enviado_cliente','Pedido enviado para aceite',null,['pedido_id'=>$pedido_id]);
    echo json_encode(['success'=>true]);
    exit;
}

// Fluxo GET/POST por token (público)
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
if(!$token){ http_response_code(400); echo json_encode(['erro'=>'token obrigatório']); exit; }
$tokenHash = hash_token($token);

// Rate limit público por IP nesta rota + bucket por token
rate_limit_enforce($db, 'public.pedidos_aceite', 60, 300, true);
rate_limit_enforce($db, 'public.pedidos_aceite:' . $tokenHash, 30, 300, true);

if($method==='GET'){
    $sql = "SELECT p.id, p.cliente_aceite_status, p.cliente_aceite_em, c.requisicao_id FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id WHERE p.cliente_aceite_token_hash=?";
    $st=$db->prepare($sql); $st->execute([$tokenHash]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row){
        // log seguro de falha de token
        try { seg_log_token_fail($db, 'public.pedidos_aceite:GET', $tokenHash, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
        http_response_code(404); header('Cache-Control: public, max-age=3600'); echo json_encode(['erro'=>'Token inválido']); exit;
    }
    echo json_encode(['pedido_id'=>$row['id'],'status'=>$row['cliente_aceite_status'],'aceite_em'=>$row['cliente_aceite_em']]);
    exit;
}

if($method==='POST'){
    $acao = $_POST['acao'] ?? 'aceitar';
    // Expandir seleção para incluir cotacao_id e rodada atual
    $sql = "SELECT p.id, p.cliente_aceite_status, c.requisicao_id, c.id AS cotacao_id, c.rodada AS cotacao_rodada FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id WHERE p.cliente_aceite_token_hash=?";
    $st=$db->prepare($sql); $st->execute([$tokenHash]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row){
        try { seg_log_token_fail($db, 'public.pedidos_aceite:POST', $tokenHash, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
        http_response_code(404); header('Cache-Control: public, max-age=3600'); echo json_encode(['erro'=>'Token inválido']); exit;
    }
    if($row['cliente_aceite_status']!=='pendente'){ echo json_encode(['erro'=>'Já processado']); exit; }
    $novoStatus = $acao==='rejeitar' ? 'rejeitado' : 'aceito';
    $db->prepare('UPDATE pedidos SET cliente_aceite_status=?, cliente_aceite_em=NOW() WHERE id=?')->execute([$novoStatus,$row['id']]);
    // Transição de status do pedido conforme decisão do cliente
    if($novoStatus==='aceito'){
        try { $db->prepare('UPDATE pedidos SET status="pendente" WHERE id=?')->execute([$row['id']]); } catch(Throwable $e){}
        try { email_send_pedido_confirmacao((int)$row['id'], false); } catch (Throwable $e) { }
        // NOVO: Encerrar cotação imediatamente ao aceite do cliente
        try {
            if(!empty($row['cotacao_id'])){
                $upCot = $db->prepare('UPDATE cotacoes SET status="encerrada", token_expira_em=LEAST(NOW(), COALESCE(token_expira_em, NOW())) WHERE id=?');
                $upCot->execute([(int)$row['cotacao_id']]);
                // Log timeline do encerramento
                log_requisicao_event($db,(int)$row['requisicao_id'],'cotacao_encerrada','Cotação encerrada por aceite do cliente',null,['cotacao_id'=>(int)$row['cotacao_id']]);
            }
        } catch(Throwable $e){ /* ignore */ }
    } else {
        try { $db->prepare('UPDATE pedidos SET status="cancelado" WHERE id=?')->execute([$row['id']]); } catch(Throwable $e){}
        // NOVO: Iniciar nova fase (rodada) da cotação ao rejeitar
        try {
            if(!empty($row['cotacao_id'])){
                // Recupera rodada atual para log
                $rodadaAtual = (int)($row['cotacao_rodada'] ?? 1);
                $novaRodada = $rodadaAtual + 1;
                $upCot = $db->prepare('UPDATE cotacoes SET status="aberta", rodada=?, token_expira_em=DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE id=?');
                $upCot->execute([$novaRodada, (int)$row['cotacao_id']]);
                log_requisicao_event($db,(int)$row['requisicao_id'],'cotacao_rodada_alterada','Nova rodada de cotação iniciada',null,['cotacao_id'=>(int)$row['cotacao_id'],'de'=>$rodadaAtual,'para'=>$novaRodada]);
            }
        } catch(Throwable $e){ /* ignore */ }
    }
    log_requisicao_event($db,(int)$row['requisicao_id'], $novoStatus==='aceito'?'pedido_aceito':'pedido_rejeitado','Pedido '.$novoStatus,null,['pedido_id'=>$row['id'],'status'=>$novoStatus]);
    echo json_encode(['success'=>true,'status'=>$novoStatus]);
    exit;
}

http_response_code(405); echo json_encode(['erro'=>'Método não suportado']);
