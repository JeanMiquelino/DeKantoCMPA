<?php
// A sessão já deve ter sido iniciada pela página que inclui este ficheiro
require_once __DIR__ . '/../includes/branding.php';
// Integrar auth para exibir links condicionais
require_once __DIR__ . '/../includes/auth.php';
$authUser = auth_usuario();
$canUsuarios = $authUser && auth_can('usuarios.ver');
$canConfig = $authUser && auth_can('config.ver');

// Usar URL absoluta da logo resolvida pelo branding
$logoUrl = $branding['logo_url'] ?? '';
$app_name = $branding['app_name'] ?? 'Dekanto';
$current_page = basename($_SERVER['PHP_SELF']);
$usuarioNome = $authUser['nome'] ?? ($_SESSION['usuario_nome'] ?? 'Usuário');
$avatarIni = strtoupper(substr(trim($usuarioNome),0,1));
$avatarUrl = $authUser['avatar_url'] ?? ($_SESSION['usuario_avatar_url'] ?? null);
// Normalizar barras invertidas no caminho vindo do banco/sessão
if ($avatarUrl) { $avatarUrl = str_replace('\\','/', (string)$avatarUrl); }
$isCliente = ($authUser['tipo'] ?? null) === 'cliente';

// Detectar se a página atual está dentro de /pages/cliente para ajustar os links relativos
$scriptPath = str_replace('\\','/', $_SERVER['PHP_SELF'] ?? '');
$inClienteDir = (strpos($scriptPath, '/pages/cliente/') !== false) || (substr($scriptPath, -15) === '/pages/cliente');
$clientBase = $inClienteDir ? '../' : '';

// Ajustar URL do avatar (se relativo) conforme diretório atual
$avatarUrlWeb = $avatarUrl;
if ($avatarUrlWeb) {
    $avatarUrlWeb = str_replace('\\','/', (string)$avatarUrlWeb);
    $isAbsAvatar = preg_match('#^https?://#i', $avatarUrlWeb) || str_starts_with($avatarUrlWeb, 'data:') || str_starts_with($avatarUrlWeb, '/');
    if (!$isAbsAvatar) {
        $av = ltrim((string)$avatarUrlWeb, "./");
        $prefix = $inClienteDir ? '../../' : '../';
        if (str_starts_with($av, 'assets/') || str_starts_with($av, 'uploads/')) {
            $avatarUrlWeb = $prefix . $av;
        } elseif (str_starts_with($av, '../assets/') || str_starts_with($av, '../uploads/')) {
            if ($inClienteDir && !str_starts_with($av, '../../')) {
                $avatarUrlWeb = '../' . $av;
            } else {
                $avatarUrlWeb = $av;
            }
        } else {
            $avatarUrlWeb = $prefix . $av;
        }
    }
}
?>

<script>
// Definir favicon com a mesma logo da navbar (branding)
(function(){
  var href = <?php echo json_encode($logoUrl); ?>;
  if(!href) return;
  function setFav(h){
    try{
      var head = document.head || document.getElementsByTagName('head')[0];
      if(!head) return;
      var links = head.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"], link[rel*="icon"]');
      if(links.length){
        links.forEach(function(l){ l.href = h; l.type = 'image/png'; });
      } else {
        var link = document.createElement('link');
        link.rel = 'icon';
        link.type = 'image/png';
        link.href = h;
        head.appendChild(link);
      }
    }catch(e){}
  }
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', function(){ setFav(href); });
  } else { setFav(href); }
})();
</script>

<style>
/* Navbar (Dekanto light theme) */
.navbar-theme { background: var(--matrix-surface-transparent) !important; backdrop-filter: blur(12px); border-bottom: 1px solid var(--matrix-border); position: sticky; top: 0; z-index: 1040; }
.navbar-theme .navbar-brand span { color: var(--matrix-text-primary); font-weight: 700; letter-spacing: .5px; font-size: 1.05rem; }
.navbar-theme .nav-link { color: var(--matrix-text-secondary) !important; font-weight: 500; font-size: .9rem; transition: all .2s ease; border-radius: 6px; padding: .55rem .9rem; position: relative; }
.navbar-theme .nav-link:hover, .navbar-theme .nav-link:focus { color: var(--matrix-text-primary) !important; background: rgba(181,168,134,.10); }
.navbar-theme .nav-link.active { color: #222 !important; background: var(--matrix-primary); box-shadow: 0 0 0 1px rgba(0,0,0,.03) inset; }

.navbar-theme .dropdown-menu { background: var(--matrix-surface); border: 1px solid var(--matrix-border); border-radius: 12px; padding: .45rem .4rem; min-width: 200px; }
.navbar-theme .dropdown-item { color: var(--matrix-text-secondary); font-size: .85rem; border-radius: 6px; display: flex; align-items: center; gap: .55rem; }
.navbar-theme .dropdown-item:hover { background: rgba(181,168,134,.12); color: var(--matrix-text-primary); }
.navbar-theme .dropdown-divider { border-top: 1px solid var(--matrix-border); }

.navbar-theme .user-chip { display: inline-flex; align-items: center; background: rgba(181,168,134,.15); color: var(--matrix-text-primary); padding: .35rem .7rem; border-radius: 30px; font-size: .8rem; font-weight: 500; letter-spacing: .3px; }
.navbar-theme .user-avatar { width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; background: var(--matrix-tertiary); color: #fff; font-weight: 600; border-radius: 50%; margin-right: .45rem; font-size: .75rem; box-shadow: 0 0 0 2px rgba(0,0,0,.04); overflow: hidden; position: relative; }
.navbar-theme .user-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.navbar-theme .user-avatar .avatar-fallback { display: none; position: absolute; inset: 0; align-items: center; justify-content: center; }
.navbar-theme .user-avatar.fallback .avatar-fallback { display: flex; }

.navbar-theme .brand-divider { width: 1px; height: 34px; background: linear-gradient(180deg, rgba(0,0,0,.04), var(--matrix-border), rgba(0,0,0,.04)); margin: 0 1rem; }
.navbar-theme .nav-link i { font-size: 1rem; margin-right: .35rem; opacity: .9; }
.navbar-theme .dropdown-item i { font-size: .95rem; opacity: .9; }

/* Toggler - dark icon on light background */
.navbar-theme .navbar-toggler { border-color: rgba(0,0,0,.15); }
.navbar-theme .navbar-toggler-icon { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(34,34,34,0.85)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e"); }

@media (max-width:991px){ .navbar-theme .brand-divider{ display:none } .navbar-theme .user-chip{ margin-top:.5rem } }

/* Kanban toolbar (page-specific) */
.kanban-toolbar { border-top: 1px solid var(--matrix-border); margin-top: .25rem; }
.kanban-filters { background: var(--matrix-surface); border: 1px solid var(--matrix-border); border-radius: 12px; padding: .45rem .4rem; }
.kanban-filters .page-title { color: var(--matrix-text-primary); font-weight: 700; }
.kanban-filters .quick-legend { font-size: .85rem; color: var(--matrix-text-secondary); }
.kanban-filters .input-group { width: 250px; }
.kanban-filters .btn-group { margin-left: auto; }
</style>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<nav class="navbar navbar-expand-lg navbar-theme">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?= $isCliente ? ($clientBase.'cliente/index.php') : 'index.php' ?>">
            <?php if ($logoUrl): ?>
                <img src="<?=htmlspecialchars($logoUrl)?>" height="32" class="me-2" alt="logo">
            <?php endif; ?>
            <span><?php echo htmlspecialchars($app_name); ?><?php if($isCliente) echo ' · Cliente'; ?></span>
        </a>
        <span class="brand-divider d-none d-lg-inline"></span>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if(!$isCliente): ?>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'index.php') ? 'active' : ''; ?>" href="index.php"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'fornecedores.php') ? 'active' : ''; ?>" href="fornecedores.php"><i class="bi bi-truck"></i>Fornecedores</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'clientes.php') ? 'active' : ''; ?>" href="clientes.php"><i class="bi bi-people"></i>Clientes</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'produtos.php') ? 'active' : ''; ?>" href="produtos.php"><i class="bi bi-box-seam"></i>Produtos</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'requisicoes.php') ? 'active' : ''; ?>" href="requisicoes.php"><i class="bi bi-journal-arrow-down"></i>Requisições</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'cotacoes.php') ? 'active' : ''; ?>" href="cotacoes.php"><i class="bi bi-clipboard-data"></i>Cotações</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'propostas.php') ? 'active' : ''; ?>" href="propostas.php"><i class="bi bi-file-earmark-text"></i>Propostas</a></li>
                <!-- Kanban: desktop only -->
                <li class="nav-item d-none d-lg-block"><a class="nav-link <?php echo ($current_page == 'kanban.php') ? 'active' : ''; ?>" href="kanban.php"><i class="bi bi-kanban"></i>Kanban</a></li>
                <li class="nav-item"><a class="nav-link <?php echo ($current_page == 'pedidos.php') ? 'active' : ''; ?>" href="pedidos.php"><i class="bi bi-cart-check"></i>Pedidos</a></li>
                <?php else: ?>
                <li class="nav-item"><a class="nav-link" href="<?= $clientBase ?>cliente/index.php"><i class="bi bi-house"></i>Início</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $clientBase ?>cliente/requisicoes.php"><i class="bi bi-journal-arrow-down"></i>Minhas Requisições</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $clientBase ?>cliente/pedidos.php"><i class="bi bi-cart-check"></i>Pedidos</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-lg-3">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-chip"><span class="user-avatar<?php echo $avatarUrlWeb? '' : ' fallback'; ?>">
                            <?php if($avatarUrlWeb): ?>
                                <img src="<?=htmlspecialchars($avatarUrlWeb)?>" alt="avatar" onerror="this.style.display='none'; this.closest('.user-avatar').classList.add('fallback');">
                            <?php endif; ?>
                            <span class="avatar-fallback"><?=htmlspecialchars($avatarIni)?></span>
                        </span><?=htmlspecialchars($usuarioNome)?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header" style="color:#8f93b8;font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;">Conta</h6></li>
                        <li><a class="dropdown-item" href="<?= $clientBase ?>conta.php"><i class="bi bi-person-circle"></i> Minha Conta</a></li>
                        <?php if($canConfig && !$isCliente): ?><li><a class="dropdown-item" href="configuracoes.php"><i class="bi bi-sliders"></i> Configurações do Sistema</a></li><?php endif; ?>
                        <?php if($canUsuarios && !$isCliente): ?><li><a class="dropdown-item" href="usuarios.php"><i class="bi bi-person-gear"></i> Gerenciar Usuários</a></li><?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= $clientBase ?>logout.php"><i class="bi bi-box-arrow-right"></i> Sair</a></li>
                    </ul>
                </li>
            </ul>
        </div>

    </div>
</nav>

<!-- Global Confirmation Modal (reusable) -->
<div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger">
        <h5 class="modal-title" id="globalConfirmTitle"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar ação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <p id="globalConfirmMessage">Tem a certeza?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal" id="globalConfirmCancel">Cancelar</button>
        <button type="button" class="btn btn-matrix-danger" id="globalConfirmOk">Confirmar</button>
      </div>
    </div>
  </div>
</div>
<script>
// Reusable confirm dialog that returns a Promise<boolean>
window.confirmDialog = function(opts){
  return new Promise(resolve=>{
    const el = document.getElementById('globalConfirmModal');
    // Fallback when Bootstrap/element not available
    if(!el || !window.bootstrap){ const ok = window.confirm(opts?.message||'Confirmar ação?'); resolve(!!ok); return; }
    const titleEl = document.getElementById('globalConfirmTitle');
    const msgEl = document.getElementById('globalConfirmMessage');
    const okBtn = document.getElementById('globalConfirmOk');
    const cancelBtn = document.getElementById('globalConfirmCancel');
    const modal = bootstrap.Modal.getOrCreateInstance(el);

    // Configure content
    const title = opts?.title || 'Confirmar ação';
    const iconHtml = opts?.iconHtml || '<i class="bi bi-exclamation-triangle-fill me-2"></i>';
    titleEl.innerHTML = iconHtml + title;
    msgEl.textContent = opts?.message || 'Tem a certeza?';
    okBtn.textContent = opts?.confirmText || 'Confirmar';
    cancelBtn.textContent = opts?.cancelText || 'Cancelar';

    // Style variant (primary | secondary | danger)
    okBtn.classList.remove('btn-matrix-primary','btn-matrix-secondary','btn-matrix-danger');
    const variant = (opts?.variant||'danger').toLowerCase();
    okBtn.classList.add(variant==='primary'?'btn-matrix-primary':(variant==='secondary'?'btn-matrix-secondary':'btn-matrix-danger'));

    // Wire events (once)
    const onOk = ()=>{ cleanup(); resolve(true); modal.hide(); };
    const onHide = ()=>{ cleanup(); resolve(false); };
    function cleanup(){ okBtn.removeEventListener('click', onOk); el.removeEventListener('hidden.bs.modal', onHide); }
    okBtn.addEventListener('click', onOk, { once:true });
    el.addEventListener('hidden.bs.modal', onHide, { once:true });

    modal.show();
  });
};

// --- Mobile Tables: transformar tabelas em "cards" no celular ---
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
        tbl.parentNode && tbl.parentNode.insertBefore(div, tbl);
        div.appendChild(tbl);
      }
      tbl.dataset.mobileCardsApplied = '1';
    });
  }
  // Executar após o DOM pronto
  window.addEventListener('DOMContentLoaded', applyMobileTableCards);
})();
</script>