<?php
// API de acompanhamento agregando status de uma requisição e seus derivados
// GET autenticado:  /api/acompanhar_requisicao.php?requisicao_id=123
// GET público via token: /api/acompanhar_requisicao.php?token=ABCDEFG
// Param opcional: timeline=1 para incluir últimos eventos da timeline
header('Content-Type: application/json; charset=utf-8');
// Security headers (public-capable endpoint)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/timeline.php';

$db = get_db_connection();
$token = trim($_GET['token'] ?? '');
$requisicao_id = (int)($_GET['requisicao_id'] ?? 0);
$withTimeline = isset($_GET['timeline']) && $_GET['timeline']=='1';
$publicoDetalhado = ($token !== '' && isset($_GET['publico_detalhado']) && $_GET['publico_detalhado']=='1');
if($publicoDetalhado){ $withTimeline = true; }

function sanitize_publico_detalhado(array &$payload): void {
    // Remove / mascara campos sensíveis
    // Requisição: remover cliente_id se existir (expor apenas status e titulo)
    if(isset($payload['requisicao'])){
        unset($payload['requisicao']['cliente_id']);
        // Opcional: outros campos internos
    }
    $fase = $payload['fase_atual'] ?? 'requisicao';
    // Cotações: remover campos possivelmente sensíveis (ex: fornecedor interno)
    if(isset($payload['cotacoes'])){
        foreach($payload['cotacoes'] as &$c){
            unset($c['fornecedor_id']);
        }
        unset($c);
    }
    // Propostas: mascarar valores até existir pedido
    $temPedido = !empty($payload['pedidos']);
    if(isset($payload['propostas'])){
        foreach($payload['propostas'] as &$p){
            unset($p['observacoes']);
            if(!$temPedido){
                if(isset($p['valor_total'])){ $p['valor_total_masked'] = true; $p['valor_total'] = null; }
            }
            // fornecedor_id pode ser considerado sensível
            unset($p['fornecedor_id']);
        }
        unset($p);
    }
    // Pedidos: expor somente subset
    if(isset($payload['pedidos'])){
        foreach($payload['pedidos'] as &$pd){
            $pd = [
                'id'=>$pd['id'],
                'status'=>$pd['status'] ?? null,
                'prazo_entrega'=>$pd['prazo_entrega'] ?? null,
                'pagamento_dias'=>$pd['pagamento_dias'] ?? null
            ];
        }
        unset($pd);
    }
    // Timeline já sanitizada parcialmente no endpoint; reforçar removendo ip/usuario
    if(isset($payload['timeline'])){
        foreach($payload['timeline'] as &$ev){
            unset($ev['ip_origem'],$ev['usuario_id']);
            // Remove diffs detalhados exceto eventos permitidos básicos
            unset($ev['dados_antes'],$ev['dados_depois']);
        }
        unset($ev);
    }
    // Stats: manter apenas contagens gerais
    if(isset($payload['stats'])){
        // nada a remover por enquanto
    }
    // Anexos: remover campos de arquivo
    if(isset($payload['anexos'])){
        foreach($payload['anexos'] as &$a){
            unset($a['caminho'],$a['mime'],$a['tamanho']);
        }
        unset($a);
    }
}


// Se vier token usamos acesso público; caso contrário exige login
if ($token === '') {
    $u = auth_usuario();
    if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Nao autenticado']); exit; }
    // Rate limit leve para acesso autenticado
    try { rate_limit_enforce($db, 'api.acompanhar_requisicao', 600, 300, true); } catch(Throwable $e){}
} else {
    // Rate limit público por IP quando usando token
    try {
        rate_limit_enforce($db, 'public.acompanhar_requisicao', 120, 300, true);
        // Bucket adicional parametrizado por token (hash) para mitigar scraping por token único
        $tHash = hash('sha256', $token);
        rate_limit_enforce($db, 'public.acompanhar_requisicao:' . $tHash, 60, 300, true);
        if($publicoDetalhado){
            rate_limit_enforce($db, 'public.acompanhar_requisicao.detalhado', 40, 300, true);
            rate_limit_enforce($db, 'public.acompanhar_requisicao.detalhado:' . $tHash, 20, 300, true);
        }
    } catch(Throwable $e){}
}

try {
    // === Localizar requisição ===
    if ($token !== '') {
        // Garantir que as colunas de tracking existam
        $cols = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'tracking_token'")->fetch();
        if(!$cols){
            // Se não existir aborta (admin ainda não aplicou migration)
            http_response_code(400);
            echo json_encode(['erro'=>'Tracking token indisponível']);
            exit;
        }
        $st = $db->prepare('SELECT * FROM requisicoes WHERE tracking_token=? AND (tracking_token_expira IS NULL OR tracking_token_expira > NOW())');
        $st->execute([$token]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if(!$req){
            // Log seguro de falha e resposta genérica
            try { seg_log_token_fail($db, 'public.acompanhar_requisicao', hash('sha256',$token), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
            http_response_code(404); header('Cache-Control: public, max-age=3600'); echo json_encode(['erro'=>'Não encontrado']); exit; }
        $requisicao_id = (int)$req['id'];
    } else {
        if($requisicao_id<=0){ echo json_encode(['erro'=>'requisicao_id obrigatório']); exit; }
        $st = $db->prepare('SELECT * FROM requisicoes WHERE id=?');
        $st->execute([$requisicao_id]);
        $req = $st->fetch(PDO::FETCH_ASSOC);
        if(!$req){ echo json_encode(['erro'=>'Requisição não encontrada']); exit; }
    }

    // Itens
    $st = $db->prepare('SELECT ri.*, p.nome AS produto_nome FROM requisicao_itens ri JOIN produtos p ON p.id=ri.produto_id WHERE ri.requisicao_id=?');
    $st->execute([$requisicao_id]);
    $itens = $st->fetchAll(PDO::FETCH_ASSOC);

    // Cotações
    $st = $db->prepare('SELECT * FROM cotacoes WHERE requisicao_id=? ORDER BY id ASC');
    $st->execute([$requisicao_id]);
    $cotacoes = $st->fetchAll(PDO::FETCH_ASSOC);
    $cotacoes_ids = array_column($cotacoes,'id');

    // Propostas
    $propostas = [];
    if($cotacoes_ids){
        $in = implode(',', array_fill(0,count($cotacoes_ids),'?'));
        $st = $db->prepare("SELECT * FROM propostas WHERE cotacao_id IN ($in) ORDER BY id ASC");
        $st->execute($cotacoes_ids);
        $propostas = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $propostas_ids = array_column($propostas,'id');

    // Pedidos
    $pedidos = [];
    if($propostas_ids){
        $in = implode(',', array_fill(0,count($propostas_ids),'?'));
        $st = $db->prepare("SELECT p.*, pr.valor_total, pr.fornecedor_id, pr.prazo_entrega, pr.pagamento_dias FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id WHERE p.proposta_id IN ($in) ORDER BY p.id ASC");
        $st->execute($propostas_ids);
        $pedidos = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $stats = [
        'itens' => count($itens),
        'cotacoes' => count($cotacoes),
        'propostas' => count($propostas),
        'propostas_aprovadas' => count(array_filter($propostas, fn($p)=> strtolower($p['status']??'')==='aprovada')),
        'pedidos' => count($pedidos),
    ];

    $fase = 'requisicao';
    if($pedidos){ $fase='pedido'; }
    elseif($propostas){ $fase='proposta'; }
    elseif($cotacoes){ $fase='cotacao'; }

    $payload = [
        'success'=>true,
        'fase_atual'=>$fase,
        'requisicao'=>$req,
        'itens'=>$itens,
        'cotacoes'=>$cotacoes,
        'propostas'=>$propostas,
        'pedidos'=>$pedidos,
        'stats'=>$stats,
        'publico'=> $token !== ''
    ];

    if($withTimeline){
        try {
            // Verifica se tabela existe
            $tlExists = $db->query("SHOW TABLES LIKE 'requisicoes_timeline'")->fetch();
            $timeline = [];
            if($tlExists){
                $st = $db->prepare('SELECT id, requisicao_id, tipo_evento, descricao, dados_antes, dados_depois, usuario_id, ip_origem, criado_em FROM requisicoes_timeline WHERE requisicao_id=? ORDER BY criado_em DESC, id DESC LIMIT 50');
                $st->execute([$requisicao_id]);
                $timeline = $st->fetchAll(PDO::FETCH_ASSOC);
                if($token !== ''){
                    // Sanitiza para público: remove ip, dados detalhados e ids de usuário
                    foreach($timeline as &$ev){
                        unset($ev['ip_origem']);
                        unset($ev['usuario_id']);
                        // Opcional: remover diffs internos para eventos de alteração de campos
                        if(in_array($ev['tipo_evento'], ['campo_alterado'])){
                            unset($ev['dados_antes']);
                            unset($ev['dados_depois']);
                        }
                    }
                    unset($ev);
                } else {
                    // Decodifica JSON para facilitar consumo interno
                    foreach($timeline as &$ev){
                        if(isset($ev['dados_antes']) && $ev['dados_antes']!==null){ $dec = json_decode($ev['dados_antes'],true); if($dec!==null) $ev['dados_antes']=$dec; }
                        if(isset($ev['dados_depois']) && $ev['dados_depois']!==null){ $dec = json_decode($ev['dados_depois'],true); if($dec!==null) $ev['dados_depois']=$dec; }
                    }
                    unset($ev);
                }
            }
            $payload['timeline'] = $timeline;
        } catch(Throwable $eT){
            $payload['timeline_erro'] = 'Nao foi possivel carregar timeline';
        }
    }

    // Anexos (se tabela existir)
    try {
        $axTab = $db->query("SHOW TABLES LIKE 'anexos'")->fetch();
        if($axTab){
            if($token !== ''){
                $stAx = $db->prepare('SELECT id, requisicao_id, tipo_ref, ref_id, nome_original, publico, criado_em FROM anexos WHERE requisicao_id=? AND publico=1 ORDER BY id DESC LIMIT 100');
                $stAx->execute([$requisicao_id]);
                $anexos = $stAx->fetchAll(PDO::FETCH_ASSOC);
                $payload['anexos'] = $anexos;
            } else {
                // Interno (admin / autenticado): retornamos dois buckets: anexos gerais + anexos privados de proposta (fornecedores)
                $stAxAll = $db->prepare('SELECT * FROM anexos WHERE requisicao_id=? AND (publico=1 OR tipo_ref<>"proposta") ORDER BY id DESC LIMIT 100');
                $stAxAll->execute([$requisicao_id]);
                $payload['anexos'] = $stAxAll->fetchAll(PDO::FETCH_ASSOC);
                // Bucket separado de anexos privados de propostas (publico=0, tipo_ref=proposta)
                $stAxPriv = $db->prepare('SELECT * FROM anexos WHERE requisicao_id=? AND tipo_ref="proposta" AND publico=0 ORDER BY id DESC LIMIT 100');
                $stAxPriv->execute([$requisicao_id]);
                $payload['anexos_privados_propostas'] = $stAxPriv->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch(Throwable $eAx){ /* ignore */ }

    if($publicoDetalhado){
        sanitize_publico_detalhado($payload);
        $payload['publico_detalhado']=true;
    }

    echo json_encode($payload);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao montar acompanhamento','detalhe'=>$e->getMessage()]);
}
