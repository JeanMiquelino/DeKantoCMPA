<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/rate_limit.php';
require_once __DIR__.'/../../includes/ranking.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor') { http_response_code(403); echo json_encode(['erro'=>'Acesso restrito']); exit; }
$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_ranking_hist',60,300,true);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){ echo json_encode([]); exit; }
$lim = isset($_GET['limite'])? max(1,min(30,(int)$_GET['limite'])):10;
$debugMode = isset($_GET['debug']);
$debug=[]; $mode=null; $fallbackUsed=false; $dynamicStatuses=[]; $dynamicTried=false; $dynamicFallbackUsed=false; $statusCounts=[];
$out=[];
try {
    // Levanta contagem de status (debug)
    if($debugMode){
        try { $stCnt=$db->prepare("SELECT status,COUNT(*) c FROM propostas WHERE fornecedor_id=? GROUP BY status"); $stCnt->execute([$fornecedorId]); $statusCounts=$stCnt->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Throwable $eSc){ $debug[]='cnt:'.$eSc->getMessage(); }
    }
    // Captura lista distinta de status usados pelo fornecedor (exclui vazios) para tentativa dinâmica
    try {
        $stDistinct=$db->prepare("SELECT DISTINCT status FROM propostas WHERE fornecedor_id=? AND status IS NOT NULL AND status<>'' LIMIT 15");
        $stDistinct->execute([$fornecedorId]);
        $dynamicStatuses = array_values(array_filter(array_map(fn($r)=> $r['status']??'', $stDistinct->fetchAll(PDO::FETCH_ASSOC))));
    } catch(Throwable $eDs){ $debug[]='distinct_status:'.$eDs->getMessage(); }

    // Estratégias de seleção de propostas (ordenadas da mais recente atividade para trás) com modos legacy
    $queries = [
        ["SELECT p.id,p.cotacao_id, COALESCE(p.updated_at,p.created_at,p.criado_em) AS atividade FROM propostas p WHERE p.fornecedor_id=? AND p.status IN ('enviada','aprovada') ORDER BY COALESCE(p.updated_at,p.created_at,p.criado_em,p.id) DESC LIMIT 120", 'std'],
        ["SELECT p.id,p.cotacao_id, p.criado_em AS atividade FROM propostas p WHERE p.fornecedor_id=? AND p.status IN ('enviada','aprovada') ORDER BY p.criado_em DESC LIMIT 120", 'criado_em'],
        ["SELECT p.id,p.cotacao_id, NULL AS atividade FROM propostas p WHERE p.fornecedor_id=? AND p.status IN ('enviada','aprovada') ORDER BY p.id DESC LIMIT 120", 'id_desc'],
    ];
    $rows=null;
    foreach($queries as [$sql,$m]){
        try { $st=$db->prepare($sql); $st->execute([$fornecedorId]); $tmp=$st->fetchAll(PDO::FETCH_ASSOC); if($tmp){ $rows=$tmp; $mode=$m; break; } } catch(Throwable $eQ){ $debug[]="modo_$m: ".$eQ->getMessage(); }
    }
    if($rows===null){ $rows=[]; }

    $vistos=[]; 
    foreach($rows as $r){
        $cId = (int)$r['cotacao_id'];
        if(!$cId || isset($vistos[$cId])) continue; $vistos[$cId]=true;
        try { $ordered = ranking_compute_for_cotacao($db,$cId); } catch(Throwable $eComp){ $debug[]='rank_comp_'.$cId.': '.$eComp->getMessage(); $ordered=[]; }
        if(!$ordered && $dynamicStatuses){
            // Tenta novamente com statuses dinâmicos (caso usem outros nomes de status)
            $dynamicTried=true;
            try { $ordered = ranking_compute_for_cotacao($db,$cId,$dynamicStatuses); } catch(Throwable $eDyn){ $debug[]='dyn_'.$cId.':'.$eDyn->getMessage(); }
        }
        if(!$ordered){
            // Fallback liberal: qualquer status
            try { $ordered = ranking_compute_for_cotacao_any_status($db,$cId); $fallbackUsed=true; } catch(Throwable $eAny){ $debug[]='any_'.$cId.':'.$eAny->getMessage(); $ordered=[]; }
        }
        if($ordered){
            $pos = ranking_position_for_fornecedor($ordered,$fornecedorId);
            if($pos!==null){
                try { [$b,$frase,$range] = ranking_band_from_position($pos); } catch(Throwable $eBand){ $debug[]='band_'.$cId.': '.$eBand->getMessage(); $b=''; $frase=''; $range=null; }
                $out[] = [ 'cotacao_id'=>$cId, 'proposta_id'=>(int)$r['id'], 'pos'=>$pos, 'banda'=>$b, 'frase'=>$frase, 'range'=>$range, 'atividade'=>$r['atividade'] ];
                if(count($out)>=$lim) break; 
            }
        }
    }

    // Se ainda não há histórico e há statuses diferentes dos padrões, tentar lista dinâmica diretamente
    if(!$out && $dynamicStatuses){
        $dynamicTried=true; $dynamicFallbackUsed=true; $debug[]='dynamic_direct';
        try {
            $stAny=$db->prepare("SELECT p.id,p.cotacao_id, COALESCE(p.updated_at,p.created_at,p.criado_em) AS atividade FROM propostas p WHERE p.fornecedor_id=? ORDER BY COALESCE(p.updated_at,p.created_at,p.criado_em,p.id) DESC LIMIT 120");
            $stAny->execute([$fornecedorId]);
            $rows2=$stAny->fetchAll(PDO::FETCH_ASSOC); $vistos=[];
            foreach($rows2 as $r){
                $cId=(int)$r['cotacao_id']; if(!$cId || isset($vistos[$cId])) continue; $vistos[$cId]=true;
                $ordered = ranking_compute_for_cotacao($db,$cId,$dynamicStatuses);
                if(!$ordered){ $ordered = ranking_compute_for_cotacao_any_status($db,$cId); }
                if($ordered){ $pos=ranking_position_for_fornecedor($ordered,$fornecedorId); if($pos!==null){ [$b,$frase,$range]=ranking_band_from_position($pos); $out[]=['cotacao_id'=>$cId,'proposta_id'=>(int)$r['id'],'pos'=>$pos,'banda'=>$b,'frase'=>$frase,'range'=>$range,'atividade'=>$r['atividade']]; if(count($out)>=$lim) break; } }
            }
        } catch(Throwable $eDyn2){ $debug[]='dynamic_direct_err:'.$eDyn2->getMessage(); }
    }

    // Ordena resultado final por atividade se existir
    usort($out, function($a,$b){ return strcmp((string)($b['atividade']??''),(string)($a['atividade']??'')); });

    if($debugMode){
        $out[] = [ '_debug'=>true, 'mode'=>$mode, 'detalhes'=>$debug, 'fallback'=>$fallbackUsed, 'status_counts'=>$statusCounts, 'dynamic_statuses'=>$dynamicStatuses, 'dynamic_tried'=>$dynamicTried, 'dynamic_direct_used'=>$dynamicFallbackUsed ];
    }
    echo json_encode($out);
} catch(Throwable $e){
    if($debugMode){ echo json_encode([['_debug'=>true,'erro'=>'Falha ao obter histórico','msg'=>$e->getMessage(),'detalhes'=>$debug,'fallback'=>$fallbackUsed,'status_counts'=>$statusCounts,'dynamic_statuses'=>$dynamicStatuses]]); }
    else { http_response_code(500); echo json_encode(['erro'=>'Falha ao obter histórico']); }
}
