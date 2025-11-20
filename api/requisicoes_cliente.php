<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ http_response_code(403); echo json_encode(['erro'=>'Acesso restrito ao cliente']); exit; }

$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);
if($clienteId <= 0){ echo json_encode(['data'=>[], 'meta'=>['page'=>1,'per_page'=>20,'total'=>0,'total_pages'=>1,'status_options'=>[]]]); exit; }

if($_SERVER['REQUEST_METHOD'] !== 'GET'){
    http_response_code(405);
    echo json_encode(['erro'=>'Método não suportado']);
    exit;
}

try {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(5, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $busca = trim((string)($_GET['busca'] ?? ''));
    $statusFiltro = trim((string)($_GET['status'] ?? ''));

    $where = ['r.cliente_id=?'];
    $params = [$clienteId];
    if($statusFiltro !== ''){
        $where[] = 'LOWER(r.status)=?';
        $params[] = strtolower($statusFiltro);
    }
    if($busca !== ''){
        $like = '%' . $busca . '%';
                $where[] = "(CAST(r.id AS CHAR) LIKE ? OR LOWER(COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id))) LIKE ?)";
                $params[] = $like;
        $params[] = '%' . strtolower($busca) . '%';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countSql = "SELECT COUNT(*) FROM requisicoes r $whereSql";
    $stCount = $db->prepare($countSql);
    $stCount->execute($params);
    $total = (int)$stCount->fetchColumn();

    $dataSql = "SELECT r.id, COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id)) AS titulo, r.status, r.criado_em
                FROM requisicoes r
                $whereSql
                ORDER BY r.id DESC
                LIMIT $perPage OFFSET $offset";
    $stData = $db->prepare($dataSql);
    $stData->execute($params);
    $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

    $statusOptions = [];
    try {
        $statusSql = "SELECT DISTINCT LOWER(status) AS value, status AS label FROM requisicoes WHERE cliente_id=? ORDER BY status";
        $stStatus = $db->prepare($statusSql);
        $stStatus->execute([$clienteId]);
        $options = $stStatus->fetchAll(PDO::FETCH_ASSOC);
        foreach($options as $row){
            if(($row['value'] ?? '') !== ''){
                $statusOptions[] = [
                    'value' => $row['value'],
                    'label' => $row['label']
                ];
            }
        }
    } catch(Throwable $e){ /* ignora */ }

    echo json_encode([
        'data' => $rows,
        'meta' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
            'status_options' => $statusOptions
        ]
    ]);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha ao listar requisições']);
}
