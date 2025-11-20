<?php
session_start();
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
$u = auth_usuario();
if(!$u){ header('Location: ../login.php'); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ header('Location: ../index.php'); exit; }
$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);
if($_SERVER['REQUEST_METHOD']==='POST'){
  $titulo = trim($_POST['titulo'] ?? '');
  // Status inicial passa a exigir aprovação administrativa
  $status = 'pendente_aprovacao';
  // Insere via DB e redireciona direto para a tela da requisição para adicionar itens
  $temTitulo = (bool)$db->query("SHOW COLUMNS FROM requisicoes LIKE 'titulo'")->fetch();
  if($temTitulo){ $st = $db->prepare('INSERT INTO requisicoes (titulo, cliente_id, status) VALUES (?,?,?)'); $st->execute([$titulo,$clienteId,$status]); }
  else { $st = $db->prepare('INSERT INTO requisicoes (cliente_id, status) VALUES (?,?)'); $st->execute([$clienteId,$status]); }
  $id = (int)$db->lastInsertId();
  if($temTitulo && ($titulo==='' || $titulo===null)){
    try{ $db->prepare('UPDATE requisicoes SET titulo=? WHERE id=?')->execute(['Requisição #'.$id, $id]); }catch(Throwable $e){}
  }
  header('Location: requisicao.php?id='.$id); exit;
}
// Mapa de rótulos legíveis para status de requisições
$STATUS_LABELS = [
  'pendente_aprovacao' => 'Pendente de aprovação',
  'em_analise' => 'Em análise',
  'aberta' => 'Aberta',
  'fechada' => 'Fechada',
  'aprovada' => 'Aprovada',
  'rejeitada' => 'Rejeitada',
];
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Minhas Requisições</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/nexus.css">
  <style>
  .table thead th{white-space:nowrap;}
  .filtros-inline{flex-wrap:nowrap;overflow-x:auto;padding-bottom:.2rem;}
  .filtros-inline>*{flex:0 1 auto;min-width:160px;}
  .filtros-inline input{min-width:220px;}
  .pagination-hint{font-size:.85rem;}
  .pagination-sm .page-link{min-width:2.3rem;text-align:center;}
  .pagination-matrix .page-link{
    --matrix-border-soft: rgba(181,168,134,.45);
    --matrix-bg-soft: linear-gradient(140deg,rgba(181,168,134,.18),rgba(147,22,33,.08));
    --matrix-bg-hover: linear-gradient(140deg,rgba(181,168,134,.28),rgba(147,22,33,.18));
    color:#2b1c11;
    background: var(--matrix-bg-soft);
    border-color: var(--matrix-border-soft);
    font-weight:600;
    transition: background .25s ease,border-color .25s ease,transform .2s ease;
  }
  .pagination-matrix .page-link:hover{background:var(--matrix-bg-hover);border-color:rgba(147,22,33,.45);color:#1b0f08;}
  .pagination-matrix .page-link:focus-visible{box-shadow:0 0 0 3px rgba(147,22,33,.35);outline:none;}
  .pagination-matrix .page-item.active .page-link{color:#fff;background:linear-gradient(120deg,#b5a886,#a0454f);border-color:#a0454f;box-shadow:0 6px 16px rgba(147,22,33,.35);}
  .pagination-matrix .page-item.disabled .page-link{color:rgba(43,28,17,.45);background:linear-gradient(140deg,rgba(181,168,134,.08),rgba(147,22,33,.02));border-color:rgba(181,168,134,.25);opacity:.75;}
  </style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Minhas Requisições</h3>
    <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#novaReq">Nova Requisição</button>
  </div>
  <div class="card-matrix">
    <div class="card-header-matrix d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center">
      <span class="fw-semibold"><i class="bi bi-list-task me-2"></i>Minhas requisições</span>
      <div class="filtros-inline d-flex align-items-center gap-2">
        <input type="text" id="filtroBusca" class="form-control form-control-sm" placeholder="Buscar por ID ou título...">
        <select id="filtroStatus" class="form-select form-select-sm">
          <option value="">Status (todos)</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr><th>ID</th><th>Título</th><th>Status</th><th>Criado em</th><th>Ações</th></tr></thead>
        <tbody id="tbody-requisicoes"><tr><td colspan="5" class="text-center text-secondary py-4">Carregando...</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center px-3 py-3 border-top border-dark-subtle">
      <div id="paginationHint" class="text-secondary pagination-hint"></div>
      <nav aria-label="Paginação de requisições" data-bs-theme="light">
        <ul id="paginationContainer" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
      </nav>
    </div>
  </div>
</div>
<div class="modal fade" id="novaReq" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <form method="post">
      <div class="modal-header"><h5 class="modal-title">Nova Requisição</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <label class="form-label">Título (opcional)</label>
        <input type="text" name="titulo" class="form-control" placeholder="Ex: Compra de insumos">
        <div class="form-text text-secondary mt-2">Após criar, sua requisição ficará aguardando aprovação do administrador.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-matrix-primary">Criar</button></div>
    </form>
  </div></div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API_URL='../../api/requisicoes_cliente.php';
const DEFAULT_PER_PAGE=15;
const STATUS_LABELS = <?php echo json_encode($STATUS_LABELS ?? [], JSON_UNESCAPED_UNICODE); ?>;
const numberFormat=new Intl.NumberFormat('pt-BR');
function showToast(msg,type='info'){ const c=document.querySelector('.toast-container'); if(!c) return alert(msg); const id='t'+Date.now(); c.insertAdjacentHTML('beforeend',`<div id='${id}' class='toast align-items-center text-white bg-${type} border-0'><div class='d-flex'><div class='toast-body'>${msg}</div><button class='btn-close btn-close-white me-2 m-auto' data-bs-dismiss='toast'></button></div></div>`); const el=document.getElementById(id); const t=new bootstrap.Toast(el,{delay:2500}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove()); }
async function copyToClipboard(text){
  if(!text) return false;
  try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; } }catch(e){}
  try{ const ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.left='-9999px'; document.body.appendChild(ta); ta.select(); const ok=document.execCommand('copy'); ta.remove(); return !!ok; }catch(e){ return false; }
}
function handleCopyLink(id){
  fetch('../../api/requisicoes_tracking.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: Number(id) }) })
    .then(r=>r.json())
    .then(async d=>{
      if(!d.success){ showToast(d.erro||'Falha ao gerar link','danger'); return; }
      const base = location.pathname.replace(/cliente\/requisicoes\.php$/, '');
      const url = `${location.origin}${base}acompanhar_requisicao.php?token=${encodeURIComponent(d.token)}`;
      const ok = await copyToClipboard(url);
      showToast(ok? 'Link copiado!' : 'Não foi possível copiar o link.', ok? 'success':'warning');
    })
    .catch(()=> showToast('Erro de rede ao gerar link','danger'));
}
const state={
  rows:[],
  meta:{page:1,per_page:DEFAULT_PER_PAGE,total:0,total_pages:1},
  statusOptions:[]
};
const filtros={
  busca:document.getElementById('filtroBusca'),
  status:document.getElementById('filtroStatus')
};
const ui={
  tbody:document.getElementById('tbody-requisicoes'),
  pagination:document.getElementById('paginationContainer'),
  hint:document.getElementById('paginationHint')
};
let buscaTimeout=null;
function statusBadge(status){const st=(status||'').toLowerCase();if(st==='aberta')return 'info';if(st==='em_analise'||st==='pendente_aprovacao')return 'warning text-dark';if(st==='fechada'||st==='aprovada')return 'success';if(st==='rejeitada')return 'danger';return 'secondary';}
function statusLabel(status){const key=(status||'').toLowerCase();return STATUS_LABELS && STATUS_LABELS[key]?STATUS_LABELS[key]: (status?status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()):'-');}
function setTableLoading(){if(!ui.tbody)return;ui.tbody.innerHTML='<tr><td colspan="5" class="text-center text-secondary py-4">Carregando...</td></tr>';if(ui.pagination){ui.pagination.innerHTML='';}if(ui.hint){ui.hint.textContent='';}}
function buildParams(page){const params=new URLSearchParams();params.set('page',Math.max(1,page));params.set('per_page',state.meta.per_page||DEFAULT_PER_PAGE);const termo=(filtros.busca?.value||'').trim();if(termo){params.set('busca',termo);}const statusVal=filtros.status?.value||'';if(statusVal){params.set('status',statusVal);}return params;}
function renderRows(){if(!ui.tbody)return;const rows=Array.isArray(state.rows)?state.rows:[];if(!rows.length){ui.tbody.innerHTML='<tr><td colspan="5" class="text-center text-secondary py-4">Nenhuma requisição encontrada.</td></tr>';return;}ui.tbody.innerHTML=rows.map(r=>{const badge=statusBadge(r.status);const label=statusLabel(r.status);const criado=r.criado_em?new Date(r.criado_em.replace(' ','T')).toLocaleString('pt-BR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}):'-';return `<tr>
      <td>#${r.id}</td>
      <td>${r.titulo?escapeHtml(r.titulo):'-'}</td>
      <td><span class="badge bg-${badge}">${escapeHtml(label)}</span></td>
      <td>${escapeHtml(criado)}</td>
      <td>
        <a class="btn btn-sm btn-client-action" href="requisicao.php?id=${r.id}"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>
        <a class="btn btn-sm btn-client-action ms-1" href="acompanhar_requisicao.php?id=${r.id}"><span class="btn-icon"><i class="bi bi-geo-alt"></i></span><span>Acompanhar</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>
        <button type="button" class="btn btn-sm btn-client-action ms-1 btn-copy-link" data-id="${r.id}" title="Copiar link público"><span class="btn-icon"><i class="bi bi-link-45deg"></i></span><span>Copiar link</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></button>
      </td>
    </tr>`;}).join('');}
function escapeHtml(text){if(text==null)return '';return text.replace(/[&<>"']/g,function(ch){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]);});}
function createPageItem(label,targetPage,{disabled=false,active=false}={}){const classes=['page-item'];if(disabled)classes.push('disabled');if(active)classes.push('active');const content=disabled?`<span class="page-link">${label}</span>`:`<button type="button" class="page-link" data-page="${targetPage}">${label}</button>`;return `<li class="${classes.join(' ')}">${content}</li>`;}
function renderPagination(){if(!ui.pagination||!ui.hint)return;const meta=state.meta||{};const total=Number(meta.total)||0;const perPage=Number(meta.per_page)||DEFAULT_PER_PAGE;const page=Math.max(1,Number(meta.page)||1);const totalPages=Math.max(1,Number(meta.total_pages)||Math.ceil((total||1)/perPage));if(total===0){ui.pagination.innerHTML='';ui.hint.textContent='Nenhuma requisição encontrada para os filtros selecionados.';return;}const start=((page-1)*perPage)+1;const end=Math.min(total,start+(state.rows.length?state.rows.length:perPage)-1);ui.hint.textContent=`Mostrando ${numberFormat.format(start)}-${numberFormat.format(end)} de ${numberFormat.format(total)} requisições`;
  const items=[];
  items.push(createPageItem('«',1,{disabled:page===1}));
  items.push(createPageItem('‹',Math.max(1,page-1),{disabled:page===1}));
  const windowSize=5;let startPage=Math.max(1,page-Math.floor(windowSize/2));let endPage=startPage+windowSize-1;if(endPage>totalPages){endPage=totalPages;startPage=Math.max(1,endPage-windowSize+1);}for(let p=startPage;p<=endPage;p++){items.push(createPageItem(String(p),p,{active:p===page}));}
  items.push(createPageItem('›',Math.min(totalPages,page+1),{disabled:page===totalPages}));
  items.push(createPageItem('»',totalPages,{disabled:page===totalPages}));
  ui.pagination.innerHTML=items.join('');
  ui.pagination.querySelectorAll('button[data-page]').forEach(btn=>btn.addEventListener('click',ev=>{const target=parseInt(ev.currentTarget.dataset.page,10)||1;if(target!==page){carregar(target);}}));
}
function preencherFiltroStatus(){if(!filtros.status)return;const selected=(filtros.status.value||'').toLowerCase();const options=['<option value="">Status (todos)</option>'];const lista=Array.isArray(state.statusOptions)?state.statusOptions:[];lista.forEach(item=>{if(!item||!item.value)return;const value=String(item.value).toLowerCase();options.push(`<option value="${value}">${item.label||item.value}</option>`);});filtros.status.innerHTML=options.join('');if(selected){const exists=lista.some(item=>String(item.value).toLowerCase()===selected);if(exists){filtros.status.value=selected;}}
}
async function carregar(pageOverride){const page=Math.max(1,pageOverride||state.meta.page||1);setTableLoading();try{const params=buildParams(page);const resp=await fetch(`${API_URL}?${params.toString()}`,{cache:'no-store'});if(!resp.ok)throw new Error('Erro ao carregar requisições');const payload=await resp.json();state.rows=Array.isArray(payload?.data)?payload.data:[];const meta=payload?.meta||{};state.meta={
      page:Number(meta.page)||page,
      per_page:Number(meta.per_page)||DEFAULT_PER_PAGE,
      total:Number(meta.total)||state.rows.length,
      total_pages:Number(meta.total_pages)||1
    };
    state.statusOptions=Array.isArray(meta.status_options)?meta.status_options:[];
    renderRows();
    preencherFiltroStatus();
    renderPagination();
  }catch(err){if(ui.tbody){ui.tbody.innerHTML='<tr><td colspan="5" class="text-center text-danger py-4">Erro ao carregar requisições.</td></tr>';}
    if(ui.pagination){ui.pagination.innerHTML='';}
    if(ui.hint){ui.hint.textContent='';}
    showToast('Não foi possível carregar as requisições agora.','danger');
    console.error(err);
  }
}
function handleBuscaInput(){clearTimeout(buscaTimeout);buscaTimeout=setTimeout(()=>carregar(1),350);}
function handleFiltroChange(){carregar(1);}
document.addEventListener('DOMContentLoaded',()=>{
  carregar(1);
  filtros.busca?.addEventListener('input',handleBuscaInput);
  filtros.status?.addEventListener('change',handleFiltroChange);
});
document.addEventListener('click', function(e){ const btn = e.target.closest('.btn-copy-link'); if(!btn) return; e.preventDefault(); const id = btn.getAttribute('data-id'); handleCopyLink(id); });
</script>
</body>
</html>
