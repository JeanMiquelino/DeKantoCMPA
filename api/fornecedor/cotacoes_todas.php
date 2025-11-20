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
rate_limit_enforce($db,'api/fornecedor_cotacoes_todas',240,300,true);
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try {
        $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid = (int)$stF->fetchColumn();
        if($fid>0){
            $fornecedorId = $fid;
            $u['fornecedor_id'] = $fid; // cache local
        }
    } catch(Throwable $e){ /* ignore */ }
}
if($_SERVER['REQUEST_METHOD']!=='GET'){
    http_response_code(405); echo json_encode(['erro'=>'Método não permitido']); exit;
}
try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $statusFiltro = trim((string)($_GET['status'] ?? ''));

    $where = [];
    $params = [];
    if($statusFiltro !== ''){
        $where[] = 'LOWER(c.status)=?';
        $params[] = strtolower($statusFiltro);
    }
    if($busca !== ''){
        $like = '%' . $busca . '%';
        $where[] = '(CAST(c.id AS CHAR) LIKE ? OR CAST(c.requisicao_id AS CHAR) LIKE ? OR LOWER(c.status) LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = '%' . strtolower($busca) . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stCount = $db->prepare("SELECT COUNT(*) FROM cotacoes c $whereSql");
    $stCount->execute($params);
    $total = (int)$stCount->fetchColumn();

    $rows = [];
    $baseParams = array_merge([$fornecedorId], $params);
    $limitClause = ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    try {
        $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.tipo_frete,c.criado_em,(pf.cotacao_id IS NOT NULL) AS ja_enviou
                FROM cotacoes c
                LEFT JOIN (SELECT DISTINCT cotacao_id FROM propostas WHERE fornecedor_id=?) pf ON pf.cotacao_id=c.id
                $whereSql
                ORDER BY c.id DESC" . $limitClause;
        $stData = $db->prepare($sql);
        $stData->execute($baseParams);
        $rows = $stData->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $eList){
        $sql = "SELECT c.id,c.requisicao_id,c.status,c.rodada,c.criado_em,(pf.cotacao_id IS NOT NULL) AS ja_enviou
                FROM cotacoes c
                LEFT JOIN (SELECT DISTINCT cotacao_id FROM propostas WHERE fornecedor_id=?) pf ON pf.cotacao_id=c.id
                $whereSql
                ORDER BY c.id DESC" . $limitClause;
        $stData = $db->prepare($sql);
        $stData->execute($baseParams);
        $rows = $stData->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$r){ if(!array_key_exists('tipo_frete',$r)) $r['tipo_frete']=null; }
    }

    $statusOptions = [];
    try {
        $stStatus = $db->query("SELECT DISTINCT LOWER(status) AS value,status AS label FROM cotacoes ORDER BY status");
        $statusRows = $stStatus ? $stStatus->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach($statusRows as $row){
            if(($row['value'] ?? '')!==''){
                $statusOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $eStatus){ /* ignora */ }

    $response = [
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'status_options' => $statusOptions
        ]
    ];
    echo json_encode($response);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao listar cotações','mensagem'=>$e->getMessage()]);
}
