<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php'; // added for type checking
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
$__u = auth_usuario();
if ($__u && in_array(($__u['tipo'] ?? ''), ['cliente','fornecedor'], true)) {
    if(($__u['tipo'] ?? '') === 'cliente') { header('Location: cliente/index.php'); } else { header('Location: fornecedor/index.php'); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedidos - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
        /* Ajustes de layout de filtros */
        #form-filtros {
            background: var(--matrix-surface-transparent);
            border: 1px solid var(--matrix-border);
            border-radius: 12px;
            padding: 1.25rem 1.25rem 1.25rem; /* aumentado padding inferior */
        }
        #form-filtros .form-label { margin-bottom: .35rem; }
        #form-filtros .col-md-3 button { height:48px; }
        #filtros-toolbar { display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
        #btn-limpar-filtros { white-space:nowrap; }
        .card-header-matrix .actions-inline { display:flex; gap:.5rem; }
        .export-format-pedidos .choice {flex:1; position:relative;}
        .export-format-pedidos input {position:absolute; opacity:0; inset:0; cursor:pointer;}
        .export-format-pedidos label {display:flex; align-items:center; justify-content:center; gap:.4rem; background:rgba(13,12,29,.5); border:1px solid var(--matrix-border); border-radius:.5rem; padding:.55rem .65rem; font-weight:500; cursor:pointer; transition:.25s; font-size:.85rem;}
        .export-format-pedidos label:hover {background:rgba(13,12,29,.65); border-color:var(--matrix-primary); color:var(--matrix-text-primary);}
        .export-format-pedidos input:checked + label {background:rgba(13,12,29,.85); border-color:var(--matrix-primary); color:#fff; box-shadow:0 0 0 .15rem rgba(128,114,255,.25);}        
        .modal small.hint {color:var(--matrix-text-secondary);}        
        /* NOVO: estilos leves para modal de itens do pedido */
        #modal-itens-pedido .resumo-pedido {background: var(--matrix-surface); border:1px solid var(--matrix-border); border-radius:.65rem; padding:.75rem 1rem; font-size:.85rem;}
        #modal-itens-pedido table thead th {white-space:nowrap;}
        #modal-itens-pedido tfoot td {font-weight:600; border-top:2px solid var(--matrix-border);}        
        /* Autocomplete filtro fornecedor */
        #dropdown-fornecedor-filtro{position:fixed; z-index:1056; max-height:260px; overflow:auto; display:none;}
        #filtro-fornecedor-search.loading{background-image:linear-gradient(90deg,rgba(255,255,255,0.05),rgba(255,255,255,0.15),rgba(255,255,255,0.05)); background-size:200% 100%; animation:shimmer 1.2s linear infinite;}
        @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
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

<?php include 'navbar.php'; ?>

<div class="container py-4" id="page-container" 
    data-endpoint="../api/pedidos.php" 
    data-singular="Pedido"
    data-plural="Pedidos">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Pedidos</h1>
            <p class="text-secondary mb-0">Acompanhe o status e os detalhes dos seus pedidos de compra.</p>
        </div>
        <div id="filtros-toolbar">
            <button class="btn btn-sm btn-matrix-secondary" id="btn-exportar" data-bs-toggle="modal" data-bs-target="#modal-exportar-pedidos"><i class="bi bi-download me-1"></i>Exportar</button>
        </div>
    </header>

    <div class="card-matrix mb-4">
        <div class="card-body pt-3">
            <form id="form-filtros" class="row g-3 align-items-end">
                <div class="col-md-4" id="col-filtro-fornecedor">
                    <label for="filtro-fornecedor" class="form-label">Fornecedor</label>
                    <select id="filtro-fornecedor" class="form-select">
                        <option value="">Todos os Fornecedores</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="filtro-status" class="form-label">Status</label>
                    <select id="filtro-status" class="form-select">
                        <option value="">Todos os Status</option>
                        <option value="aguardando_aprovacao_cliente">Aguardando aprovação do cliente</option>
                        <option value="pendente">Pendente</option>
                        <option value="emitido">Emitido</option>
                        <option value="em_producao">Em Produção</option>
                        <option value="enviado">Enviado</option>
                        <option value="entregue">Entregue</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="filtro-data" class="form-label">Data</label>
                    <input type="date" class="form-control" id="filtro-data">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-matrix-primary flex-fill">Filtrar</button>
                    <button type="button" id="btn-limpar-filtros" class="btn btn-matrix-secondary flex-fill">Limpar</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>Pedidos Gerados</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Pedido ID</th>
                        <th>Fornecedor</th>
                        <th>Data</th>
                        <th>Valor Total</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabela-main-body"></tbody>
            </table>
        </div>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 border-top px-3 py-2">
            <div id="pagination-hint" class="text-secondary small">Carregando pedidos...</div>
            <nav aria-label="Paginação de pedidos" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-status" tabindex="-1" aria-labelledby="modalStatusLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
        <div class="modal-header card-header-matrix">
            <h5 class="modal-title" id="modalStatusLabel"><i class="bi bi-toggles me-2"></i>Alterar Status do Pedido</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
            <form id="form-status">
                <input type="hidden" id="pedido-id-status" name="id">
                <label for="select-status" class="form-label">Selecione o novo status para o pedido <strong id="pedido-id-label"></strong>:</label>
                <select class="form-select" id="select-status" name="status">
                    <option value="aguardando_aprovacao_cliente">Aguardando aprovação do cliente</option>
                    <option value="pendente">Pendente</option>
                    <option value="emitido">Emitido</option>
                    <option value="em_producao">Em Produção</option>
                    <option value="enviado">Enviado</option>
                    <option value="entregue">Entregue</option>
                    <option value="cancelado">Cancelado</option>
                </select>
            </form>
            <!-- Aviso condicional quando bloqueado por aceite do cliente -->
            <div id="status-locked-hint" class="alert alert-warning small mt-3 d-none">
                Este pedido está aguardando aceite do cliente. O status não pode ser alterado até o cliente aceitar.
            </div>
        </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" id="btn-salvar-status" class="btn btn-matrix-primary" form="form-status">Salvar Alteração</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Exportação -->
<div class="modal fade" id="modal-exportar-pedidos" tabindex="-1" aria-labelledby="modalExportarPedidosLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalExportarPedidosLabel"><i class="bi bi-download me-2"></i>Exportar Pedidos</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-exportar-pedidos">
            <div class="mb-3">
                <label class="form-label">Formato</label>
                <div class="d-flex gap-2 export-format-pedidos">
                    <div class="choice">
                        <input type="radio" name="formato" id="exp-ped-csv" value="csv">
                        <label for="exp-ped-csv"><i class="bi bi-filetype-csv"></i> CSV</label>
                    </div>
                    <div class="choice">
                        <input type="radio" name="formato" id="exp-ped-xlsx" value="xlsx" checked>
                        <label for="exp-ped-xlsx"><i class="bi bi-file-earmark-excel"></i> XLSX</label>
                    </div>
                    <div class="choice">
                        <input type="radio" name="formato" id="exp-ped-pdf" value="pdf">
                        <label for="exp-ped-pdf"><i class="bi bi-filetype-pdf"></i> PDF</label>
                    </div>
                </div>
                <small class="hint d-block mt-1">Selecione o formato desejado (CSV, XLSX ou PDF).</small>
            </div>
            <div class="mb-2">
                <label class="form-label">Intervalo</label>
                <select class="form-select" name="intervalo" id="exp-ped-intervalo">
                    <option value="pagina_atual">Pedidos da página atual</option>
                    <option value="filtrados">Resultados filtrados (todas as páginas)</option>
                    <option value="todos">Todos os pedidos</option>
                </select>
            </div>
        </form>
        <p class="small text-secondary mt-3 mb-0" id="exp-ped-info"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-exportar-pedidos">Exportar Agora</button>
      </div>
    </div>
  </div>
</div>

<!-- NOVO MODAL: Itens do Pedido (somente leitura / vinculados à proposta) -->
<div class="modal fade" id="modal-itens-pedido" tabindex="-1" aria-labelledby="modalItensPedidoLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalItensPedidoLabel"><i class="bi bi-card-checklist me-2"></i>Itens do Pedido</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="pedido-itens-info" class="resumo-pedido mb-3 small text-secondary"></div>
        <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Produto</th>
                <th class="text-center">Qtd.</th>
                <th class="text-end">Preço Unit.</th>
                <th class="text-end">Subtotal</th>
              </tr>
            </thead>
            <tbody id="pedido-itens-body"></tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end">Total Geral</td>
                <td class="text-end" id="pedido-itens-total">-</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <small class="text-secondary d-block mt-2">Os itens apresentados são derivados da proposta vinculada ao pedido e não podem ser alterados por aqui.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- INÍCIO DO SCRIPT COMPLETO E FINAL PARA PEDIDOS ---

document.addEventListener('DOMContentLoaded', function() {
    // --- CONFIGURAÇÃO E VARIÁVEIS GLOBAIS ---
    const pageConfig = {
        endpoint: document.getElementById('page-container').dataset.endpoint,
        singular: document.getElementById('page-container').dataset.singular,
        plural: document.getElementById('page-container').dataset.plural.toLowerCase(),
    };
    // --- ELEMENTOS DO DOM ---
    const tableBody = document.getElementById('tabela-main-body');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const paginationNav = paginationContainer ? paginationContainer.closest('nav') : null;
    const formFiltros = document.getElementById('form-filtros');
    const filtroFornecedor = document.getElementById('filtro-fornecedor');
    const filtroStatus = document.getElementById('filtro-status');
    const filtroData = document.getElementById('filtro-data');
    const colFiltroFornecedor = document.getElementById('col-filtro-fornecedor');
    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;
    // --- NOVO: Autocomplete para Filtro de Fornecedor ---
    const fornecedorFiltroAutocomplete = (()=>{
        if(!filtroFornecedor) return null;
        filtroFornecedor.classList.add('d-none');
        const wrapper = document.createElement('div');
        wrapper.className = 'position-relative';
        colFiltroFornecedor.insertBefore(wrapper, filtroFornecedor);
        wrapper.appendChild(filtroFornecedor);
        const input = document.createElement('input');
        input.type='text';
        input.className='form-control mb-1';
        input.id='filtro-fornecedor-search';
        input.placeholder='Digite para buscar fornecedor...';
        input.autocomplete='off';
        input.spellcheck=false;
        wrapper.insertBefore(input, filtroFornecedor);
        const dropdown = document.createElement('div');
        dropdown.id='dropdown-fornecedor-filtro';
        dropdown.className='list-group lookup-dropdown shadow';
        document.body.appendChild(dropdown);
        const state = { aberto:false, lista:[], filtrada:[], highlighted:-1, lastQuery:'' };
        const MIN_CHARS = 2; let abortCtrl=null; let debounceTimer=null;
        function position(){ if(!state.aberto) return; const r=input.getBoundingClientRect(); dropdown.style.width=r.width+'px'; dropdown.style.left=r.left+'px'; dropdown.style.top=(r.bottom+4)+'px'; }
        function open(){ if(!state.aberto){ dropdown.style.display='block'; state.aberto=true; window.addEventListener('scroll', position, true); window.addEventListener('resize', position); } position(); }
        function close(){ if(state.aberto){ dropdown.style.display='none'; state.aberto=false; state.highlighted=-1; window.removeEventListener('scroll', position, true); window.removeEventListener('resize', position); } }
        function render(term){
            const t=(term||'').toLowerCase();
            if(t.length<MIN_CHARS){ dropdown.innerHTML=''; close(); return; }
            state.filtrada = state.lista.filter(f=> f.search.includes(t));
            if(state.filtrada.length===0){ dropdown.innerHTML='<div class="list-group-item small text-secondary">Nenhum fornecedor.</div>'; open(); return; }
            dropdown.innerHTML = state.filtrada.map((f,i)=>`<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${i===state.highlighted?'active':''}" data-id="${f.id}"><span class="text-truncate">${f.label}</span><small class="text-secondary ms-2">#${f.id}</small></button>`).join('');
            open();
        }
        function fetchRemote(term){
            const q=term.trim(); if(q.length<MIN_CHARS) return;
            if(abortCtrl) abortCtrl.abort(); abortCtrl = new AbortController(); input.classList.add('loading');
            fetch(`../api/fornecedores.php?q=${encodeURIComponent(q)}&limit=15`, {signal:abortCtrl.signal, credentials:'same-origin', headers:{'Accept':'application/json'}})
              .then(r=> r.ok? r.json():[]) .then(lista=>{
                  state.lista = Array.isArray(lista)? lista.map(f=>{ const nome = f.nome_fantasia || f.razao_social || `Fornecedor #${f.id}`; return { id:f.id, label:nome, search:(nome+" #"+f.id).toLowerCase() }; }):[];
                  render(q);
              }).catch(()=>{ dropdown.innerHTML='<div class="list-group-item small text-danger">Erro na busca.</div>'; open(); })
              .finally(()=> input.classList.remove('loading'));
        }
        function selectItem(item){ if(!item) return; filtroFornecedor.value = item.id; input.value = item.label; close(); aplicarFiltros(); }
        function navigate(d){ if(state.filtrada.length===0) return; state.highlighted=(state.highlighted+d+state.filtrada.length)%state.filtrada.length; render(input.value); }
        input.addEventListener('input', ()=>{ const val=input.value; clearTimeout(debounceTimer); debounceTimer=setTimeout(()=>{ fetchRemote(val); }, 250); });
        input.addEventListener('focus', ()=>{ if(input.value.trim().length>=MIN_CHARS){ fetchRemote(input.value); } });
        input.addEventListener('keydown', e=>{ if(e.key==='ArrowDown'){ e.preventDefault(); navigate(1);} else if(e.key==='ArrowUp'){ e.preventDefault(); navigate(-1);} else if(e.key==='Enter'){ if(state.highlighted>=0){ e.preventDefault(); selectItem(state.filtrada[state.highlighted]); } } else if(e.key==='Escape'){ close(); } else if(e.key==='Backspace' && input.value===''){ filtroFornecedor.value=''; aplicarFiltros(); } });
        dropdown.addEventListener('mousedown', e=>{ const btn=e.target.closest('button.list-group-item'); if(!btn) return; e.preventDefault(); const id=btn.dataset.id; const item=state.lista.find(x=>String(x.id)===id); selectItem(item); });
        document.addEventListener('click', e=>{ if(!wrapper.contains(e.target) && !dropdown.contains(e.target)) close(); });
        return { input, dropdown };
    })();
    const todosOsFornecedores = {};
    const defaultPerPage = 10;
    const defaultHeaders = { 'Accept': 'application/json' };
    const defaultFetchOptions = { headers: defaultHeaders, credentials: 'same-origin' };
    const state = {
        rows: [],
        meta: { page: 1, per_page: defaultPerPage, total: 0, total_pages: 1 },
        filters: { fornecedor: '', status: '', data: '' },
        loading: false
    };

    // Padronização de rótulos legíveis para status de pedidos
    const STATUS_LABELS = {
        'aguardando_aprovacao_cliente': 'Aguardando aprovação do cliente',
        'pendente': 'Pendente',
        'emitido': 'Emitido',
        'em_producao': 'Em produção',
        'enviado': 'Enviado',
        'entregue': 'Entregue',
        'cancelado': 'Cancelado'
    };
    function humanizeStatus(v){ if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/^.|\s\w/g, s=> s.toUpperCase()); }
    function statusLabel(v){ return STATUS_LABELS[v] || humanizeStatus(v); }

    // Modal de Status
    const modalStatusEl = document.getElementById('modal-status');
    const modalStatus = new bootstrap.Modal(modalStatusEl);
    const formStatus = document.getElementById('form-status');
    const pedidoIdStatusInput = document.getElementById('pedido-id-status');
    const pedidoIdLabel = document.getElementById('pedido-id-label');
    const selectStatus = document.getElementById('select-status');
    const statusLockedHint = document.getElementById('status-locked-hint');
    const btnSalvarStatus = document.getElementById('btn-salvar-status');

    // Export modal
    const modalExportarPedidos = document.getElementById('modal-exportar-pedidos');
    const formExportarPedidos = document.getElementById('form-exportar-pedidos');
    const expPedInfo = document.getElementById('exp-ped-info');
    const expPedIntervalo = document.getElementById('exp-ped-intervalo');

    // NOVO: Modal de Itens do Pedido
    const modalItensPedidoEl = document.getElementById('modal-itens-pedido');
    const pedidoItensInfo = document.getElementById('pedido-itens-info');
    const pedidoItensBody = document.getElementById('pedido-itens-body');
    const pedidoItensTotal = document.getElementById('pedido-itens-total');

    function formatCurrencyBR(v){ return parseFloat(v||0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' }); }

    function carregarItensPedido(propostaId, pedidoId){
        if(!propostaId){
            pedidoItensBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-4">Pedido sem proposta vinculada.</td></tr>';
            pedidoItensTotal.textContent='-';
            return;
        }
        pedidoItensBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-4">Carregando itens...</td></tr>';
        pedidoItensTotal.textContent = '-';
        fetch(`../api/proposta_itens.php?proposta_id=${propostaId}`)
          .then(r=>r.json())
          .then(itens=>{
              pedidoItensBody.innerHTML='';
              if(!Array.isArray(itens) || itens.length===0){
                  pedidoItensBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary py-4">Nenhum item encontrado.</td></tr>';
                  pedidoItensTotal.textContent='-';
                  return;
              }
              let total = 0;
              itens.forEach(it=>{
                  const qtd = parseFloat(it.quantidade||0);
                  const preco = parseFloat(it.preco_unitario||0);
                  const subtotal = qtd * preco;
                  total += subtotal;
                  pedidoItensBody.insertAdjacentHTML('beforeend', `
                    <tr>
                      <td>${it.nome || 'Produto #'+(it.produto_id||'-')}</td>
                      <td class="text-center">${qtd} ${it.unidade||''}</td>
                      <td class="text-end">${formatCurrencyBR(preco)}</td>
                      <td class="text-end">${formatCurrencyBR(subtotal)}</td>
                    </tr>`);
              });
              pedidoItensTotal.textContent = formatCurrencyBR(total);
          })
          .catch(()=>{
              pedidoItensBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger py-4">Erro ao carregar itens.</td></tr>';
              pedidoItensTotal.textContent='-';
          });
    }

    // --- FUNÇÕES DE LÓGICA E RENDERIZAÇÃO ---

    const normalizeApiResponse = (payload) => {
        let rows = [];
        let meta = {
            page: state.meta.page || 1,
            per_page: state.meta.per_page || defaultPerPage,
            total: state.meta.total || 0,
            total_pages: state.meta.total_pages || 1
        };
        if (Array.isArray(payload)) {
            rows = payload;
            meta = {
                page: 1,
                per_page: rows.length || defaultPerPage,
                total: rows.length,
                total_pages: 1
            };
            return { rows, meta };
        }
        if (payload && typeof payload === 'object') {
            if (Array.isArray(payload.data)) rows = payload.data;
            else if (Array.isArray(payload.rows)) rows = payload.rows;

            const rawMeta = payload.meta || {};
            const legacy = ('page' in payload || 'per_page' in payload || 'total' in payload)
                ? { page: payload.page, per_page: payload.per_page, total: payload.total }
                : {};
            const totalRows = rawMeta.total ?? legacy.total ?? rows.length;
            const perPage = rawMeta.per_page ?? legacy.per_page ?? (rows.length || defaultPerPage);
            meta = {
                page: rawMeta.page ?? legacy.page ?? 1,
                per_page: perPage,
                total: totalRows,
                total_pages: rawMeta.total_pages ?? Math.max(1, perPage ? Math.ceil((totalRows || 1) / perPage) : 1)
            };
        }
        return { rows, meta };
    };

    const setLoading = (isLoading) => {
        state.loading = isLoading;
        if (isLoading && tableBody) {
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Carregando ${pageConfig.plural}...</td></tr>`;
        }
    };

    const updatePaginationHint = () => {
        if (!paginationHint) return;
        const meta = state.meta || {};
        if (!meta.total) {
            paginationHint.textContent = `Nenhum ${pageConfig.singular.toLowerCase()} encontrado`;
            return;
        }
        const perPage = Number(meta.per_page) || defaultPerPage;
        const currentPage = Number(meta.page) || 1;
        const start = (currentPage - 1) * perPage + 1;
        const end = start + (state.rows.length || 0) - 1;
        paginationHint.innerHTML = `Mostrando <strong>${start}-${end}</strong> de <strong>${meta.total}</strong> ${pageConfig.plural}`;
    };

    const renderRows = () => {
        if (!tableBody) return;
        if (!state.rows.length) {
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Nenhum ${pageConfig.singular.toLowerCase()} encontrado.</td></tr>`;
            return;
        }
        tableBody.innerHTML = state.rows.map(criarLinhaTabela).join('');
    };

    const createPageItem = (label, targetPage, { disabled = false, active = false } = {}) => {
        const classes = ['page-item'];
        if (disabled) classes.push('disabled');
        if (active) classes.push('active');
        const content = disabled
            ? `<span class="page-link">${label}</span>`
            : `<button type="button" class="page-link" data-page="${targetPage}">${label}</button>`;
        return `<li class="${classes.join(' ')}">${content}</li>`;
    };

    const renderPagination = () => {
        if (!paginationContainer) return;
        const meta = state.meta || {};
        const total = Number(meta.total) || 0;
        const perPage = Number(meta.per_page) || defaultPerPage;
        const totalPages = Math.max(1, Number(meta.total_pages) || Math.ceil((total || 1) / perPage));
        const current = Math.min(Math.max(1, Number(meta.page) || 1), totalPages);
        if (paginationNav) paginationNav.classList.remove('invisible');
        if (!total) {
            paginationContainer.innerHTML = '';
            if (paginationNav) paginationNav.classList.add('invisible');
            return;
        }
        const items = [];
        items.push(createPageItem('«', 1, { disabled: current === 1 }));
        items.push(createPageItem('‹', Math.max(1, current - 1), { disabled: current === 1 }));
        const windowSize = 5;
        let startPage = Math.max(1, current - Math.floor(windowSize / 2));
        let endPage = startPage + windowSize - 1;
        if (endPage > totalPages) {
            endPage = totalPages;
            startPage = Math.max(1, endPage - windowSize + 1);
        }
        for (let i = startPage; i <= endPage; i++) {
            items.push(createPageItem(String(i), i, { active: i === current }));
        }
        items.push(createPageItem('›', Math.min(totalPages, current + 1), { disabled: current === totalPages }));
        items.push(createPageItem('»', totalPages, { disabled: current === totalPages }));
        paginationContainer.innerHTML = items.join('');
    };

    const buildQueryParams = (pageOverride) => {
        const params = new URLSearchParams();
        params.set('page', pageOverride || state.meta.page || 1);
        params.set('per_page', state.meta.per_page || defaultPerPage);
        if (state.filters.fornecedor) params.set('fornecedor_id', state.filters.fornecedor);
        if (state.filters.status) params.set('status', state.filters.status);
        if (state.filters.data) params.set('data', state.filters.data);
        return params;
    };

    const fetchPedidos = (pageOverride) => {
        if (pageOverride) state.meta.page = pageOverride;
        const params = buildQueryParams(pageOverride);
        setLoading(true);
        return fetch(`${pageConfig.endpoint}?${params.toString()}`, defaultFetchOptions)
            .then(async res => {
                const data = await res.json().catch(() => null);
                if (!res.ok || !data) {
                    throw new Error(data?.erro || `Erro ao carregar ${pageConfig.plural}.`);
                }
                const normalized = normalizeApiResponse(data);
                state.rows = Array.isArray(normalized.rows) ? normalized.rows : [];
                state.meta = normalized.meta;
                renderRows();
                renderPagination();
                updatePaginationHint();
                return normalized;
            })
            .catch(err => {
                if (tableBody) {
                    tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Erro ao carregar ${pageConfig.plural}.</td></tr>`;
                }
                if (paginationHint) paginationHint.textContent = 'Erro ao carregar';
                if (paginationContainer) paginationContainer.innerHTML = '';
                if (paginationNav) paginationNav.classList.add('invisible');
                showToast(err.message || `Erro ao carregar ${pageConfig.plural}.`, 'danger');
            })
            .finally(() => setLoading(false));
    };

    const fetchPedidosIdsFiltrados = () => {
        const params = new URLSearchParams();
        params.set('mode', 'ids');
        if (state.filters.fornecedor) params.set('fornecedor_id', state.filters.fornecedor);
        if (state.filters.status) params.set('status', state.filters.status);
        if (state.filters.data) params.set('data', state.filters.data);
        return fetch(`${pageConfig.endpoint}?${params.toString()}`, defaultFetchOptions)
            .then(res => res.json().catch(() => null))
            .then(payload => Array.isArray(payload?.ids) ? payload.ids.map(id => parseInt(id, 10)).filter(Boolean) : [])
            .catch(() => []);
    };

    function carregarFornecedores() {
        if (!filtroFornecedor) return Promise.resolve();
        filtroFornecedor.innerHTML = '<option value="">Todos os Fornecedores</option>';
        return fetch('../api/fornecedores.php', defaultFetchOptions)
            .then(res => res.json().catch(() => null))
            .then(lista => {
                if (!Array.isArray(lista)) return;
                lista.forEach(f => {
                    const nome = f.razao_social || f.nome_fantasia || `Fornecedor #${f.id}`;
                    todosOsFornecedores[f.id] = nome;
                    const option = document.createElement('option');
                    option.value = f.id;
                    option.textContent = nome;
                    filtroFornecedor.appendChild(option);
                });
                renderRows();
            })
            .catch(() => {});
    }

    function aplicarFiltros(event) {
        if (event) event.preventDefault();
        state.filters = {
            fornecedor: filtroFornecedor?.value || '',
            status: filtroStatus?.value || '',
            data: filtroData?.value || ''
        };
        fetchPedidos(1);
    }

    const carregarDadosIniciais = () => Promise.all([
        carregarFornecedores(),
        fetchPedidos(1)
    ]);

    function criarLinhaTabela(item) {
        const itemJson = JSON.stringify(item).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
        // Classes por status padronizadas
        const statusClassMap = {
            'aguardando_aprovacao_cliente': 'warning',
            'pendente': 'primary',
            'emitido': 'info',
            'em_producao': 'warning',
            'enviado': 'info',
            'entregue': 'success',
            'cancelado': 'danger'
        };
        const st = item.status || '';
        const statusClass = statusClassMap[st] || 'secondary';
        const label = statusLabel(st);
        const dataFormatada = new Date(item.criado_em).toLocaleDateString('pt-BR');
        const valorFormatado = parseFloat(item.valor_total || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        const nomeFornecedor = todosOsFornecedores[item.fornecedor_id] || `ID #${item.fornecedor_id}`;

        // Se o status estiver aguardando aprovação do cliente, não exibe nenhum botão (removido cadeado desabilitado)
        let editBtn = '';
        if (st.toLowerCase() !== 'aguardando_aprovacao_cliente') {
            editBtn = `<button class="btn btn-sm btn-outline-light btn-action-edit" title="Alterar Status" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-status"><i class="bi bi-toggles"></i></button>`;
        }

        return `
            <tr>
                <td><span class="font-monospace">#${item.id}</span></td>
                <td>${nomeFornecedor}</td>
                <td>${dataFormatada}</td>
                <td>${valorFormatado}</td>
                <td><span class="badge bg-${statusClass}">${label}</span></td>
                <td class="text-end">
                    ${editBtn}
                    <a href="pedido_pdf.php?id=${item.proposta_id}" target="_blank" class="btn btn-sm btn-outline-light btn-action-link" title="Gerar PDF do Pedido">
                        <i class="bi bi-file-earmark-pdf-fill"></i>
                    </a>
                    <button class="btn btn-sm btn-outline-light btn-action-items" title="Ver Itens do Pedido" data-proposta-id="${item.proposta_id}" data-pedido-id="${item.id}" data-bs-toggle="modal" data-bs-target="#modal-itens-pedido">
                        <i class="bi bi-card-checklist"></i>
                    </button>
                </td>
            </tr>`;
    }

    // --- EVENT LISTENERS ---

    formFiltros.addEventListener('submit', aplicarFiltros);

    document.getElementById('btn-limpar-filtros').addEventListener('click', () => {
        if (filtroFornecedor) filtroFornecedor.value = '';
        if (filtroStatus) filtroStatus.value = '';
        if (filtroData) filtroData.value = '';
        if (fornecedorFiltroAutocomplete?.input) fornecedorFiltroAutocomplete.input.value = '';
        aplicarFiltros();
    });

    if (paginationContainer) {
        paginationContainer.addEventListener('click', e => {
            const btn = e.target.closest('button[data-page]');
            if (!btn || btn.closest('.disabled')) return;
            const targetPage = parseInt(btn.dataset.page, 10);
            if (!Number.isNaN(targetPage)) {
                fetchPedidos(targetPage);
            }
        });
    }

    tableBody.addEventListener('click', function(event) {
        const target = event.target.closest('button');
        if (!target) return;

        if (target.classList.contains('btn-action-edit')) {
            const itemData = JSON.parse(target.dataset.item.replace(/&quot;/g, '"'));
            // Bloqueio extra de segurança no frontend
            if ((itemData.status||'').toLowerCase() === 'aguardando_aprovacao_cliente') {
                showToast('Pedido aguardando aceite do cliente. Não é permitido alterar o status.', 'warning');
                // Garante modal bloqueado se for aberto por algum motivo
                pedidoIdStatusInput.value = itemData.id;
                pedidoIdLabel.textContent = `#${itemData.id}`;
                selectStatus.value = itemData.status;
                selectStatus.disabled = true;
                btnSalvarStatus.disabled = true;
                statusLockedHint?.classList?.remove('d-none');
                return;
            }
            pedidoIdStatusInput.value = itemData.id;
            pedidoIdLabel.textContent = `#${itemData.id}`;
            selectStatus.value = itemData.status;
            // Desbloqueia modal para casos permitidos
            selectStatus.disabled = false;
            btnSalvarStatus.disabled = false;
            statusLockedHint?.classList?.add('d-none');
        }
        if (target.classList.contains('btn-action-send')) {
            return; // botão removido (fluxo automático)
        }
        if (target.classList.contains('btn-action-items')) {
            const propostaId = target.dataset.propostaId;
            const pedidoId = target.dataset.pedidoId;
            pedidoItensInfo.innerHTML = `<strong>Pedido:</strong> #${pedidoId} &middot; <strong>Proposta:</strong> #${propostaId || '-'}<br><span class='text-secondary'>Itens derivados da proposta vinculada.</span>`;
            carregarItensPedido(propostaId, pedidoId);
        }
    });
    
    formStatus.addEventListener('submit', function(e) {
        e.preventDefault();
        // Se bloqueado, não envia
        if (selectStatus.disabled) { showToast('Aguardando aceite do cliente. Alteração de status bloqueada.', 'warning'); return; }
        const payload = new URLSearchParams(new FormData(formStatus));
        payload.set('_method', 'PUT');

        fetch(pageConfig.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: payload.toString()
        })
        .then(res => res.json())
        .then(resData => {
            if (resData.success) {
                showToast(`Status do pedido #${pedidoIdStatusInput.value} atualizado!`, 'success');
                modalStatus.hide();
                fetchPedidos(state.meta.page || 1);
            } else {
                showToast(resData.erro || 'Ocorreu um erro ao atualizar o status.', 'danger');
            }
        });
    });

    // Export Modal handlers
    modalExportarPedidos.addEventListener('show.bs.modal', () => {
        const meta = state.meta || {};
        const qtdPagina = state.rows.length || 0;
        const qtdFiltrados = meta.total || 0;
        expPedInfo.textContent = `${qtdFiltrados} resultado(s) filtrado(s). Página ${meta.page || 1} exibindo ${qtdPagina} linha(s).`;
    });

    formExportarPedidos.addEventListener('submit', async function(e){
        e.preventDefault();
        const formato = this.formato.value; // csv, xlsx ou pdf
        const intervalo = this.intervalo.value;
        let ids = [];
        if (intervalo === 'pagina_atual') {
            ids = state.rows.map(d => d.id);
        } else if (intervalo === 'filtrados') {
            ids = await fetchPedidosIdsFiltrados();
        }
        if (intervalo !== 'todos' && (!ids || ids.length === 0)) {
            showToast('Nenhum dado para exportar no intervalo escolhido.', 'warning');
            return;
        }
        gerarPostExportacao({formato, ids, intervalo});
        bootstrap.Modal.getInstance(modalExportarPedidos)?.hide();
    });

    function gerarPostExportacao({formato, ids = [], intervalo}) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'exportador.php';
        form.target = '_blank';
        form.innerHTML = `
            <input type="hidden" name="modulo" value="pedidos">
            <input type="hidden" name="formato" value="${formato}">
            <input type="hidden" name="intervalo" value="${intervalo}">
            <input type="hidden" name="ids" value="${ids.join(',')}">
        `;
        document.body.appendChild(form);
        form.submit();
        form.remove();
        showToast('Gerando arquivo ' + formato.toUpperCase() + '...', 'success');
    }

    // Carga inicial
    carregarDadosIniciais().catch(() => showToast('Erro ao carregar dados iniciais.', 'danger'));
});

function showToast(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;
    const toastId = 'toast-' + Date.now();
    const toastHtml = `<div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button></div></div>`;
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3000 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
</script>
</body>
</html>