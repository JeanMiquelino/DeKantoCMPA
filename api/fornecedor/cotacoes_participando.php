<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    http_response_code(403);
    echo json_encode(['erro'=>'Acesso restrito a fornecedores']);
    exit;
}
$db = get_db_connection();
rate_limit_enforce($db,'api/fornecedor_cotacoes_participando',180,300,true);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try {
        $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid = (int)$stF->fetchColumn();
        if($fid>0){ $fornecedorId = $fid; $u['fornecedor_id']=$fid; }
    } catch(Throwable $e){ /* ignora */ }
}
if($fornecedorId<=0){
    echo json_encode(['data'=>[], 'meta'=>['page'=>1,'per_page'=>20,'total'=>0,'total_pages'=>1]]);
    exit;
}

$hasPosColumn = true;
try { $db->query('SELECT pos FROM propostas LIMIT 1'); }
catch(Throwable $e){ $hasPosColumn = false; }

try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $statusCotacao = trim((string)($_GET['status_cotacao'] ?? ''));
    $statusProposta = trim((string)($_GET['status_proposta'] ?? ''));

    $where = [];
    $paramsFilters = [];
    if($statusCotacao !== ''){
        $where[] = 'LOWER(c.status)=?';
        $paramsFilters[] = strtolower($statusCotacao);
    }
    if($statusProposta !== ''){
        if($statusProposta === '__pendente'){
            $where[] = '(lp.status IS NULL OR lp.status="")';
        } else {
            $where[] = 'LOWER(lp.status)=?';
            $paramsFilters[] = strtolower($statusProposta);
        }
    }
    if($busca !== ''){
        $like = '%' . $busca . '%';
        $where[] = '(CAST(c.id AS CHAR) LIKE ? OR CAST(c.requisicao_id AS CHAR) LIKE ? OR LOWER(lp.status) LIKE ?)';
        $paramsFilters[] = $like;
        $paramsFilters[] = $like;
        $paramsFilters[] = '%' . strtolower($busca) . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $posSelect = $hasPosColumn ? 'p1.pos' : 'NULL';
    $latestSub = "SELECT p1.id,p1.cotacao_id,p1.status,p1.valor_total,$posSelect AS pos
                  FROM propostas p1
                  JOIN (
                      SELECT cotacao_id, MAX(id) AS max_id
                      FROM propostas
                      WHERE fornecedor_id=?
                      GROUP BY cotacao_id
                  ) latest ON latest.cotacao_id = p1.cotacao_id AND latest.max_id = p1.id
                  WHERE p1.fornecedor_id=?";

    $baseSql = "FROM (" . $latestSub . ") lp
                JOIN cotacoes c ON c.id = lp.cotacao_id";

    $countParams = array_merge([$fornecedorId,$fornecedorId], $paramsFilters);
    $countSql = "SELECT COUNT(*) $baseSql $whereSql";
    $stCount = $db->prepare($countSql);
    $stCount->execute($countParams);
    $total = (int)$stCount->fetchColumn();

    $limitClause = ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $dataParams = array_merge([$fornecedorId,$fornecedorId], $paramsFilters);
    $rows = [];
    try {
        $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.tipo_frete,c.criado_em,
                       lp.id AS minha_proposta_id,lp.status AS minha_proposta_status,lp.valor_total,lp.pos
                $baseSql
                $whereSql
                ORDER BY lp.id DESC" . $limitClause;
        $st = $db->prepare($sql);
        $st->execute($dataParams);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $eData){
        try {
            $latestSubFallback = "SELECT p1.id,p1.cotacao_id,p1.status,p1.valor_total,NULL AS pos
                  FROM propostas p1
                  JOIN (
                      SELECT cotacao_id, MAX(id) AS max_id
                      FROM propostas
                      WHERE fornecedor_id=?
                      GROUP BY cotacao_id
                  ) latest ON latest.cotacao_id = p1.cotacao_id AND latest.max_id = p1.id
                  WHERE p1.fornecedor_id=?";
            $baseSqlFallback = "FROM (" . $latestSubFallback . ") lp
                JOIN cotacoes c ON c.id = lp.cotacao_id";
            $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.criado_em,
                       lp.id AS minha_proposta_id,lp.status AS minha_proposta_status,lp.valor_total,lp.pos
                $baseSqlFallback
                $whereSql
                ORDER BY lp.id DESC" . $limitClause;
            $st = $db->prepare($sql);
            $st->execute($dataParams);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$r){ if(!isset($r['tipo_frete'])) $r['tipo_frete']=null; }
        } catch(Throwable $eData2){
            throw $eData;
        }
    }

    $statusCotOptions = [];
    $statusPropOptions = [];
    try {
        $statusCotSql = "SELECT DISTINCT LOWER(c.status) AS value,c.status AS label $baseSql ORDER BY label";
        $stCot = $db->prepare($statusCotSql);
        $stCot->execute([$fornecedorId,$fornecedorId]);
        $statusCotRows = $stCot->fetchAll(PDO::FETCH_ASSOC);
        foreach($statusCotRows as $row){
            if(($row['value'] ?? '')!==''){
                $statusCotOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $eCot){ /* ignora */ }
    try {
        $statusPropSql = "SELECT DISTINCT LOWER(lp.status) AS value,lp.status AS label $baseSql WHERE lp.status IS NOT NULL AND lp.status<>'' ORDER BY label";
        $stProp = $db->prepare($statusPropSql);
        $stProp->execute([$fornecedorId,$fornecedorId]);
        $statusPropRows = $stProp->fetchAll(PDO::FETCH_ASSOC);
        foreach($statusPropRows as $row){
            if(($row['value'] ?? '')!==''){
                $statusPropOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
        $pendingSql = "SELECT 1 $baseSql WHERE (lp.status IS NULL OR lp.status='') LIMIT 1";
        $stPend = $db->prepare($pendingSql);
        $stPend->execute([$fornecedorId,$fornecedorId]);
        if($stPend->fetchColumn()){
            array_unshift($statusPropOptions, ['value'=>'__pendente','label'=>'Pendente']);
        }
    } catch(Throwable $eProp){ /* ignora */ }

    $response = [
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'status_cotacao_options' => $statusCotOptions,
            'status_proposta_options' => $statusPropOptions
        ]
    ];
    echo json_encode($response);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao listar cotações em participação','mensagem'=>$e->getMessage()]);
}
