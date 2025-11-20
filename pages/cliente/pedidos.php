<?php
session_start();
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/timeline.php';
require_once __DIR__.'/../../includes/email.php';
$u = auth_usuario();
if(!$u){ header('Location: ../login.php'); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ header('Location: ../index.php'); exit; }
$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);

// Ação de aceitar/rejeitar sem token (via portal autenticado)
if($_SERVER['REQUEST_METHOD']==='POST'){
  $pedidoId = (int)($_POST['pedido_id'] ?? 0);
  $acao = $_POST['acao'] ?? 'aceitar';
  if($pedidoId){
    // Verificar ownership e status pendente
    $sql = "SELECT p.id, p.cliente_aceite_status, c.requisicao_id FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id JOIN requisicoes r ON r.id=c.requisicao_id WHERE p.id=? AND r.cliente_id=?";
    $st=$db->prepare($sql); $st->execute([$pedidoId,$clienteId]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if($row && $row['cliente_aceite_status']==='pendente'){
      $novoStatus = ($acao==='rejeitar')?'rejeitado':'aceito';
      $db->prepare('UPDATE pedidos SET cliente_aceite_status=?, cliente_aceite_em=NOW() WHERE id=?')->execute([$novoStatus,$pedidoId]);
      // Atualizar status do pedido conforme decisão do cliente + email em caso de aceite
      if($novoStatus==='aceito'){
        try { $db->prepare('UPDATE pedidos SET status="pendente" WHERE id=?')->execute([$pedidoId]); } catch(Throwable $e){}
        if(function_exists('email_send_pedido_confirmacao')){
          try { email_send_pedido_confirmacao($pedidoId, false); } catch(Throwable $e){}
        }
      } else {
        try { $db->prepare('UPDATE pedidos SET status="cancelado" WHERE id=?')->execute([$pedidoId]); } catch(Throwable $e){}
      }
      // Log na timeline da requisicao
      try { log_requisicao_event($db, (int)$row['requisicao_id'], $novoStatus==='aceito'?'pedido_aceito':'pedido_rejeitado', 'Pedido '.$novoStatus.' pelo cliente via portal', null, ['pedido_id'=>$pedidoId,'status'=>$novoStatus]); } catch(Throwable $e){}
    }
  }
  header('Location: pedidos.php'); exit;
}

// Mapa de rótulos do aceite do cliente
$ACEITE_LABELS = [ 'pendente'=>'Pendente', 'aceito'=>'Aceito', 'rejeitado'=>'Rejeitado' ];
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pedidos do Cliente</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/nexus.css">
  <style>
  .table thead th{white-space:nowrap;}
  .filtros-inline{flex-wrap:nowrap;overflow-x:auto;padding-bottom:.2rem;}
  .filtros-inline>*{flex:0 1 auto;min-width:150px;}
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
  .btn-client-approve,.btn-client-reject{border-radius:999px;display:inline-flex;align-items:center;gap:.35rem;font-weight:600;padding:.32rem .95rem;font-size:.85rem;transition:transform .18s ease,background .2s ease,color .2s ease;border:1px solid transparent;letter-spacing:.01em}
  .btn-client-approve{background:linear-gradient(120deg,#d3f9d8,#7dd3a7);color:#064e3b;border-color:rgba(34,197,94,.35)}
  .btn-client-approve:hover,.btn-client-approve:focus-visible{transform:translateY(-1px);color:#022c22}
  .btn-client-reject{background:linear-gradient(145deg,rgba(127,29,29,.85),rgba(244,63,94,.65));color:#fee2e2;border-color:rgba(220,38,38,.85)}
  .btn-client-reject:hover,.btn-client-reject:focus-visible{background:linear-gradient(145deg,rgba(88,28,28,.95),rgba(220,38,38,.85));color:#fff;transform:translateY(-1px)}
  .btn-client-approve i,.btn-client-reject i{font-size:1rem;line-height:1}
  </style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<div class="container py-4">
  <h3 class="mb-3">Pedidos</h3>
  <div class="card-matrix">
    <div class="card-header-matrix d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center">
      <span class="fw-semibold"><i class="bi bi-box-seam me-2"></i>Pedidos emitidos</span>
      <div class="filtros-inline d-flex align-items-center gap-2">
        <input type="text" id="filtroBusca" class="form-control form-control-sm" placeholder="Buscar por pedido ou requisição...">
        <select id="filtroStatus" class="form-select form-select-sm">
          <option value="">Status (todos)</option>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead>
          <tr><th>ID</th><th>Requisição</th><th>Valor</th><th>Prazo</th><th>Status Aceite</th><th>Ações</th></tr>
        </thead>
        <tbody id="tbody-pedidos"><tr><td colspan="6" class="text-center text-secondary py-4">Carregando...</td></tr></tbody>
      </table>
    </div>
    <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center px-3 py-3 border-top border-dark-subtle">
      <div id="paginationHint" class="text-secondary pagination-hint"></div>
      <nav aria-label="Paginação de pedidos" data-bs-theme="light">
        <ul id="paginationContainer" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
      </nav>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const API_URL='../../api/pedidos_cliente.php';
const DEFAULT_PER_PAGE=15;
const STATUS_LABELS = <?php echo json_encode(array_change_key_case($ACEITE_LABELS, CASE_LOWER), JSON_UNESCAPED_UNICODE); ?>;
const currencyFormat=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
const numberFormat=new Intl.NumberFormat('pt-BR');
const state={rows:[],meta:{page:1,per_page:DEFAULT_PER_PAGE,total:0,total_pages:1},statusOptions:[]};
const filtros={
  busca:document.getElementById('filtroBusca'),
  status:document.getElementById('filtroStatus')
};
const ui={
  tbody:document.getElementById('tbody-pedidos'),
  pagination:document.getElementById('paginationContainer'),
  hint:document.getElementById('paginationHint')
};
let buscaTimeout=null;
function statusBadge(status){const st=(status||'').toLowerCase();if(st==='aceito')return 'success';if(st==='rejeitado')return 'danger';if(st==='pendente')return 'warning text-dark';return 'secondary';}
function statusLabel(status){const key=(status||'').toLowerCase();return STATUS_LABELS[key]??(status?status.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()):'-');}
function setTableLoading(){if(!ui.tbody)return;ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-4">Carregando...</td></tr>';if(ui.pagination){ui.pagination.innerHTML='';}if(ui.hint){ui.hint.textContent='';}}
function buildParams(page){const params=new URLSearchParams();params.set('page',Math.max(1,page));params.set('per_page',state.meta.per_page||DEFAULT_PER_PAGE);const termo=(filtros.busca?.value||'').trim();if(termo){params.set('busca',termo);}const statusVal=filtros.status?.value||'';if(statusVal){params.set('status',statusVal);}return params;}
function escapeHtml(text){if(text==null)return '';return text.replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));}
function renderActions(row){const pendente=(row.cliente_aceite_status||'').toLowerCase()==='pendente';const viewBtn=`<a class="btn btn-sm btn-client-action me-1" href="requisicao.php?id=${row.requisicao_id}"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>`;if(!pendente){return viewBtn;}return `${viewBtn}
    <form method="post" class="d-inline ms-1" data-bs-theme="light">
      <input type="hidden" name="pedido_id" value="${row.id}">
      <button name="acao" value="aceitar" class="btn btn-client-approve"><i class="bi bi-check2-circle"></i><span>Aprovar</span></button>
    </form>
    <form method="post" class="d-inline ms-1" data-bs-theme="light">
      <input type="hidden" name="pedido_id" value="${row.id}">
      <button name="acao" value="rejeitar" class="btn btn-client-reject"><i class="bi bi-x-circle"></i><span>Rejeitar</span></button>
    </form>`;
}
function renderRows(){if(!ui.tbody)return;const rows=Array.isArray(state.rows)?state.rows:[];if(!rows.length){ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-4">Nenhum pedido encontrado.</td></tr>';return;}ui.tbody.innerHTML=rows.map(row=>{const valor=currencyFormat.format(Number(row.valor_total||0));const prazo=row.prazo_dias?`${row.prazo_dias} dias`:'-';const badge=statusBadge(row.cliente_aceite_status);const label=statusLabel(row.cliente_aceite_status);return `<tr>
      <td>#${row.id}</td>
      <td>${escapeHtml(row.requisicao_titulo||'-')}</td>
      <td>${valor}</td>
      <td>${escapeHtml(prazo)}</td>
      <td><span class="badge bg-${badge}">${escapeHtml(label)}</span></td>
      <td>${renderActions(row)}</td>
    </tr>`;}).join('');}
function createPageItem(label,targetPage,{disabled=false,active=false}={}){const classes=['page-item'];if(disabled)classes.push('disabled');if(active)classes.push('active');const content=disabled?`<span class="page-link">${label}</span>`:`<button type="button" class="page-link" data-page="${targetPage}">${label}</button>`;return `<li class="${classes.join(' ')}">${content}</li>`;}
function renderPagination(){if(!ui.pagination||!ui.hint)return;const meta=state.meta||{};const total=Number(meta.total)||0;const perPage=Number(meta.per_page)||DEFAULT_PER_PAGE;const page=Math.max(1,Number(meta.page)||1);const totalPages=Math.max(1,Number(meta.total_pages)||Math.ceil((total||1)/perPage));if(total===0){ui.pagination.innerHTML='';ui.hint.textContent='Nenhum pedido encontrado para os filtros selecionados.';return;}const start=((page-1)*perPage)+1;const end=Math.min(total,start+(state.rows.length?state.rows.length:perPage)-1);ui.hint.textContent=`Mostrando ${numberFormat.format(start)}-${numberFormat.format(end)} de ${numberFormat.format(total)} pedidos`;
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
async function carregar(pageOverride){const page=Math.max(1,pageOverride||state.meta.page||1);setTableLoading();try{const params=buildParams(page);const resp=await fetch(`${API_URL}?${params.toString()}`,{cache:'no-store'});if(!resp.ok)throw new Error('Erro ao carregar pedidos');const payload=await resp.json();state.rows=Array.isArray(payload?.data)?payload.data:[];const meta=payload?.meta||{};state.meta={
      page:Number(meta.page)||page,
      per_page:Number(meta.per_page)||DEFAULT_PER_PAGE,
      total:Number(meta.total)||state.rows.length,
      total_pages:Number(meta.total_pages)||1
    };
    state.statusOptions=Array.isArray(meta.status_options)?meta.status_options:[];
    renderRows();
    preencherFiltroStatus();
    renderPagination();
  }catch(err){if(ui.tbody){ui.tbody.innerHTML='<tr><td colspan="6" class="text-center text-danger py-4">Erro ao carregar pedidos.</td></tr>';}
    if(ui.pagination){ui.pagination.innerHTML='';}
    if(ui.hint){ui.hint.textContent='';}
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
</script>
</body>
</html>
