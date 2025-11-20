<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotações - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
    /* Força visual claro para elementos específicos no modal de convites */
    #modal-convites .alert-convite-light{
        background:#fefdf8 !important;
        color:#2f2818 !important;
        border:1px solid rgba(0,0,0,0.08) !important;
        box-shadow:0 4px 14px rgba(0,0,0,0.08);
    }
    #modal-convites .form-check-input.invite-check{
        background-color:#ffffff !important;
        border-color:#b5a886 !important;
        box-shadow:none;
    }
    #modal-convites .form-check-input.invite-check:checked{
        background-color:#b5a886 !important;
        border-color:#b5a886 !important;
    }
    #modal-convites .form-check-label{
        color:#3a3324 !important;
    }
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
    data-endpoint="../api/cotacoes.php" 
    data-singular="Cotação"
    data-plural="Cotações"
    data-modulo="cotacoes">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Cotações</h1>
            <p class="text-secondary mb-0">Crie e envie links de cotação para os seus fornecedores.</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-exportar">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#modal-cotacao">
                <i class="bi bi-plus-circle me-2"></i>Nova Cotação
            </button>
        </div>
    </header>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Cotações Ativas</span>
            <span class="text-secondary small">Use os filtros embutidos na tabela para refinar os resultados.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID Cotação</th>
                        <th>ID Requisição</th>
                        <th>Data Criação</th>
                        <th>Expira em</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    <tr class="table-filters bg-body-secondary-subtle align-middle">
                        <th colspan="3">
                            <div class="input-group input-group-sm shadow-sm" style="min-width:260px">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-start-0" placeholder="Buscar por ID da cotação ou requisição" id="searchInput">
                            </div>
                        </th>
                        <th colspan="2">
                            <select class="form-select form-select-sm" id="filtroStatus">
                                <option value="">Todos os Status</option>
                            </select>
                        </th>
                        <th class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-clear-filtros">
                                <i class="bi bi-eraser me-1"></i>Limpar
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody id="tabela-main-body"></tbody>
            </table>
        </div>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 border-top px-3 py-2">
            <div id="pagination-hint" class="text-secondary small">Carregando cotações...</div>
            <nav aria-label="Paginação de cotações" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-cotacao" tabindex="-1" aria-labelledby="modal-title-cotacao" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
        <div class="modal-header card-header-matrix">
            <h5 class="modal-title" id="modal-title-cotacao"><i class="bi bi-plus-circle me-2"></i>Criar Nova Cotação</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body form-container-gestao">
            <form id="form-main" novalidate>
                <div class="row g-3">
                    <div class="col-12">
                        <label for="requisicao_id" class="form-label">Requisição de Origem</label>
                        <select class="form-select" id="requisicao_id" name="requisicao_id" required>
                            <option value="" disabled selected>Selecione uma requisição em aberto...</option>
                            </select>
                         <div class="form-text">Apenas requisições com status "aberta" são listadas.</div>
                    </div>
                    <!-- Campo de Tipo de Frete removido: agora definido pelo fornecedor ao responder a cotação -->
                </div>
            </form>
        </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-main">Gerar Cotação</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-link" tabindex="-1" aria-labelledby="modalLinkLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalLinkLabel"><i class="bi bi-link-45deg me-2"></i>Link Público da Cotação</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <p class="text-secondary">Envie este link para os fornecedores responderem à cotação.</p>
        <div class="input-group">
            <input type="text" class="form-control font-monospace" id="cotacao-link-input" readonly>
            <button class="btn btn-matrix-secondary-outline" id="btn-copiar-link" title="Copiar para a área de transferência">
                <i class="bi bi-clipboard-check"></i>
            </button>
        </div>
        <p class="small text-warning mt-3">Atenção: Este link expira em <strong id="data-expiracao-link"></strong>.</p>
        <!-- NOVO: Botão regenerar token -->
        <div class="mt-3">
            <button class="btn btn-matrix-secondary-outline" id="btn-regenerar-token">
                <i class="bi bi-arrow-clockwise me-1"></i>Gerar Novo Token
            </button>
            <div class="form-text small mt-2">Gerar um novo token invalida o link anterior e renova o prazo (+2 dias).</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- NOVO: Modal de Convites Individuais -->
<div class="modal fade" id="modal-convites" tabindex="-1" aria-labelledby="modalConvitesLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalConvitesLabel"><i class="bi bi-envelope-plus me-2"></i>Gerenciar Convites</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <!-- Filtro de status (sincronizado com a listagem) -->
        <div class="alert alert-secondary small mb-3 alert-convite-light">
            Os convites são links únicos por fornecedor. Pesquise pelo nome, fantasia ou CNPJ e adicione à lista.
        </div>
        <form id="form-convites" class="mb-4">
            <input type="hidden" id="convites-cotacao-id" name="cotacao_id" value="">
            <input type="hidden" id="convites-requisicao-id" name="requisicao_id" value="">
            <!-- Novo: manter ids selecionados para referência/localStorage -->
            <input type="hidden" id="fornecedores-ids" name="fornecedores_ids" value="">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Fornecedores</label>
                    <div class="position-relative">
                        <div class="d-flex flex-wrap gap-2 mb-2" id="chips-fornecedores"></div>
                        <input type="text" class="form-control" id="busca-fornecedor" placeholder="Digite para buscar por nome, fantasia ou CNPJ..." autocomplete="off">
                        <div class="list-group position-absolute w-100 shadow" id="dropdown-fornecedores" style="z-index:1056; max-height:260px; overflow:auto; display:none;"></div>
                    </div>
                    <div class="form-text">Selecione fornecedores na busca e eles serão adicionados como chips. Clique no X para remover.</div>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Validade (dias)</label>
                    <input type="number" class="form-control" id="dias-validade" min="1" value="5">
                </div>
                <div class="col-md-2">
                    <div class="form-check">
                        <input class="form-check-input invite-check" type="checkbox" id="exibir-tokens">
                        <label class="form-check-label" for="exibir-tokens">Exibir tokens ao criar</label>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-matrix-primary"><i class="bi bi-plus-lg me-1"></i>Criar Convites</button>
                <button type="button" class="btn btn-matrix-secondary" id="btn-recarregar-convites"><i class="bi bi-arrow-repeat me-1"></i>Recarregar</button>
            </div>
        </form>
    <div id="convites-criados-tokens" class="mb-3" style="display:none;"></div>
    <div id="convites-errors-box" class="alert alert-warning small" style="display:none;"></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Fornecedor</th>
                        <th>Status</th>
                        <th>Expira em</th>
                        <th>Enviado</th>
                        <th>Respondido</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="convites-list-body">
                    <tr><td colspan="7" class="text-center text-secondary">Nenhum convite carregado.</td></tr>
                </tbody>
            </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-exportar" tabindex="-1" aria-labelledby="modalExportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalExportarLabel"><i class="bi bi-download me-2"></i>Exportar Cotações</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-exportar">
            <input type="hidden" name="modulo" value="cotacoes"><!-- NOVO -->
            <div class="mb-4">
                <label class="form-label">Formato do Ficheiro</label>
                <div class="export-options d-flex gap-3">
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-csv-cot" value="csv" checked>
                        <label class="form-check-label" for="export-csv-cot"><i class="bi bi-filetype-csv me-2"></i>CSV</label>
                    </div>
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-cot" value="xlsx">
                        <label class="form-check-label" for="export-xlsx-cot"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label>
                    </div>
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-cot" value="pdf">
                        <label class="form-check-label" for="export-pdf-cot"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Intervalo de Dados</label>
                 <select class="form-select" name="intervalo">
                    <option value="todos">Todas as Cotações</option>
                    <option value="pagina_atual">Apenas a página atual</option>
                    <option value="filtrados">Apenas os resultados da busca</option>
                </select>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-exportar">Exportar Agora</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger"><h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar Exclusão</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p>Tem a certeza de que deseja excluir permanentemente a cotação <strong id="modal-item-name" class="text-warning"></strong>?</p>
        <p class="text-secondary small">Esta ação não pode ser desfeita.</p>
        <!-- Área de erro amigável -->
        <div id="delete-error" class="alert alert-warning d-none" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-matrix-danger" id="confirm-delete-btn">Confirmar Exclusão</button></div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- INÍCIO DO SCRIPT COMPLETO E FINAL PARA COTAÇÕES ---

const debounce = (fn, delay = 400) => {
    let timeout;
    return (...args) => {
        clearTimeout(timeout);
        timeout = setTimeout(() => fn.apply(null, args), delay);
    };
};

document.addEventListener('DOMContentLoaded', function() {
    // --- CONFIGURAÇÃO E VARIÁVEIS GLOBAIS ---
    const containerEl = document.getElementById('page-container');
    const pageConfig = {
        endpoint: containerEl.dataset.endpoint,
        singular: containerEl.dataset.singular,
        plural: containerEl.dataset.plural.toLowerCase(),
        modulo: containerEl.dataset.modulo || 'cotacoes'
    };

    // Mapa padronizado de rótulos de status (cotação)
    const STATUS_LABELS = {
        aberta: 'Aberta',
        encerrada: 'Encerrada',
        fechada: 'Encerrada', // compatibilidade legado
        pendente_aprovacao: 'Pendente Aprovação', // novo
        pendente: 'Pendente' // caso exista
    };
    function statusLabel(v){ return STATUS_LABELS[v] || (v? (v.charAt(0).toUpperCase()+v.slice(1)) : '-'); }

    const defaultPerPage = 10;
    const defaultHeaders = { 'Accept': 'application/json' };
    const defaultFetchOptions = { headers: defaultHeaders, credentials: 'same-origin' };
    const state = {
        rows: [],
        meta: { page: 1, per_page: defaultPerPage, total: 0, total_pages: 1, status_options: [] },
        filters: { busca: '', status: 'aberta' },
        loading: false
    };
    let editandoId = null;
    let itemParaRemoverId = null;

    // --- ELEMENTOS DO DOM ---
    const tableBody = document.getElementById('tabela-main-body');
    const searchInput = document.getElementById('searchInput');
    const filtroStatusSelect = document.getElementById('filtroStatus');
    const btnLimparFiltros = document.getElementById('btn-clear-filtros');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const paginationNav = paginationContainer ? paginationContainer.closest('nav') : null;
    const requisicaoSelect = document.getElementById('requisicao_id');
    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;
// --- NOVO: Autocomplete para Requisições (substitui select visualmente) ---
    const requisicaoAutocomplete = (()=>{
        // esconder select original mas manter para submissão/validação
        requisicaoSelect.classList.add('d-none');
        requisicaoSelect.required = true; // garantir
        // criar container relativo se não existir
        const wrapper = document.createElement('div');
        wrapper.className = 'position-relative';
        // inserir antes do select e mover select para dentro
        requisicaoSelect.parentElement.insertBefore(wrapper, requisicaoSelect);
        wrapper.appendChild(requisicaoSelect);
        // input de busca
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control mb-1';
        input.id = 'requisicao-search';
        input.placeholder = 'Digite para buscar requisição...';
        input.autocomplete = 'off';
        input.spellcheck = false;
        wrapper.insertBefore(input, requisicaoSelect); // fica acima do select oculto
        // dropdown (PORTAL no body para não ser cortado pelo modal)
        const dropdown = document.createElement('div');
        dropdown.id = 'dropdown-requisicoes';
        // Visual padronizado (mesma base dos fornecedores) mantendo portal (overflow livre)
        // Adicionamos 'lookup-dropdown' para herdar o mesmo tema claro do dropdown de fornecedores
        dropdown.className = 'list-group lookup-dropdown shadow';
        dropdown.style.cssText = 'position:fixed; z-index:1056; max-height:260px; overflow:auto; display:none;';
        document.body.appendChild(dropdown); // portal
        const state = { lista: [], filtrada: [], highlighted: -1, aberto:false };
        const MIN_CHARS = 1; // mínimo para exibir sugestões
        function escapeHtml(str){ return String(str).replace(/[&<>"]/g, s=> ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[s])); }
        function highlight(label, term){
            if(!term) return escapeHtml(label);
            const esc = term.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
            return escapeHtml(label).replace(new RegExp('('+esc+')','ig'), '<mark>$1</mark>');
        }
        function positionDropdown(){
            if(!state.aberto) return;
            const rect = input.getBoundingClientRect();
            dropdown.style.width = rect.width + 'px';
            dropdown.style.left = rect.left + 'px';
            dropdown.style.top = (rect.bottom + 4) + 'px';
        }
        const repositionHandler = ()=> positionDropdown();
        function attachGlobalListeners(){
            window.addEventListener('scroll', repositionHandler, true); // capture para scroll interno
            window.addEventListener('resize', repositionHandler);
        }
        function detachGlobalListeners(){
            window.removeEventListener('scroll', repositionHandler, true);
            window.removeEventListener('resize', repositionHandler);
        }
        function open(){
            if(!state.aberto){ dropdown.style.display='block'; state.aberto=true; attachGlobalListeners(); }
            positionDropdown();
        }
        function close(){ if(state.aberto){ dropdown.style.display='none'; state.aberto=false; state.highlighted=-1; detachGlobalListeners(); } }
        function render(term){
            const t = (term||'').toLowerCase();
            if(t.length < MIN_CHARS){ dropdown.innerHTML=''; close(); return; }
            state.filtrada = !t ? state.lista.slice() : state.lista.filter(r => r.search.includes(t));
            if(state.filtrada.length===0){ dropdown.innerHTML = '<div class="list-group-item text-secondary small">Nenhuma requisição encontrada.</div>'; state.highlighted=-1; open(); return; }
            dropdown.innerHTML = state.filtrada.map((r,i)=>{
                const active = i===state.highlighted ? 'active' : '';
                // Padrão: span principal (titulo) + small secundário (#id) alinhados
                return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${active}" data-id="${r.id}">`+
                       `<span class="text-truncate">${highlight(r.labelSemId, t)}</span>`+
                       `<small class="text-secondary ms-2">#${r.id}</small>`+
                       `</button>`;
            }).join('');
            open();
        }
        function setData(list){
            state.lista = Array.isArray(list)? list.map(r=>{
                const titulo = r.titulo || 'Requisição sem título';
                const label = `#${r.id} - ${titulo}`;
                return { id:r.id, titulo, label, labelSemId: titulo, search:(`#${r.id} ${titulo}`).toLowerCase() };
            }) : [];
            render(input.value.trim());
        }
        function selectItem(item){
            if(!item) return;
            requisicaoSelect.value = item.id;
            input.value = item.label; // agora label definido em setData
            close();
        }
        function navigate(dir){ // dir: 1 ou -1
            if(state.filtrada.length===0) return;
            state.highlighted = (state.highlighted + dir + state.filtrada.length) % state.filtrada.length;
            render(input.value.trim());
        }
        input.addEventListener('input', ()=>{ render(input.value.trim()); });
        input.addEventListener('focus', ()=>{ /* não abre automaticamente sem digitar */ if(input.value.trim().length >= MIN_CHARS){ render(input.value.trim()); } });
        document.addEventListener('click', (e)=>{ if(!wrapper.contains(e.target) && !dropdown.contains(e.target)){ close(); }});
        input.addEventListener('keydown', (e)=>{
            if(e.key==='ArrowDown'){ e.preventDefault(); navigate(1); }
            else if(e.key==='ArrowUp'){ e.preventDefault(); navigate(-1); }
            else if(e.key==='Enter'){ if(state.highlighted>=0){ e.preventDefault(); selectItem(state.filtrada[state.highlighted]); } }
            else if(e.key==='Escape'){ close(); }
            else if(e.key==='Backspace' && input.value===''){ requisicaoSelect.value=''; }
        });
        dropdown.addEventListener('mousedown', (e)=>{ // usar mousedown para evitar blur antes da seleção
            const btn = e.target.closest('button.list-group-item');
            if(!btn) return;
            const id = btn.dataset.id;
            const item = state.lista.find(r=> String(r.id)===String(id));
            selectItem(item);
        });
        const clearValue = () => {
            input.value = '';
            requisicaoSelect.value = '';
            state.highlighted = -1;
            close();
        };
        return { setData, selectById:(id)=>{ const item= state.lista.find(r=> String(r.id)===String(id)); if(item) selectItem(item); }, clearValue, input, dropdown, close, open };
    })();

// --- RESTAURADO: Referências de elementos e helpers perdidos ---
    const form = document.getElementById('form-main');
    const modalEl = document.getElementById('modal-cotacao');
    const modalCotacaoInstance = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    const modalLink = new bootstrap.Modal(document.getElementById('modal-link'));
    const cotacaoLinkInput = document.getElementById('cotacao-link-input');
    const btnCopiarLink = document.getElementById('btn-copiar-link');
    const dataExpiracaoLink = document.getElementById('data-expiracao-link');
    const btnRegenerarToken = document.getElementById('btn-regenerar-token');
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = new bootstrap.Modal(confirmationModalEl);
    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const modalItemName = document.getElementById('modal-item-name');
    const deleteError = document.getElementById('delete-error');
    const formExportar = document.getElementById('form-exportar');
    let cotacaoAtual = null;

    // Helper de cópia
    async function copyToClipboard(text){
        if(!text) return false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return true;
            }
        } catch (_) {}
        try {
            const ta = document.createElement('textarea');
            ta.value = text; ta.readOnly = true; ta.style.position='fixed'; ta.style.left='-9999px';
            document.body.appendChild(ta); ta.select(); const ok = document.execCommand('copy'); ta.remove(); return ok;
        } catch (_) { return false; }
    }
    // Toast helper
    function showToast(message, type='info'){
        const toastContainer = document.querySelector('.toast-container');
        if(!toastContainer) return;
        const id = 't'+Date.now()+Math.random().toString(16).slice(2);
        toastContainer.insertAdjacentHTML('beforeend', `<div id="${id}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button></div></div>`);
        const el = document.getElementById(id);
        const bs = new bootstrap.Toast(el, { delay:3000 });
        bs.show();
        el.addEventListener('hidden.bs.toast', ()=> el.remove());
    }

    // Modal Convites elementos
    const modalConvitesEl = document.getElementById('modal-convites');
    const modalConvites = modalConvitesEl ? new bootstrap.Modal(modalConvitesEl) : null;
    const convitesCotacaoInput = document.getElementById('convites-cotacao-id');
    const convitesReqInput = document.getElementById('convites-requisicao-id');
    const fornecedoresIdsInput = document.getElementById('fornecedores-ids');
    const diasValidadeInput = document.getElementById('dias-validade');
    const exibirTokensCheckbox = document.getElementById('exibir-tokens');
    const formConvites = document.getElementById('form-convites');
    const convitesListBody = document.getElementById('convites-list-body');
    const btnRecarregarConvites = document.getElementById('btn-recarregar-convites');
    const convitesCriadosTokens = document.getElementById('convites-criados-tokens');
    const convitesErrorsBox = document.getElementById('convites-errors-box');
    let cotacaoIdConvitesAtual = null;
    let requisicaoIdConvitesAtual = null;

    // Chips / Autocomplete fornecedores
    const buscaInput = document.getElementById('busca-fornecedor');
    const dropdownFor = document.getElementById('dropdown-fornecedores');
    const chipsContainer = document.getElementById('chips-fornecedores');
    let fornecedoresSelecionados = []; let buscaTimer = null; let abortCtrl = null;

    function renderChips(){
        if(!chipsContainer) return;
        chipsContainer.innerHTML = fornecedoresSelecionados.map(f => `
            <span class="badge rounded-pill text-bg-secondary d-inline-flex align-items-center gap-2">
                <i class="bi bi-truck"></i>
                <span>${(f.nome || ('Fornecedor #'+f.id))}</span>
                <button type="button" class="btn btn-sm btn-outline-light border-0 p-0 ms-1 btn-chip-remove" data-id="${f.id}" title="Remover"><i class="bi bi-x-lg"></i></button>
            </span>`).join('');
        if(fornecedoresIdsInput){ fornecedoresIdsInput.value = fornecedoresSelecionados.map(f=>f.id).join(','); }
    }
    function addFornecedor(item){
        if(!item || !item.id) return;
        if(fornecedoresSelecionados.some(f => f.id === item.id)) return;
        const nome = item.nome_fantasia || item.razao_social || (item.cnpj ? `CNPJ ${item.cnpj}` : `Fornecedor #${item.id}`);
        fornecedoresSelecionados.push({ id:item.id, nome });
        renderChips();
    }
    function removeFornecedor(id){ fornecedoresSelecionados = fornecedoresSelecionados.filter(f => f.id !== id); renderChips(); }
    if(chipsContainer){ chipsContainer.addEventListener('click', e=>{ const btn = e.target.closest('.btn-chip-remove'); if(btn){ removeFornecedor(parseInt(btn.dataset.id,10)); } }); }

    const escapeHtmlText = (str='') => String(str ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[s]));
    const labelFornecedor = (id) => {
        const found = fornecedoresSelecionados.find(f => String(f.id) === String(id));
        return found?.nome || `Fornecedor #${id}`;
    };
    function renderConviteErrors(errors = []){
        if(!convitesErrorsBox) return;
        if(!Array.isArray(errors) || errors.length === 0){
            convitesErrorsBox.style.display = 'none';
            convitesErrorsBox.innerHTML = '';
            return;
        }
        const items = errors.map(err => {
            const nome = escapeHtmlText(labelFornecedor(err.fornecedor_id));
            const motivo = escapeHtmlText(err.erro || 'Motivo não informado');
            const tokenRaw = err.token_raw || '';
            const link = tokenRaw ? escapeHtmlText(construirLinkConvite(tokenRaw)) : '';
            return `<li class="mb-2">
                <div><strong>${nome}</strong> &mdash; ${motivo}</div>
                ${link ? `<div class="input-group input-group-sm mt-2">
                    <input type="text" class="form-control font-monospace" value="${link}" readonly>
                    <button class="btn btn-outline-dark btn-copy-error-token" data-token="${tokenRaw}"><i class="bi bi-clipboard-check"></i></button>
                </div>` : ''}
            </li>`;
        }).join('');
        convitesErrorsBox.style.display = '';
        convitesErrorsBox.innerHTML = `
            <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Alguns convites precisam de atenção:</div>
            <ul class="ps-3 mb-2">${items}</ul>
            <div class="text-secondary">Reveja o e-mail do fornecedor ou compartilhe o link manualmente.</div>
        `;
    }
    if(convitesErrorsBox){
        convitesErrorsBox.addEventListener('click', async (e)=>{
            const btn = e.target.closest('.btn-copy-error-token');
            if(!btn) return;
            const tok = btn.dataset.token;
            const ok = tok ? await copyToClipboard(construirLinkConvite(tok)) : false;
            showToast(ok ? 'Link copiado.' : 'Falha ao copiar.', ok ? 'success' : 'danger');
        });
    }

    function hideDropdownFor(){ if(dropdownFor){ dropdownFor.style.display='none'; dropdownFor.innerHTML=''; } }
    function showDropdownFor(){ if(dropdownFor){ dropdownFor.style.display='block'; } }

    function searchFornecedores(term){
        if(!dropdownFor) return;
        term = (term||'').trim();
        if(term.length < 2){ hideDropdownFor(); return; }
        dropdownFor.innerHTML = '<div class="list-group-item text-secondary small">Buscando...</div>';
        showDropdownFor();
        if(abortCtrl){ abortCtrl.abort(); }
        abortCtrl = new AbortController();
        fetch(`../api/fornecedores.php?q=${encodeURIComponent(term)}&limit=10`, { signal: abortCtrl.signal, credentials:'same-origin', headers:{'Accept':'application/json'} })
            .then(r=>{ if(!r.ok) throw new Error(r.status===401?'Sessão expirada':'Erro na busca'); return r.json(); })
            .then(lista=>{
                if(!Array.isArray(lista) || lista.length===0){ dropdownFor.innerHTML = '<div class="list-group-item text-secondary small">Nenhum resultado.</div>'; return; }
                dropdownFor.innerHTML = lista.map(f=>{
                    const nome = (f.nome_fantasia || f.razao_social || '').replace(/</g,'&lt;');
                    const cnpj = (f.cnpj || '').replace(/</g,'&lt;');
                    return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" data-json='${JSON.stringify(f).replace(/'/g,"&apos;").replace(/\"/g,'&quot;')}'><span>${nome}</span><small class="text-secondary">${cnpj}</small></button>`;
                }).join('');
            })
            .catch(()=>{ dropdownFor.innerHTML = '<div class="list-group-item text-danger small">Erro na busca.</div>'; });
    }
    if(buscaInput){
        buscaInput.addEventListener('input', ()=>{ clearTimeout(buscaTimer); buscaTimer = setTimeout(()=> searchFornecedores(buscaInput.value), 250); });
        buscaInput.addEventListener('focus', ()=>{ if((buscaInput.value||'').trim().length>=2){ searchFornecedores(buscaInput.value); } });
    }
    document.addEventListener('click', (e)=>{ if(dropdownFor && !dropdownFor.contains(e.target) && e.target !== buscaInput){ hideDropdownFor(); } });
    if(dropdownFor){ dropdownFor.addEventListener('mousedown', e=>{ const btn=e.target.closest('button.list-group-item'); if(!btn) return; e.preventDefault(); try{ const f=JSON.parse((btn.dataset.json||'{}').replace(/&quot;/g,'"')); addFornecedor(f); }catch(_){} hideDropdownFor(); if(buscaInput) buscaInput.value=''; }); }

    // --- FUNÇÕES DE LÓGICA E RENDERIZAÇÃO ---

    const normalizeApiResponse = (payload) => {
        let rows = [];
        let meta = {
            page: state.meta.page || 1,
            per_page: state.meta.per_page || defaultPerPage,
            total: state.meta.total || 0,
            total_pages: state.meta.total_pages || 1,
            status_options: state.meta.status_options || []
        };
        if (Array.isArray(payload)) {
            rows = payload;
            meta = {
                page: 1,
                per_page: rows.length || defaultPerPage,
                total: rows.length,
                total_pages: 1,
                status_options: meta.status_options
            };
            return { rows, meta };
        }
        if (payload && typeof payload === 'object') {
            if (Array.isArray(payload.data)) rows = payload.data;
            else if (Array.isArray(payload.rows)) rows = payload.rows;
            else if (Array.isArray(payload.lista)) rows = payload.lista;

            const metaRaw = payload.meta || {};
            const legacyMeta = ('page' in payload || 'per_page' in payload || 'total' in payload)
                ? { page: payload.page, per_page: payload.per_page, total: payload.total }
                : {};
            const totalRows = metaRaw.total ?? legacyMeta.total ?? rows.length;
            const perPage = metaRaw.per_page ?? legacyMeta.per_page ?? (rows.length || defaultPerPage);
            const computedTotalPages = metaRaw.total_pages ?? (perPage ? Math.max(1, Math.ceil((totalRows || 1) / perPage)) : 1);

            meta = {
                page: metaRaw.page ?? legacyMeta.page ?? 1,
                per_page: perPage,
                total: totalRows,
                total_pages: computedTotalPages,
                status_options: metaRaw.status_options ?? meta.status_options
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

    const preencherFiltroStatus = (options = []) => {
        if (!filtroStatusSelect) return;
        const normalized = options.map(opt => {
            if (typeof opt === 'string') return { value: opt, label: statusLabel(opt) };
            if (opt && typeof opt === 'object') {
                const value = opt.value || opt.id || opt.slug || '';
                if (!value) return null;
                return { value, label: opt.label || statusLabel(value) };
            }
            return null;
        }).filter(Boolean);
        const seen = new Set();
        let html = '<option value="">Todos os Status</option>';
        const ensureOption = (value, label) => {
            if (!value || seen.has(value)) return;
            seen.add(value);
            html += `<option value="${value}">${label}</option>`;
        };
        ensureOption('aberta', 'Apenas abertas');
        normalized.forEach(opt => ensureOption(opt.value, opt.label || statusLabel(opt.value)));
        filtroStatusSelect.innerHTML = html;
        const target = state.filters.status || '';
        const hasTarget = [...filtroStatusSelect.options].some(opt => opt.value === target);
        filtroStatusSelect.value = hasTarget ? target : '';
    };

    const updatePaginationHint = () => {
        if (!paginationHint) return;
        const meta = state.meta || {};
        if (!meta.total) {
            paginationHint.textContent = `Nenhuma ${pageConfig.singular.toLowerCase()} encontrada`;
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
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Nenhuma ${pageConfig.singular.toLowerCase()} encontrada.</td></tr>`;
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
        if (total === 0) {
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
        params.set('include_token', '1');
        if (state.filters.busca) params.set('busca', state.filters.busca);
        if (state.filters.status) params.set('status', state.filters.status);
        return params;
    };

    const fetchCotacoes = (pageOverride) => {
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
                preencherFiltroStatus(state.meta.status_options || []);
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
                showToast(err.message || `Erro ao carregar ${pageConfig.plural}.`, 'danger');
            })
            .finally(() => setLoading(false));
    };

    const fetchIdsFiltrados = () => {
        const params = new URLSearchParams();
        params.set('mode', 'ids');
        if (state.filters.busca) params.set('busca', state.filters.busca);
        if (state.filters.status) params.set('status', state.filters.status);
        return fetch(`${pageConfig.endpoint}?${params.toString()}`, defaultFetchOptions)
            .then(res => res.json().catch(() => null))
            .then(payload => Array.isArray(payload?.ids) ? payload.ids.map(id => parseInt(id, 10)).filter(Boolean) : [])
            .catch(() => []);
    };

    function carregarRequisicoesAbertas(){
        if(!requisicaoSelect) return Promise.resolve();
        requisicaoSelect.innerHTML = '<option value="" disabled selected>Carregando requisições...</option>';
        return fetch('../api/requisicoes.php?status=aberta&per_page=200', defaultFetchOptions)
            .then(res => res.json().catch(() => null))
            .then(payload => {
                const lista = Array.isArray(payload?.data) ? payload.data : (Array.isArray(payload) ? payload : []);
                const requisicoesAbertas = lista.filter(r => String(r.status||'').toLowerCase() === 'aberta');
                if (requisicoesAbertas.length === 0) {
                    requisicaoSelect.innerHTML = '<option value="" disabled>Nenhuma requisição aberta encontrada</option>';
                } else {
                    requisicaoSelect.innerHTML = '<option value="" disabled selected>Selecione uma requisição em aberto...</option>';
                    requisicoesAbertas.forEach(req => {
                        const option = document.createElement('option');
                        option.value = req.id;
                        option.textContent = `#${req.id} - ${req.titulo || 'Requisição sem título'}`;
                        requisicaoSelect.appendChild(option);
                    });
                }
                if(requisicaoAutocomplete){ requisicaoAutocomplete.setData(requisicoesAbertas); }
            })
            .catch(() => {
                requisicaoSelect.innerHTML = '<option value="" disabled>Não foi possível carregar requisições</option>';
            });
    }

    const carregarDadosIniciais = () => Promise.all([
        fetchCotacoes(1),
        carregarRequisicoesAbertas()
    ]);

    function criarLinhaTabela(item) {
        const itemJson = JSON.stringify(item).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
        // Badge por status padronizado
        let statusClass = 'secondary';
        let statusIcon = 'bi-dot';
        const st = (item.status || '').toLowerCase();
        if (st === 'aberta') { statusClass='info'; statusIcon='bi-unlock-fill'; }
        else if (st === 'encerrada' || st === 'fechada') { statusClass='secondary'; statusIcon='bi-lock-fill'; }
        const dataCriacao = item.criado_em ? new Date(item.criado_em).toLocaleDateString('pt-BR') : '-';
        const dataExpiracao = item.token_expira_em ? new Date(item.token_expira_em).toLocaleDateString('pt-BR') : '-';
        const label = statusLabel(st);
        const isAberta = st === 'aberta';

        return `
            <tr>
                <td><span class="font-monospace">#${item.id}</span></td>
                <td>Requisição #${item.requisicao_id}</td>
                <td>${dataCriacao}</td>
                <td>${dataExpiracao}</td>
                <td><span class="badge bg-${statusClass}"><i class="bi ${statusIcon} me-1"></i>${label}</span></td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-light btn-action-convites" title="${isAberta ? 'Gerenciar Convites' : 'Disponível apenas enquanto a cotação está aberta'}" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-convites" ${isAberta ? '' : 'disabled'}><i class="bi bi-envelope-plus"></i></button>
                    <button class="btn btn-sm btn-outline-light btn-action-details" title="Ver Link" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-link"><i class="bi bi-link-45deg"></i></button>
                    <button class="btn btn-sm btn-matrix-danger btn-action-delete" title="${isAberta ? 'Excluir' : 'Somente para cotações abertas'}" data-item='${itemJson}' ${isAberta ? '' : 'disabled'}><i class="bi bi-trash"></i></button>
                </td>
            </tr>`;}
    function confirmarRemocao(id, nome) {
        itemParaRemoverId = id;
        modalItemName.textContent = nome;
        // limpar erro anterior, se houver
        if(deleteError){ deleteError.classList.add('d-none'); deleteError.textContent=''; }
        confirmationModal.show();
    }

    // --- FUNÇÕES DE CONVITES ---
    function carregarConvites(cotacaoId, requisicaoId){
        if(!cotacaoId){
            convitesListBody.innerHTML = `<tr><td colspan="7" class="text-center text-secondary py-3">Cotação não identificada.</td></tr>`;
            return;
        }
        convitesListBody.innerHTML = `<tr><td colspan="7" class="text-center text-secondary py-3">Carregando...</td></tr>`;
        const params = new URLSearchParams({ cotacao_id: String(cotacaoId), convites: 'list' });
        if(requisicaoId){ params.append('requisicao_id', String(requisicaoId)); }
        fetch(`${pageConfig.endpoint}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(async r => {
                if (r.status === 401) {
                    throw new Error('Sessão expirada. Faça login novamente.');
                }
                const rows = await r.json();
                if (!Array.isArray(rows)) throw new Error('Falha ao carregar convites');
                renderizarConvites(rows);
            })
            .catch(err => {
                convitesListBody.innerHTML = `<tr><td colspan="7" class="text-center text-danger">${err.message}</td></tr>`;
            });
    }

    function renderizarConvites(rows){
        if(!rows || rows.length===0){
            convitesListBody.innerHTML = `<tr><td colspan="7" class="text-center text-secondary py-3">Nenhum convite encontrado.</td></tr>`;
            return;
        }
        convitesListBody.innerHTML = rows.map(r => {
            const exp = r.expira_em ? new Date(r.expira_em).toLocaleString('pt-BR') : '-';
            const env = r.enviado_em ? new Date(r.enviado_em).toLocaleString('pt-BR') : '-';
            const resp = r.respondido_em ? new Date(r.respondido_em).toLocaleString('pt-BR') : '-';
            const badgeClass = r.status==='respondido' ? 'success' : (r.status==='enviado' ? 'info' : (r.status==='cancelado' ? 'secondary' : 'warning'));
            const nomeFornecedor = (r.fornecedor_nome || `Fornecedor #${r.fornecedor_id}`);
            const cnpjFornecedor = (r.fornecedor_cnpj || '');
            const canCancel = r.status === 'enviado';
            return `
            <tr>
                <td>${r.id}</td>
                <td>
                    <div class="d-flex flex-column">
                        <span>${nomeFornecedor}</span>
                        ${cnpjFornecedor ? `<small class="text-secondary">CNPJ ${cnpjFornecedor}</small>` : ''}
                    </div>
                </td>
                <td><span class="badge bg-${badgeClass}">${r.status ? r.status.charAt(0).toUpperCase()+r.status.slice(1) : '-'}</span></td>
                <td>${exp}</td>
                <td>${env}</td>
                <td>${resp}</td>
                <td class="text-end">
                    ${canCancel ? `<button class="btn btn-sm btn-outline-light btn-cancelar-convite" data-id="${r.id}" title="Cancelar convite"><i class="bi bi-x-circle"></i></button>` : '<span class="text-secondary">—</span>'}
                </td>
            </tr>`;
        }).join('');
    }

    function construirBase(){
        return `${window.location.origin}${window.location.pathname.replace('pages/cotacoes.php', '')}`;
    }
    function construirLinkPublico(tokenRaw){
        const base = construirBase();
        return `${base}pages/cotacao_responder.php?token=${encodeURIComponent(tokenRaw)}`;
    }
    function construirLinkConvite(tokenRaw){
        const base = construirBase();
        return `${base}pages/cotacao_responder.php?conv=${encodeURIComponent(tokenRaw)}`;
    }

    // --- EVENT LISTENERS ---

    const debouncedSearch = debounce(() => fetchCotacoes(1), 400);

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            state.filters.busca = searchInput.value.trim();
            debouncedSearch();
        });
    }

    if (filtroStatusSelect) {
        filtroStatusSelect.addEventListener('change', () => {
            state.filters.status = filtroStatusSelect.value || '';
            fetchCotacoes(1);
        });
    }

    if (btnLimparFiltros) {
        btnLimparFiltros.addEventListener('click', () => {
            if (!state.filters.busca && !state.filters.status) return;
            state.filters.busca = '';
            state.filters.status = '';
            if (searchInput) searchInput.value = '';
            if (filtroStatusSelect) filtroStatusSelect.value = '';
            fetchCotacoes(1);
        });
    }

    if (paginationContainer) {
        paginationContainer.addEventListener('click', e => {
            const btn = e.target.closest('[data-page]');
            if (!btn) return;
            e.preventDefault();
            const targetPage = parseInt(btn.dataset.page, 10);
            if (!targetPage || targetPage === state.meta.page) return;
            fetchCotacoes(Math.max(1, targetPage));
        });
    }

    tableBody.addEventListener('click', function(event) {
        const btn = event.target.closest('button');
        if (!btn) return;
        const itemData = (()=>{ try { return JSON.parse((btn.dataset.item || '{}').replace(/&quot;/g,'"')); } catch(e){ return null; }})();
        if(btn.classList.contains('btn-action-details')){
            if(!itemData){ return; }
            cotacaoAtual = itemData;
            const token = itemData.token || '';
            if(token){
                cotacaoLinkInput.value = construirLinkPublico(token);
            } else {
                cotacaoLinkInput.value = '';
            }
            dataExpiracaoLink.textContent = itemData.token_expira_em ? new Date(itemData.token_expira_em).toLocaleDateString('pt-BR') : '-';
            // Habilitar/desabilitar regenerar conforme status
            btnRegenerarToken.disabled = ((itemData.status || '').toLowerCase() !== 'aberta');
            // Se não temos token atual, sugerir regenerar
            if(!token){ showToast('Token não disponível. Gere um novo token para obter o link.', 'warning'); }
        }
        if(btn.classList.contains('btn-action-convites')){
            if(!itemData) return;
            cotacaoIdConvitesAtual = itemData.id || null;
            requisicaoIdConvitesAtual = itemData.requisicao_id || null;
            if(convitesCotacaoInput){ convitesCotacaoInput.value = cotacaoIdConvitesAtual ? String(cotacaoIdConvitesAtual) : ''; }
            if(convitesReqInput){ convitesReqInput.value = requisicaoIdConvitesAtual ? String(requisicaoIdConvitesAtual) : ''; }
            fornecedoresSelecionados = [];
            renderChips();
            convitesCriadosTokens.style.display = 'none';
            convitesCriadosTokens.innerHTML = '';
            renderConviteErrors([]);
            if(cotacaoIdConvitesAtual){
                carregarConvites(cotacaoIdConvitesAtual, requisicaoIdConvitesAtual);
            } else {
                convitesListBody.innerHTML = `<tr><td colspan="7" class="text-center text-secondary py-3">Cotação inválida.</td></tr>`;
            }
        }
        if(btn.classList.contains('btn-action-delete')){
            const id = itemData?.id || btn.dataset.id;
            confirmarRemocao(id, `Cotação #${id}`);
        }
    });

    // Confirmar exclusão
    confirmDeleteBtn.addEventListener('click', async ()=>{
        if(!itemParaRemoverId){ return; }
        confirmDeleteBtn.disabled = true;
        if(deleteError){ deleteError.classList.add('d-none'); deleteError.textContent=''; }
        const originalHtml = confirmDeleteBtn.innerHTML;
        confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Excluindo';
        try{
            const requestUrl = pageConfig.endpoint + '?_method=DELETE';
            const body = new URLSearchParams({ id: String(itemParaRemoverId) });
            const response = await fetch(requestUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: body.toString()
            });
            const raw = await response.text();
            let data = null;
            if(raw){ try { data = JSON.parse(raw); } catch(_) { data = null; } }
            if(!response.ok || !data){
                const message = data?.erro || data?.error || (raw ? raw.replace(/<[^>]+>/g,'').slice(0,160) : '');
                throw new Error(message || `Erro ao excluir (HTTP ${response.status})`);
            }
            if(data.success === false){ throw new Error(data.erro || 'Falha ao excluir'); }
            showToast('Cotação excluída.', 'success');
            bootstrap.Modal.getInstance(confirmationModalEl)?.hide();
            const currentPage = state.meta.page || 1;
            const shouldGoBack = currentPage > 1 && state.rows.length <= 1;
            itemParaRemoverId = null;
            fetchCotacoes(shouldGoBack ? currentPage - 1 : currentPage);
        }catch(e){
            if(deleteError){ deleteError.textContent = e.message || 'Erro ao excluir'; deleteError.classList.remove('d-none'); }
            showToast(e.message || 'Erro ao excluir', 'danger');
        }finally{
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = originalHtml;
        }
    });

    // Copiar link público
    btnCopiarLink.addEventListener('click', async ()=>{
        const v = (cotacaoLinkInput.value || '').trim();
        if(!v){ showToast('Nenhum link para copiar.', 'warning'); return; }
        const ok = await copyToClipboard(v);
        showToast(ok ? 'Link copiado.' : 'Falha ao copiar link.', ok ? 'success' : 'danger');
    });

    // Regenerar token público
    btnRegenerarToken.addEventListener('click', async ()=>{
        if(!cotacaoAtual || !cotacaoAtual.id){ showToast('Selecione uma cotação.', 'warning'); return; }
        btnRegenerarToken.disabled = true;
        try{
            const body = new URLSearchParams({ id: String(cotacaoAtual.id), regenerar_token: '1' });
            const r = await fetch(pageConfig.endpoint, { method:'PUT', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
            const js = await r.json();
            if(!r.ok || !js || js.success===false){ throw new Error(js && js.erro ? js.erro : 'Falha ao regenerar token'); }
            if(js.token){ cotacaoLinkInput.value = construirLinkPublico(js.token); }
            if(js.expira){ dataExpiracaoLink.textContent = new Date(js.expira).toLocaleDateString('pt-BR'); }
            showToast('Novo token gerado.', 'success');
            // Atualizar cache local e tabela
            if(js.token){
                // Atualiza objeto atual e na lista
                cotacaoAtual.token = js.token;
            }
            if(js.expira){ cotacaoAtual.token_expira_em = js.expira; }
            state.rows = state.rows.map(row => {
                if (String(row.id) !== String(cotacaoAtual.id)) return row;
                return {
                    ...row,
                    token: js.token || row.token,
                    token_expira_em: js.expira || row.token_expira_em
                };
            });
            renderRows();
        }catch(e){
            showToast(e.message || 'Erro ao regenerar token', 'danger');
        }finally{
            btnRegenerarToken.disabled = false;
        }
    });

    // Criar nova cotação
    if(modalEl){
        modalEl.addEventListener('show.bs.modal', ()=>{
            requisicaoAutocomplete?.clearValue?.();
        });
    }

    form.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const reqId = parseInt(requisicaoSelect.value,10);
        if(!reqId){ showToast('Selecione uma requisição.', 'warning'); return; }
        const btn = form.querySelector('button[type="submit"]');
        if(btn) btn.disabled = true;
        try{
            const r = await fetch(pageConfig.endpoint, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ requisicao_id: reqId }) });
            const js = await r.json();
            if(!r.ok || !js || js.success===false){ throw new Error(js && js.erro ? js.erro : 'Falha ao criar cotação'); }
            showToast('Cotação criada.', 'success');
            modalCotacaoInstance?.hide();
            requisicaoAutocomplete?.clearValue?.();
            // Recarrega lista e reaplica filtros (mantém "Apenas abertas" como padrão)
            carregarDadosIniciais();
            // Se token foi retornado, abrir modal de link pré-preenchido
            if(js.token){
                cotacaoAtual = { id: js.id, token: js.token, token_expira_em: js.expira };
                cotacaoLinkInput.value = construirLinkPublico(js.token);
                dataExpiracaoLink.textContent = js.expira ? new Date(js.expira).toLocaleDateString('pt-BR') : '-';
                modalLink.show();
            }
        }catch(err){ showToast(err.message || 'Erro ao criar cotação', 'danger'); }
        finally{ if(btn) btn.disabled = false; }
    });

    // Convites: criar
    formConvites.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const cotacaoId = cotacaoIdConvitesAtual || parseInt(convitesCotacaoInput?.value || '', 10) || null;
        const rid = parseInt(convitesReqInput?.value || '',10) || null;
        if(!cotacaoId){ showToast('Cotação inválida.', 'warning'); return; }
        if(fornecedoresSelecionados.length===0){ showToast('Adicione ao menos um fornecedor.', 'warning'); return; }
        const payload = {
            cotacao_id: cotacaoId,
            fornecedores: fornecedoresSelecionados.map(f=>({ fornecedor_id: f.id })),
            dias_validade: parseInt(diasValidadeInput.value,10) || 5,
            include_raw: !!exibirTokensCheckbox.checked
        };
        if(rid){ payload.requisicao_id = rid; }
        const btn = formConvites.querySelector('button[type="submit"]');
        if(btn) btn.disabled = true;
        try{
            const r = await fetch(pageConfig.endpoint, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ ...payload, convites: 'create' }) });
            const js = await r.json();
            if(!r.ok || js.erro){ throw new Error(js.erro || 'Falha ao criar convites'); }
            const temErros = Array.isArray(js.errors) && js.errors.length>0;
            const msg = temErros ? 'Alguns convites não foram gerados.' : 'Convites processados.';
            showToast(msg, temErros ? 'warning' : 'success');
            renderConviteErrors(js.errors || []);
            if(temErros){
                console.warn('Erros ao gerar convites:', js.errors);
            }
            carregarConvites(cotacaoIdConvitesAtual, requisicaoIdConvitesAtual);
            // Mostrar tokens criados (quando include_raw)
            if(Array.isArray(js.criados) && js.criados.length>0 && exibirTokensCheckbox.checked){
                convitesCriadosTokens.style.display = '';
                convitesCriadosTokens.innerHTML = '<div class="alert alert-secondary small mb-2">Tokens gerados (copie os links abaixo se necessário):</div>' +
                    js.criados.map(c=> c.token_raw ? `\n                        <div class="input-group mb-2">\n                            <input type="text" class="form-control font-monospace" value="${construirLinkConvite(c.token_raw)}" readonly>\n                            <button class="btn btn-outline-light btn-copy-new-token" data-token="${c.token_raw}"><i class="bi bi-clipboard-check"></i></button>\n                        </div>` : '').join('');
            } else {
                convitesCriadosTokens.style.display = 'none';
                convitesCriadosTokens.innerHTML = '';
            }
            // Limpar seleção
            fornecedoresSelecionados = [];
            renderChips();
        }catch(err){ showToast(err.message || 'Erro ao criar convites', 'danger'); }
        finally{ if(btn) btn.disabled = false; }
    });

    // Recarregar convites
    btnRecarregarConvites.addEventListener('click', ()=>{ if(cotacaoIdConvitesAtual){ carregarConvites(cotacaoIdConvitesAtual, requisicaoIdConvitesAtual); } });

    // Copiar tokens criados (convites)
    convitesCriadosTokens.addEventListener('click', async (e)=>{
        const b = e.target.closest('.btn-copy-new-token');
        if(!b) return;
        const tok = b.dataset.token;
        const ok = await copyToClipboard(construirLinkConvite(tok));
        showToast(ok ? 'Link copiado.' : 'Falha ao copiar.', ok? 'success':'danger');
    });

    // Cancelar convite existente
    convitesListBody.addEventListener('click', async (e)=>{
        const b = e.target.closest('.btn-cancelar-convite');
        if(!b) return;
        const id = parseInt(b.dataset.id,10);
        if(!id) return;
        b.disabled = true;
        try{
            const r = await fetch(pageConfig.endpoint, {
                method:'POST',
                credentials:'same-origin',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json'
                },
                body: JSON.stringify({ convites: 'cancel', id })
            });
            const js = await r.json();
            if(!r.ok || js.erro){ throw new Error(js.erro || 'Falha ao cancelar convite'); }
            showToast('Convite cancelado.', 'success');
            if(cotacaoIdConvitesAtual){ carregarConvites(cotacaoIdConvitesAtual, requisicaoIdConvitesAtual); }
        }catch(err){ showToast(err.message || 'Erro ao cancelar convite', 'danger'); }
        finally{ b.disabled = false; }
    });

    if (formExportar) {
        formExportar.addEventListener('submit', async e => {
            e.preventDefault();
            const formato = formExportar.formato?.value || 'csv';
            const intervalo = formExportar.intervalo?.value || 'todos';
            let idsString = '';
            try {
                if (intervalo === 'pagina_atual') {
                    const idsPagina = state.rows.map(row => parseInt(row.id, 10)).filter(Boolean);
                    if (!idsPagina.length) {
                        showToast('Nenhuma cotação na página atual para exportar.', 'warning');
                        return;
                    }
                    idsString = idsPagina.join(',');
                } else if (intervalo === 'filtrados') {
                    const idsFiltrados = await fetchIdsFiltrados();
                    if (!idsFiltrados.length) {
                        showToast('Nenhuma cotação encontrada com os filtros atuais.', 'warning');
                        return;
                    }
                    idsString = idsFiltrados.join(',');
                }

                const hiddenForm = document.createElement('form');
                hiddenForm.method = 'POST';
                hiddenForm.action = 'exportador.php';
                hiddenForm.target = '_blank';
                hiddenForm.innerHTML = `
                    <input type="hidden" name="modulo" value="${pageConfig.modulo}">
                    <input type="hidden" name="formato" value="${formato}">
                    <input type="hidden" name="intervalo" value="${intervalo}">
                    ${idsString ? `<input type="hidden" name="ids" value="${idsString}">` : ''}`;
                document.body.appendChild(hiddenForm);
                hiddenForm.submit();
                hiddenForm.remove();
                showToast(`Gerando ${formato.toUpperCase()}...`, 'success');
                bootstrap.Modal.getInstance(document.getElementById('modal-exportar'))?.hide();
            } catch (err) {
                showToast(err.message || 'Erro ao preparar exportação.', 'danger');
            }
        });
    }

    carregarDadosIniciais();
});
// --- FIM DO SCRIPT ---
</script>
</body>
</html>