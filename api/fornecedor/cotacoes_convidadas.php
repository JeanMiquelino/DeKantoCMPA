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
rate_limit_enforce($db,'api/fornecedor_cotacoes_convidadas',180,300,true);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try { $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1'); $stF->execute([$u['id']]); $fid=(int)$stF->fetchColumn(); if($fid>0){ $fornecedorId=$fid; $u['fornecedor_id']=$fid; } } catch(Throwable $e){}
}
if($fornecedorId<=0){
    echo json_encode(['data'=>[], 'meta'=>['page'=>1,'per_page'=>20,'total'=>0,'total_pages'=>1]]);
    exit;
}
// Verifica existência da tabela
try { $has = (bool)$db->query("SHOW TABLES LIKE 'cotacao_convites'")->fetch(); } catch(Throwable $e){ $has=false; }
if(!$has){ echo json_encode(['data'=>[], 'meta'=>['page'=>1,'per_page'=>20,'total'=>0,'total_pages'=>1]]); exit; }

try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $statusConvite = trim((string)($_GET['status_convite'] ?? ''));
    $statusCotacao = trim((string)($_GET['status_cotacao'] ?? ''));

    $where = [];
    $filterParams = [];
    if($statusConvite !== ''){
        $where[] = 'LOWER(cc.status)=?';
        $filterParams[] = strtolower($statusConvite);
    }
    if($statusCotacao !== ''){
        $where[] = 'LOWER(c.status)=?';
        $filterParams[] = strtolower($statusCotacao);
    }
    if($busca !== ''){
        $like = '%' . $busca . '%';
        $where[] = '(CAST(cc.id AS CHAR) LIKE ? OR CAST(cc.requisicao_id AS CHAR) LIKE ? OR CAST(c.id AS CHAR) LIKE ? OR LOWER(cc.status) LIKE ?)';
        $filterParams[] = $like;
        $filterParams[] = $like;
        $filterParams[] = $like;
        $filterParams[] = '%' . strtolower($busca) . '%';
    }
    $whereExtra = $where ? (' AND ' . implode(' AND ', $where)) : '';

    $latestCotacoes = "SELECT c1.id,c1.requisicao_id,c1.status,c1.rodada
                        FROM cotacoes c1
                        JOIN (
                            SELECT requisicao_id, MAX(id) AS max_id
                            FROM cotacoes
                            GROUP BY requisicao_id
                        ) latest ON latest.requisicao_id = c1.requisicao_id AND latest.max_id = c1.id";

    $latestPropostas = "SELECT p1.id,p1.cotacao_id
                         FROM propostas p1
                         JOIN (
                             SELECT cotacao_id, MAX(id) AS max_id
                             FROM propostas
                             WHERE fornecedor_id=?
                             GROUP BY cotacao_id
                         ) lp ON lp.cotacao_id = p1.cotacao_id AND lp.max_id = p1.id
                         WHERE p1.fornecedor_id=?";

    $baseSql = "FROM cotacao_convites cc
                LEFT JOIN ($latestCotacoes) c ON c.requisicao_id = cc.requisicao_id
                LEFT JOIN ($latestPropostas) lp ON lp.cotacao_id = c.id
                WHERE cc.fornecedor_id=?" . $whereExtra;

    $countParams = array_merge([$fornecedorId,$fornecedorId,$fornecedorId], $filterParams);
    $countSql = "SELECT COUNT(*) $baseSql";
    $stCount = $db->prepare($countSql);
    $stCount->execute($countParams);
    $total = (int)$stCount->fetchColumn();

    $limitClause = ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $dataParams = array_merge([$fornecedorId,$fornecedorId,$fornecedorId], $filterParams);
    $sql = "SELECT cc.id AS convite_id, cc.requisicao_id, cc.status AS convite_status, cc.expira_em, cc.enviado_em, cc.respondido_em,
                   c.id AS cotacao_id, c.status AS cotacao_status, c.rodada AS cotacao_rodada,
                   lp.id AS minha_proposta_id
            $baseSql
            ORDER BY cc.id DESC" . $limitClause;
    $st = $db->prepare($sql);
    $st->execute($dataParams);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    $statusConviteOptions = [];
    $statusCotacaoOptions = [];
    try {
        $statusConvSql = "SELECT DISTINCT LOWER(status) AS value,status AS label FROM cotacao_convites WHERE fornecedor_id=? ORDER BY label";
        $stConv = $db->prepare($statusConvSql);
        $stConv->execute([$fornecedorId]);
        $convRows = $stConv->fetchAll(PDO::FETCH_ASSOC);
        foreach($convRows as $row){
            if(($row['value'] ?? '')!==''){
                $statusConviteOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $eConv){ /* ignora */ }
    try {
        $statusCotSql = "SELECT DISTINCT LOWER(c.status) AS value,c.status AS label
                         FROM cotacao_convites cc
                         LEFT JOIN ($latestCotacoes) c ON c.requisicao_id = cc.requisicao_id
                         WHERE cc.fornecedor_id=? AND c.status IS NOT NULL AND c.status<>''
                         ORDER BY c.status";
        $stCot = $db->prepare($statusCotSql);
        $stCot->execute([$fornecedorId]);
        $cotRows = $stCot->fetchAll(PDO::FETCH_ASSOC);
        foreach($cotRows as $row){
            if(($row['value'] ?? '')!==''){
                $statusCotacaoOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $eCot){ /* ignora */ }

    $response = [
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'status_convite_options' => $statusConviteOptions,
            'status_cotacao_options' => $statusCotacaoOptions
        ]
    ];
    echo json_encode($response);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao listar convites','mensagem'=>$e->getMessage()]);
}
