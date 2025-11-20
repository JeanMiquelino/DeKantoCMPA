<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/rate_limit.php';
require_once __DIR__.'/../../includes/ranking.php'; // novo compartilhado

$u = auth_usuario();
if(!$u || ($u['tipo']??'') !== 'fornecedor'){
    http_response_code(403);
    echo json_encode(['erro'=>'Acesso restrito a fornecedores']);
    exit;
}

$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_ranking',120,300,true);

$cotacaoId = (int)($_GET['cotacao_id'] ?? 0);
if($cotacaoId <= 0){
    http_response_code(400);
    echo json_encode(['erro'=>'cotacao_id obrigatório']);
    exit;
}

// Fallback para usuários antigos sem fornecedor_id setado
$fornId = (int)($u['fornecedor_id'] ?? 0);
if($fornId<=0){
    try { $stF=$db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1'); $stF->execute([$u['id']]); $fid=(int)$stF->fetchColumn(); if($fid>0){ $fornId=$fid; $u['fornecedor_id']=$fid; } } catch(Throwable $e){ /* ignore */ }
}

try {
    $ordered = ranking_compute_for_cotacao($db,$cotacaoId);
    if(!$ordered){
        echo json_encode([
            'cotacao_id'=>$cotacaoId,
            'faixa'=>null,
            'mensagem'=>'Ainda não há propostas enviadas para ranking.'
        ]);
        exit;
    }
    $pos = ranking_position_for_fornecedor($ordered,$fornId);
    if($pos===null){
        echo json_encode([
            'cotacao_id'=>$cotacaoId,
            'faixa'=>null,
            'mensagem'=>'Você ainda não enviou uma proposta válida para esta cotação.'
        ]);
        exit;
    }
    [$faixa,$frase,$range] = ranking_band_from_position($pos);
    echo json_encode([
        'cotacao_id'=>$cotacaoId,
        'faixa'=>$faixa,
        'frase'=>$frase,
        'posicao_banda'=>$range,
        'total_participantes'=>count($ordered),
        'criterios'=>[
            'ordem'=>['menor_valor_total','incoterm_priority','maior_pagamento_dias','menor_prazo_entrega','id_asc'],
            'incoterm_priority_order'=>array_keys(ranking_incoterm_priority())
        ],
        'ultima_atualizacao'=>gmdate('c')
    ]);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao calcular ranking','detalhe'=>$e->getCode()?:null]);
}
