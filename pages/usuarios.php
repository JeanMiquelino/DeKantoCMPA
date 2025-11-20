<?php
session_start();
require_once __DIR__.'/../includes/auth.php';
$u = auth_requer_login();
// Block cliente/fornecedor types from this internal management page
if ($u && in_array(($u['tipo'] ?? ''), ['cliente','fornecedor'], true)) {
    if(($u['tipo'] ?? '') === 'cliente') { header('Location: cliente/index.php'); } else { header('Location: fornecedor/index.php'); }
    exit;
}
if (!auth_can('usuarios.ver')) { http_response_code(403); echo 'Sem acesso'; exit; }
require_once __DIR__.'/../includes/db.php';
$db = get_db_connection();
$roles = $db->query("SELECT id,nome FROM roles ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$defaultRoleId = null;
foreach($roles as $r){
  if(($r['nome'] ?? '') === 'comprador'){
    $defaultRoleId = (int)$r['id'];
    break;
  }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Usuários</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/nexus.css">
<?php
$logoFav=null; try { require_once __DIR__.'/../includes/branding.php'; $logoFav = $branding['logo_url'] ?? null; } catch(Throwable $e) {}
if($logoFav){ echo '<link rel="icon" type="image/png" href="'.htmlspecialchars($logoFav).'">'; }
?>
<style>
/* ===================== PAGE (NOVA VERSÃO LIMPA – DESIGN SYSTEM) ===================== */
body { background: var(--matrix-bg); }
.page-users-header { display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; justify-content:space-between; margin-bottom:1.25rem; }
.page-users-header h1 { font-size:1.55rem; font-weight:700; margin:0; letter-spacing:-.5px; }
.page-users-header small { font-size:.72rem; font-weight:600; color:var(--matrix-text-secondary); letter-spacing:.3px; }

/* KPI CARDS */
.kpi-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); margin:0 0 1.4rem; }
.kpi-card { background:var(--matrix-surface-transparent); border:1px solid var(--matrix-border); border-radius:12px; padding:.85rem .9rem .75rem; position:relative; box-shadow:0 4px 16px rgba(0,0,0,.06); transition:.25s; }
.kpi-card:hover { transform:translateY(-3px); box-shadow:0 8px 26px rgba(0,0,0,.12); }
.kpi-card h6 { margin:0 0 .55rem; font-size:.58rem; font-weight:700; text-transform:uppercase; letter-spacing:.14em; color:var(--matrix-text-secondary); }
.kpi-value-wrap { display:flex; align-items:baseline; gap:.45rem; }
.kpi-value { font-size:1.85rem; font-weight:700; line-height:1; letter-spacing:-1px; color:var(--matrix-text-primary); font-variant-numeric:tabular-nums; }
.kpi-trend { font-size:.58rem; font-weight:600; padding:.28rem .55rem; border-radius:999px; background:rgba(181,168,134,.15); color:#3f3a29; }
.kpi-sub { font-size:.6rem; margin-top:.45rem; color:var(--matrix-text-secondary); display:flex; gap:.35rem; font-weight:600; }
.kpi-bar { height:6px; background:rgba(181,168,134,.18); border-radius:4px; margin-top:.55rem; overflow:hidden; }
.kpi-bar span { display:block; height:100%; width:0; background:linear-gradient(90deg,var(--matrix-primary),rgba(181,168,134,.55)); transition:1s cubic-bezier(.55,.15,.15,1); }
#rolesChart { font-size:.6rem; }
.role-row { margin-bottom:.55rem; }
.role-head { display:flex; justify-content:space-between; font-size:.52rem; font-weight:600; letter-spacing:.4px; color:var(--matrix-text-secondary); }
.role-bar { height:15px; background:rgba(181,168,134,.12); border:1px solid var(--matrix-border); border-radius:8px; overflow:hidden; position:relative; }
.role-bar span { position:absolute; inset:0; width:0; background:linear-gradient(90deg,var(--matrix-primary),var(--matrix-primary-light)); color:#222; font-weight:600; font-size:.52rem; display:flex; align-items:center; padding:0 .45rem; transition:1s cubic-bezier(.55,.15,.15,1); }

/* FILTER BAR */
.filter-bar { display:flex; flex-wrap:wrap; gap:.6rem; margin-bottom:1rem; background:var(--matrix-surface-transparent); border:1px solid var(--matrix-border); border-radius:10px; padding:.7rem .75rem; box-shadow:0 4px 12px rgba(0,0,0,.05); }
.filter-bar .form-control, .filter-bar .form-select { height:38px; font-size:.72rem; padding:.4rem .65rem; }
.filter-bar button { font-size:.62rem; font-weight:700; letter-spacing:.6px; text-transform:uppercase; }

/* USER GRID */
.users-wrapper { position:relative; }
.users-grid { display:grid; gap:.9rem; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); }
.user-card { background:var(--matrix-surface); border:1px solid var(--matrix-border); border-radius:14px; padding:.85rem .85rem .75rem; display:flex; flex-direction:column; gap:.55rem; box-shadow:0 2px 8px rgba(0,0,0,.05); transition:.25s; cursor:default; }
.user-card:hover, .user-card:focus-visible { transform:translateY(-3px); box-shadow:0 6px 18px rgba(0,0,0,.12); }
.uc-head { display:flex; gap:.7rem; }
.uc-avatar { width:50px; height:50px; border-radius:50%; background:linear-gradient(145deg,#fff,#f1efe9); border:1px solid var(--matrix-border); font-weight:700; font-size:.95rem; display:flex; align-items:center; justify-content:center; color:#2d2d2d; }
.uc-name { font-size:.93rem; font-weight:700; margin:0; letter-spacing:-.3px; }
.uc-email { font-size:.58rem; letter-spacing:.35px; color:var(--matrix-text-secondary); margin-top:2px; word-break:break-all; }
.role-badges { display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.3rem; }
.role-badges span { font-size:.5rem; font-weight:600; padding:.28rem .55rem; border-radius:999px; background:rgba(181,168,134,.18); color:#3f3a29; letter-spacing:.4px; }
.uc-footer { display:flex; justify-content:space-between; align-items:flex-end; gap:.55rem; }
.status-pill { background:var(--matrix-surface); border:1px solid var(--matrix-border); border-radius:8px; padding:.42rem .55rem .38rem; min-width:70px; display:flex; flex-direction:column; gap:2px; }
.status-pill small { font-size:.48rem; font-weight:600; letter-spacing:.5px; opacity:.65; }
.status-pill strong { font-size:.62rem; font-weight:700; letter-spacing:.5px; }
.status-pill--success { background:rgba(46,125,50,.10); border-color:rgba(46,125,50,.30); color:var(--matrix-success); }
.status-pill--danger { background:rgba(147,22,33,.12); border-color:rgba(147,22,33,.30); color:var(--matrix-danger); }
.uc-actions { display:flex; gap:.3rem; flex-wrap:wrap; justify-content:flex-end; }
.uc-actions .btn { font-size:.52rem; font-weight:600; padding:.4rem .55rem; border-radius:6px; }
.uc-actions .btn-outline-secondary { background:#fff; border:1px solid var(--matrix-border); }

/* SKELETON */
.skeleton-grid { display:grid; gap:.9rem; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); }
.skeleton-card { height:180px; border-radius:14px; background:linear-gradient(90deg,#e9e7e1,#f4f3ef,#e9e7e1); background-size:200% 100%; animation:skel 1.6s ease-in-out infinite; border:1px solid var(--matrix-border); }
@keyframes skel { 0%{background-position:0 0;} 50%{background-position:100% 0;} 100%{background-position:0 0;} }

.empty { font-size:.7rem; font-weight:600; letter-spacing:.45px; color:var(--matrix-text-secondary); margin-top:1.2rem; }
.load-more-wrap { margin-top:1.1rem; text-align:center; }
.load-more-wrap .btn { font-size:.62rem; font-weight:700; letter-spacing:.6px; }

/* MODAIS */
.modal-modern .modal-content { border-radius:14px; }
.section-label { font-size:.55rem; font-weight:700; letter-spacing:.55px; text-transform:uppercase; color:var(--matrix-text-secondary); }
.roles-box { display:flex; flex-wrap:wrap; gap:.4rem .5rem; margin-top:.4rem; }
.roles-box label { display:flex; align-items:center; gap:.4rem; background:var(--matrix-surface); border:1px solid var(--matrix-border); padding:.35rem .55rem; font-size:.6rem; border-radius:6px; font-weight:600; cursor:pointer; }
.roles-box input { margin:0; }
.detail-grid { display:grid; gap:.55rem; margin-top:.4rem; }
.detail-field { background:var(--matrix-surface); border:1px solid var(--matrix-border); border-radius:8px; padding:.55rem .6rem .5rem; }
.detail-field h6 { margin:0 0 .25rem; font-size:.52rem; font-weight:600; letter-spacing:.45px; opacity:.7; }
.detail-field p { margin:0; font-size:.66rem; font-weight:600; letter-spacing:.25px; }

/* FOCUS */
.user-card:focus-visible, .kpi-card:focus-visible, .filter-bar :focus-visible { outline:2px solid var(--matrix-primary); outline-offset:2px; }

/* ===== ENHANCEMENTS (toolbar, active filters, view modes, overlay) ===== */
.toolbar-users { display:flex; flex-wrap:wrap; gap:.6rem; align-items:center; justify-content:space-between; margin:-.4rem 0 1rem; }
.view-toggle { display:flex; gap:.4rem; }
.view-toggle button { border:1px solid var(--matrix-border); background:#fff; padding:.4rem .55rem; font-size:.6rem; font-weight:600; letter-spacing:.4px; border-radius:6px; line-height:1; display:inline-flex; align-items:center; gap:.25rem; }
.view-toggle button.active { background:var(--matrix-primary); color:#222; border-color:var(--matrix-primary); box-shadow:0 0 0 2px rgba(181,168,134,.35); }
.active-filters { display:flex; flex-wrap:wrap; gap:.4rem; }
.active-filters .af-chip { background:#fff; border:1px solid var(--matrix-border); padding:.3rem .55rem; font-size:.55rem; font-weight:600; border-radius:999px; display:inline-flex; align-items:center; gap:.4rem; }
.active-filters .af-chip button { background:rgba(0,0,0,.08); border:0; width:16px; height:16px; display:flex; align-items:center; justify-content:center; border-radius:50%; font-size:.6rem; cursor:pointer; }
.active-filters .af-chip button:hover { background:var(--matrix-danger); color:#fff; }
.btn-clear-filters { font-size:.55rem; font-weight:600; letter-spacing:.5px; }
.users-grid.list-view { display:block; }
.users-grid.list-view .user-card { flex-direction:row; align-items:center; gap:.9rem; padding:.7rem .9rem; }
.users-grid.list-view .user-card .uc-head { margin:0; }
.users-grid.list-view .user-card .uc-footer { margin-left:auto; flex-direction:row; }
.users-grid.list-view .role-badges { max-width:240px; overflow:hidden; }
.op-overlay { position:fixed; inset:0; background:rgba(255,255,255,.55); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; z-index:1080; }
.op-overlay .spinner-border { width:2.5rem; height:2.5rem; border-width:.3rem; }
@media (max-width:520px){ .users-grid.list-view .user-card { flex-direction:column; align-items:stretch; } }

</style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<div class="container-fluid px-3 px-md-4 py-3 page-users">
  <div class="page-users-header">
    <div>
      <h1 class="page-title">Usuários</h1>
      <small>Gestão de contas, permissões e status</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <?php if(auth_can('usuarios.editar')): ?><button class="btn btn-matrix-primary" id="btnNovo"><i class="bi bi-plus-lg me-1"></i>Novo</button><?php endif; ?>
      <button class="btn btn-matrix-secondary" id="btnRefreshStats" title="Atualizar estatísticas"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
  </div>

  <!-- KPIs -->
  <div class="kpi-grid" id="kpiGrid">
    <div class="kpi-card" tabindex="0"><h6>Total Usuários</h6><div class="kpi-value-wrap"><div class="kpi-value" id="kpiTotal">0</div><div class="kpi-trend" id="kpiTrendAtivos">--</div></div><div class="kpi-sub" id="kpiAtivosPct">Ativos -</div><div class="kpi-bar"><span id="kpiBarAtivos"></span></div></div>
    <div class="kpi-card" tabindex="0"><h6>Ativos</h6><div class="kpi-value-wrap"><div class="kpi-value" id="kpiAtivos">0</div></div><div class="kpi-sub" id="kpiInativos">Inativos -</div><div class="kpi-bar"><span id="kpiBarInativos"></span></div></div>
    <div class="kpi-card" tabindex="0"><h6>Novos (7 dias)</h6><div class="kpi-value-wrap"><div class="kpi-value" id="kpiNovos7d">0</div></div><div class="kpi-sub" id="kpiAtualizadoEm">Atualizado -</div><div class="kpi-bar"><span id="kpiBarNovos"></span></div></div>
    <div class="kpi-card" tabindex="0"><h6>Distribuição Roles</h6><div id="rolesChart">Sem dados</div></div>
  </div>

  <div class="toolbar-users">
    <div class="active-filters" id="activeFilters"></div>
    <div class="view-toggle" id="viewToggle">
      <button type="button" data-view="grid" class="active" title="Grid"><i class="bi bi-grid-3x3-gap"></i> Grid</button>
      <button type="button" data-view="list" title="Lista"><i class="bi bi-list"></i> Lista</button>
    </div>
  </div>

  <div class="filter-bar" id="filtros">
    <input class="form-control" type="text" id="filtroBusca" placeholder="Buscar nome ou email" autocomplete="off">
    <select class="form-select" id="filtroStatus">
      <option value="">Status: Todos</option>
      <option value="ativo">Ativos</option>
      <option value="inativo">Inativos</option>
    </select>
    <select class="form-select" id="filtroRole" <?php echo count($roles) > 1 ? '' : 'style="display:none;"'; ?>>
      <option value="">Role: Todas</option>
      <?php foreach($roles as $r){ echo '<option value="'.(int)$r['id'].'">'.htmlspecialchars($r['nome']).'</option>'; } ?>
    </select>
    <select class="form-select" id="filtroPerPage">
      <option value="12">12 / pág</option>
      <option value="24">24 / pág</option>
      <option value="36">36 / pág</option>
      <option value="48">48 / pág</option>
    </select>
    <button class="btn btn-matrix-secondary" id="btnAplicar"><i class="bi bi-filter me-1"></i>Aplicar</button>
  </div>

  <div class="card-matrix p-0" style="border-radius:14px; padding:1.1rem 1.1rem 1.4rem !important;">
    <div class="users-wrapper">
      <div id="skeletonContainer" class="skeleton-grid d-none"></div>
      <div id="usersContainer" class="users-grid"></div>
      <div id="emptyState" class="empty d-none">Nenhum usuário encontrado.</div>
    </div>
    <div class="load-more-wrap d-none" id="loadMoreWrap"><button class="btn btn-outline-primary btn-sm" id="btnLoadMore">Carregar mais</button></div>
  </div>
</div>

<!-- Modal Create/Edit -->
<div class="modal fade modal-modern" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="modalTitulo">Novo Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
      <div class="modal-body">
        <div class="row g-4">
          <div class="col-lg-7">
            <form id="formUser" autocomplete="off">
              <input type="hidden" name="id" id="usrId">
              <div class="mb-3">
                <label class="section-label">Dados Básicos</label>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Nome</label>
                    <input class="form-control" type="text" name="nome" id="usrNome" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input class="form-control" type="email" name="email" id="usrEmail" required>
                  </div>
                  <div class="col-md-6 senha-inicial-group">
                    <label class="form-label" id="lblSenha">Senha Inicial</label>
                    <input class="form-control" type="password" name="senha" id="usrSenha" placeholder="••••••" autocomplete="new-password">
                  </div>
                  <div class="col-md-6 senha-inicial-group">
                    <label class="form-label">Confirmar Senha Inicial</label>
                    <input class="form-control" type="password" name="senha_conf" id="usrSenhaConf" placeholder="Repetir" autocomplete="new-password">
                  </div>
                  <div class="col-md-6 senha-nova-group d-none">
                    <label class="form-label">Nova Senha (opcional)</label>
                    <input class="form-control" type="password" name="senha_nova" id="usrSenhaNova" placeholder="Deixe em branco" autocomplete="new-password">
                  </div>
                  <div class="col-md-6 senha-nova-group d-none">
                    <label class="form-label">Confirmar Nova Senha</label>
                    <input class="form-control" type="password" name="senha_nova_conf" id="usrSenhaNovaConf" placeholder="Repetir" autocomplete="new-password">
                  </div>
                </div>
              </div>
              <div class="mb-3">
                <label class="section-label">Role</label>
                <div class="roles-box" id="rolesBox" data-default-role="<?php echo (int)($defaultRoleId ?? 0); ?>">
                  <?php if($roles){
            foreach($roles as $r){
              $roleId = (int)$r['id'];
              $roleLabel = htmlspecialchars($r['nome']);
              echo '<label><input type="radio" name="roleOption" value="'.$roleId.'" data-role-label="'.$roleLabel.'"> <span>'.$roleLabel.'</span></label>';
            }
                  } else {
                      echo '<span class="text-secondary small">Nenhum papel disponível.</span>';
                  } ?>
                </div>
              </div>
            </form>
          </div>
          <div class="col-lg-5">
            <label class="section-label">Resumo</label>
            <div class="detail-grid" id="userResumoCreate">
              <div class="detail-field"><h6>Status</h6><p id="resumoStatus">NOVO</p></div>
              <div class="detail-field"><h6>Role selecionada</h6><p id="resumoRoles">Nenhuma</p></div>
              <div class="detail-field"><h6>ID</h6><p id="resumoId">-</p></div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <div id="modalMsg" style="font-size:.7rem;color:var(--matrix-text-secondary)"></div>
        <div>
          <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
          <?php if(auth_can('usuarios.editar')): ?><button class="btn btn-matrix-primary" id="btnSalvarUser">Salvar</button><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Detalhes -->
<div class="modal fade modal-modern" id="modalDetalhes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Usuário</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div id="detalhesBody">Carregando...</div></div>
      <div class="modal-footer"><button class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button></div>
    </div>
  </div>
</div>

<!-- Modal Reset Senha -->
<div class="modal fade modal-modern" id="modalSenha" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Redefinir Senha</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label">Nova Senha</label>
        <input class="form-control" id="resetSenhaNova" type="password" placeholder="••••••" autocomplete="new-password">
        <label class="form-label mt-2">Confirmar Nova Senha</label>
        <input class="form-control" id="resetSenhaNovaConf" type="password" placeholder="Repetir" autocomplete="new-password">
        <input type="hidden" id="resetUserId">
        <div id="resetMsg" style="font-size:.65rem;margin-top:.5rem;color:var(--matrix-text-secondary)"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <?php if(auth_can('usuarios.reset_senha')): ?><button class="btn btn-matrix-primary" id="btnResetSenhaDo">Redefinir</button><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Modal Confirma Excluir Usuário -->
<div class="modal fade modal-modern" id="modalConfirmDelete" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;">
      <div class="modal-header" style="background:linear-gradient(135deg,var(--matrix-danger) 0%, #c54a55 100%); color:#fff;">
        <h5 class="modal-title"><i class="bi bi-trash3 me-1"></i>Excluir Usuário</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" style="font-size:.78rem; line-height:1.35;">
        <p id="delUserInfo" class="mb-2">Confirmar exclusão?</p>
        <div class="p-2 rounded" style="background:rgba(147,22,33,.08); border:1px solid rgba(147,22,33,.25); font-size:.65rem; font-weight:600; letter-spacing:.3px;">
          Esta ação é irreversível. O usuário e vínculos associados podem ser removidos.
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger btn-sm" id="btnConfirmDelete"><i class="bi bi-check2-circle me-1"></i>Excluir</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ================= STATE =================
const apiUrl = '../api/usuarios.php';
const resetApiUrl = '../api/usuarios_reset.php';
let page=1, loading=false, finished=false, perPage=12, currentFilters={q:'',status:'',role:''};
const usersContainer = document.getElementById('usersContainer');
const emptyState = document.getElementById('emptyState');
const loadMoreWrap = document.getElementById('loadMoreWrap');
const btnLoadMore = document.getElementById('btnLoadMore');
// KPIs
const kpiTotal = document.getElementById('kpiTotal');
const kpiAtivos = document.getElementById('kpiAtivos');
const kpiAtivosPct = document.getElementById('kpiAtivosPct');
const kpiInativos = document.getElementById('kpiInativos');
const kpiNovos7d = document.getElementById('kpiNovos7d');
const kpiAtualizadoEm = document.getElementById('kpiAtualizadoEm');
const rolesChart = document.getElementById('rolesChart');
const btnRefreshStats = document.getElementById('btnRefreshStats');
const kpiTrendAtivos = document.getElementById('kpiTrendAtivos');
const kpiBarAtivos = document.getElementById('kpiBarAtivos');
const kpiBarInativos = document.getElementById('kpiBarInativos');
const kpiBarNovos = document.getElementById('kpiBarNovos');
// Permissões
const canEditar = <?=(auth_can('usuarios.editar')? 'true':'false')?>;
const canReset = <?=(auth_can('usuarios.reset_senha')? 'true':'false')?>;
const currentUserId = <?= (int)$u['id'] ?>;

// Skeleton
const skeletonContainer = document.getElementById('skeletonContainer');
function showSkeleton(count=8){ skeletonContainer.classList.remove('d-none'); skeletonContainer.innerHTML = Array.from({length:count}).map(()=>'<div class="skeleton-card"></div>').join(''); }
function hideSkeleton(){ skeletonContainer.classList.add('d-none'); skeletonContainer.innerHTML=''; }

// Helpers
function avatarIni(nome){ return (nome||'?').trim().substring(0,1).toUpperCase(); }
function escapeHtml(str){ return (str||'').replace(/[&<>"']+/g, s=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'': '&#39;'}[s])); }
function animateCount(el,to,dur=600){ if(!el) return; const from=parseInt(el.textContent)||0; const start=performance.now(); function f(t){ const p=Math.min(1,(t-start)/dur); const val=Math.round(from+(to-from)*p); el.textContent=val; if(p<1) requestAnimationFrame(f);} requestAnimationFrame(f);} 

function roleBadges(arr){ return arr.map(r=>`<span>${r}</span>`).join(''); }

function userCard(u){
  const roles = u.roles_array||[];
  const roleIds = Array.isArray(u.roles_ids)? u.roles_ids : [];
  const ativo=u.ativo==1;
  return `<div class="user-card" data-id="${u.id}" data-role-ids="${roleIds.join(',')}" tabindex="0">\n  <div class="uc-head">\n    <div class="uc-avatar">${avatarIni(u.nome||u.email)}</div>\n    <div style="flex:1">\n      <p class="uc-name mb-0">${escapeHtml(u.nome)}</p>\n      <div class="uc-email">${escapeHtml(u.email)}</div>\n      <div class="role-badges">${roleBadges(roles)}</div>\n    </div>\n  </div>\n  <div class="uc-footer">\n    <div class="status-pill ${ativo?'status-pill--success':'status-pill--danger'}"><small>Status</small><strong>${ativo?'ATIVO':'INATIVO'}</strong></div>\n    <div class="uc-actions">\n      ${canEditar?'<button class="btn btn-outline-secondary" data-act="edit">Editar</button>':''}\n      ${canEditar && u.id!=currentUserId?'<button class="btn btn-outline-secondary" data-act="toggle">'+(ativo?'Desativar':'Ativar')+'</button>':''}\n      ${canReset && u.id!=currentUserId?'<button class="btn btn-outline-secondary" data-act="reset">Senha</button>':''}\n      ${canEditar && u.id!=currentUserId?'<button class="btn btn-danger" data-act="delete">Excluir</button>':''}\n    </div>\n  </div>\n</div>`; }

// Fetch Users
function fetchUsers(reset=false){ if(loading || (finished && !reset)) return; loading=true; if(reset){ page=1; finished=false; usersContainer.innerHTML=''; showSkeleton(); }
  const params = new URLSearchParams({page, per_page:perPage});
  if(currentFilters.q) params.append('q', currentFilters.q);
  if(currentFilters.status) params.append('status', currentFilters.status);
  if(currentFilters.role) params.append('role', currentFilters.role);
  fetch(apiUrl+'?'+params.toString())
    .then(r=>r.json())
    .then(data=>{ hideSkeleton(); loading=false; if(page===1 && data.data.length===0){ emptyState.classList.remove('d-none'); loadMoreWrap.classList.add('d-none'); return; } else { emptyState.classList.add('d-none'); }
      data.data.forEach(u=> usersContainer.insertAdjacentHTML('beforeend', userCard(u)) );
      const totalLoaded = document.querySelectorAll('.user-card').length;
      if(totalLoaded >= data.total){ finished=true; loadMoreWrap.classList.add('d-none'); } else { finished=false; loadMoreWrap.classList.remove('d-none'); }
      attachCardEvents();
    })
    .catch(()=>{ hideSkeleton(); loading=false; }); }

// Stats
function fetchStats(){ rolesChart.textContent='Carregando...'; fetch(apiUrl+'?stats=1').then(r=>r.json()).then(j=>{ if(!j.ok){ rolesChart.textContent='Erro'; return; }
  animateCount(kpiTotal,j.total); animateCount(kpiAtivos,j.ativos); animateCount(kpiNovos7d,j.novos_7d);
  kpiAtivosPct.textContent='Ativos '+j.pct_ativos+'%'; kpiInativos.textContent='Inativos '+j.inativos; kpiAtualizadoEm.textContent='Atualizado '+ new Date(j.timestamp).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}); kpiTrendAtivos.textContent=(j.pct_ativos>=50?'↑ ':'↓ ')+j.pct_ativos+'%';
  requestAnimationFrame(()=>{ if(kpiBarAtivos) kpiBarAtivos.style.width=j.pct_ativos+'%'; if(kpiBarInativos){ const pctInat=j.total?(j.inativos/j.total*100).toFixed(1):0; kpiBarInativos.style.width=pctInat+'%'; } if(kpiBarNovos){ const pctNov=j.total?(j.novos_7d/j.total*100).toFixed(1):0; kpiBarNovos.style.width=pctNov+'%'; } });
  renderRolesChart(j.roles,j.total); }).catch(()=> rolesChart.textContent='Erro'); }

function renderRolesChart(list,total){ if(!list||!list.length){ rolesChart.innerHTML='<span class="text-secondary">Sem roles atribuídas</span>'; return; } rolesChart.innerHTML = list.map(r=>{ const pct = total? (r.qtd/total*100):0; const pctFmt=pct.toFixed(1)+'%'; return `<div class="role-row"><div class="role-head"><span>${escapeHtml(r.nome)}</span><span>${r.qtd} · ${pctFmt}</span></div><div class="role-bar" data-pct="${pct.toFixed(1)}"><span>${pctFmt}</span></div></div>`; }).join(''); requestAnimationFrame(()=>{ rolesChart.querySelectorAll('.role-bar').forEach(b=>{ const v=b.dataset.pct; const span=b.querySelector('span'); span.style.width=(v<4 && v>0?4:v)+'%'; }); }); }

// Events
function attachCardEvents(){ usersContainer.querySelectorAll('.user-card').forEach(card=>{ card.querySelectorAll('.uc-actions button').forEach(btn=> btn.onclick = ()=> handleAction(card, btn.dataset.act)); card.addEventListener('dblclick', ()=> openDetails(card)); card.addEventListener('keydown', e=>{ if(e.key==='Enter') openDetails(card); }); }); }
function handleAction(card, act){ const id=card.dataset.id; if(act==='edit') openEdit(card); if(act==='toggle') toggleUser(id,card); if(act==='reset') openReset(id); if(act==='delete') showDeleteUserModal(id,card); }

// CRUD
function toggleUser(id,card){ (async()=>{ let ok = window.confirmDialog? await window.confirmDialog({ title:'Alterar status', message:'Confirmar mudança?', variant:'secondary', confirmText:'Confirmar', cancelText:'Cancelar'}): confirm('Alterar status?'); if(!ok) return; const fd=new FormData(); fd.append('action','toggle'); fd.append('id',id); fetch(apiUrl,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.ok){ const pill=card.querySelector('.status-pill'); const ativo=j.ativo==1; pill.classList.toggle('status-pill--success',ativo); pill.classList.toggle('status-pill--danger',!ativo); pill.innerHTML='<small>Status</small><strong>'+(ativo?'ATIVO':'INATIVO')+'</strong>'; const btn=card.querySelector('[data-act="toggle"]'); if(btn) btn.textContent=ativo?'Desativar':'Ativar'; fetchStats(); } else alert(j.error||'Falha'); }); })(); }
function openEdit(card){
  const id=card.dataset.id;
  document.getElementById('usrId').value=id;
  document.getElementById('usrNome').value=card.querySelector('.uc-name').textContent.trim();
  document.getElementById('usrEmail').value=card.querySelector('.uc-email').textContent.trim();
  ['usrSenha','usrSenhaConf','usrSenhaNova','usrSenhaNovaConf'].forEach(i=> document.getElementById(i).value='');
  document.querySelectorAll('.senha-inicial-group').forEach(e=> e.classList.add('d-none'));
  document.querySelectorAll('.senha-nova-group').forEach(e=> e.classList.remove('d-none'));
  const selectedIds = (card.dataset.roleIds || '').split(',').filter(Boolean);
  if(rolesBoxEl){
    const radios = getRoleInputs();
    const selectedId = selectedIds.length ? selectedIds[0] : '';
    let matched=false;
    radios.forEach(rb=>{
      rb.checked = rb.value === selectedId;
      if(rb.checked) matched=true;
    });
    if(!matched && radios.length){ radios[0].checked=true; }
  }
  document.getElementById('modalTitulo').textContent='Editar Usuário #'+id;
  document.getElementById('resumoId').textContent=id;
  document.getElementById('resumoStatus').textContent = card.querySelector('.status-pill strong').textContent;
  updateResumoRoles();
  modalEditar.show();
}
function openReset(id){ document.getElementById('resetUserId').value=id; document.getElementById('resetSenhaNova').value=''; document.getElementById('resetSenhaNovaConf').value=''; document.getElementById('resetMsg').textContent=''; modalSenha.show(); }
function novoUsuario(){
  document.getElementById('formUser').reset();
  document.getElementById('usrId').value='';
  if(rolesBoxEl){
    const inputs = getRoleInputs();
    inputs.forEach(cb=> cb.checked=false);
    const defaultRoleId = rolesBoxEl.dataset.defaultRole;
    let appliedDefault=false;
    if(defaultRoleId){
      const cbDefault = rolesBoxEl.querySelector(`input[value='${defaultRoleId}']`);
      if(cbDefault){ cbDefault.checked=true; appliedDefault=true; }
    }
    if(!appliedDefault && inputs.length){ inputs[0].checked=true; }
  }
  document.querySelectorAll('.senha-inicial-group').forEach(e=> e.classList.remove('d-none'));
  document.querySelectorAll('.senha-nova-group').forEach(e=> e.classList.add('d-none'));
  document.getElementById('modalTitulo').textContent='Novo Usuário';
  document.getElementById('resumoId').textContent='-';
  document.getElementById('resumoStatus').textContent='NOVO';
  updateResumoRoles();
  modalEditar.show();
}
function salvarUsuario(){
  const id=usrId.value.trim();
  const nome=usrNome.value.trim();
  const email=usrEmail.value.trim();
  const senha=usrSenha.value;
  const senhaConf=usrSenhaConf.value;
  const senhaNova=usrSenhaNova.value;
  const senhaNovaConf=usrSenhaNovaConf.value;
  const rolesSel = collectSelectedRoles();
  if(!nome||!email){ setModalMsg('Preencha nome e email'); return; }
  if(!id){
    if(!senha){ setModalMsg('Informe senha inicial'); return;}
    if(senha!==senhaConf){ setModalMsg('Confirmação da senha inicial não confere'); return;}
  } else if(senhaNova && senhaNova!==senhaNovaConf){ setModalMsg('Confirmação da nova senha não confere'); return; }
  if(!rolesSel.length){ setModalMsg('Selecione pelo menos um perfil'); return; }
  const fd=new FormData();
  fd.append('action', id? 'update':'create');
  if(id) fd.append('id',id);
  fd.append('nome',nome);
  fd.append('email',email);
  if(!id){ fd.append('senha',senha); fd.append('senha_conf',senhaConf);}
  if(id && senhaNova){ fd.append('senha_nova',senhaNova); fd.append('senha_nova_conf',senhaNovaConf);}
  rolesSel.forEach(r=> fd.append('roles[]',r));
  setModalMsg('Salvando...');
  fetch(apiUrl,{method:'POST',body:fd})
    .then(r=>r.json())
    .then(j=>{ if(j.ok){ setModalMsg('Salvo'); setTimeout(()=>{ modalEditar.hide(); fetchUsers(true); fetchStats(); },350); } else setModalMsg(j.error||'Erro'); })
    .catch(()=> setModalMsg('Erro rede'));
}
function resetSenha(){ const id=resetUserId.value; const n=resetSenhaNova.value.trim(); const nc=resetSenhaNovaConf.value.trim(); if(!n){ resetMsg.textContent='Informe a nova senha'; return;} if(n!==nc){ resetMsg.textContent='Confirmação não confere'; return;} const fd=new FormData(); fd.append('id',id); fd.append('nova',n); resetMsg.textContent='Enviando...'; fetch(resetApiUrl,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.ok){ resetMsg.textContent='Senha redefinida'; setTimeout(()=> modalSenha.hide(),650); } else resetMsg.textContent=j.error||'Erro'; }).catch(()=> resetMsg.textContent='Erro rede'); }
function deleteUser(id,card){ const fd=new FormData(); fd.append('action','delete'); fd.append('id',id); fetch(apiUrl,{method:'POST',body:fd}).then(r=>r.json()).then(j=>{ if(j.ok){ card.remove(); modalConfirmDelete.hide(); if(!document.querySelector('.user-card')) fetchUsers(true); fetchStats(); } else alert(j.error||'Falha ao excluir'); }).catch(()=> alert('Erro de rede')); }

// Replace old delete confirm with modal flow
let deleteCtx = { id:null, card:null };
function showDeleteUserModal(id,card){ deleteCtx.id=id; deleteCtx.card=card; const info=document.getElementById('delUserInfo'); if(info){ info.innerHTML='Excluir o usuário <strong>#'+id+'</strong>?<br><small class="text-danger">Ação irreversível.</small>'; } modalConfirmDelete.show(); }

// Modals
const modalEditar = new bootstrap.Modal(document.getElementById('modalEditar'));
const modalSenha = new bootstrap.Modal(document.getElementById('modalSenha'));
const modalDetalhes = new bootstrap.Modal(document.getElementById('modalDetalhes'));
const modalConfirmDelete = new bootstrap.Modal(document.getElementById('modalConfirmDelete'));

// Bind confirm delete button
const btnConfirmDelete = document.getElementById('btnConfirmDelete');
if(btnConfirmDelete){ btnConfirmDelete.addEventListener('click', ()=>{ if(deleteCtx.id && deleteCtx.card){ deleteUser(deleteCtx.id, deleteCtx.card); } }); }

// Inject missing element references and handlers
const btnNovo = document.getElementById('btnNovo');
const btnSalvarUser = document.getElementById('btnSalvarUser');
const modalMsgEl = document.getElementById('modalMsg');
const usrId = document.getElementById('usrId');
const usrNome = document.getElementById('usrNome');
const usrEmail = document.getElementById('usrEmail');
const usrSenha = document.getElementById('usrSenha');
const usrSenhaConf = document.getElementById('usrSenhaConf');
const usrSenhaNova = document.getElementById('usrSenhaNova');
const usrSenhaNovaConf = document.getElementById('usrSenhaNovaConf');
const rolesBoxEl = document.getElementById('rolesBox');
const resumoRolesEl = document.getElementById('resumoRoles');
const btnResetSenhaDo = document.getElementById('btnResetSenhaDo');
const resetUserId = document.getElementById('resetUserId');
const resetSenhaNova = document.getElementById('resetSenhaNova');
const resetSenhaNovaConf = document.getElementById('resetSenhaNovaConf');
const resetMsg = document.getElementById('resetMsg');
function getRoleInputs(){ return rolesBoxEl? rolesBoxEl.querySelectorAll('input[type=radio]') : []; }
function setModalMsg(t){ if(modalMsgEl) modalMsgEl.textContent = t; }

function collectSelectedRoles(){
  if(!rolesBoxEl) return [];
  const selected = rolesBoxEl.querySelector('input[type=radio]:checked');
  return selected ? [selected.value] : [];
}

function updateResumoRoles(){
  if(!resumoRolesEl) return;
  const selected = rolesBoxEl? rolesBoxEl.querySelector('input[type=radio]:checked') : null;
  resumoRolesEl.textContent = selected ? (selected.dataset.roleLabel || 'Selecionada') : 'Nenhuma';
}

if(rolesBoxEl){ getRoleInputs().forEach(cb=> cb.addEventListener('change', updateResumoRoles)); }

if(btnNovo){ btnNovo.addEventListener('click', novoUsuario); }
if(btnSalvarUser){ btnSalvarUser.addEventListener('click', salvarUsuario); }
if(btnResetSenhaDo){ btnResetSenhaDo.addEventListener('click', resetSenha); }

// Init
showSkeleton();
fetchUsers();
fetchStats();
</script>
<div id="opOverlay" class="op-overlay d-none"><div class="spinner-border text-secondary" role="status"></div></div>
<script>
// ==== DEBOUNCE & VIEW / FILTER ENHANCEMENTS ====
function debounce(fn,delay){ let t; return function(...a){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,a),delay); }; }
const filtroBuscaEl = document.getElementById('filtroBusca');
const activeFiltersEl = document.getElementById('activeFilters');
const viewToggleEl = document.getElementById('viewToggle');
let currentView='grid';
function setView(view){ if(view===currentView) return; currentView=view; document.querySelectorAll('#viewToggle button').forEach(b=> b.classList.toggle('active', b.dataset.view===view)); if(view==='list'){ usersContainer.classList.add('list-view'); } else { usersContainer.classList.remove('list-view'); } }
viewToggleEl.querySelectorAll('button').forEach(btn=> btn.addEventListener('click',()=> setView(btn.dataset.view)));
function renderActiveFilters(){ const chips=[]; if(currentFilters.q) chips.push({k:'q',label:'Busca: '+currentFilters.q}); if(currentFilters.status) chips.push({k:'status',label:'Status: '+currentFilters.status}); if(currentFilters.role) { const sel = document.querySelector('#filtroRole option[value="'+currentFilters.role+'"]'); chips.push({k:'role',label:'Role: '+(sel?sel.textContent:'ID '+currentFilters.role)}); }
  if(!chips.length){ activeFiltersEl.innerHTML=''; return; }
  activeFiltersEl.innerHTML = chips.map(c=>`<span class="af-chip" data-k="${c.k}">${c.label}<button type="button" aria-label="Remover" data-close="${c.k}">×</button></span>`).join('') + ' <button type="button" class="btn btn-link p-0 ms-1 btn-clear-filters" id="btnClearFilters">Limpar</button>';
  activeFiltersEl.querySelectorAll('[data-close]').forEach(b=> b.onclick = ()=>{ const k=b.getAttribute('data-close'); if(k==='q') filtroBuscaEl.value=''; if(k==='status') document.getElementById('filtroStatus').value=''; if(k==='role') document.getElementById('filtroRole').value=''; applyFilters(); });
  const clearBtn=document.getElementById('btnClearFilters'); if(clearBtn) clearBtn.onclick = ()=>{ filtroBuscaEl.value=''; document.getElementById('filtroStatus').value=''; document.getElementById('filtroRole').value=''; applyFilters(); };
}
const applyFilters = ()=> { perPage=parseInt(document.getElementById('filtroPerPage').value,10); currentFilters.q=filtroBuscaEl.value.trim(); currentFilters.status=filtroStatus.value; currentFilters.role=filtroRole.value; renderActiveFilters(); fetchUsers(true); };
// Replace previous click handler if exists
if(typeof btnAplicar !== 'undefined'){ btnAplicar.onclick = applyFilters; }
// Debounced live search
filtroBuscaEl.addEventListener('input', debounce(()=>{ currentFilters.q=filtroBuscaEl.value.trim(); if(currentFilters.q.length===0 || currentFilters.q.length>2){ applyFilters(); } }, 450));

function setOpLoading(v){ const ov=document.getElementById('opOverlay'); if(!ov) return; ov.classList.toggle('d-none', !v); }
// Patch operations to show overlay
const _origSalvar = salvarUsuario; salvarUsuario = function(){ setOpLoading(true); const done=()=> setTimeout(()=> setOpLoading(false),300); const prom = _origSalvar(); setTimeout(done,800); return prom; };
const _origToggle = toggleUser; toggleUser = function(id,card){ setOpLoading(true); _origToggle(id,card); setTimeout(()=> setOpLoading(false),800); };
const _origDelete = deleteUser; deleteUser = function(id,card){ setOpLoading(true); _origDelete(id,card); setTimeout(()=> setOpLoading(false),1000); };
const _origReset = resetSenha; resetSenha = function(){ setOpLoading(true); _origReset(); setTimeout(()=> setOpLoading(false),800); };

// After initial load show active filters if any
window.addEventListener('load', ()=> renderActiveFilters());
</script>
</body>
</html>
