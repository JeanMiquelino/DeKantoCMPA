<?php
// Navbar exclusiva de administração
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/branding.php';
$authUser = auth_requer_login();
$canConfig = auth_can('config.ver');
$canUsuarios = auth_can('usuarios.ver');
if(!$canConfig && !$canUsuarios){
    http_response_code(403); echo 'Sem acesso administrativo'; exit;
}
// Ajuste de logo via branding centralizado (URL absoluta)
$logo = $branding['logo_url'] ?? null;
$app_name = $branding['app_name'] ?? 'Dekanto';
$current_page = basename($_SERVER['PHP_SELF']);
$usuarioNome = $authUser['nome'] ?? 'Usuário';
$avatarIni = strtoupper(substr(trim($usuarioNome),0,1));
$avatarUrl = $authUser['avatar_url'] ?? ($_SESSION['usuario_avatar_url'] ?? null); // novo
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="../assets/css/nexus.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
/* Admin Navbar (Dekanto light theme) */
.admin-navbar{ background: var(--matrix-surface-transparent); backdrop-filter: blur(12px); border-bottom: 1px solid var(--matrix-border); position: sticky; top: 0; z-index: 1100; }
.admin-navbar .nav-link{ color: var(--matrix-text-secondary) !important; font-weight: 500; font-size: .9rem; padding: .55rem .8rem; border-radius: 7px; display: flex; align-items: center; gap: .45rem; }
.admin-navbar .nav-link:hover{ background: rgba(181,168,134,.10); color: var(--matrix-text-primary) !important; }
.admin-navbar .nav-link.active{ background: var(--matrix-primary); color: #222 !important; box-shadow: 0 0 0 1px rgba(0,0,0,.03) inset; }
.admin-navbar .navbar-brand span{ font-weight: 700; letter-spacing: .5px; color: var(--matrix-text-primary); }
.admin-navbar .user-chip{ display: inline-flex; align-items: center; background: rgba(181,168,134,.15); color: var(--matrix-text-primary); padding: .38rem .75rem; border-radius: 30px; font-size: .75rem; font-weight: 600; letter-spacing: .4px; }
.admin-navbar .user-avatar{ width: 26px; height: 26px; border-radius: 50%; background: var(--matrix-tertiary); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; margin-right: .4rem; font-size: .75rem; overflow: hidden; }
.admin-navbar .user-avatar img{ width: 100%; height: 100%; object-fit: cover; display: block; }
.admin-navbar .dropdown-menu{ background: var(--matrix-surface); border: 1px solid var(--matrix-border); border-radius: 12px; padding: .45rem .4rem; min-width: 200px; z-index: 1200; }
.admin-navbar .dropdown-item{ color: var(--matrix-text-secondary); font-size: .9rem; border-radius: 6px; display: flex; align-items: center; gap: .55rem; }
.admin-navbar .dropdown-item:hover{ background: rgba(181,168,134,.12); color: var(--matrix-text-primary); }
.admin-navbar .dropdown-item i{ min-width: 16px; display: inline-block; }
.admin-navbar .dropdown-divider { border-top: 1px solid var(--matrix-border); }
.navbar-toggler { border-color: rgba(0,0,0,.15); }
.navbar-toggler-icon { background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(34,34,34,0.85)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e"); }

/* Viewport guard + fallback alignment when Popper isn't active */
.admin-navbar .dropdown-menu{ max-height: calc(100vh - 80px); overflow:auto; }
.admin-navbar .dropdown-menu-end:not([data-bs-popper]){ right: 0; left: auto; }
.admin-navbar .dropdown-menu-start:not([data-bs-popper]){ left: 0; right: auto; }

/* Ensure modals/backdrop cover navbar when open */
body.modal-open .admin-navbar { z-index: 1040; }
</style>
<nav class="navbar navbar-expand-lg admin-navbar">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center" href="configuracoes.php">
      <?php if($logo): ?><img src="<?=htmlspecialchars($logo)?>" height="30" class="me-2" alt="logo"><?php endif; ?>
      <span>Admin - <?=htmlspecialchars($app_name)?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Alternar navegação"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="adminNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <?php if($canConfig): ?>
        <li class="nav-item"><a class="nav-link <?=$current_page==='configuracoes.php'?'active':''?>" href="configuracoes.php"><i class="bi bi-sliders"></i>Configurações</a></li>
        <?php endif; ?>
        <?php if($canUsuarios): ?>
        <li class="nav-item"><a class="nav-link <?=$current_page==='usuarios.php'?'active':''?>" href="usuarios.php"><i class="bi bi-person-gear"></i>Usuários</a></li>
        <?php endif; ?>
        <li class="nav-item"><a class="nav-link" href="index.php"><i class="bi bi-arrow-left-circle"></i>Voltar ao Sistema</a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <span class="user-chip"><span class="user-avatar"><?php if($avatarUrl): ?><img src="<?=htmlspecialchars($avatarUrl)?>" alt="avatar"><?php else: ?><?=htmlspecialchars($avatarIni)?><?php endif; ?></span><?=htmlspecialchars($usuarioNome)?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="conta.php"><i class="bi bi-person-circle"></i> Minha Conta</a></li>
            <?php if($canConfig): ?><li><a class="dropdown-item" href="configuracoes.php"><i class="bi bi-sliders"></i> Configurações do Sistema</a></li><?php endif; ?>
            <?php if($canUsuarios): ?><li><a class="dropdown-item" href="usuarios.php"><i class="bi bi-person-gear"></i> Gerenciar Usuários</a></li><?php endif; ?>
            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sair</a></li>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
// Force initialization when Bootstrap is available
window.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el=>{
    try{ bootstrap.Dropdown.getOrCreateInstance(el); }catch(e){}
  });
});

// Fallback dropdown (when Bootstrap JS is unavailable)
(function(){
  if (window.bootstrap && bootstrap.Dropdown) return;
  document.addEventListener('click', function(e){
    const toggle = e.target.closest('.nav-item.dropdown > a.dropdown-toggle');
    const openItems = document.querySelectorAll('.nav-item.dropdown.show');
    if (toggle) {
      e.preventDefault(); e.stopPropagation();
      const li = toggle.parentElement;
      const menu = li.querySelector('.dropdown-menu');
      const isOpen = li.classList.contains('show');
      // close others
      openItems.forEach(x=>{ if(x!==li){ x.classList.remove('show'); const m=x.querySelector('.dropdown-menu'); if(m){ m.classList.remove('show'); m.removeAttribute('data-bs-popper'); m.style.left=''; m.style.right=''; } x.querySelector('a.dropdown-toggle')?.setAttribute('aria-expanded','false'); }});
      // toggle current
      if (!isOpen) {
        li.classList.add('show'); if(menu){ menu.classList.add('show'); menu.setAttribute('data-bs-popper','static'); if(menu.classList.contains('dropdown-menu-end')){ menu.style.left='auto'; menu.style.right='0'; } else { menu.style.left='0'; menu.style.right='auto'; } }
        toggle.setAttribute('aria-expanded','true');
      } else {
        li.classList.remove('show'); if(menu){ menu.classList.remove('show'); menu.removeAttribute('data-bs-popper'); menu.style.left=''; menu.style.right=''; }
        toggle.setAttribute('aria-expanded','false');
      }
    } else {
      // click outside -> close
      openItems.forEach(x=>{ x.classList.remove('show'); const m=x.querySelector('.dropdown-menu'); if(m){ m.classList.remove('show'); m.removeAttribute('data-bs-popper'); m.style.left=''; m.style.right=''; } x.querySelector('a.dropdown-toggle')?.setAttribute('aria-expanded','false'); });
    }
  }, true);
})();
</script>
<script>
// Definir favicon (admin) usando a logo de branding
(function(){
  var href = <?php echo json_encode($logo); ?>;
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

<?php
// ...existing code...
?>
