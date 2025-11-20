<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/rate_limit.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    http_response_code(403); echo json_encode(['erro'=>'Acesso restrito a fornecedores']); exit;
}
$db = get_db_connection();
// Fallback para usuários antigos sem fornecedor_id preenchido
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try {
        $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid = (int)$stF->fetchColumn();
        if($fid>0){
            $fornecedorId = $fid;
            $u['fornecedor_id'] = $fid; // apenas em memória para este request
        }
    } catch(Throwable $e){ /* ignore */ }
}
if($fornecedorId<=0){ http_response_code(400); echo json_encode(['erro'=>'Fornecedor não configurado']); exit; }
rate_limit_enforce($db,'api/fornecedor_proposta',240,300,true);
$method = resolve_http_method();
function json_body(){ $raw=file_get_contents('php://input'); if(!$raw) return []; $d=json_decode($raw,true); return is_array($d)?$d:[]; }
function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') { return $method; }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_POST['_method'])) {
        $override = $_POST['_method'];
        unset($_POST['_method']);
    }
    if (!$override && isset($_GET['_method'])) { $override = $_GET['_method']; }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['PUT','PATCH','DELETE'], true)) {
            return $override;
        }
    }
    return $method;
}
function read_request_payload(): array {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        return $cached = json_body();
    }
    if (!empty($_POST)) {
        return $cached = $_POST;
    }
    $raw = file_get_contents('php://input');
    if ($raw) {
        $tmp = [];
        parse_str($raw, $tmp);
        if ($tmp) { return $cached = $tmp; }
    }
    return $cached = [];
}

switch($method){
    case 'GET':
        $cotacaoId = (int)($_GET['cotacao_id'] ?? 0);
        if($cotacaoId<=0){ http_response_code(400); echo json_encode(['erro'=>'cotacao_id obrigatório']); break; }
        // Ajuste: incluir tipo_frete via join com cotacoes para consistência
        $st = $db->prepare("SELECT p.*, c.tipo_frete FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.cotacao_id=? AND p.fornecedor_id=? ORDER BY p.id DESC LIMIT 1");
        $st->execute([$cotacaoId,$fornecedorId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode($p ?: null);
        break;
    case 'POST':
    $data = read_request_payload();
        $cotacaoId = (int)($data['cotacao_id'] ?? 0);
        if($cotacaoId<=0){ http_response_code(400); echo json_encode(['erro'=>'cotacao_id inválido']); break; }
        $stC = $db->prepare('SELECT id,status,rodada,tipo_frete FROM cotacoes WHERE id=?');
        $stC->execute([$cotacaoId]);
        $cot = $stC->fetch(PDO::FETCH_ASSOC);
        if(!$cot){ http_response_code(404); echo json_encode(['erro'=>'Cotação não encontrada']); break; }
        if(($cot['status']??'')!=='aberta'){ http_response_code(409); echo json_encode(['erro'=>'Cotação não está aberta']); break; }
        $rodada = (int)($cot['rodada'] ?? 1);
        $stChk = $db->prepare('SELECT id FROM propostas WHERE cotacao_id=? AND fornecedor_id=? LIMIT 1');
        $stChk->execute([$cotacaoId,$fornecedorId]);
        if($stChk->fetch()){ http_response_code(409); echo json_encode(['erro'=>'Já existe proposta para esta cotação']); break; }
        $valor_total = isset($data['valor_total']) ? (float)$data['valor_total'] : null;
        $prazo_entrega = isset($data['prazo_entrega']) ? (int)$data['prazo_entrega'] : null;
        $pagamento_dias = isset($data['pagamento_dias']) ? (int)$data['pagamento_dias'] : null;
        $observacoes = isset($data['observacoes']) ? trim($data['observacoes']) : null;
        $tipo_frete = isset($data['tipo_frete']) ? strtoupper(trim($data['tipo_frete'])) : null;
        $INCOTERMS_VALIDOS = ['CIF','FOB','EXW','DAP','DDP'];
        if($tipo_frete && !in_array($tipo_frete,$INCOTERMS_VALIDOS,true)) $tipo_frete = null; // sanitiza
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('INSERT INTO propostas (cotacao_id, fornecedor_id, valor_total, prazo_entrega, pagamento_dias, observacoes, status) VALUES (?,?,?,?,?,?,?)');
            $ok = $stmt->execute([$cotacaoId,$fornecedorId,$valor_total,$prazo_entrega,$pagamento_dias,$observacoes,'enviada']);
            $newId = (int)$db->lastInsertId();
            if($tipo_frete){
                try { $db->prepare('UPDATE cotacoes SET tipo_frete=? WHERE id=?')->execute([$tipo_frete,$cotacaoId]); } catch(Throwable $e){ /* ignore */ }
            }
            $db->commit();
            echo json_encode(['success'=>$ok,'id'=>$newId,'tipo_frete'=>$tipo_frete]);
        } catch(Throwable $e){ if($db->inTransaction()) $db->rollBack(); http_response_code(500); echo json_encode(['erro'=>'Falha ao criar proposta']); }
        break;
    case 'PUT':
    $data = read_request_payload();
        $id = (int)($data['id'] ?? 0);
        if($id<=0){ http_response_code(400); echo json_encode(['erro'=>'id inválido']); break; }
        $st = $db->prepare('SELECT p.*, c.status AS cot_status, c.rodada, c.id AS cotacao_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=? AND p.fornecedor_id=?');
        $st->execute([$id,$fornecedorId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if(!$p){ http_response_code(404); echo json_encode(['erro'=>'Proposta não encontrada']); break; }
        if(($p['cot_status']??'')!=='aberta'){ http_response_code(409); echo json_encode(['erro'=>'Cotação não está aberta']); break; }
        $statusAtual = strtolower((string)($p['status'] ?? ''));
        $statusEditaveis = ['','enviada'];
        if(!in_array($statusAtual, $statusEditaveis, true)){
            http_response_code(409);
            echo json_encode(['erro'=>'Esta proposta já foi '.($statusAtual==='aprovada'?'aprovada':($statusAtual==='rejeitada'?'rejeitada':'finalizada')).' e não pode mais ser editada']);
            break;
        }
        $campos=[]; $params=[];
        if(isset($data['valor_total'])){ $campos[]='valor_total=?'; $params[] = ($data['valor_total']===''? null : (float)$data['valor_total']); }
        if(isset($data['prazo_entrega'])){ $campos[]='prazo_entrega=?'; $params[] = ($data['prazo_entrega']===''? null : (int)$data['prazo_entrega']); }
        if(isset($data['pagamento_dias'])){ $campos[]='pagamento_dias=?'; $params[] = ($data['pagamento_dias']===''? null : (int)$data['pagamento_dias']); }
        if(isset($data['observacoes'])){ $campos[]='observacoes=?'; $params[] = $data['observacoes']; }
        $tipo_frete = isset($data['tipo_frete']) ? strtoupper(trim($data['tipo_frete'])) : null; $INCOTERMS_VALIDOS=['CIF','FOB','EXW','DAP','DDP'];
        $updateTipoFrete = ($tipo_frete && in_array($tipo_frete,$INCOTERMS_VALIDOS,true));
        if(!$campos && !$updateTipoFrete){ echo json_encode(['success'=>false,'erro'=>'Nada para atualizar']); break; }
        try {
            $db->beginTransaction();
            if($campos){
                $params[]=$id; $sql='UPDATE propostas SET '.implode(',', $campos).' WHERE id=? AND fornecedor_id='.$fornecedorId; $db->prepare($sql)->execute($params);
            }
            if($updateTipoFrete){ try{ $db->prepare('UPDATE cotacoes SET tipo_frete=? WHERE id=?')->execute([$tipo_frete,$p['cotacao_id']]); }catch(Throwable $e){ /* ignore */ } }
            $db->commit();
            echo json_encode(['success'=>true,'tipo_frete'=>$updateTipoFrete?$tipo_frete:null]);
        } catch(Throwable $e){ if($db->inTransaction()) $db->rollBack(); http_response_code(500); echo json_encode(['erro'=>'Falha ao atualizar']); }
        break;
    default:
        http_response_code(405); echo json_encode(['erro'=>'Método não permitido']);
}
