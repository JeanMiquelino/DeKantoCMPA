<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/email.php';
require_once __DIR__.'/../includes/rate_limit.php';

function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        return $method;
    }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_POST['_method'])) {
        $override = $_POST['_method'];
    }
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['DELETE', 'PUT', 'PATCH'], true)) {
            return $override;
        }
    }
    return $method;
}

function read_request_payload(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $tmp = [];
        parse_str($raw, $tmp);
        if (is_array($tmp) && $tmp) {
            $data = $tmp;
        }
    }
    if (!is_array($data) || !$data) {
        $data = $_POST ?: [];
    }
    if (isset($data['_method'])) {
        unset($data['_method']);
    }
    $cached = $data;
    return $cached;
}

$u = auth_usuario();
if(!$u){ http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }
$db = get_db_connection();
$method = resolve_http_method();

function hash_token($t){ return hash('sha256',$t); }
function gerar_token_raw($len=40){ return bin2hex(random_bytes($len/2)); }

function table_has_column(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table.'.'.$column;
    if(array_key_exists($key, $cache)){ return $cache[$key]; }
    if(!preg_match('/^[a-z0-9_]+$/i', $table)){ $cache[$key] = false; return false; }
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch(Throwable $e){
        $cache[$key] = false;
    }
    return $cache[$key];
}

// Checagem defensiva de tabela
try { $hasConv = (bool)$db->query("SHOW TABLES LIKE 'cotacao_convites'")->fetch(); } catch(Throwable $e){ $hasConv=false; }
if(!$hasConv){ http_response_code(500); echo json_encode(['erro'=>'Tabela de convites ausente']); exit; }
$hasCotacaoColumn = table_has_column($db, 'cotacao_convites', 'cotacao_id');

if($method==='POST'){
    // Apenas usuários internos podem criar convites
    if(($u['tipo'] ?? '') === 'cliente'){ http_response_code(403); echo json_encode(['erro'=>'Proibido']); exit; }
    try { rate_limit_enforce($db, 'api.cotacoes_convites:post', 120, 300, true); } catch(Throwable $e){}
    $data = read_request_payload();
    $cotacao_id = (int)($data['cotacao_id'] ?? 0);
    $requisicao_id_payload = (int)($data['requisicao_id'] ?? 0);
    $fornecedores = $data['fornecedores'] ?? []; // array de {fornecedor_id,email?}
    $dias = (int)($data['dias_validade'] ?? 5);
    $includeRaw = !empty($data['include_raw']); // somente honrado para usuários internos
    if(!$cotacao_id || !$fornecedores){ http_response_code(400); echo json_encode(['erro'=>'cotacao_id e fornecedores obrigatórios']); exit; }
    // Cotação deve existir e trazer a requisição vinculada
    try {
        $stCot = $db->prepare('SELECT id, requisicao_id FROM cotacoes WHERE id=?');
        $stCot->execute([$cotacao_id]);
        $cotRow = $stCot->fetch(PDO::FETCH_ASSOC);
    } catch(Throwable $e){ $cotRow = false; }
    if(!$cotRow){ http_response_code(404); echo json_encode(['erro'=>'Cotação não encontrada']); exit; }
    $requisicao_id = (int)($cotRow['requisicao_id'] ?? 0);
    if(!$requisicao_id && $requisicao_id_payload){ $requisicao_id = $requisicao_id_payload; }
    if(!$requisicao_id){ http_response_code(400); echo json_encode(['erro'=>'Requisição vinculada inválida']); exit; }
    // Requisição deve existir (garante escopo e dados auxiliares)
    try { $chkReq = $db->prepare('SELECT id, cliente_id FROM requisicoes WHERE id=?'); $chkReq->execute([$requisicao_id]); $reqRow = $chkReq->fetch(PDO::FETCH_ASSOC); } catch(Throwable $e){ $reqRow=false; }
    if(!$reqRow){ http_response_code(404); echo json_encode(['erro'=>'Requisição não encontrada']); exit; }
    // Escopo cliente (se por acaso cair aqui no futuro): só permitir na própria
    if(($u['tipo'] ?? null)==='cliente'){
        if((int)$reqRow['cliente_id'] !== (int)($u['cliente_id'] ?? 0)){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); exit; }
    }
    $expira_em = (new DateTimeImmutable('+'.$dias.' days'))->format('Y-m-d H:i:s');
    $created=[]; $skipped=[]; $errors=[]; $createdCount=0;
    foreach($fornecedores as $f){
        $fid = (int)($f['fornecedor_id'] ?? 0); if(!$fid){ continue; }
        $tokenRaw = gerar_token_raw(48); $tokenHash = hash_token($tokenRaw);
        $conviteId = null; $reemitido = false;
        // Verifica duplicidade (constraint UNIQUE cuida, mas evita exception)
        if($hasCotacaoColumn){
            $st = $db->prepare('SELECT id,status FROM cotacao_convites WHERE cotacao_id=? AND fornecedor_id=?');
            $st->execute([$cotacao_id,$fid]);
        } else {
            $st = $db->prepare('SELECT id,status FROM cotacao_convites WHERE requisicao_id=? AND fornecedor_id=?');
            $st->execute([$requisicao_id,$fid]);
        }
        $exists = $st->fetch(PDO::FETCH_ASSOC);
        if($exists){
            if(($exists['status'] ?? '') !== 'cancelado'){
                $skipped[]=['fornecedor_id'=>$fid,'motivo'=>'já existe','status'=>$exists['status']];
                continue;
            }
            try {
                if($hasCotacaoColumn){
                    $upd = $db->prepare("UPDATE cotacao_convites SET cotacao_id = ?, requisicao_id = ?, token_hash = ?, expira_em = ?, status = 'enviado', enviado_em = NULL WHERE id = ?");
                    $upd->execute([$cotacao_id, $requisicao_id, $tokenHash, $expira_em, (int)$exists['id']]);
                } else {
                    $upd = $db->prepare("UPDATE cotacao_convites SET token_hash = ?, expira_em = ?, status = 'enviado', enviado_em = NULL WHERE id = ?");
                    $upd->execute([$tokenHash, $expira_em, (int)$exists['id']]);
                }
                $conviteId = (int)$exists['id'];
                $reemitido = true;
            } catch(Throwable $e){
                $errors[]=['fornecedor_id'=>$fid,'erro'=>$e->getMessage()];
                continue;
            }
        } else {
            try {
                if($hasCotacaoColumn){
                    $ins = $db->prepare('INSERT INTO cotacao_convites (cotacao_id, requisicao_id, fornecedor_id, token_hash, expira_em) VALUES (?,?,?,?,?)');
                    $ins->execute([$cotacao_id,$requisicao_id,$fid,$tokenHash,$expira_em]);
                } else {
                    $ins = $db->prepare('INSERT INTO cotacao_convites (requisicao_id, fornecedor_id, token_hash, expira_em) VALUES (?,?,?,?)');
                    $ins->execute([$requisicao_id,$fid,$tokenHash,$expira_em]);
                }
                $conviteId = (int)$db->lastInsertId();
            } catch(Throwable $e){
                $errors[]=['fornecedor_id'=>$fid,'erro'=>$e->getMessage()];
                continue;
            }
        }
        if(!$conviteId){ continue; }
        $createdCount++;
        // Email fornecedor: tentar obter email se não informado
        $email = trim($f['email'] ?? '');
        if(!$email){
            try { $stE = $db->prepare('SELECT email FROM fornecedores WHERE id=?'); $stE->execute([$fid]); $email = $stE->fetchColumn() ?: ''; } catch(Throwable $e){}
        }
        $canExposeToken = (($u['tipo'] ?? '') !== 'cliente');
        $shouldExposeToken = $includeRaw;
        if($email){
            unset($GLOBALS['EMAIL_LAST_ERROR']);
            try {
                $sent = email_send_cotacao_convite(
                    $email,
                    $requisicao_id,
                    $tokenRaw,
                    false,
                    [
                        'convite_id' => $conviteId,
                        'fornecedor_id' => $fid,
                            'expira_em' => $expira_em,
                            'cotacao_id' => $cotacao_id
                    ]
                );
                if($sent === false || (is_array($sent) && empty($sent))){
                    $shouldExposeToken = true;
                    $msg = $GLOBALS['EMAIL_LAST_ERROR'] ?? null;
                    $errors[]=[
                        'fornecedor_id'=>$fid,
                        'erro'=>$msg ? ('Falha ao enviar email: '.$msg) : 'Falha ao enviar email',
                        'token_raw'=>$canExposeToken ? $tokenRaw : null,
                        'tipo'=>'email'
                    ];
                }
            } catch(Throwable $e){
                $shouldExposeToken = true;
                $errors[]=[
                    'fornecedor_id'=>$fid,
                    'erro'=>'Falha ao enviar email: '.$e->getMessage(),
                    'token_raw'=>$canExposeToken ? $tokenRaw : null,
                    'tipo'=>'email'
                ];
            }
        } else {
            $shouldExposeToken = true;
            $errors[]=[
                'fornecedor_id'=>$fid,
                'erro'=>'Fornecedor sem e-mail cadastrado',
                'token_raw'=>$canExposeToken ? $tokenRaw : null,
                'tipo'=>'sem_email'
            ];
        }
        log_requisicao_event(
            $db,
            $requisicao_id,
            $reemitido ? 'cotacao_convite_reemitido' : 'cotacao_convite_enviado',
            $reemitido ? 'Convite de cotação reemitido' : 'Convite de cotação enviado',
            ['fornecedor_id'=>$fid, 'cotacao_id'=>$cotacao_id],
            ['fornecedor_id'=>$fid, 'convite_id'=>$conviteId, 'cotacao_id'=>$cotacao_id]
        );
        // Nunca retornar token_hash; token_raw somente quando explicitamente solicitado por interno
    $payloadConvite = ['convite_id'=>$conviteId,'fornecedor_id'=>$fid,'expira_em'=>$expira_em,'cotacao_id'=>$cotacao_id,'requisicao_id'=>$requisicao_id];
        if($reemitido){ $payloadConvite['reemitido'] = true; }
        if($shouldExposeToken && $canExposeToken){
            $payloadConvite['token_raw'] = $tokenRaw;
        }
        $created[] = $payloadConvite;
    }
    // Auditoria quando tokens brutos foram incluídos na resposta
    if($includeRaw && (($u['tipo'] ?? '') !== 'cliente') && $createdCount>0){
        log_requisicao_event($db,$requisicao_id,'cotacao_convite_tokens_expostos','Tokens de convites retornados na API',null,['qtd'=>$createdCount,'cotacao_id'=>$cotacao_id]);
    }
    echo json_encode(['success'=>true,'criados'=>$created,'skipped'=>$skipped,'errors'=>$errors]);
    exit;
}

if($method==='GET'){
    try { rate_limit_enforce($db, 'api.cotacoes_convites:get', 300, 300, true); } catch(Throwable $e){}
    $cotacao_id = (int)($_GET['cotacao_id'] ?? 0);
    $requisicao_id = (int)($_GET['requisicao_id'] ?? 0);
    if($cotacao_id){
        try {
            $stCot = $db->prepare('SELECT id, requisicao_id FROM cotacoes WHERE id=?');
            $stCot->execute([$cotacao_id]);
            $cotRow = $stCot->fetch(PDO::FETCH_ASSOC);
        } catch(Throwable $e){ $cotRow=false; }
        if(!$cotRow){ http_response_code(404); echo json_encode(['erro'=>'Cotação não encontrada']); exit; }
        $requisicao_id = (int)($cotRow['requisicao_id'] ?? 0);
    }
    if(!$requisicao_id){ http_response_code(400); echo json_encode(['erro'=>'Identificador inválido']); exit; }
    // Escopo cliente para leitura
    if(($u['tipo'] ?? null)==='cliente'){
        $chk = $db->prepare('SELECT 1 FROM requisicoes WHERE id=? AND cliente_id=?');
        $chk->execute([$requisicao_id,(int)($u['cliente_id'] ?? 0)]);
        if(!$chk->fetch()){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); exit; }
    }
    // Nunca expor token_hash ou dados sensíveis
    $selectExtras = $hasCotacaoColumn ? ', cc.cotacao_id' : '';
    if($hasCotacaoColumn && $cotacao_id){
        $st = $db->prepare(
            'SELECT cc.id, cc.fornecedor_id, cc.status, cc.expira_em, cc.enviado_em, cc.respondido_em'.$selectExtras.',
                    COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome,
                    f.cnpj AS fornecedor_cnpj
             FROM cotacao_convites cc
             LEFT JOIN fornecedores f ON f.id = cc.fornecedor_id
             WHERE cc.cotacao_id=?
             ORDER BY cc.id ASC'
        );
        $st->execute([$cotacao_id]);
    } else {
        $st = $db->prepare(
            'SELECT cc.id, cc.fornecedor_id, cc.status, cc.expira_em, cc.enviado_em, cc.respondido_em'.$selectExtras.',
                    COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome,
                    f.cnpj AS fornecedor_cnpj
             FROM cotacao_convites cc
             LEFT JOIN fornecedores f ON f.id = cc.fornecedor_id
             WHERE cc.requisicao_id=?
             ORDER BY cc.id ASC'
        );
        $st->execute([$requisicao_id]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows); exit;
}

if($method==='DELETE'){
    // Apenas internos; cancela convite
    if(($u['tipo'] ?? '') === 'cliente'){ http_response_code(403); echo json_encode(['erro'=>'Proibido']); exit; }
    try { rate_limit_enforce($db, 'api.cotacoes_convites:delete', 60, 300, true); } catch(Throwable $e){}
    // aceitar JSON body ou query param
    $payload = read_request_payload();
    $id = (int)($payload['id'] ?? ($_GET['id'] ?? 0));
    if(!$id){ http_response_code(400); echo json_encode(['erro'=>'id obrigatório']); exit; }
    // Carregar convite
    $selectDelete = $hasCotacaoColumn ? 'id, requisicao_id, cotacao_id, status' : 'id, requisicao_id, status';
    $st = $db->prepare("SELECT $selectDelete FROM cotacao_convites WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if(!$row){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); exit; }
    if($row['status'] === 'cancelado'){ echo json_encode(['success'=>true,'status'=>'cancelado']); exit; }
    try {
    $up = $db->prepare("UPDATE cotacao_convites SET status='cancelado' WHERE id=? AND status='enviado'");
    $up->execute([$id]);
    $logMeta = ['id'=>$id,'status'=>'cancelado'];
    if($hasCotacaoColumn && isset($row['cotacao_id'])){ $logMeta['cotacao_id'] = (int)$row['cotacao_id']; }
    log_requisicao_event($db,(int)$row['requisicao_id'],'cotacao_convite_cancelado','Convite de cotação cancelado',['id'=>$id,'cotacao_id'=>$logMeta['cotacao_id'] ?? null],$logMeta);
        echo json_encode(['success'=>true,'status'=>'cancelado']);
    } catch(Throwable $e){ http_response_code(500); echo json_encode(['erro'=>'Falha ao cancelar']); }
    exit;
}

http_response_code(405); echo json_encode(['erro'=>'Método não suportado']);
