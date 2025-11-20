<?php
// Navbar padronizada para Portal do Fornecedor (seguindo estilo admin_navbar)
if(session_status()!==PHP_SESSION_ACTIVE){ session_start(); }
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/branding.php';
$__fornUser = isset($u) ? $u : auth_usuario();
if(!$__fornUser || ($__fornUser['tipo']??'')!=='fornecedor'){
    header('Location: ../login.php');
    exit;
}
$__page = basename($_SERVER['PHP_SELF']);
$__current = $currentNav ?? ($__page==='index.php'?'dashboard':($__page==='cotacoes.php'?'cotacoes':'outro'));
$__logo = $branding['logo_url'] ?? null; // pode ser absoluta
$__app = $branding['app_name'] ?? 'Dekanto';
$__nome = $__fornUser['nome'] ?? 'Fornecedor';
$__ini = strtoupper(substr(trim($__nome),0,1));
$__avatarUrl = $__fornUser['avatar_url'] ?? null;
$__faviconSource = $branding['favicon_url'] ?? null;
if(!$__faviconSource){
  foreach(['favicon','app_favicon','favicon_path','logo_favicon','logo'] as $favKey){
    if(!empty($branding[$favKey])){ $__faviconSource = trim((string)$branding[$favKey]); break; }
  }
}
if(!$__faviconSource){ $__faviconSource = 'assets/images/logo.png'; }
if(!preg_match('#^https?://#i', $__faviconSource)){
  if(defined('APP_URL') && APP_URL){
    $__faviconSource = rtrim(APP_URL,'/').'/'.ltrim($__faviconSource,'/');
  } else {
    $__faviconSource = '../../'.ltrim($__faviconSource,'/');
  }
}
if(!defined('FORNECEDOR_FAVICON_ATTACHED')) {
  define('FORNECEDOR_FAVICON_ATTACHED', true);
  ?><script>(function(d){var href=<?= '\'' . addslashes($__faviconSource) . '\'' ?>;if(!href||!d||!d.head){return;}["icon","shortcut icon"].forEach(function(rel){var existing=d.querySelector('link[rel="'+rel+'"]');if(existing){existing.setAttribute('href',href);return;}var link=d.createElement('link');link.setAttribute('rel',rel);link.setAttribute('href',href);d.head.appendChild(link);});})(document);</script><?php
}
?>
<style>
.forn-navbar{ background: var(--matrix-surface-transparent); backdrop-filter: blur(12px); border-bottom:1px solid var(--matrix-border); position:sticky; top:0; z-index:1080; }
.forn-navbar .navbar-brand span{ font-weight:700; letter-spacing:.5px; color: var(--matrix-text-primary); }
.forn-navbar .nav-link{ color: var(--matrix-text-secondary)!important; font-weight:500; font-size:.9rem; padding:.55rem .85rem; border-radius:8px; display:flex; align-items:center; gap:.45rem; }
.forn-navbar .nav-link:hover{ background:rgba(181,168,134,.10); color:var(--matrix-text-primary)!important; }
.forn-navbar .nav-link.active{ background: var(--matrix-primary); color:#222!important; box-shadow:0 0 0 1px rgba(0,0,0,.04) inset; }
.forn-navbar .user-chip{ display:inline-flex; align-items:center; background:rgba(181,168,134,.15); color:var(--matrix-text-primary); padding:.38rem .75rem; border-radius:30px; font-size:.75rem; font-weight:600; letter-spacing:.4px; }
.forn-navbar .user-avatar{ width:26px; height:26px; border-radius:50%; background:var(--matrix-tertiary); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-weight:600; margin-right:.4rem; font-size:.75rem; overflow:hidden; }
.forn-navbar .user-avatar img{ width:100%; height:100%; object-fit:cover; display:block; }
.forn-navbar .dropdown-menu{ background: var(--matrix-surface); border:1px solid var(--matrix-border); border-radius:12px; padding:.45rem .4rem; min-width:200px; }
.forn-navbar .dropdown-item{ color:var(--matrix-text-secondary); font-size:.9rem; border-radius:6px; display:flex; align-items:center; gap:.55rem; }
.forn-navbar .dropdown-item:hover{ background:rgba(181,168,134,.12); color:var(--matrix-text-primary); }
.forn-navbar .dropdown-divider{ border-top:1px solid var(--matrix-border); }
.forn-navbar .logo-img{ height:30px; }
body.modal-open .forn-navbar { z-index:1040; }
</style>
<nav class="navbar navbar-expand-lg forn-navbar" aria-label="Navegação principal fornecedor">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center" href="index.php">
      <?php if($__logo): ?>
        <img src="<?=htmlspecialchars($__logo)?>" class="me-2 logo-img" alt="logo">
      <?php else: ?>
        <img src="../../assets/images/logo.png" class="me-2 logo-img" alt="logo">
      <?php endif; ?>
      <span>Fornecedor - <?=htmlspecialchars($__app)?></span>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#fornNav" aria-controls="fornNav" aria-expanded="false" aria-label="Alternar navegação">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="fornNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link <?=($__current==='dashboard'?'active':'')?>" href="index.php"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?=($__current==='cotacoes'?'active':'')?>" href="cotacoes.php"><i class="bi bi-shop"></i>Cotações</a></li>
        <li class="nav-item"><a class="nav-link <?=($__current==='cotacoes_participando'?'active':'')?>" href="cotacoes_participando.php"><i class="bi bi-hourglass-split"></i> Participando</a></li>
        <li class="nav-item"><a class="nav-link <?=($__current==='cotacoes_disponiveis'?'active':'')?>" href="cotacoes_disponiveis.php"><i class="bi bi-check-circle"></i> Disponíveis</a></li>
        <li class="nav-item"><a class="nav-link <?=($__current==='cotacoes_convidadas'?'active':'')?>" href="cotacoes_convidadas.php"><i class="bi bi-envelope-open"></i> Convidadas</a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <span class="user-chip"><span class="user-avatar"><?php if($__avatarUrl): ?><img src="<?=htmlspecialchars($__avatarUrl)?>" alt="avatar"><?php else: ?><?=htmlspecialchars($__ini)?><?php endif; ?></span><?=htmlspecialchars($__nome)?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="../conta.php"><i class="bi bi-person-circle"></i> Minha Conta</a></li>
            <li><a class="dropdown-item" href="cotacoes.php"><i class="bi bi-shop"></i> Minhas Cotações</a></li>
            <li><div class="dropdown-divider"></div></li>
            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Sair</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<script>
// Inicialização segura de dropdowns (como admin)
window.addEventListener('DOMContentLoaded',()=>{ document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(el=>{ try{ bootstrap.Dropdown.getOrCreateInstance(el); }catch(e){} }); });
// Fallback se Bootstrap JS absent
(function(){ if(window.bootstrap && bootstrap.Dropdown) return; document.addEventListener('click',function(e){ const t=e.target.closest('.nav-item.dropdown > a.dropdown-toggle'); const opened=document.querySelectorAll('.nav-item.dropdown.show'); if(t){ e.preventDefault(); e.stopPropagation(); const li=t.parentElement; const menu=li.querySelector('.dropdown-menu'); const isOpen=li.classList.contains('show'); opened.forEach(x=>{ if(x!==li){ x.classList.remove('show'); const m=x.querySelector('.dropdown-menu'); if(m){ m.classList.remove('show'); m.removeAttribute('data-bs-popper'); m.style.left=''; m.style.right=''; } x.querySelector('a.dropdown-toggle')?.setAttribute('aria-expanded','false'); } }); if(!isOpen){ li.classList.add('show'); if(menu){ menu.classList.add('show'); menu.setAttribute('data-bs-popper','static'); if(menu.classList.contains('dropdown-menu-end')){ menu.style.left='auto'; menu.style.right='0'; } else { menu.style.left='0'; menu.style.right='auto'; } } t.setAttribute('aria-expanded','true'); } else { li.classList.remove('show'); if(menu){ menu.classList.remove('show'); menu.removeAttribute('data-bs-popper'); menu.style.left=''; menu.style.right=''; } t.setAttribute('aria-expanded','false'); }
        } else { opened.forEach(x=>{ x.classList.remove('show'); const m=x.querySelector('.dropdown-menu'); if(m){ m.classList.remove('show'); m.removeAttribute('data-bs-popper'); m.style.left=''; m.style.right=''; } x.querySelector('a.dropdown-toggle')?.setAttribute('aria-expanded','false'); }); }
  }, true); })();
</script>
<?php if(isset($currentNav)): ?>
<script>
// Ajuste visual (opcional) pode ser feito aqui
</script>
<?php endif; ?>
