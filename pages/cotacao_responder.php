<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/timeline.php'; // novo: para seg_log_token_fail
require_once __DIR__ . '/../includes/legacy_anexos.php';
$db = get_db_connection();
// Rate limit: 60 requests por 5 minutos por IP para esta rota pública
rate_limit_enforce($db, 'public.cotacao_responder', 60, 300, false);
// Rate limit adicional para submissões (POST) nesta rota pública
if(($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'){
    // Mais restrito: 15 envios por 10 minutos por IP
    rate_limit_enforce($db, 'public.cotacao_responder.submit', 15, 600, false);
}
$token = $_GET['token'] ?? '';
$conv = $_GET['conv'] ?? '';
$erro = '';
$sucesso = '';
$imagem_meta = [];

// Verificar / autocorrigir coluna tipo_frete
$has_tipo_frete = false;
try {
    $chk = $db->query("SHOW COLUMNS FROM cotacoes LIKE 'tipo_frete'");
    if ($chk && $chk->rowCount() > 0) { $has_tipo_frete = true; }
    else {
        // tentar criar automaticamente (idempotente)
        try { $db->exec("ALTER TABLE cotacoes ADD COLUMN tipo_frete VARCHAR(40) NULL AFTER status"); $has_tipo_frete = true; }
        catch (Exception $e2) { /* ignorar se falhar */ }
    }
} catch (Exception $e) { /* silencioso */ }

// --- Suporte a dois fluxos: convite individual (conv) OU link público (token) ---
$cotacao = null; $convite = null;
if ($conv) {
    // Fluxo convite individual primeiro (para não morrer no token faltante)
    $hash = hash('sha256', $conv);
    try {
        $stCv = $db->prepare('SELECT cc.id, cc.fornecedor_id, cc.requisicao_id, cc.status, cc.expira_em FROM cotacao_convites cc WHERE cc.token_hash=? AND cc.expira_em>NOW()');
        $stCv->execute([$hash]);
        $convite = $stCv->fetch(PDO::FETCH_ASSOC);
        if ($convite) {
            $stC = $db->prepare('SELECT * FROM cotacoes WHERE requisicao_id=? ORDER BY id DESC LIMIT 1');
            $stC->execute([(int)$convite['requisicao_id']]);
            $cotacao = $stC->fetch(PDO::FETCH_ASSOC);
        } else {
            // log seguro de falha de token de convite
            try { seg_log_token_fail($db, 'public.cotacao_responder:conv', $hash, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
            die('<!DOCTYPE html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Convite inválido</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="bg-dark text-light"><div class="container py-5"><div class="card-matrix"><div class="card-header-matrix">Convite Indisponível</div><div class="card-body"><div class="alert alert-danger mb-0">Convite inválido ou expirado.</div></div></div></div></body></html>');
        }
    } catch (Throwable $e) {
        die('<!DOCTYPE html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Erro</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="bg-dark text-light"><div class="container py-5"><div class="card-matrix"><div class="card-header-matrix">Erro</div><div class="card-body"><div class="alert alert-danger mb-0">Falha ao validar convite.</div></div></div></div></body></html>');
    }
} elseif ($token) {
    // Fluxo link público
    $stmt = $db->prepare('SELECT * FROM cotacoes WHERE token = ? AND token_expira_em > NOW()');
    $stmt->execute([$token]);
    $cotacao = $stmt->fetch();
    if (!$cotacao) {
        // log seguro de falha de token legado
        try { seg_log_token_fail($db, 'public.cotacao_responder:token', hash('sha256',$token), $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
        die('<!DOCTYPE html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Cotação Expirada</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="bg-dark text-light"><div class="container py-5"><div class="card-matrix"><div class="card-header-matrix">Cotação Indisponível</div><div class="card-body"><div class="alert alert-danger mb-0">Token inválido ou expirado.</div></div></div></div></body></html>');
    }
} else {
    // Nenhum identificador fornecido
    die('<!DOCTYPE html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Link inválido</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="bg-dark text-light"><div class="container py-5"><div class="card-matrix"><div class="card-header-matrix">Link inválido</div><div class="card-body"><div class="alert alert-danger mb-0">Parâmetros ausentes. Use o link recebido por e-mail.</div></div></div></div></body></html>');
}

// Buscar itens da requisição (requer cotacao válida)
$stmt = $db->prepare('SELECT ri.*, p.nome, p.unidade FROM requisicao_itens ri JOIN produtos p ON ri.produto_id = p.id WHERE ri.requisicao_id = ?');
$stmt->execute([(int)$cotacao['requisicao_id']]);
$itens = $stmt->fetchAll();

// Tempo restante do token/convite (segundos)
$expRef = null;
if ($convite && !empty($convite['expira_em'])) { $expRef = $convite['expira_em']; }
elseif (!empty($cotacao['token_expira_em'])) { $expRef = $cotacao['token_expira_em']; }
$token_restante_seg = $expRef ? max(0, strtotime($expRef) - time()) : 0;

// Preservar valores do form (simples)
$old_fornecedor = '';
$old_cnpj = '';
$old_prazo = '';
$old_observacoes = '';
$old_tipo_frete = $has_tipo_frete ? ($cotacao['tipo_frete'] ?? '') : '';
$old_pagamento_dias = 0;

$INCOTERMS_VALIDOS = ['CIF','FOB','EXW','DAP','DDP'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fornecedor = trim($_POST['fornecedor'] ?? '');
    $cnpj_input = trim($_POST['cnpj'] ?? '');
    $prazo = (int)($_POST['prazo'] ?? 0);
    $observacoes = trim($_POST['observacoes'] ?? '');
    $pagamento_dias = (int)($_POST['pagamento_dias'] ?? 0); // novo campo
    $valores = $_POST['valor'] ?? [];
    $quantidades = $_POST['quantidade'] ?? [];
    $tipo_frete_input = strtoupper(trim($_POST['tipo_frete'] ?? ''));

    if($has_tipo_frete && $tipo_frete_input && !in_array($tipo_frete_input, $INCOTERMS_VALIDOS)) {
        $erro = 'Tipo de frete inválido.';
    }

    $old_fornecedor = $fornecedor;
    $old_cnpj = $cnpj_input;
    $old_prazo = $prazo;
    $old_observacoes = $observacoes;
    $old_pagamento_dias = $pagamento_dias; // novo

    // Normalizar CNPJ (apenas dígitos)
    $cnpj_digits = preg_replace('/\D/','', $cnpj_input);
    if (!$erro && strlen($cnpj_digits) !== 14) {
        $erro = 'CNPJ inválido (deve conter 14 dígitos).';
    }

    // Verificar existência do fornecedor
    $fornecedor_id = null;
    if (!$erro) {
        $stmtF = $db->prepare("SELECT id, razao_social, nome_fantasia FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/', ''),' ', '') = ? LIMIT 1");
        $stmtF->execute([$cnpj_digits]);
        $found = $stmtF->fetch();
        if ($found) {
            $fornecedor_id = (int)$found['id'];
        } else {
            $erro = 'CNPJ não encontrado na base de fornecedores.';
        }
    }

    // Impedir múltiplas propostas do mesmo fornecedor para a mesma cotação
    if (!$erro && $fornecedor_id) {
        $stmtDup = $db->prepare('SELECT id FROM propostas WHERE cotacao_id=? AND fornecedor_id=? LIMIT 1');
        $stmtDup->execute([(int)$cotacao['id'], $fornecedor_id]);
        if ($stmtDup->fetchColumn()) {
            $erro = 'Este fornecedor já enviou uma proposta para esta cotação.';
        }
    }

    // Verificações adicionais de campos obrigatórios
    if (!$erro && $fornecedor === '') {
        $erro = 'Informe o nome do fornecedor.';
    }
    if (!$erro && $prazo < 0) {
        $erro = 'Prazo inválido.';
    }
    if(!$erro && $pagamento_dias < 0){ $erro = 'Pagamento (dias) inválido.'; } // validação
    if ($has_tipo_frete && !$erro && !$tipo_frete_input) {
        $erro = 'Informe o tipo de frete.';
    }

    // Validar se pelo menos um preço foi informado (>0)
    if (!$erro) {
        $tem_preco = false;
        foreach ($itens as $i) {
            $pid = $i['produto_id'];
            if (isset($valores[$pid]) && (float)$valores[$pid] > 0) { $tem_preco = true; break; }
        }
        if (!$tem_preco) {
            $erro = 'Informe ao menos um preço unitário.';
        }
    }

    $imagem_url = '';
    if (!$erro && isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/','',$ext);
        if (!in_array($ext, ['png','jpg','jpeg','pdf','webp'])) { $ext = 'bin'; }
        $dest = '../assets/images/proposta_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['imagem']['tmp_name'], $dest)) {
            $imagem_url = $dest;
            $imagem_meta = [
                'nome_original' => $_FILES['imagem']['name'] ?? basename($dest),
                'mime' => $_FILES['imagem']['type'] ?? null,
                'tamanho' => $_FILES['imagem']['size'] ?? null
            ];
        }
    }
    try {
        if (!$erro) {
            $db->beginTransaction();
            // Recalcular valor total
            $valor_total = 0;
            foreach ($itens as $i) {
                $pid = $i['produto_id'];
                $preco = (float)($valores[$pid] ?? 0);
                $qtd = (float)($quantidades[$pid] ?? $i['quantidade']);
                if ($preco < 0) $preco = 0; if ($qtd < 0) $qtd = 0;
                $valor_total += $preco * $qtd;
            }
            $stmt = $db->prepare('INSERT INTO propostas (cotacao_id, fornecedor_id, valor_total, prazo_entrega, pagamento_dias, observacoes, imagem_url, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$cotacao['id'], $fornecedor_id, $valor_total, $prazo, $pagamento_dias, $observacoes, $imagem_url, 'enviada']);
            $proposta_id = $db->lastInsertId();
            $stmt_item = $db->prepare('INSERT INTO proposta_itens (proposta_id, produto_id, preco_unitario, quantidade) VALUES (?, ?, ?, ?)');
            foreach ($itens as $i) {
                $pid = $i['produto_id'];
                $stmt_item->execute([$proposta_id, $pid, (float)($valores[$pid] ?? 0), (float)($quantidades[$pid] ?? $i['quantidade'])]);
            }
            if ($imagem_url) {
                legacy_sync_proposta_anexo($db, (int)$proposta_id, (int)$cotacao['id'], (int)$cotacao['requisicao_id'], $imagem_url, $imagem_meta);
            }
            // Atualiza tipo_frete na cotação (definido pelo fornecedor)
            if($has_tipo_frete) {
                $stmtTf = $db->prepare('UPDATE cotacoes SET tipo_frete=? WHERE id=?');
                $stmtTf->execute([$tipo_frete_input ?: null, $cotacao['id']]);
            }
            $db->commit();
            $sucesso = 'Proposta enviada com sucesso!';
            // Limpar campos após sucesso
            $old_fornecedor = $old_cnpj = $old_prazo = $old_observacoes = '';
            // Registrar evento timeline (cotacao_resposta_recebida + proposta_criada legacy)
            try {
                require_once __DIR__.'/../includes/timeline.php';
                $stReq = $db->prepare('SELECT requisicao_id FROM cotacoes WHERE id=?');
                $stReq->execute([$cotacao['id']]);
                $reqIdEv = (int)($stReq->fetchColumn() ?: 0);
                if($reqIdEv){
                    log_requisicao_event($db,$reqIdEv,'cotacao_resposta_recebida','Resposta de cotação recebida',null,['proposta_id'=>$proposta_id,'cotacao_id'=>$cotacao['id'],'fornecedor_id'=>$fornecedor_id]);
                    log_requisicao_event($db,$reqIdEv,'proposta_criada','Proposta criada',null,['proposta_id'=>$proposta_id,'cotacao_id'=>$cotacao['id'],'fornecedor_id'=>$fornecedor_id]);
                }
            } catch(Throwable $e) { /* ignore */ }
            // Se veio por convite individual, marcar convite respondido
            if($conv){
                $hashConv = hash('sha256',$conv);
                try { $upCv = $db->prepare("UPDATE cotacao_convites SET status='respondido', respondido_em=NOW() WHERE token_hash=? AND status='enviado'"); $upCv->execute([$hashConv]); } catch(Throwable $e){ }
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        $erro = 'Erro ao enviar proposta: ' . $e->getMessage();
    }
}

// Fluxo convite individual: conv é token raw -> hash
if($conv){
    $hash = hash('sha256',$conv);
    try {
        $stCv = $db->prepare('SELECT cc.id, cc.fornecedor_id, cc.requisicao_id, cc.status, cc.expira_em, r.id as req_id FROM cotacao_convites cc JOIN requisicoes r ON r.id=cc.requisicao_id WHERE cc.token_hash=? AND cc.expira_em>NOW()');
        $stCv->execute([$hash]);
        $convite = $stCv->fetch(PDO::FETCH_ASSOC);
        if($convite){
            // Encontrar cotacao aberta correspondente
            $stC = $db->prepare('SELECT * FROM cotacoes WHERE requisicao_id=? ORDER BY id DESC LIMIT 1');
            $stC->execute([$convite['requisicao_id']]);
            $cotacao = $stC->fetch(PDO::FETCH_ASSOC) ?: $cotacao; // fallback se já definido por token global
        } else {
            // log seguro de falha de token de convite (segunda verificação)
            try { seg_log_token_fail($db, 'public.cotacao_responder:conv', $hash, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, null); } catch(Throwable $e){}
            die('<!DOCTYPE html><html lang="pt-br" data-bs-theme="dark"><head><meta charset="utf-8"><title>Convite inválido</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/nexus.css"></head><body class="bg-dark text-light"><div class="container py-5"><div class="card-matrix"><div class="card-header-matrix">Convite Indisponível</div><div class="card-body"><div class="alert alert-danger mb-0">Convite inválido ou expirado.</div></div></div></div></body></html>');
        }
    } catch(Throwable $e){ /* silêncio */ }
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Responder Cotação - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link rel="icon" href="../assets/images/logo.png" type="image/png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
        .page-wrapper { max-width: 1100px; }
        /* Espaçamentos adicionais */
        .page-wrapper > *:not(:first-child) { scroll-margin-top: 2rem; }
        .card-matrix { margin-bottom: 2.75rem !important; }
        .card-matrix .card-body { padding: 1.65rem 1.75rem; }
        .page-header-gestao { margin-bottom: 2.5rem!important; padding-bottom:.75rem; border-bottom:1px solid var(--matrix-border); }
        .table-cotacao th { white-space: nowrap; }
        .table-cotacao input { min-width: 110px; }
        .valor-total-box { font-size:1.1rem; font-weight:600; }
        .badge-status { font-size:.75rem; }
        .card-matrix .form-label { font-weight:500; }
        .drop-zone { border:2px dashed var(--matrix-border); padding:1.6rem; border-radius:.9rem; text-align:center; cursor:pointer; transition:.25s; }
        .drop-zone:hover { background:rgba(255,255,255,.03); }
        /* Token Chip personalizado */
    .token-chip { display:inline-flex; align-items:center; gap:.55rem; background:rgba(0,0,0,.25); color:#f7f5ef; padding:.55rem .95rem .55rem .75rem; border-radius:999px; font-size:.7rem; font-weight:600; letter-spacing:.35px; position:relative; line-height:1; border:1px solid rgba(255,255,255,.12); box-shadow:0 8px 22px rgba(0,0,0,.35); overflow:hidden; backdrop-filter:blur(6px); }
    .token-chip .pulse { width:8px; height:8px; background:#39da8a; border-radius:50%; box-shadow:0 0 0 0 rgba(57,218,138,.7); animation:pulse 2s infinite; }
        @keyframes pulse { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.7);} 70%{box-shadow:0 0 0 9px rgba(34,197,94,0);} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0);} }
        .token-chip .time { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', monospace; font-weight:500; background:rgba(255,255,255,.15); padding:.15rem .45rem; border-radius:.5rem; }
    .token-chip.expiring { background:rgba(249, 159, 51, .25); border-color:rgba(249,159,51,.6); }
    .token-chip.expired { background:rgba(177, 22, 22, .35); border-color:rgba(255,87,87,.65); }
    .token-chip.expired .pulse { background:#ef4444; animation:none; box-shadow:none; }
        .token-chip.expired .time { background:rgba(0,0,0,.35); }
        .cnpj-invalid { border-color:#dc3545 !important; }
        .field-required:after { content:' *'; color:#ef4444; }
        .alert-success-light {
            background: #f2fbf2;
            color: #134e2c;
            border: 1px solid rgba(52, 211, 153, .45);
            box-shadow: 0 6px 18px rgba(12, 50, 32, .15);
        }
        .alert-danger-light {
            background: #fef2f2;
            color: #7f1d1d;
            border: 1px solid rgba(239, 68, 68, .45);
            box-shadow: 0 6px 18px rgba(63, 7, 7, .12);
        }
    </style>
</head>
<body>
<div class="container py-5 page-wrapper">
    <header class="page-header-gestao d-flex flex-wrap gap-3 justify-content-between align-items-start align-items-md-center">
        <div class="me-3">
            <h1 class="page-title mb-2">Responder Cotação</h1>
            <p class="text-secondary mb-0">Preencha os preços e condições para enviar sua proposta.</p>
        </div>
        <div class="ms-auto d-flex align-items-center gap-2">
            <div id="token-chip" class="token-chip" data-remaining="<?php echo (int)$token_restante_seg; ?>" title="Expira em: <?php echo htmlspecialchars($expRef ?? ($cotacao['token_expira_em'] ?? '')); ?>">
                <span class="pulse"></span>
                <span><?php echo $conv ? 'Convite válido' : 'Token válido'; ?></span>
                <span class="time" id="token-remaining">--:--</span>
            </div>
        </div>
    </header>

    <?php if ($erro): ?><div class="alert alert-danger alert-danger-light mb-4" data-bs-theme="light"><?php echo htmlspecialchars($erro); ?></div><?php endif; ?>
    <?php if ($sucesso): ?><div class="alert alert-success alert-success-light mb-4" data-bs-theme="light"><?php echo htmlspecialchars($sucesso); ?></div><?php endif; ?>

    <div class="card-matrix">
        <div class="card-header-matrix"><i class="bi bi-info-circle me-2"></i>Dados da Cotação</div>
        <div class="card-body small text-secondary">
            <div class="row g-3">
                <div class="col-md-3"><strong>ID Cotação:</strong> #<?php echo (int)$cotacao['id']; ?></div>
                <div class="col-md-3"><strong>ID Requisição:</strong> #<?php echo (int)$cotacao['requisicao_id']; ?></div>
                <div class="col-md-3"><strong>Rodada:</strong> <?php echo (int)$cotacao['rodada']; ?></div>
                <div class="col-md-3"><strong>Status:</strong> <?php echo htmlspecialchars($cotacao['status']); ?></div>
            </div>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
        <div class="card-matrix mb-4">
            <div class="card-header-matrix"><i class="bi bi-building me-2"></i>Dados do Fornecedor</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label field-required">CNPJ</label>
                    <input type="text" name="cnpj" id="cnpj" class="form-control" required maxlength="18" value="<?php echo htmlspecialchars($old_cnpj); ?>" placeholder="00.000.000/0000-00" autocomplete="off">
                    <div class="invalid-feedback">Informe um CNPJ válido.</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label field-required">Nome do Fornecedor</label>
                    <input type="text" name="fornecedor" id="fornecedor" class="form-control" required value="<?php echo htmlspecialchars($old_fornecedor); ?>">
                    <div class="invalid-feedback">Informe o nome.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label field-required">Prazo Entrega (dias)</label>
                    <input type="number" name="prazo" min="0" class="form-control" required value="<?php echo htmlspecialchars($old_prazo); ?>">
                    <div class="invalid-feedback">Informe o prazo.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Pagamento (dias)</label>
                    <input type="number" name="pagamento_dias" min="0" class="form-control" value="<?php echo htmlspecialchars($old_pagamento_dias ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label<?php echo $has_tipo_frete ? ' field-required' : ''; ?>">Tipo de Frete</label>
                    <select name="tipo_frete" class="form-select" <?php echo $has_tipo_frete ? 'required' : 'disabled'; ?>>
                        <option value="" disabled <?php echo $old_tipo_frete?'' : 'selected';?>>(selecione)</option>
                        <?php if($has_tipo_frete){ foreach($INCOTERMS_VALIDOS as $opt): ?>
                            <option value="<?php echo $opt; ?>" <?php echo $old_tipo_frete===$opt?'selected':''; ?>><?php echo $opt; ?></option>
                        <?php endforeach; } ?>
                    </select>
                    <?php if(!$has_tipo_frete): ?><div class="form-text text-warning">Coluna tipo_frete ausente. Aplique a migration 20250817_0008.</div><?php endif; ?>
                    <div class="invalid-feedback">Selecione o tipo de frete.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Observações</label>
                    <textarea name="observacoes" class="form-control" rows="3" placeholder="Condições comerciais, impostos..."><?php echo htmlspecialchars($old_observacoes); ?></textarea>
                </div>
                <?php if($erro && strpos($erro,'CNPJ')!==false): ?>
                    <div class="col-12"><div class="alert alert-warning py-2 px-3 mb-0"><i class="bi bi-exclamation-triangle me-1"></i><?php echo htmlspecialchars($erro); ?></div></div>
                <?php endif; ?>
            </div>
            <div class="card-body pt-0 small text-secondary">O CNPJ deve já estar cadastrado como fornecedor ativo.</div>
        </div>

        <div class="card-matrix mb-4" id="itens-list">
            <div class="card-header-matrix d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-ul me-2"></i>Itens da Cotação</span>
                <span class="valor-total-box">Total: <span id="valor-total-display">R$ 0,00</span></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-cotacao mb-0">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th style="width:130px">Qtd</th>
                            <th>Unid</th>
                            <th style="width:150px">Preço Unit.</th>
                            <th style="width:140px">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($itens as $i): $pid = (int)$i['produto_id']; ?>
                        <tr data-produto-id="<?php echo $pid; ?>">
                            <td><?php echo htmlspecialchars($i['nome']); ?></td>
                            <td><input type="number" step="0.01" min="0" name="quantidade[<?php echo $pid; ?>]" value="<?php echo (float)$i['quantidade']; ?>" class="form-control input-qtd" required></td>
                            <td><?php echo htmlspecialchars($i['unidade']); ?></td>
                            <td><input type="number" step="0.01" min="0" name="valor[<?php echo $pid; ?>]" class="form-control input-preco" placeholder="0,00" required></td>
                            <td class="subtotal-cell text-secondary">R$ 0,00</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-0 small text-secondary">Informe preço unitário e ajuste quantidade se necessário.</div>
        </div>

        <div class="card-matrix mb-4">
            <div class="card-header-matrix"><i class="bi bi-paperclip me-2"></i>Anexo (Opcional)</div>
            <div class="card-body">
                <label class="form-label">Imagem / PDF (até ~5MB)</label>
                <div class="drop-zone" id="drop-zone">
                    <i class="bi bi-cloud-upload fs-4 d-block mb-2"></i>
                    <span id="drop-zone-text">Arraste o arquivo aqui ou clique para selecionar</span>
                    <input type="file" name="imagem" class="d-none" id="input-file" accept="image/*,application/pdf">
                </div>
            </div>
        </div>

        <div class="d-flex gap-3 mb-5">
            <button type="submit" class="btn btn-matrix-primary px-4"><i class="bi bi-send me-1"></i>Enviar Proposta</button>
            <a href="https://<?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? ''); ?>" class="btn btn-matrix-secondary">Cancelar</a>
        </div>
    </form>

    <p class="text-center text-secondary small mb-0">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?> - Portal de Resposta de Cotações</p>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) { event.preventDefault(); event.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();

function formatCurrency(v){
    return v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
}

function recalc(){
    let total = 0;
    document.querySelectorAll('tbody tr[data-produto-id]').forEach(tr => {
        const qtd = parseFloat(tr.querySelector('.input-qtd').value.replace(',','.')) || 0;
        const preco = parseFloat(tr.querySelector('.input-preco').value.replace(',','.')) || 0;
        const sub = qtd * preco;
        total += sub;
        tr.querySelector('.subtotal-cell').textContent = formatCurrency(sub);
    });
    document.getElementById('valor-total-display').textContent = formatCurrency(total);
}

document.addEventListener('input', e => {
    if (e.target.classList.contains('input-qtd') || e.target.classList.contains('input-preco')) recalc();
});

document.addEventListener('DOMContentLoaded', recalc);

// Upload
const dropZone = document.getElementById('drop-zone');
const inputFile = document.getElementById('input-file');
dropZone.addEventListener('click', ()=> inputFile.click());
dropZone.addEventListener('dragover', e=>{ e.preventDefault(); dropZone.classList.add('border-primary'); });
dropZone.addEventListener('dragleave', ()=> dropZone.classList.remove('border-primary'));
dropZone.addEventListener('drop', e=>{ e.preventDefault(); dropZone.classList.remove('border-primary'); if(e.dataTransfer.files[0]){ inputFile.files = e.dataTransfer.files; updateFileName(); }});
inputFile.addEventListener('change', updateFileName);
function updateFileName(){
    const t = document.getElementById('drop-zone-text');
    if (inputFile.files.length) t.textContent = 'Selecionado: ' + inputFile.files[0].name; else t.textContent='Arraste o arquivo aqui ou clique para selecionar';
}

function showToast(message, type='info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;
    const toastId = 'toast-' + Date.now();
    const toastHtml = `<div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button></div></div>`;
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3000 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', ()=> toastEl.remove());
}

// Countdown Token
(function(){
  const chip = document.getElementById('token-chip');
  if(!chip) return; 
  const timeSpan = document.getElementById('token-remaining');
  let remaining = parseInt(chip.dataset.remaining,10) || 0;
  function fmt(sec){
    const m = Math.floor(sec/60); const s = sec%60; return String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  }
  function tick(){
    if(remaining <= 0){
      chip.classList.remove('expiring');
      chip.classList.add('expired');
      chip.querySelector('span:nth-child(2)').textContent = 'Token expirado';
      timeSpan.textContent = '00:00';
      return;
    }
    timeSpan.textContent = fmt(remaining);
    if(remaining <= 300) chip.classList.add('expiring'); // < 5min
    remaining--;
    setTimeout(tick,1000);
  }
  tick();
})();

// Máscara e validação simples de CNPJ no front-end
(function(){
  const cnpj = document.getElementById('cnpj');
  const fornecedorInput = document.getElementById('fornecedor');
  let lastLookup = '';
  let abortCtrl = null;
  if(!cnpj) return;
  function format(v){
    let d = v.replace(/\D/g,'').slice(0,14);
    let out = '';
    if(d.length>0) out = d.substring(0,2);
    if(d.length>=3) out += '.'+d.substring(2,5);
    if(d.length>=6) out += '.'+d.substring(5,8);
    if(d.length>=9) out += '/'+d.substring(8,12);
    if(d.length>=13) out += '-'+d.substring(12,14);
    return out;
  }
  function lookup(){
     const digits = cnpj.value.replace(/\D/g,'');
     if(digits.length !== 14){ cnpj.classList.add('cnpj-invalid'); return; }
     cnpj.classList.remove('cnpj-invalid');
     if(digits === lastLookup) return; // evita repetição
     lastLookup = digits;
     if(abortCtrl) abortCtrl.abort();
     abortCtrl = new AbortController();
     fetch('../api/fornecedor_lookup.php?cnpj='+digits,{signal:abortCtrl.signal})
       .then(r=>r.json())
       .then(js=>{
          if(js && js.found && js.fornecedor){
             if(!fornecedorInput.value){ fornecedorInput.value = js.fornecedor.razao_social || js.fornecedor.nome_fantasia || ''; }
             fornecedorInput.dataset.lookupOk = '1';
             fornecedorInput.classList.remove('is-invalid');
          } else {
             fornecedorInput.dataset.lookupOk = '0';
          }
       }).catch(()=>{});
  }
  cnpj.addEventListener('input', ()=>{ cnpj.value = format(cnpj.value); if(cnpj.value.replace(/\D/g,'').length===14) lookup(); });
  cnpj.addEventListener('blur', lookup);
})();

// Mobile Tables: transformar tabelas em "cards" no celular (standalone page)
(function(){
  function applyMobileTableCards(){
    const tables = document.querySelectorAll('table');
    tables.forEach(tbl => {
      if (tbl.dataset.mobileCardsApplied === '1') return;
      // Mapear cabeçalhos
      const headers = Array.from(tbl.querySelectorAll('thead th')).map(th => th.textContent.trim());
      if (headers.length === 0) return; // requer thead para rotular
      // Atribuir data-label por célula
      tbl.querySelectorAll('tbody tr').forEach(tr => {
        Array.from(tr.children).forEach((cell, idx) => {
          if (cell.tagName && cell.tagName.toLowerCase() === 'td') {
            if (!cell.hasAttribute('data-label') && headers[idx]) {
              cell.setAttribute('data-label', headers[idx]);
            }
          }
        });
      });
      // Anexar classe do wrapper para estilo mobile
      let wrapper = tbl.closest('.table-responsive');
      if (wrapper) {
        wrapper.classList.add('table-mobile-cards');
      } else {
        const div = document.createElement('div');
        div.className = 'table-mobile-cards';
        if (tbl.parentNode) { tbl.parentNode.insertBefore(div, tbl); div.appendChild(tbl); }
      }
      tbl.dataset.mobileCardsApplied = '1';
    });
  }
  window.addEventListener('DOMContentLoaded', applyMobileTableCards);
})();
</script>
</body>
</html>