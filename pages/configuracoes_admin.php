<?php
require_once __DIR__.'/../includes/auth.php';
$u = auth_requer_login();
if (!auth_can('config.ver')) { http_response_code(403); echo 'Sem acesso'; exit; }
require_once __DIR__.'/../includes/db.php';
$db = get_db_connection();

// Salvar
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!auth_can('config.editar')) { http_response_code(403); echo 'Sem permissao editar'; exit; }
    foreach ($_POST['cfg'] ?? [] as $ch=>$val) {
        $st = $db->prepare('INSERT INTO configuracoes (chave,valor,tipo,atualizado_por) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor), tipo=VALUES(tipo), atualizado_por=VALUES(atualizado_por), atualizado_em=NOW()');
        $tipo = 'string';
        $st->execute([$ch,trim($val),$tipo,$u['id']]);
        auditoria_log($u['id'],'config_update','configuracoes',null,['chave'=>$ch]);
    }
    header('Location: configuracoes_admin.php?saved=1');
    exit;
}

// Carregar configs
$rows = $db->query('SELECT * FROM configuracoes ORDER BY chave')->fetchAll(PDO::FETCH_ASSOC);
$map = [];
foreach ($rows as $r) $map[$r['chave']]=$r;

function cfg($k,$def=''){global $map; return $map[$k]['valor'] ?? $def;}

?><!DOCTYPE html><html lang="pt-br"><head><meta charset="utf-8"><title>Configurações</title>
<link rel="stylesheet" href="../assets/css/nexus.css">
<style>
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:1rem}
.card{background:var(--matrix-surface-transparent);border:1px solid var(--matrix-border);padding:1rem;border-radius:12px}
label{font-size:12px;color:var(--matrix-text-secondary);font-weight:600;letter-spacing:.5px}
input[type=text],textarea{width:100%;background:rgba(13,12,29,.5);border:1px solid var(--matrix-border);color:var(--matrix-text-primary);padding:.55rem .7rem;border-radius:8px;font-size:13px}
section h2{margin:1.5rem 0 .5rem;font-size:16px;color:var(--matrix-primary-light);letter-spacing:.5px}
.notice{background:#123;padding:.5rem .75rem;border:1px solid #234;border-radius:6px;font-size:12px;margin-top:.5rem;color:#8ab}
.btn-save{background:var(--matrix-primary);color:#fff;border:none;padding:.75rem 1.5rem;border-radius:8px;font-weight:600;cursor:pointer}
.btn-save:hover{background:var(--matrix-primary-light)}
</style></head><body>
<div style="max-width:1200px;margin:2rem auto;padding:0 1rem">
<h1 class="page-title">Configurações da Plataforma</h1>
<?php if(isset($_GET['saved'])) echo '<div class="notice">Configurações salvas.</div>'; ?>
<form method="post">
<section>
<h2>Aplicação</h2>
<div class="form-grid">
<div class="card"><label>app.nome</label><input type="text" name="cfg[app.nome]" value="<?=htmlspecialchars(cfg('app.nome','Atlas'))?>"></div>
<div class="card"><label>app.debug (0/1)</label><input type="text" name="cfg[app.debug]" value="<?=htmlspecialchars(cfg('app.debug','1'))?>"></div>
<div class="card"><label>app.timezone</label><input type="text" name="cfg[app.timezone]" value="<?=htmlspecialchars(cfg('app.timezone','America/Sao_Paulo'))?>"></div>
</div>
</section>
<section>
<h2>Exportação / PDF</h2>
<div class="form-grid">
<div class="card"><label>export.max_registros</label><input type="text" name="cfg[export.max_registros]" value="<?=htmlspecialchars(cfg('export.max_registros','5000'))?>"></div>
<div class="card"><label>pdf.logo_path</label><input type="text" name="cfg[pdf.logo_path]" value="<?=htmlspecialchars(cfg('pdf.logo_path',''))?>"></div>
<div class="card"><label>pdf.footer_text</label><input type="text" name="cfg[pdf.footer_text]" value="<?=htmlspecialchars(cfg('pdf.footer_text','Sistema Atlas'))?>"></div>
</div>
</section>
<section>
<h2>Segurança / Autenticação</h2>
<div class="form-grid">
<div class="card"><label>auth.tentativas_max</label><input type="text" name="cfg[auth.tentativas_max]" value="<?=htmlspecialchars(cfg('auth.tentativas_max','5'))?>"></div>
<div class="card"><label>auth.lock_minutos</label><input type="text" name="cfg[auth.lock_minutos]" value="<?=htmlspecialchars(cfg('auth.lock_minutos','15'))?>"></div>
<div class="card"><label>auth.sessao_ttl_min</label><input type="text" name="cfg[auth.sessao_ttl_min]" value="<?=htmlspecialchars(cfg('auth.sessao_ttl_min','240'))?>"></div>
</div>
</section>
<section>
<h2>Retenção / Limpeza</h2>
<div class="form-grid">
<div class="card"><label>retencao.auditoria_dias</label><input type="text" name="cfg[retencao.auditoria_dias]" value="<?=htmlspecialchars(cfg('retencao.auditoria_dias','90'))?>"></div>
<div class="card"><label>retencao.login_fail_dias</label><input type="text" name="cfg[retencao.login_fail_dias]" value="<?=htmlspecialchars(cfg('retencao.login_fail_dias','30'))?>"></div>
</div>
</section>
<section>
<h2>E-mail SMTP</h2>
<div class="form-grid">
<div class="card"><label>mail.host</label><input type="text" name="cfg[mail.host]" value="<?=htmlspecialchars(cfg('mail.host',SMTP_HOST))?>"></div>
<div class="card"><label>mail.port</label><input type="text" name="cfg[mail.port]" value="<?=htmlspecialchars(cfg('mail.port',SMTP_PORT))?>"></div>
<div class="card"><label>mail.user</label><input type="text" name="cfg[mail.user]" value="<?=htmlspecialchars(cfg('mail.user',SMTP_USER))?>"></div>
<div class="card"><label>mail.from</label><input type="text" name="cfg[mail.from]" value="<?=htmlspecialchars(cfg('mail.from',SMTP_FROM))?>"></div>
</div>
</section>
<div style="margin-top:1rem"><button class="btn-save" type="submit">Salvar</button></div>
</form>
</div>
</body></html>
