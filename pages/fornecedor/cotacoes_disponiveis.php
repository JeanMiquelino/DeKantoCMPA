<?php
session_start();
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/auth.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    header('Location: ../login.php');
    exit;
}
$currentNav='cotacoes_disponiveis';
?><!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cotações Disponíveis - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../../assets/css/nexus.css">
<style>
.table thead th{white-space:nowrap;}
.badge-status{font-size:.65rem;letter-spacing:.04em;}
.filtros-inline{flex-wrap:nowrap;overflow-x:auto;padding-bottom:.2rem;}
.filtros-inline> *{flex:0 1 auto;min-width:150px;}
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
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container py-4" id="lista-cotacoes-container">
  <div class="card-matrix mb-4">
    <div class="card-header-matrix d-flex justify-content-between align-items-center">
      <span><i class="bi bi-shop me-2"></i>Cotações Abertas</span>
      <div class="filtros-inline d-flex align-items-center gap-2">
        <input type="text" id="filtroBusca" class="form-control form-control-sm" placeholder="Buscar por ID ou requisição...">
        <select id="filtroStatus" class="form-select form-select-sm">
          <option value="">Status (todos)</option>
        </select>
        <select id="filtroEnvio" class="form-select form-select-sm">
          <option value="">Envio (todos)</option>
          <option value="sim">Já enviei</option>
          <option value="nao" selected>Ainda não</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Requisição</th>
            <th>Rodada</th>
            <th>Status</th>
            <th>Já Enviou</th>
            <th class="text-end">Ações</th>
          </tr>
        </thead>
        <tbody id="tbody-cotacoes"><tr><td colspan="6" class="text-center text-secondary py-4">Carregando...</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center px-3 py-3 border-top border-dark-subtle">
      <div id="paginationHint" class="text-secondary pagination-hint"></div>
      <nav aria-label="Paginação de cotações" data-bs-theme="light">
        <ul id="paginationContainer" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
      </nav>
    </div>
  </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API_URL='../../api/fornecedor/cotacoes.php';
const DEFAULT_PER_PAGE=15;
const numberFormat=new Intl.NumberFormat('pt-BR');
function showToast(message,type='info'){const c=document.querySelector('.toast-container');if(!c)return;const id='t'+Date.now();c.insertAdjacentHTML('beforeend',`<div id="${id}" class="toast text-white bg-${type} border-0" data-bs-delay="3500"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);const el=document.getElementById(id);new bootstrap.Toast(el).show();el.addEventListener('hidden.bs.toast',()=>el.remove());}
const state={
  rows:[],
  meta:{page:1,per_page:DEFAULT_PER_PAGE,total:0,total_pages:1},
  statusOptions:[]
};
const filtros={
  busca:document.getElementById('filtroBusca'),
  status:document.getElementById('filtroStatus'),
  envio:document.getElementById('filtroEnvio')
};
const ui={
  tbody:document.getElementById('tbody-cotacoes'),
  pagination:document.getElementById('paginationContainer'),
  hint:document.getElementById('paginationHint')
};
let buscaTimeout=null;
function setTableLoading(){if(!ui.tbody)return;ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-4">Carregando...</td></tr>';if(ui.pagination){ui.pagination.innerHTML='';}if(ui.hint){ui.hint.textContent='';}}
function buildParams(page){const params=new URLSearchParams();params.set('page',Math.max(1,page));params.set('per_page',state.meta.per_page||DEFAULT_PER_PAGE);const termo=(filtros.busca?.value||'').trim();if(termo){params.set('busca',termo);}const statusVal=filtros.status?.value||'';if(statusVal){params.set('status',statusVal);}const envioVal=filtros.envio?.value||'';if(envioVal){params.set('envio',envioVal);}return params;}
function renderRows(){if(!ui.tbody)return;const rows=Array.isArray(state.rows)?state.rows:[];if(!rows.length){ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-4">Nenhuma cotação encontrada.</td></tr>';return;}ui.tbody.innerHTML=rows.map(c=>{const jaEnviou=c.ja_enviou===true||c.ja_enviou==='1'||c.ja_enviou===1;const badge=jaEnviou?'<span data-bs-theme="light" class="badge bg-success-subtle text-success-emphasis">Enviada</span>':'<span data-bs-theme="light" class="badge bg-secondary-subtle text-secondary-emphasis">Pendente</span>';const statusLabel=c.status||'-';return `<tr>
        <td>#${c.id}</td>
        <td>#${c.requisicao_id||'-'}</td>
        <td>${c.rodada||1}</td>
        <td><span data-bs-theme="light" class="badge bg-info-subtle text-info-emphasis">${statusLabel}</span></td>
        <td>${badge}</td>
        <td class="text-end">
          <a href="cotacao.php?id=${c.id}" class="btn btn-sm btn-matrix-primary" title="Responder">
            <i class="bi bi-arrow-right"></i>
          </a>
        </td>
      </tr>`;}).join('');}
function createPageItem(label,targetPage,{disabled=false,active=false}={}){const classes=['page-item'];if(disabled)classes.push('disabled');if(active)classes.push('active');const content=disabled?`<span class="page-link">${label}</span>`:`<button type="button" class="page-link" data-page="${targetPage}">${label}</button>`;return `<li class="${classes.join(' ')}">${content}</li>`;}
function renderPagination(){if(!ui.pagination||!ui.hint)return;const meta=state.meta||{};const total=Number(meta.total)||0;const perPage=Number(meta.per_page)||DEFAULT_PER_PAGE;const page=Math.max(1,Number(meta.page)||1);const totalPages=Math.max(1,Number(meta.total_pages)||Math.ceil((total||1)/perPage));if(total===0){ui.pagination.innerHTML='';ui.hint.textContent='Nenhuma cotação encontrada para os filtros selecionados.';return;}const start=((page-1)*perPage)+1;const end=Math.min(total,start+(state.rows.length?state.rows.length:perPage)-1);ui.hint.textContent=`Mostrando ${numberFormat.format(start)}-${numberFormat.format(end)} de ${numberFormat.format(total)} cotações`;
  const items=[];
  items.push(createPageItem('«',1,{disabled:page===1}));
  items.push(createPageItem('‹',Math.max(1,page-1),{disabled:page===1}));
  const windowSize=5;let startPage=Math.max(1,page-Math.floor(windowSize/2));let endPage=startPage+windowSize-1;if(endPage>totalPages){endPage=totalPages;startPage=Math.max(1,endPage-windowSize+1);}for(let p=startPage;p<=endPage;p++){items.push(createPageItem(String(p),p,{active:p===page}));}
  items.push(createPageItem('›',Math.min(totalPages,page+1),{disabled:page===totalPages}));
  items.push(createPageItem('»',totalPages,{disabled:page===totalPages}));
  ui.pagination.innerHTML=items.join('');
  ui.pagination.querySelectorAll('button[data-page]').forEach(btn=>btn.addEventListener('click',ev=>{const target=parseInt(ev.currentTarget.dataset.page,10)||1;if(target!==page){carregar(target);}}));
}
function preencherFiltroStatus(){if(!filtros.status)return;const selected=(filtros.status.value||'').toLowerCase();const options=['<option value="">Status (todos)</option>'];const lista=Array.isArray(state.statusOptions)?state.statusOptions:[];lista.forEach(item=>{if(!item||!item.value)return;const value=String(item.value).toLowerCase();const label=item.label||item.value;options.push(`<option value="${value}">${label}</option>`);});filtros.status.innerHTML=options.join('');if(selected){const exists=lista.some(item=>String(item.value).toLowerCase()===selected);if(exists){filtros.status.value=selected;}}
}
async function carregar(pageOverride){const page=Math.max(1,pageOverride||state.meta.page||1);setTableLoading();try{const params=buildParams(page);const resp=await fetch(`${API_URL}?${params.toString()}`,{cache:'no-store'});if(!resp.ok)throw new Error('Erro ao carregar as cotações');const payload=await resp.json();state.rows=Array.isArray(payload?.data)?payload.data:[];const meta=payload?.meta||{};state.meta={
      page:Number(meta.page)||page,
      per_page:Number(meta.per_page)||DEFAULT_PER_PAGE,
      total:Number(meta.total)||state.rows.length,
      total_pages:Number(meta.total_pages)||1
    };
    state.statusOptions=Array.isArray(meta.status_options)?meta.status_options:[];
    renderRows();
    preencherFiltroStatus();
    renderPagination();
  }catch(err){if(ui.tbody){ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-danger py-4">Erro ao carregar as cotações.</td></tr>';}
    if(ui.pagination){ui.pagination.innerHTML='';}
    if(ui.hint){ui.hint.textContent='';}
    showToast('Não foi possível carregar as cotações no momento.','danger');
    console.error(err);
  }
}
function handleBuscaInput(){clearTimeout(buscaTimeout);buscaTimeout=setTimeout(()=>carregar(1),350);}
function handleFiltroChange(){carregar(1);}
document.addEventListener('DOMContentLoaded',()=>{
  if(filtros.envio&& !filtros.envio.value){filtros.envio.value='nao';}
  carregar(1);
  filtros.busca?.addEventListener('input',handleBuscaInput);
  filtros.status?.addEventListener('change',handleFiltroChange);
  filtros.envio?.addEventListener('change',handleFiltroChange);
});
</script>
</body>
</html>
