<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/ranking.php'; // novo
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    http_response_code(403);
    echo json_encode(['erro'=>'Acesso restrito a fornecedores','fornecedor_id'=>null]);
    exit;
}
$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_dashboard',120,300,true);
$debugMode = isset($_GET['debug']);
$debug = [];
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try {
        $stF=$db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid=(int)$stF->fetchColumn();
        if($fid>0){ $fornecedorId=$fid; $u['fornecedor_id']=$fid; }
    } catch(Throwable $e){ $debug[]='fallback_fornecedor: '.$e->getMessage(); }
}
if($fornecedorId<=0){
    echo json_encode([
        'abertas'=>0,
        'enviadas'=>0,
        'aprovadas'=>0,
        'ultimo_ranking'=>null,
        'posicao_frase'=>null,
        'fornecedor_id'=>0,
        'aviso'=>'Usuário sem vínculo de fornecedor (fornecedor_id vazio)',
        'debug'=>$debugMode? $debug: null
    ]);
    exit;
}
try {
    $abertas = 0; $enviadas=0; $aprovadas=0; $ultimoRanking=null; $posicaoFrase=null; $lastMode='std'; $dynamicStatuses=[]; $dynamicTried=false;
    // Cotações abertas
    try { $abertas = (int)$db->query("SELECT COUNT(*) FROM cotacoes WHERE status='aberta'")->fetchColumn(); }
    catch(Throwable $eA){ $debug[]='abertas: '.$eA->getMessage(); $abertas=0; }
    // Propostas enviadas
    try {
        $stEnv = $db->prepare("SELECT COUNT(*) FROM propostas WHERE fornecedor_id=? AND status IN ('enviada','aprovada')");
        $stEnv->execute([$fornecedorId]);
        $enviadas = (int)$stEnv->fetchColumn();
    } catch(Throwable $eEnv){ $debug[]='enviadas: '.$eEnv->getMessage(); }
    // Propostas aprovadas
    try {
        $stApr = $db->prepare("SELECT COUNT(*) FROM propostas WHERE fornecedor_id=? AND status='aprovada'");
        $stApr->execute([$fornecedorId]);
        $aprovadas = (int)$stApr->fetchColumn();
    } catch(Throwable $eApr){ $debug[]='aprovadas: '.$eApr->getMessage(); }

    // Distinct statuses dinâmicos para fallback de ranking
    try {
        $stDistinct=$db->prepare("SELECT DISTINCT status FROM propostas WHERE fornecedor_id=? AND status IS NOT NULL AND status<>'' LIMIT 15");
        $stDistinct->execute([$fornecedorId]);
        $dynamicStatuses = array_values(array_filter(array_map(fn($r)=> $r['status']??'', $stDistinct->fetchAll(PDO::FETCH_ASSOC))));
    } catch(Throwable $eDs){ $debug[]='distinct_status:'.$eDs->getMessage(); }

    // Última proposta para ranking (compatibilidade com schemas antigos sem created_at/updated_at)
    $last = null;
    try {
        $stLast = $db->prepare("SELECT p.id,p.cotacao_id,p.updated_at,p.created_at,p.criado_em FROM propostas p WHERE p.fornecedor_id=? ORDER BY COALESCE(p.updated_at,p.created_at,p.criado_em,p.id) DESC LIMIT 1");
        $stLast->execute([$fornecedorId]);
        $last = $stLast->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch(Throwable $eLast){ $debug[]='last_proposta_std: '.$eLast->getMessage(); }
    if(!$last){ $debug[]='sem_ultima_proposta'; }

    if($last){
        $cotId=(int)$last['cotacao_id'];
        try {
            $ordered = ranking_compute_for_cotacao($db,$cotId); // padrão
            if(!$ordered && $dynamicStatuses){
                $dynamicTried=true; $ordered = ranking_compute_for_cotacao($db,$cotId,$dynamicStatuses); // tenta com dinâmicos
            }
            if(!$ordered){
                // liberal
                $ordered = ranking_compute_for_cotacao_any_status($db,$cotId);
            }
            if($ordered){
                $pos = ranking_position_for_fornecedor($ordered,$fornecedorId);
                if($pos!==null){
                    try { [$b,$frase,$range]=ranking_band_from_position($pos); $ultimoRanking=$b; $posicaoFrase=$frase; } catch(Throwable $eBand){ $debug[]='band_calc: '.$eBand->getMessage(); }
                } else { $debug[]='pos_null_for_last_cotacao'; }
            } else { $debug[]='ordered_vazio_para_cotacao_'.$cotId; }
        } catch(Throwable $eRank){ $debug[]='ranking: '.$eRank->getMessage(); }
    }
    echo json_encode([
        'abertas'=>$abertas,
        'enviadas'=>$enviadas,
        'aprovadas'=>$aprovadas,
        'ultimo_ranking'=>$ultimoRanking,
        'posicao_frase'=>$posicaoFrase,
        'fornecedor_id'=>$fornecedorId,
        'last_mode'=>$debugMode? $lastMode: null,
        'dynamic_statuses'=>$debugMode? $dynamicStatuses: null,
        'dynamic_tried'=>$debugMode? $dynamicTried: null,
        'debug'=>$debugMode? $debug: null
    ]);
} catch(Throwable $e){
    if($debugMode){
        echo json_encode([
            'abertas'=>0,
            'enviadas'=>0,
            'aprovadas'=>0,
            'ultimo_ranking'=>null,
            'posicao_frase'=>null,
            'fornecedor_id'=>$fornecedorId,
            'erro'=>'Falha geral',
            'msg'=>$e->getMessage(),
            'debug'=>$debug
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['erro'=>'Falha ao obter dashboard','detalhe'=>$e->getCode()?:null,'fornecedor_id'=>$fornecedorId]);
    }
}
