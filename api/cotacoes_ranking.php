<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$db = get_db_connection();
// Rate limit para chamadas de ranking
try { rate_limit_enforce($db, 'api.cotacoes_ranking', 300, 300, true); } catch(Throwable $e){}

$requisicao_id = (int)($_GET['requisicao_id'] ?? 0);
if(!$requisicao_id){ http_response_code(400); echo json_encode(['erro'=>'requisicao_id obrigatório']); exit; }

$u = auth_usuario();
if(($u['tipo'] ?? null) === 'cliente'){
    // Restringe ranking à requisicao do cliente
    $stChk = $db->prepare('SELECT 1 FROM requisicoes WHERE id=? AND cliente_id=?');
    $stChk->execute([$requisicao_id, (int)($u['cliente_id'] ?? 0)]);
    if(!$stChk->fetch()){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); exit; }
}

// Pesos default (poderão vir de configuracoes futuramente)
$pesoPreco = 0.6; $pesoPrazo = 0.25; $pesoPagamento = 0.15;
try {
    $cfg = $db->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('peso_preco','peso_prazo','peso_pagamento')")->fetchAll(PDO::FETCH_KEY_PAIR);
    if(isset($cfg['peso_preco'])) $pesoPreco = (float)$cfg['peso_preco'];
    if(isset($cfg['peso_prazo'])) $pesoPrazo = (float)$cfg['peso_prazo'];
    if(isset($cfg['peso_pagamento'])) $pesoPagamento = (float)$cfg['peso_pagamento'];
} catch(Throwable $e){}

$sql = "SELECT pr.id as proposta_id, pr.valor_total, pr.prazo_entrega, pr.pagamento_dias, pr.fornecedor_id,
               pr.status AS proposta_status,
               c.id AS cotacao_id,
               COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome
        FROM propostas pr
        JOIN cotacoes c ON c.id = pr.cotacao_id
        LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
        WHERE c.requisicao_id = ?";
$st = $db->prepare($sql); $st->execute([$requisicao_id]);
$propostas = $st->fetchAll(PDO::FETCH_ASSOC);
if(!$propostas){ echo json_encode([]); exit; }

$precoMin = min(array_column($propostas,'valor_total'));
$prazoVals = array_filter(array_column($propostas,'prazo_entrega'), fn($v)=>$v!==null && $v!=='');
$prazoMin = $prazoVals? min($prazoVals): null;

$resultado = [];
foreach($propostas as $p){
    $precoScore = $precoMin>0 ? ($precoMin / max((float)$p['valor_total'],0.0001)) : 0;
    $prazoScore = ($prazoMin && $p['prazo_entrega']) ? ($prazoMin / max((float)$p['prazo_entrega'],1)) : 0;
    $pag = (int)($p['pagamento_dias'] ?? 0); // fator pagamento
    if($pag<=30) $fatorPag = 1; elseif($pag<=45) $fatorPag=0.8; elseif($pag<=60) $fatorPag=0.6; else $fatorPag=0.4;
    $score = ($precoScore * $pesoPreco) + ($prazoScore * $pesoPrazo) + ($fatorPag * $pesoPagamento);
    $resultado[] = [
        'proposta_id'=>$p['proposta_id'],
        'cotacao_id'=>$p['cotacao_id'],
        'status'=>$p['proposta_status'],
        'fornecedor_id'=>$p['fornecedor_id'],
        'fornecedor_nome'=>$p['fornecedor_nome'] ?? null,
        'valor_total'=>(float)$p['valor_total'],
        'prazo_entrega'=>$p['prazo_entrega'],
        'pagamento_dias'=>$p['pagamento_dias'],
        'score'=>round($score,4),
        'componentes'=>[
            'preco'=>round($precoScore,4),
            'prazo'=>round($prazoScore,4),
            'pagamento'=>$fatorPag
        ]
    ];
}
usort($resultado, fn($a,$b)=> $b['score']<=>$a['score']);

// Registrar evento de ranking (uma única vez por chamada)
try { log_requisicao_event($db,$requisicao_id,'ranking_gerado','Ranking de cotações gerado',null,['total'=>count($resultado)]); } catch(Throwable $e){}

echo json_encode($resultado);
