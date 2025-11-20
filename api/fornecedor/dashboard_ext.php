<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
require_once __DIR__ . '/../../includes/ranking.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor') { http_response_code(403); echo json_encode(['erro'=>'Acesso restrito']); exit; }
$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_dashboard_ext',120,300,true);
$debugMode = isset($_GET['debug']);
$fastMode = isset($_GET['fast']);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try { $stF=$db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1'); $stF->execute([$u['id']]); $fid=(int)$stF->fetchColumn(); if($fid>0) $fornecedorId=$fid; } catch(Throwable $e){ /* ignore */ }
}
if($fornecedorId<=0){ echo json_encode(['erro'=>'Fornecedor não vinculado','fornecedor_id'=>0]); exit; }
$debug=[]; $diag=[];
function col_exists(PDO $db,string $table,string $col): bool { static $cache=[]; $k=$table.'|'.$col; if(isset($cache[$k])) return $cache[$k]; try { $st=$db->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); $cache[$k]=(bool)$st->fetch(); return $cache[$k]; } catch(Throwable $e){ return false; } }
$out = [ 'fornecedor_id'=>$fornecedorId ];
try {
    // ===== Coleta distribuição de status =====
    $statusCounts=[]; $distinctStatuses=[];
    try {
        $st=$db->prepare("SELECT status,COUNT(*) c FROM propostas WHERE fornecedor_id=? GROUP BY status");
        $st->execute([$fornecedorId]);
        while($r=$st->fetch(PDO::FETCH_ASSOC)){
            $statusCounts[$r['status']??'']= (int)$r['c'];
            if(($r['status']??'')!=='') $distinctStatuses[]=$r['status'];
        }
    } catch(Throwable $e){ $debug[]='statusCounts:'.$e->getMessage(); }
    $distinctStatuses = array_values(array_unique($distinctStatuses));

    // Métricas básicas
    try { $out['abertas'] = (int)$db->query("SELECT COUNT(*) FROM cotacoes WHERE status='aberta'")->fetchColumn(); } catch(Throwable $e){ $debug[]='abertas:'.$e->getMessage(); $out['abertas']=0; }
    try { $st=$db->prepare("SELECT COUNT(*) FROM propostas WHERE fornecedor_id=? AND LOWER(status) IN ('enviada','aprovada','respondida','ganha','vencedora')"); $st->execute([$fornecedorId]); $out['enviadas']=(int)$st->fetchColumn(); } catch(Throwable $e){ $debug[]='enviadas:'.$e->getMessage(); $out['enviadas']=0; }
    try { $st=$db->prepare("SELECT COUNT(*) FROM propostas WHERE fornecedor_id=? AND LOWER(status) IN ('aprovada','ganha','vencedora')"); $st->execute([$fornecedorId]); $out['aprovadas']=(int)$st->fetchColumn(); } catch(Throwable $e){ $debug[]='aprovadas:'.$e->getMessage(); $out['aprovadas']=0; }
    try { $st=$db->prepare("SELECT COUNT(*) FROM propostas WHERE fornecedor_id=?"); $st->execute([$fornecedorId]); $out['propostas_totais']=(int)$st->fetchColumn(); } catch(Throwable $e){ $debug[]='totais:'.$e->getMessage(); $out['propostas_totais']=0; }
    $out['aprovacao_rate'] = ($out['enviadas']??0)>0 ? round(($out['aprovadas']/$out['enviadas'])*100,1) : 0.0;

    try { $st=$db->prepare("SELECT AVG(valor_total) FROM propostas WHERE fornecedor_id=? AND LOWER(status) IN ('aprovada','ganha','vencedora') AND valor_total IS NOT NULL"); $st->execute([$fornecedorId]); $out['ticket_medio_aprovado'] = (float)$st->fetchColumn(); } catch(Throwable $e){ $debug[]='ticket:'.$e->getMessage(); $out['ticket_medio_aprovado']=0; }
    try { $st=$db->prepare("SELECT AVG(prazo_entrega) FROM propostas WHERE fornecedor_id=? AND prazo_entrega IS NOT NULL"); $st->execute([$fornecedorId]); $out['prazo_medio'] = (float)$st->fetchColumn(); } catch(Throwable $e){ $debug[]='prazo:'.$e->getMessage(); $out['prazo_medio']=0; }

    // Últimas propostas (limit maior se não fast, menor se fast) + Fallback de coluna alternativa
    // ===== Preparar colunas dinâmicas =====
    $hasUpdProposta = col_exists($db,'propostas','updated_at');
    $hasCreatedProposta = col_exists($db,'propostas','created_at');
    $hasCriadoEmProposta = col_exists($db,'propostas','criado_em');
    $tsParts=[]; if($hasUpdProposta) $tsParts[]='p.updated_at'; if($hasCreatedProposta) $tsParts[]='p.created_at'; if($hasCriadoEmProposta) $tsParts[]='p.criado_em';
    if(!$tsParts) $tsParts[]='p.id';
    $propostaTsExpr = 'COALESCE('.implode(',',$tsParts).')';

    $hasTituloCot = col_exists($db,'cotacoes','titulo');
    $hasNomeCot = col_exists($db,'cotacoes','nome');
    $hasDescricaoCot = col_exists($db,'cotacoes','descricao');
    $hasAssuntoCot = col_exists($db,'cotacoes','assunto');
    $cotTitleExpr = null;
    foreach(['c.titulo','c.nome','c.descricao','c.assunto'] as $cand){
        $col = explode('.', $cand)[1];
        if(($col==='titulo' && $hasTituloCot) || ($col==='nome' && $hasNomeCot) || ($col==='descricao' && $hasDescricaoCot) || ($col==='assunto' && $hasAssuntoCot)) { $cotTitleExpr=$cand; break; }
    }
    if(!$cotTitleExpr) $cotTitleExpr="''"; // fallback vazio

    $limitUlt = $fastMode? 40:120;
    $propostas=[]; $altCol=false; $colIdFornecedorExists = col_exists($db,'propostas','id_fornecedor');
    try {
        $st=$db->prepare("SELECT p.id,p.cotacao_id,p.valor_total,p.status, $propostaTsExpr AS atividade FROM propostas p WHERE p.fornecedor_id=? ORDER BY $propostaTsExpr DESC, p.id DESC LIMIT $limitUlt");
        $st->execute([$fornecedorId]);
        $propostas=$st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e){ $debug[]='ultimas:'.$e->getMessage(); $propostas=[]; }
    if(!$propostas && $colIdFornecedorExists){
        try {
            $altCol=true;
            $st=$db->prepare("SELECT p.id,p.cotacao_id,p.valor_total,p.status, $propostaTsExpr AS atividade FROM propostas p WHERE p.id_fornecedor=? ORDER BY $propostaTsExpr DESC, p.id DESC LIMIT $limitUlt");
            $st->execute([$fornecedorId]);
            $propostas=$st->fetchAll(PDO::FETCH_ASSOC);
            $diag['fallback_col_id_fornecedor']=true;
        } catch(Throwable $e2){ $debug[]='ultimas_alt:'.$e2->getMessage(); }
    }

    $rankingMap=[]; $melhorPos=null; $top5Count=0; $rankingSeries=[]; $rankingAttempts=0; $fallbackDynamicUsed=false; $fallbackAnyUsed=false; $rankingSimpleFallback=false;
    $ultimasLista=[];

    if(!$fastMode){
        $dynamicStatuses = array_map(fn($s)=> strtolower(trim($s)), $distinctStatuses);
        $dynamicStatuses = array_values(array_filter(array_unique($dynamicStatuses)));
        // Se ainda vazio tenta levantar distinct ignorando fornecedor (situação inicial)
        if(!$dynamicStatuses){
            try { $rs=$db->query("SELECT DISTINCT LOWER(status) s FROM propostas WHERE status IS NOT NULL AND status<>'' LIMIT 15"); $dynamicStatuses=array_values(array_filter(array_map(fn($r)=> $r['s']??'', $rs->fetchAll(PDO::FETCH_ASSOC)))); $diag['dynamic_from_global']=true; } catch(Throwable $eDs){ $debug[]='dyn_global:'.$eDs->getMessage(); }
        }
        $defaultStatuses = ['enviada','aprovada','respondida','ganha','vencedora'];
        $cotIds=[]; foreach($propostas as $p){ $cid=(int)$p['cotacao_id']; if($cid>0) $cotIds[$cid]=true; }
        foreach(array_keys($cotIds) as $cid){
            $ordered = ranking_compute_for_cotacao($db,$cid,$defaultStatuses); $rankingAttempts++;
            if(!$ordered && $dynamicStatuses){
                $ordered = ranking_compute_for_cotacao($db,$cid,$dynamicStatuses); $fallbackDynamicUsed = $fallbackDynamicUsed || (bool)$ordered; $rankingAttempts++;
            }
            if(!$ordered){
                $ordered = ranking_compute_for_cotacao_any_status($db,$cid); $fallbackAnyUsed = $fallbackAnyUsed || (bool)$ordered; $rankingAttempts++;
            }
            if(!$ordered) continue;
            foreach($ordered as $i=>$row){ if((int)$row['fornecedor_id']===$fornecedorId){ $pos=$i+1; $rankingMap[$cid]=$pos; if($melhorPos===null || $pos<$melhorPos) $melhorPos=$pos; if($pos<=5) $top5Count++; break; } }
        }
        foreach($propostas as $p){ $cid=(int)$p['cotacao_id']; if(!isset($rankingMap[$cid])) continue; $rankingSeries[]=[ 'cotacao_id'=>$cid, 'proposta_id'=>(int)$p['id'], 'pos'=>$rankingMap[$cid], 'atividade'=>$p['atividade'] ]; if(count($rankingSeries)>=25) break; }
        // Fallback extra: se ainda vazio, monta uma série simples apenas listando cotações distintas (pos desconhecida)
        if(!$rankingSeries && $propostas){
            $rankingSimpleFallback=true;
            foreach($propostas as $p){ $cid=(int)$p['cotacao_id']; if($cid<=0) continue; $rankingSeries[]=[ 'cotacao_id'=>$cid, 'proposta_id'=>(int)$p['id'], 'pos'=>null, 'atividade'=>$p['atividade'] ]; if(count($rankingSeries)>=min(10,count($propostas))) break; }
        }
    } else {
        $debug[]='FAST_MODE_ATIVO';
    }

    foreach(array_slice($propostas,0,10) as $p){ $cid=(int)$p['cotacao_id']; $ultimasLista[]=[ 'id'=>(int)$p['id'], 'cotacao_id'=>$cid, 'valor_total'=>$p['valor_total'], 'status'=>$p['status'], 'pos'=>$rankingMap[$cid]??null, 'atividade'=>$p['atividade'] ]; }

    $out['melhor_posicao']=$melhorPos; $out['top5_count']=$top5Count; $out['ranking_series']=$rankingSeries; $out['ultimas_propostas']=$ultimasLista;

    // ===== Oportunidades =====
    try {
        // Status considerados "abertos"
        $openStatusCandidates = ['aberta','aberto','em_aberto','andamento','ativa','ativo','open'];
        $stTmp = $db->query("SELECT DISTINCT LOWER(status) s FROM cotacoes WHERE status IS NOT NULL AND status<>''");
        $cotStatuses = array_map(fn($r)=> $r['s'], $stTmp->fetchAll(PDO::FETCH_ASSOC));
        $usableOpen = array_values(array_intersect($openStatusCandidates,$cotStatuses));
        if(!$usableOpen) $usableOpen=['aberta'];
        $rascunhoStatuses = ['rascunho','draft'];
        $inOpen = str_repeat('?,', count($usableOpen)-1).'?';
        $inRasc = str_repeat('?,', count($rascunhoStatuses)-1).'?';

        // Query corrigida: parâmetros na ordem (open statuses..., fornecedorId, rascunho statuses...)
        $sqlOpp = "SELECT c.id, $cotTitleExpr AS titulo, COALESCE(c.data_limite,c.id) AS limite
                    FROM cotacoes c
                    WHERE LOWER(c.status) IN ($inOpen)
                      AND NOT EXISTS(SELECT 1 FROM propostas p
                                      WHERE p.cotacao_id=c.id
                                        AND p.fornecedor_id=?
                                        AND LOWER(p.status) NOT IN ($inRasc))
                    ORDER BY COALESCE(c.data_limite,c.id) ASC
                    LIMIT 6";
        $paramsExec = array_map('strtolower',$usableOpen);
        $paramsExec[] = $fornecedorId;
        foreach($rascunhoStatuses as $rs) $paramsExec[] = strtolower($rs);
        $st=$db->prepare($sqlOpp);
        $st->execute($paramsExec);
        $oportunidades=$st->fetchAll(PDO::FETCH_ASSOC);

        // Fallback 1: se vazio, ignorar rascunho (mostrar mesmo que só exista proposta rascunho)
        if(!$oportunidades){
            $diag['oportunidades_fallback_sem_rasc']=true;
            $sqlOpp2 = "SELECT c.id, $cotTitleExpr AS titulo, COALESCE(c.data_limite,c.id) AS limite
                        FROM cotacoes c
                        WHERE LOWER(c.status) IN ($inOpen)
                        ORDER BY COALESCE(c.data_limite,c.id) ASC LIMIT 6";
            $st=$db->prepare($sqlOpp2);
            $st->execute(array_map('strtolower',$usableOpen));
            $oportunidades=$st->fetchAll(PDO::FETCH_ASSOC);
        }
        // Fallback 2: requisicoes abertas (se tabela existir) sem proposta válida
        if(!$oportunidades && col_exists($db,'requisicoes','status')){
            $diag['oportunidades_from_requisicoes']=true;
            try {
                $inOpenReq = $inOpen; // reutiliza mesmos status
                $sqlReq = "SELECT r.id AS id, COALESCE(r.titulo,r.nome,r.descricao, CONCAT('Requisição #',r.id)) AS titulo, COALESCE(r.data_limite,r.id) AS limite
                           FROM requisicoes r
                           WHERE LOWER(r.status) IN ($inOpenReq)
                           ORDER BY COALESCE(r.data_limite,r.id) ASC LIMIT 6";
                $st=$db->prepare($sqlReq);
                $st->execute(array_map('strtolower',$usableOpen));
                $oportunidades=$st->fetchAll(PDO::FETCH_ASSOC);
            } catch(Throwable $eR){ $debug[]='req_fallback:'.$eR->getMessage(); }
        }
        $out['oportunidades']=$oportunidades;
        $out['diag']['op_status_usados']=$usableOpen;
        $out['diag']['op_param_order']='open...,fornecedor,rascunhos';
    } catch(Throwable $e){ $debug[]='oportunidades:'.$e->getMessage(); $out['oportunidades']=[]; }

    try {
        $st=$db->prepare("SELECT c.tipo_frete AS incoterm, COUNT(*) c FROM cotacoes c JOIN propostas p ON p.cotacao_id=c.id AND p.fornecedor_id=? WHERE c.tipo_frete IS NOT NULL AND c.tipo_frete<>'' GROUP BY c.tipo_frete");
        $st->execute([$fornecedorId]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC); $dist=[]; foreach($rows as $r){ $dist[strtoupper($r['incoterm'])]=(int)$r['c']; }
        $out['incoterm_dist']=$dist;
    } catch(Throwable $e){ $debug[]='incoterm:'.$e->getMessage(); $out['incoterm_dist']=[]; }

    if(!$fastMode){
        try {
            $st=$db->prepare("SELECT p.id,p.cotacao_id, p.valor_total, $propostaTsExpr AS atividade FROM propostas p WHERE p.fornecedor_id=? AND LOWER(p.status) IN ('enviada','aprovada','respondida') AND $propostaTsExpr < DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY atividade DESC LIMIT 10");
            $st->execute([$fornecedorId]);
            $out['pendentes']=$st->fetchAll(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $debug[]='pendentes:'.$e->getMessage(); $out['pendentes']=[]; }
    } else { $out['pendentes']=[]; }

    $ultimoRanking=null; $posicaoFrase=null;
    if($melhorPos!==null){
        try { [$b,$frase,$range] = ranking_band_from_position($melhorPos); $ultimoRanking=$b; $posicaoFrase=$frase; } catch(Throwable $eBand){ $debug[]='band:'.$eBand->getMessage(); }
    }
    $out['ultimo_ranking']=$ultimoRanking; $out['posicao_frase']=$posicaoFrase;

    $out['metrics'] = [
        'abertas'=>$out['abertas'],
        'enviadas'=>$out['enviadas'],
        'aprovadas'=>$out['aprovadas'],
        'aprovacao_rate'=>$out['aprovacao_rate'],
        'ticket_medio_aprovado'=>$out['ticket_medio_aprovado'],
        'prazo_medio'=>$out['prazo_medio'],
        'melhor_posicao'=>$out['melhor_posicao'],
        'top5_count'=>$out['top5_count']
    ];
    if($debugMode){
        $out['debug']=$debug;
        $out['status_counts']=$statusCounts;
        $out['distinct_statuses']=$distinctStatuses;
        $out['fast_mode']=$fastMode;
        $out['diag']=$diag;
        $out['diag']['ts_expr']=$propostaTsExpr;
        $out['diag']['cot_title_expr']=$cotTitleExpr;
        $out['diag']['alt_col_used']=$altCol;
        if(!$fastMode){
            $out['ranking_debug']=[
                'ranking_map_vazio'=>empty($rankingMap),
                'ranking_attempts'=>$rankingAttempts,
                'fallback_dynamic_used'=>$fallbackDynamicUsed,
                'fallback_any_used'=>$fallbackAnyUsed,
                'simple_fallback_used'=>$rankingSimpleFallback,
                'propostas_sem_ranking'=>count(array_filter($ultimasLista,fn($x)=> $x['pos']===null))
            ];
        }
    }
    echo json_encode($out);
} catch(Throwable $e){ if($debugMode){ echo json_encode(['erro'=>'Falha geral','msg'=>$e->getMessage(),'debug'=>$debug,'diag'=>$diag,'fast_mode'=>$fastMode]); } else { http_response_code(500); echo json_encode(['erro'=>'Falha ao montar dashboard']); } }
