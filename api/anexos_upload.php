<?php
// Endpoint de upload de anexos
// POST multipart/form-data: requisicao_id (ou deduzido), tipo_ref(opcional), ref_id(opcional), publico(0/1), arquivo
// Suporta deducao automática de requisicao_id quando:
//  - tipo_ref = 'cotacao' e ref_id informado
//  - tipo_ref = 'proposta' e ref_id informado (busca cotacao -> requisicao)
// Requer autenticacao

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/rate_limit.php';

header('Content-Type: application/json; charset=utf-8');
// Cabeçalhos de segurança
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Nao autenticado']); exit; }
$ehFornecedor = (($u['tipo']??'')==='fornecedor');

try {
    $db = get_db_connection();
    // Rate limit por IP/rota (uploads)
    rate_limit_enforce($db, 'api/anexos_upload', 20, 300, true);

    // Validar se tabela existe
    $tab = $db->query("SHOW TABLES LIKE 'anexos'")->fetch();
    if(!$tab){ http_response_code(400); echo json_encode(['erro'=>'Tabela anexos inexistente']); exit; }

    if($_SERVER['REQUEST_METHOD']!=='POST'){
        http_response_code(405); header('Allow: POST'); echo json_encode(['erro'=>'Metodo nao permitido']); exit; }

    if(empty($_FILES['arquivo'])){ http_response_code(400); echo json_encode(['erro'=>'Arquivo nao enviado']); exit; }

    $requisicao_id = (int)($_POST['requisicao_id'] ?? 0);
    $tipo_ref = $_POST['tipo_ref'] ?? 'requisicao';
    $ref_id = isset($_POST['ref_id']) ? (int)$_POST['ref_id'] : null;
    $publico = isset($_POST['publico']) && $_POST['publico']=='1' ? 1 : 0;

    // --- NOVA REGRA: fornecedores só podem anexar após existir proposta e apenas 1 anexo privado por proposta ---
    if($ehFornecedor){
        // Fornecedor não deve anexar diretamente em cotacao (evita colisão entre fornecedores). Exigir proposta.
        if($tipo_ref === 'cotacao'){
            http_response_code(400); echo json_encode(['erro'=>'Envio de anexo permitido apenas após enviar a proposta']); exit; }
        if($tipo_ref === 'proposta'){
            if($ref_id<=0){ http_response_code(400); echo json_encode(['erro'=>'proposta_id inválido']); exit; }
            // Recuperar proposta e validar ownership
            $fornecedorId = (int)($u['fornecedor_id'] ?? 0);
            if($fornecedorId<=0){
                try { $stF=$db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1'); $stF->execute([$u['id']]); $fornecedorId=(int)$stF->fetchColumn(); } catch(Throwable $e){ }
            }
            if($fornecedorId<=0){ http_response_code(400); echo json_encode(['erro'=>'Fornecedor não configurado']); exit; }
            $stP = $db->prepare('SELECT p.id,c.requisicao_id,p.fornecedor_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=?');
            $stP->execute([$ref_id]);
            $prop = $stP->fetch(PDO::FETCH_ASSOC);
            if(!$prop || (int)$prop['fornecedor_id'] !== $fornecedorId){ http_response_code(403); echo json_encode(['erro'=>'Proposta não pertence ao fornecedor']); exit; }
            // Ajustar requisicao_id via proposta (prioriza consistência)
            if($requisicao_id<=0 && isset($prop['requisicao_id'])){ $requisicao_id = (int)$prop['requisicao_id']; }
            // Unicidade: já existe anexo privado desta proposta?
            $stAx = $db->prepare('SELECT id FROM anexos WHERE tipo_ref="proposta" AND ref_id=? AND publico=0 LIMIT 1');
            $stAx->execute([$ref_id]);
            if($stAx->fetch()){ http_response_code(409); echo json_encode(['erro'=>'Já existe um anexo para esta proposta']); exit; }
            // Forçar privado para fornecedor
            $publico = 0;
        }
    }

    $permitidos = ['requisicao','item','cotacao','proposta','pedido'];
    if($ehFornecedor){
        // fornecedor só pode realmente enviar para proposta (após validações acima); bloquear outros
        $permitidos = ['proposta'];
    }
    if(!in_array($tipo_ref,$permitidos,true)){ http_response_code(400); echo json_encode(['erro'=>'tipo_ref invalido']); exit; }

    // Fallbacks para deduzir requisicao_id
    if($requisicao_id<=0 && $tipo_ref==='cotacao' && $ref_id){
        try {
            $stC = $db->prepare('SELECT requisicao_id FROM cotacoes WHERE id=?');
            $stC->execute([$ref_id]);
            $rid = (int)$stC->fetchColumn();
            if($rid>0) $requisicao_id = $rid;
        } catch(Throwable $e){ /* ignore */ }
    }
    if($requisicao_id<=0 && $tipo_ref==='proposta' && $ref_id){
        try {
            $stP = $db->prepare('SELECT c.requisicao_id FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE p.id=?');
            $stP->execute([$ref_id]);
            $rid = (int)$stP->fetchColumn();
            if($rid>0) $requisicao_id = $rid;
        } catch(Throwable $e){ /* ignore */ }
    }

    if($requisicao_id<=0){ http_response_code(400); echo json_encode(['erro'=>'requisicao_id obrigatorio']); exit; }

    // Opcional: checar se requisicao existe e se user pode acessar
    $st = $db->prepare('SELECT id FROM requisicoes WHERE id=?');
    $st->execute([$requisicao_id]);
    if(!$st->fetch()){ http_response_code(404); echo json_encode(['erro'=>'Requisicao nao encontrada']); exit; }

    // Validação básica de ref_id quando fornecido (não estrito aqui)

    $file = $_FILES['arquivo'];
    if($file['error']!==UPLOAD_ERR_OK){ http_response_code(400); echo json_encode(['erro'=>'Falha upload','code'=>$file['error']]); exit; }

    $original = $file['name'];
    $mime = $file['type'] ?? null;
    $size = (int)$file['size'];
    // Limites
    $maxSize = 10 * 1024 * 1024; // 10MB
    if($size > $maxSize){ http_response_code(400); echo json_encode(['erro'=>'Arquivo excede limite 10MB']); exit; }

    // Extensoes permitidas basicas
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowExt = ['pdf','png','jpg','jpeg','gif','doc','docx','xls','xlsx','csv','txt'];
    if(!in_array($ext,$allowExt,true)){ http_response_code(400); echo json_encode(['erro'=>'Extensao nao permitida']); exit; }

    // Gerar nome seguro
    $hash = bin2hex(random_bytes(8));
    $nomeArmazenado = $hash . '_' . preg_replace('/[^a-zA-Z0-9_.-]/','_',$original);
    $subdir = date('Y/m');
    $baseDir = realpath(__DIR__ . '/../assets/anexos');
    if(!$baseDir){ $baseDir = __DIR__ . '/../assets/anexos'; if(!is_dir($baseDir)) mkdir($baseDir,0775,true); }
    $destDir = $baseDir . '/' . $subdir;
    if(!is_dir($destDir)) mkdir($destDir,0775,true);

    $destPath = $destDir . '/' . $nomeArmazenado;
    if(!move_uploaded_file($file['tmp_name'],$destPath)){
        http_response_code(500); echo json_encode(['erro'=>'Falha ao mover arquivo']); exit; }

    $relPath = 'assets/anexos/' . $subdir . '/' . $nomeArmazenado;

    $st = $db->prepare('INSERT INTO anexos (requisicao_id, tipo_ref, ref_id, nome_original, caminho, mime, tamanho, publico) VALUES (?,?,?,?,?,?,?,?)');
    $st->execute([$requisicao_id,$tipo_ref,$ref_id,$original,$relPath,$mime,$size,$publico]);
    $anexoId = (int)$db->lastInsertId();

    // Registrar timeline somente se requisicao válida
    try { log_requisicao_event($db,$requisicao_id,'anexo_enviado','Anexo enviado: '.$original,null,[ 'anexo_id'=>$anexoId,'arquivo'=>$original,'publico'=>$publico,'tipo_ref'=>$tipo_ref ] , (int)$u['id']); } catch(Throwable $e){ /* ignore timeline errors */ }

    echo json_encode(['success'=>true,'anexo'=>[
        'id'=>$anexoId,
        'requisicao_id'=>$requisicao_id,
        'tipo_ref'=>$tipo_ref,
        'ref_id'=>$ref_id,
        'nome_original'=>$original,
        'caminho'=>$relPath,
        'mime'=>$mime,
        'tamanho'=>$size,
        'publico'=>$publico
    ]]);
} catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['erro'=>'Falha processamento','detalhe'=>$e->getMessage()]);
}
