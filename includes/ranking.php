<?php
// Funções compartilhadas de ranking de propostas
// Critérios: menor valor_total, prioridade incoterm, maior pagamento_dias, menor prazo_entrega, menor id

function ranking_incoterm_priority(): array {
    return [ 'DDP'=>1,'DAP'=>2,'CIF'=>3,'FOB'=>4,'EXW'=>5 ];
}

function ranking_incoterm_score(?string $v): int {
    if(!$v) return 99; $v = strtoupper(trim($v)); $map = ranking_incoterm_priority(); return $map[$v] ?? 98;
}

function ranking_pagamento_score($dias): int {
    if($dias===null || $dias==='') return 99999; return (int)$dias; // maior é melhor, porém sorteio invertido no comparador
}

function ranking_compare(array $a,array $b): int {
    $va=(float)($a['valor_total']??0); $vb=(float)($b['valor_total']??0); if($va!==$vb) return $va<=>$vb; // menor primeiro
    $ia=ranking_incoterm_score($a['tipo_frete']??''); $ib=ranking_incoterm_score($b['tipo_frete']??''); if($ia!==$ib) return $ia<=>$ib; // menor score = prioridade
    $pa=ranking_pagamento_score($a['pagamento_dias']); $pb=ranking_pagamento_score($b['pagamento_dias']); if($pa!==$pb) return $pb<=>$pa; // maior melhor
    $pea=(int)($a['prazo_entrega']??0); $peb=(int)($b['prazo_entrega']??0); if($pea!==$peb) return $pea<=>$peb; // menor melhor
    return ((int)$a['id'])<=>((int)$b['id']);
}

function ranking_band_from_position(int $pos): array { // returns [banda, frase, range|null]
    if($pos<=3) return ['top3','Entre os 3 primeiros',['min'=>1,'max'=>3]];
    if($pos<=5) return ['top5','Entre os 5 primeiros',['min'=>4,'max'=>5]];
    if($pos<=10) return ['top10','Entre os 10 primeiros',['min'=>6,'max'=>10]];
    if($pos<=20) return ['top20','Entre os 20 primeiros',['min'=>11,'max'=>20]];
    return ['acima20','Não está entre os 20 Primeiros',null];
}

function ranking_table_has_tipo_frete(PDO $db): bool {
    static $cache=null; if($cache!==null) return $cache; try { $r=$db->query("SHOW COLUMNS FROM cotacoes LIKE 'tipo_frete'")->fetch(); $cache = $r? true:false; } catch(Throwable $e){ $cache=false; } return $cache;
}

// NOVO: agora aceita lista opcional de statuses. Se null usa padrão ['enviada','aprovada'].
function ranking_compute_for_cotacao(PDO $db,int $cotacaoId, ?array $statuses=null): array {
    $rows = [];
    $hasTipo = ranking_table_has_tipo_frete($db);
    if($statuses===null || !$statuses){ $statuses=['enviada','aprovada']; }
    // Sanitiza e remove duplicados
    $statuses = array_values(array_unique(array_filter(array_map(fn($s)=> trim((string)$s), $statuses))));
    // Evita lista vazia que quebraria a consulta
    if(!$statuses){ $statuses=['enviada','aprovada']; }
    // Placeholders dinâmicos
    $placeholders = implode(',', array_fill(0,count($statuses),'?'));
    try {
        if($hasTipo){
            $sql = 'SELECT p.id,p.fornecedor_id,p.valor_total,c.tipo_frete,p.pagamento_dias,p.prazo_entrega,p.status FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.cotacao_id=? AND p.status IN (' . $placeholders . ')';
        } else {
            $sql = 'SELECT p.id,p.fornecedor_id,p.valor_total, "" AS tipo_frete, p.pagamento_dias,p.prazo_entrega,p.status FROM propostas p WHERE p.cotacao_id=? AND p.status IN (' . $placeholders . ')';
        }
        $params = array_merge([$cotacaoId], $statuses);
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e){ return []; }
    usort($rows,'ranking_compare');
    return $rows; // ordered
}

// NOVO: versão liberal (qualquer status) usada apenas para fallback de histórico.
function ranking_compute_for_cotacao_any_status(PDO $db,int $cotacaoId): array {
    $rows=[]; $hasTipo = ranking_table_has_tipo_frete($db);
    try {
        if($hasTipo){
            $sql='SELECT p.id,p.fornecedor_id,p.valor_total,c.tipo_frete,p.pagamento_dias,p.prazo_entrega,p.status FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.cotacao_id=?';
        } else {
            $sql='SELECT p.id,p.fornecedor_id,p.valor_total, "" AS tipo_frete,p.pagamento_dias,p.prazo_entrega,p.status FROM propostas p WHERE p.cotacao_id=?';
        }
        $st=$db->prepare($sql); $st->execute([$cotacaoId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e){ return []; }
    usort($rows,'ranking_compare');
    return $rows;
}

function ranking_position_for_fornecedor(array $ordered,int $fornecedorId): ?int {
    foreach($ordered as $i=>$r){ if((int)$r['fornecedor_id']===$fornecedorId) return $i+1; }
    return null;
}
