<?php
session_start();
require_once __DIR__.'/../includes/auth.php';
$u = auth_requer_login();
if (!auth_can('config.ver')) { http_response_code(403); echo 'Sem acesso'; exit; }
require_once __DIR__.'/../includes/db.php';
$db = get_db_connection();

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    try { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } catch (Throwable $e) { $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32)); }
}

// Salvar configurações (técnicas + branding: logo)
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!auth_can('config.editar')) { http_response_code(403); echo 'Sem permissão editar'; exit; }
    // Verificação CSRF
    $csrfOk = isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
    if (!$csrfOk) { http_response_code(400); echo 'Token CSRF inválido'; exit; }
    foreach ($_POST['cfg'] ?? [] as $ch => $val) {
        $st = $db->prepare('INSERT INTO configuracoes (chave,valor,tipo,atualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), tipo=VALUES(tipo), atualizado_por=VALUES(atualizado_por), atualizado_em=NOW()');
        $st->execute([$ch, trim($val), 'string', $u['id']]);
        auditoria_log($u['id'],'config_update','configuracoes',null,['chave'=>$ch]);
    }

    // --- Branding: LOGO ---
    // Remover logo atual (se marcado)
    if (!empty($_POST['remove_logo'])) {
        try {
            $db->prepare('DELETE FROM configuracoes WHERE chave=?')->execute(['logo']);
            auditoria_log($u['id'],'config_update','configuracoes',null,['chave'=>'logo','acao'=>'remover']);
        } catch (Throwable $e) { /* ignora falha de remoção */ }
    }

    // Definir logo via URL manual (prioridade sobre upload se preenchido)
    $logoUrl = trim($_POST['logo_url'] ?? '');
    if ($logoUrl !== '') {
        // Aceita http(s), data: URI, caminho absoluto "/" ou relativo a partir do webroot (ex.: assets/images/...)
        $st = $db->prepare('INSERT INTO configuracoes (chave,valor,tipo,atualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), tipo=VALUES(tipo), atualizado_por=VALUES(atualizado_por), atualizado_em=NOW()');
        $st->execute(['logo', $logoUrl, 'string', $u['id']]);
        auditoria_log($u['id'],'config_update','configuracoes',null,['chave'=>'logo','origem'=>'url']);
    } else if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        // Upload de arquivo de logo
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','gif','svg'])) {
            $destDir = __DIR__ . '/../assets/images';
            if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
            // Nome padrão para evitar quebrar referências: logo.<ext>
            $dest = $destDir . '/logo.' . $ext;
            if (@move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $url = 'assets/images/' . 'logo.' . $ext;
                $st = $db->prepare('INSERT INTO configuracoes (chave,valor,tipo,atualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), tipo=VALUES(tipo), atualizado_por=VALUES(atualizado_por), atualizado_em=NOW()');
                $st->execute(['logo', $url, 'string', $u['id']]);
                auditoria_log($u['id'],'config_update','configuracoes',null,['chave'=>'logo','origem'=>'upload']);
            }
        }
    }

    header('Location: configuracoes.php?saved=1');
    exit;
}

// Carregar configurações atuais
$rows = $db->query('SELECT * FROM configuracoes ORDER BY chave')->fetchAll(PDO::FETCH_ASSOC);
$map = [];
foreach ($rows as $r) $map[$r['chave']] = $r;
function cfg($k,$def=''){global $map; return $map[$k]['valor'] ?? $def;}

?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Configurações</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/nexus.css">
<?php
// Tentar obter logo via branding centralizado para favicon
$logoFav = null;
try { require_once __DIR__.'/../includes/branding.php'; $logoFav = $branding['logo_url'] ?? null; } catch(Throwable $e) {}
if($logoFav){
    echo '<link rel="icon" type="image/png" href="'.htmlspecialchars($logoFav).'">';
}
?>
<style>
/* UI claro alinhado ao nexus.css */
.page-wrap{max-width:1100px;margin:2rem auto;padding:0 1rem}
.section-title{margin:1.4rem 0 .6rem;font-size:14px;color:#4b5563;font-weight:600;letter-spacing:.4px;text-transform:uppercase}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:.5rem}
.card-field{background:#fff;border:1px solid rgba(0,0,0,.08);padding:.9rem;border-radius:12px}
.card-field label{font-size:11px;color:#6b7280;font-weight:600;letter-spacing:.3px;margin-bottom:.35rem;display:block}
.card-field small{display:block;font-size:10px;color:#9ca3af;margin-top:4px}
hr.divider{border-color:rgba(0,0,0,.08);margin:2rem 0 1rem}
</style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<div class="page-wrap">
  <h1 class="h4 mb-1">Configurações Técnicas</h1>
  <p class="text-muted small">Somente parâmetros internos.</p>
  <?php if(isset($_GET['saved'])): ?>
    <div class="alert alert-success py-2 small">Configurações salvas com sucesso.</div>
  <?php endif; ?>
  <form method="post" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['csrf_token'] ?? '')?>">

    <div class="section-title">Aplicação</div>
    <div class="form-grid">
      <div class="card-field">
        <label for="cfg-app-debug">Modo Debug (0/1)</label>
        <input id="cfg-app-debug" class="form-control form-control-sm" type="number" min="0" max="1" inputmode="numeric" name="cfg[app.debug]" value="<?=htmlspecialchars(cfg('app.debug','1'))?>">
        <small>Chave: app.debug</small>
      </div>
      <div class="card-field">
        <label for="cfg-app-timezone">Fuso Horário</label>
        <input id="cfg-app-timezone" class="form-control form-control-sm" type="text" name="cfg[app.timezone]" placeholder="America/Sao_Paulo" value="<?=htmlspecialchars(cfg('app.timezone','America/Sao_Paulo'))?>">
        <small>Chave: app.timezone</small>
      </div>
    </div>

    <div class="section-title">Exportação & PDF</div>
    <div class="form-grid">
      <div class="card-field">
        <label for="cfg-export-max">Máx. Registros Exportação</label>
        <input id="cfg-export-max" class="form-control form-control-sm" type="number" min="100" step="100" name="cfg[export.max_registros]" value="<?=htmlspecialchars(cfg('export.max_registros','5000'))?>">
        <small>Chave: export.max_registros</small>
      </div>
      <div class="card-field">
        <label for="cfg-pdf-logo">Logo para PDF (caminho)</label>
        <input id="cfg-pdf-logo" class="form-control form-control-sm" type="text" name="cfg[pdf.logo_path]" value="<?=htmlspecialchars(cfg('pdf.logo_path',''))?>">
        <small>Chave: pdf.logo_path</small>
      </div>
      <div class="card-field">
        <label for="cfg-pdf-footer">Texto de Rodapé PDF</label>
        <input id="cfg-pdf-footer" class="form-control form-control-sm" type="text" name="cfg[pdf.footer_text]" value="<?=htmlspecialchars(cfg('pdf.footer_text','Sistema Atlas'))?>">
        <small>Chave: pdf.footer_text</small>
      </div>
    </div>

    <div class="section-title">Branding</div>
    <div class="form-grid">
      <div class="card-field">
        <label for="logo">Logo do sistema (upload)</label>
        <input id="logo" class="form-control form-control-sm" type="file" name="logo" accept=".png,.jpg,.jpeg,.gif,.svg">
        <small>Será salvo em assets/images/logo.&lt;ext&gt; e aplicado no topo (navbar/PDFs).</small>
        <?php $lp = cfg('logo',''); $lpWeb = $lp; if($lp && !preg_match('#^(https?://|/|data:)#i',$lp)){ $lpWeb = '../'.ltrim($lp,'/'); } ?>
        <?php if($lp): ?>
          <div class="mt-2 d-flex align-items-center gap-2">
            <img src="<?=htmlspecialchars($lpWeb)?>" alt="logo atual" style="height:34px;max-width:180px;object-fit:contain;background:#fff;border:1px solid rgba(0,0,0,.06);padding:4px;border-radius:8px;">
            <a href="<?=htmlspecialchars($lpWeb)?>" target="_blank" rel="noopener" class="small">abrir</a>
          </div>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" value="1" id="remove_logo" name="remove_logo">
            <label class="form-check-label small" for="remove_logo">Remover logo atual</label>
          </div>
        <?php endif; ?>
      </div>
      <div class="card-field">
        <label for="logo_url">Logo via URL (opcional)</label>
        <input id="logo_url" class="form-control form-control-sm" type="text" name="logo_url" placeholder="https://exemplo.com/logo.png ou assets/images/meu-logo.png" value="">
        <small>Se preenchido, tem prioridade sobre o upload.</small>
      </div>
    </div>

    <div class="section-title">Consultoria / Empresa Compradora</div>
    <div class="form-grid">
      <div class="card-field">
        <label for="consultoria_razao">Razão Social</label>
        <input id="consultoria_razao" class="form-control form-control-sm" type="text" name="cfg[consultoria.razao_social]" value="<?=htmlspecialchars(cfg('consultoria.razao_social',''))?>" placeholder="Ex: Atlas Consultoria em Compras LTDA">
        <small>Usado em propostas e comunicações externas.</small>
      </div>
      <div class="card-field">
        <label for="consultoria_fantasia">Nome Fantasia</label>
        <input id="consultoria_fantasia" class="form-control form-control-sm" type="text" name="cfg[consultoria.nome_fantasia]" value="<?=htmlspecialchars(cfg('consultoria.nome_fantasia',''))?>" placeholder="Ex: Atlas Compras">
        <small>Exibido como identificação amigável.</small>
      </div>
      <div class="card-field">
        <label for="consultoria_cnpj">CNPJ</label>
        <input id="consultoria_cnpj" class="form-control form-control-sm" type="text" name="cfg[consultoria.cnpj]" value="<?=htmlspecialchars(cfg('consultoria.cnpj',''))?>" placeholder="00.000.000/0000-00">
        <small>Informado aos fornecedores nas propostas.</small>
      </div>
      <div class="card-field">
        <label for="consultoria_ie">Inscrição Estadual</label>
        <input id="consultoria_ie" class="form-control form-control-sm" type="text" name="cfg[consultoria.ie]" value="<?=htmlspecialchars(cfg('consultoria.ie',''))?>" placeholder="ISENTO ou número">
        <small>Opcional.</small>
      </div>
      <div class="card-field" style="grid-column:span 2;">
        <label for="consultoria_endereco">Endereço Completo</label>
        <textarea id="consultoria_endereco" class="form-control form-control-sm" rows="3" name="cfg[consultoria.endereco]" placeholder="Rua, número, complemento, cidade, UF, CEP"><?=htmlspecialchars(cfg('consultoria.endereco',''))?></textarea>
        <small>Mostrado apenas nas propostas.</small>
      </div>
      <div class="card-field">
        <label for="consultoria_tel">Telefone Comercial</label>
        <input id="consultoria_tel" class="form-control form-control-sm" type="text" name="cfg[consultoria.telefone]" value="<?=htmlspecialchars(cfg('consultoria.telefone',''))?>" placeholder="(11) 99999-9999">
      </div>
      <div class="card-field">
        <label for="consultoria_email">E-mail Comercial</label>
        <input id="consultoria_email" class="form-control form-control-sm" type="email" name="cfg[consultoria.email]" value="<?=htmlspecialchars(cfg('consultoria.email',''))?>" placeholder="contato@consultoria.com">
      </div>
    </div>

    <div class="section-title">Segurança / Autenticação</div>
    <div class="form-grid">
      <div class="card-field">
        <label for="cfg-auth-tentativas">Tentativas de Login (máx)</label>
        <input id="cfg-auth-tentativas" class="form-control form-control-sm" type="number" min="1" step="1" name="cfg[auth.tentativas_max]" value="<?=htmlspecialchars(cfg('auth.tentativas_max','5'))?>">
        <small>Chave: auth.tentativas_max</small>
      </div>
      <div class="card-field">
        <label for="cfg-auth-lock">Tempo de Bloqueio (min)</label>
        <input id="cfg-auth-lock" class="form-control form-control-sm" type="number" min="1" step="1" name="cfg[auth.lock_minutos]" value="<?=htmlspecialchars(cfg('auth.lock_minutos','15'))?>">
        <small>Chave: auth.lock_minutos</small>
      </div>
      <div class="card-field">
        <label for="cfg-auth-ttl">Duração da Sessão (min)</label>
        <input id="cfg-auth-ttl" class="form-control form-control-sm" type="number" min="30" step="15" name="cfg[auth.sessao_ttl_min]" value="<?=htmlspecialchars(cfg('auth.sessao_ttl_min','240'))?>">
        <small>Chave: auth.sessao_ttl_min</small>
      </div>
    </div>

    <div class="section-title d-flex align-items-center justify-content-between">
      <span>E-mail SMTP</span>
      <a class="btn btn-outline-primary btn-sm" href="../test_email.php" target="_blank" rel="noopener">Testar SMTP</a>
    </div>
    <div class="form-grid">
      <div class="card-field">
        <label for="cfg-mail-host">Servidor SMTP (Host)</label>
        <input id="cfg-mail-host" class="form-control form-control-sm" type="text" name="cfg[mail.host]" required value="<?=htmlspecialchars(cfg('mail.host',defined('SMTP_HOST')?SMTP_HOST:''))?>">
        <small>Chave: mail.host</small>
      </div>
      <div class="card-field">
        <label for="cfg-mail-port">Porta SMTP</label>
        <input id="cfg-mail-port" class="form-control form-control-sm" type="number" min="1" max="65535" inputmode="numeric" name="cfg[mail.port]" value="<?=htmlspecialchars(cfg('mail.port',defined('SMTP_PORT')?SMTP_PORT:'587'))?>">
        <small>Chave: mail.port</small>
      </div>
      <div class="card-field">
        <label for="cfg-mail-user">Usuário SMTP</label>
        <input id="cfg-mail-user" class="form-control form-control-sm" type="text" name="cfg[mail.user]" value="<?=htmlspecialchars(cfg('mail.user',defined('SMTP_USER')?SMTP_USER:''))?>">
        <small>Chave: mail.user</small>
      </div>
      <div class="card-field">
        <label for="cfg-mail-from">E-mail Remetente</label>
        <input id="cfg-mail-from" class="form-control form-control-sm" type="email" name="cfg[mail.from]" value="<?=htmlspecialchars(cfg('mail.from',defined('SMTP_FROM')?SMTP_FROM:''))?>">
        <small>Chave: mail.from</small>
      </div>
    </div>

    <?php if(auth_can('config.editar')): ?>
    <div class="mt-4">
      <button class="btn btn-primary" type="submit">Salvar Configurações</button>
    </div>
    <?php endif; ?>
  </form>
</div>
</body>
</html>