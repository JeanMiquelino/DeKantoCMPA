<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Nao autenticado']); exit; }
$ehFornecedor = (($u['tipo']??'')==='fornecedor');

try {
    $db = get_db_connection();
    rate_limit_enforce($db,'api/anexos_list',120,300,true);

    // Fallback / derivar fornecedor_id se necessário ANTES de qualquer filtro que dependa dele
    $fornecedorId = (int)($u['fornecedor_id'] ?? 0);
    if($ehFornecedor && $fornecedorId<=0){
        try {
            $stF=$db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
            $stF->execute([$u['id']]);
            $fid=(int)$stF->fetchColumn();
            if($fid>0) $fornecedorId=$fid;
        } catch(Throwable $e){ /* ignore fallback erro */ }
    }

    $requisicao_id = isset($_GET['requisicao_id']) ? (int)$_GET['requisicao_id'] : 0;
    $tipo_ref = $_GET['tipo_ref'] ?? null;
    $ref_id = isset($_GET['ref_id']) ? (int)$_GET['ref_id'] : 0;
    $somente_publicos = isset($_GET['publicos']) ? (int)$_GET['publicos'] : null; // admin pode forçar

    if($requisicao_id<=0 && (!$tipo_ref || $ref_id<=0)){
        http_response_code(400); echo json_encode(['erro'=>'Parâmetros insuficientes']); exit;
    }

    $tab = $db->query("SHOW TABLES LIKE 'anexos'")->fetch();
    if(!$tab){ echo json_encode(['success'=>true,'anexos'=>[],'aviso'=>'Tabela anexos inexistente']); exit; }

    $sql = 'SELECT id,requisicao_id,tipo_ref,ref_id,nome_original,caminho,mime,tamanho,publico';
    try { $db->query("SELECT created_at FROM anexos LIMIT 1"); $sql .= ',created_at'; } catch(Throwable $e) { }
    $sql .= ' FROM anexos WHERE 1=1';
    $params=[];
    if($requisicao_id>0){ $sql.=' AND requisicao_id=?'; $params[]=$requisicao_id; }
    if($tipo_ref && $ref_id>0){ $sql.=' AND tipo_ref=? AND ref_id=?'; $params[]=$tipo_ref; $params[]=$ref_id; }
    if($somente_publicos===1){ $sql.=' AND publico=1'; }

    // Regras de visibilidade para fornecedor
    if($ehFornecedor){
        if($somente_publicos===1){
            // já filtrado somente públicos acima
        } else {
            if($tipo_ref==='proposta' && $ref_id>0){
                // Validar ownership da proposta
                $stProp = $db->prepare('SELECT fornecedor_id FROM propostas WHERE id=?');
                $stProp->execute([$ref_id]);
                $fid = (int)$stProp->fetchColumn();
                if($fid!==$fornecedorId){ echo json_encode(['success'=>true,'anexos'=>[]]); exit; }
                // Apenas anexos privados da própria proposta
                $sql .= ' AND publico=0';
            } elseif($tipo_ref==='cotacao') {
                // Fornecedor não visualiza anexos diretos da cotação (somente seu anexo privado via proposta)
                echo json_encode(['success'=>true,'anexos'=>[]]); exit;
            } else {
                // Fallback seguro: somente públicos
                $sql .= ' AND publico=1';
            }
        }
    }

    $sql .= ' ORDER BY id DESC LIMIT 20';
    $st=$db->prepare($sql); $st->execute($params);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'anexos'=>$rows]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['erro'=>'Falha ao listar','detalhe'=>$e->getMessage()]); }
