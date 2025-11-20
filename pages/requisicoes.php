<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php'; // added for access check
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
    <title>Requisições - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
      /* Autocomplete Produtos (modal-gerir-itens) */
      #search-produtos-modal.loading{background-image:linear-gradient(90deg,rgba(0,0,0,0.04),rgba(0,0,0,0.08),rgba(0,0,0,0.04)); background-size:200% 100%; animation:produtoShimmer 1.1s linear infinite;}
      @keyframes produtoShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
      #dropdown-produtos-modal{position:fixed; z-index:1057; max-height:300px; overflow:auto; display:none;}

      /* Modal Gerir Itens: tamanho fixo e áreas scrolláveis */
      #modal-gerir-itens .modal-dialog { max-width:1200px; }
      #modal-gerir-itens .modal-content { height:80vh; display:flex; flex-direction:column; }
      #modal-gerir-itens .modal-body { flex:1 1 auto; display:flex; flex-direction:column; overflow:hidden; }
      #modal-gerir-itens #itens-atuais-container { max-height:140px; overflow-y:auto; border:1px solid var(--matrix-border); border-radius:4px; padding:4px 8px; }
      #modal-gerir-itens .produtos-scroll-wrapper { flex:1 1 auto; min-height:0; overflow-y:auto; border:1px solid var(--matrix-border); border-radius:4px; }
      #modal-gerir-itens .produtos-scroll-wrapper table { margin-bottom:0; }
      #modal-gerir-itens thead th { position:sticky; top:0; background:#f8f9fa !important; color:#212529 !important; z-index:5; }

      /* === TABELA DE PRODUTOS (FORÇAR TEMA CLARO DENTRO DO MODAL) === */
      #modal-gerir-itens .produtos-scroll-wrapper, 
      #modal-gerir-itens .produtos-scroll-wrapper table,
      #modal-gerir-itens .produtos-scroll-wrapper table tbody tr,
      #modal-gerir-itens .produtos-scroll-wrapper table td { background:#ffffff !important; color:#212529 !important; }
      #modal-gerir-itens .produtos-scroll-wrapper { border-color:#d0d7dd !important; }
      #modal-gerir-itens .produtos-scroll-wrapper table tbody tr:hover { background:#f1f3f5 !important; }
      #modal-gerir-itens .produtos-scroll-wrapper table tbody tr.produto-adicionado-row { background:#eef4f8 !important; }
      #modal-gerir-itens .produtos-scroll-wrapper table tbody tr.produto-adicionado-row:hover { background:#dde7ed !important; }
      #modal-gerir-itens .produtos-scroll-wrapper table tbody tr.produto-adicionado-row td { color:#1f2a30 !important; }
      #modal-gerir-itens .produto-adicionado-row input.form-control[disabled],
      #modal-gerir-itens .produto-adicionado-row input.form-control-sm[disabled]{
        background:#f1f3f5 !important;
        color:#212529 !important;
        border-color:#c7d1d8 !important;
        opacity:1 !important;
      }
      #modal-gerir-itens .produto-adicionado-row input.form-control[disabled]::placeholder,
      #modal-gerir-itens .produto-adicionado-row input.form-control-sm[disabled]::placeholder{ color:#6c757d !important; }
      #modal-gerir-itens .badge.bg-success { background:#198754 !important; color:#fff !important; }
      #modal-gerir-itens .qtd-input:disabled { background:#f8f9fa !important; color:#212529 !important; }
      #modal-gerir-itens input.form-control:not([disabled]):focus{ box-shadow:0 0 0 .15rem rgba(25,135,84,.35); border-color:#198754; }

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
    data-endpoint="../api/requisicoes.php" 
    data-singular="Requisição"
    data-plural="Requisições"
    data-modulo="requisicoes">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Requisições</h1>
            <p class="text-secondary mb-0">Crie, visualize e administre os seus pedidos de compra internos.</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-importar">
                <i class="bi bi-upload me-2"></i>Importar
            </button>
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-exportar">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#modal-requisicao">
                <i class="bi bi-plus-circle me-2"></i>Nova Requisição
            </button>
        </div>
    </header>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Requisições Abertas</span>
            <span class="text-secondary small">Use os filtros dentro da tabela para refinar os resultados.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Cliente</th>
                        <th>Data</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    <tr class="table-filters bg-body-secondary-subtle align-middle">
                        <th colspan="3">
                            <div class="input-group input-group-sm shadow-sm" style="min-width:260px">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-start-0" placeholder="Buscar por título, ID ou cliente" id="searchInput">
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
            <div id="pagination-hint" class="text-secondary small">Carregando requisições...</div>
            <nav aria-label="Paginação de requisições" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-requisicao" tabindex="-1" aria-labelledby="modal-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content card-matrix">
        <div class="modal-header card-header-matrix">
            <h5 class="modal-title" id="modal-title"><i class="bi bi-plus-circle me-2"></i>Criar Nova Requisição</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body form-container-gestao">
            <form id="form-main" novalidate>
                 <input type="hidden" name="usuario_id" value="<?php echo htmlspecialchars($_SESSION['usuario_id']); ?>">
                <div class="row g-3">
                    <div class="col-12"><label for="titulo" class="form-label">Título da Requisição</label><div class="input-group"><span class="input-group-text"><i class="bi bi-chat-left-text"></i></span><input type="text" class="form-control" id="titulo" name="titulo" placeholder="Ex: Compra de materiais para escritório" required></div></div>
                    <div class="col-md-6"><label for="cliente_id" class="form-label">Cliente</label><select class="form-select" id="cliente_id" name="cliente_id" required><option value="" disabled selected>Selecione um cliente...</option></select></div>
                    <div class="col-md-6"><label for="status" class="form-label">Status</label><select class="form-select" id="status" name="status"><option value="aberta">Aberta</option><option value="fechada">Fechada</option></select></div>
                </div>
            </form>
        </div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-matrix-primary" form="form-main">Salvar Requisição</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-gerir-itens" tabindex="-1" aria-labelledby="modalGerirItensLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalGerirItensLabel"><i class="bi bi-card-checklist me-2"></i>Gerir Itens da Requisição</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h6>Itens Atuais na Requisição</h6>
        <div id="itens-atuais-container" class="mb-4"></div>
        <hr class="border-secondary">
        <h6 class="mt-4">Adicionar Novos Produtos</h6>
        <input type="text" class="form-control mb-2" id="search-produtos-modal" placeholder="Buscar produto por nome ou NCM..." autocomplete="off">
        <div class="table-responsive produtos-scroll-wrapper">
            <table class="table table-hover align-middle" id="tabela-produtos-modal">
                <thead>
                    <tr>
                        <!-- Coluna de seleção removida -->
                        <th>Produto</th>
                        <th>NCM</th>
                        <th style="width:110px;" class="text-center">Quantidade</th>
                        <th style="width:120px;" class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabela-produtos-modal-body"></tbody>
            </table>
        </div>
      </div>
      <div class="modal-footer">
        <div class="me-auto small text-secondary" id="resumo-selecao-produtos" style="display:none;"></div>
        <button type="button" class="btn btn-matrix-primary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>


<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger"><h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar Exclusão</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><p>Tem a certeza de que deseja excluir permanentemente a requisição <strong id="modal-item-name" class="text-warning"></strong>?</p><p class="text-secondary small">Esta ação não pode ser desfeita.</p>
        <!-- Área de erro amigável -->
        <div id="delete-error" class="alert alert-warning d-none" role="alert"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-matrix-danger" id="confirm-delete-btn">Confirmar Exclusão</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-importar" tabindex="-1" aria-labelledby="modalImportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalImportarLabel"><i class="bi bi-upload me-2"></i>Importar Requisições</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-importar" enctype="multipart/form-data">
            <p class="text-secondary">O ficheiro deve estar no formato CSV ou XLSX e a primeira linha deve conter os cabeçalhos corretos.</p>
            <div class="mb-3">
                <a href="baixar_exemplo.php?modulo=requisicoes&formato=csv" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .csv
                </a>
                <a href="baixar_exemplo.php?modulo=requisicoes&formato=xlsx" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .xlsx
                </a>
            </div>
            <div class="mb-3">
                <label for="arquivo-importacao" class="form-label">Ficheiro para importação</label>
                <input class="form-control" type="file" id="arquivo-importacao" name="arquivo_importacao" accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-importar" id="btn-confirmar-importacao">
            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span> Importar Agora
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-exportar" tabindex="-1" aria-labelledby="modalExportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalExportarLabel"><i class="bi bi-download me-2"></i>Exportar Requisições</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-exportar">
            <input type="hidden" name="modulo" value="requisicoes">
            <div class="mb-4">
                <label class="form-label">Formato do Ficheiro</label>
                <div class="export-options d-flex gap-3">
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-csv-req" value="csv" checked>
                        <label class="form-check-label" for="export-csv-req"><i class="bi bi-filetype-csv me-2"></i>CSV</label>
                    </div>
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-req" value="xlsx">
                        <label class="form-check-label" for="export-xlsx-req"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label>
                    </div>
                    <div class="form-check flex-fill">
                        <input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-req" value="pdf">
                        <label class="form-check-label" for="export-pdf-req"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label>
                    </div>
                </div>
            </div>
            <div>
                <label class="form-label">Intervalo de Dados</label>
                <select class="form-select" name="intervalo">
                    <option value="todos">Todas as Requisições</option>
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

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- INÍCIO DO SCRIPT COMPLETO E FINAL PARA REQUISIÇÕES ---
const debounce = (fn, delay = 350) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

document.addEventListener('DOMContentLoaded', function() {
    const containerEl = document.getElementById('page-container');
    const pageConfig = {
        endpoint: containerEl.dataset.endpoint,
        singular: containerEl.dataset.singular,
        plural: containerEl.dataset.plural.toLowerCase(),
    };
    const importEndpoint = '../api/importador.php';
    // Ajuste DEFINITIVO: usar sempre plural 'requisicoes'; fallback derivado do endpoint
    const modulo = containerEl.dataset.modulo || (pageConfig.endpoint.split('/').pop().split('.')[0]) || 'requisicoes';

    let editandoId = null;
    let itemParaRemoverId = null;
    let itemParaRemoverNome = '';
    let requisicaoAtivaId = null;
    let todosOsProdutos = [];
    const defaultPerPage = 15;
    const tableBody = document.getElementById('tabela-main-body');
    const searchInput = document.getElementById('searchInput');
    const filtroStatus = document.getElementById('filtroStatus');
    const clearFiltersBtn = document.getElementById('btn-clear-filtros');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const modalEl = document.getElementById('modal-requisicao');
    const modalRequisicaoInstance = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    const modalTitle = document.getElementById('modal-title');
    const form = document.getElementById('form-main');
    const clienteSelect = document.getElementById('cliente_id');
    const clienteAutocomplete = (() => {
        if (!clienteSelect) return null;
        clienteSelect.required = true;
        clienteSelect.classList.add('d-none');
        const wrapper = document.createElement('div');
        wrapper.className = 'position-relative';
        clienteSelect.parentElement.insertBefore(wrapper, clienteSelect);
        wrapper.appendChild(clienteSelect);
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control mb-1';
        input.id = 'cliente-search';
        input.placeholder = 'Carregando clientes...';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.disabled = true;
        wrapper.insertBefore(input, clienteSelect);
        const dropdown = document.createElement('div');
        dropdown.id = 'dropdown-clientes';
        dropdown.className = 'list-group lookup-dropdown shadow';
        dropdown.style.cssText = 'position:fixed; z-index:1056; max-height:260px; overflow:auto; display:none;';
        document.body.appendChild(dropdown);
        const state = { lista: [], filtrada: [], highlighted: -1, aberto: false };
        const MIN_CHARS = 1;
        const escapeHtml = str => {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return String(str).replace(/[&<>"']/g, ch => map[ch] || ch);
        };
        const highlight = (label, term) => {
            if (!term) return escapeHtml(label);
            const esc = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            return escapeHtml(label).replace(new RegExp(`(${esc})`, 'ig'), '<mark>$1</mark>');
        };
        function positionDropdown() {
            if (!state.aberto) return;
            const rect = input.getBoundingClientRect();
            dropdown.style.width = rect.width + 'px';
            dropdown.style.left = rect.left + 'px';
            dropdown.style.top = rect.bottom + 4 + 'px';
        }
        const repositionHandler = () => positionDropdown();
        function open() {
            if (!state.aberto) {
                dropdown.style.display = 'block';
                state.aberto = true;
                window.addEventListener('scroll', repositionHandler, true);
                window.addEventListener('resize', repositionHandler);
            }
            positionDropdown();
        }
        function close() {
            if (!state.aberto) return;
            dropdown.style.display = 'none';
            state.aberto = false;
            state.highlighted = -1;
            window.removeEventListener('scroll', repositionHandler, true);
            window.removeEventListener('resize', repositionHandler);
        }
        function render(term) {
            const query = (term || '').toLowerCase();
            if (query.length < MIN_CHARS) {
                dropdown.innerHTML = '';
                close();
                return;
            }
            state.filtrada = state.lista.filter(cli => cli.search.includes(query));
            if (!state.filtrada.length) {
                dropdown.innerHTML = '<div class="list-group-item small text-secondary">Nenhum cliente encontrado.</div>';
                open();
                return;
            }
            dropdown.innerHTML = state.filtrada.map((cli, idx) => {
                const active = idx === state.highlighted ? 'active' : '';
                const nome = highlight(cli.nome, query);
                const doc = cli.doc ? `<small class="text-secondary d-block">${highlight(cli.doc, query)}</small>` : '';
                return `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center ${active}" data-id="${cli.id}">
                    <div class="flex-grow-1 text-start">
                        <span class="text-truncate">${nome}</span>
                        ${doc}
                    </div>
                    <small class="text-secondary ms-2">#${cli.id}</small>
                </button>`;
            }).join('');
            open();
        }
        function selectItem(item) {
            if (!item) return;
            clienteSelect.value = item.id;
            input.value = item.nome;
            input.dataset.selectedId = item.id;
            input.classList.remove('is-invalid');
            close();
        }
        function navigate(delta) {
            if (!state.filtrada.length) return;
            state.highlighted = (state.highlighted + delta + state.filtrada.length) % state.filtrada.length;
            render(input.value.trim());
        }
        input.addEventListener('input', () => {
            const term = input.value.trim();
            if (!term) {
                clienteSelect.value = '';
                input.dataset.selectedId = '';
            }
            render(term);
        });
        input.addEventListener('focus', () => {
            const term = input.value.trim();
            if (term.length >= MIN_CHARS) {
                render(term);
            }
        });
        input.addEventListener('keydown', evt => {
            if (evt.key === 'ArrowDown') {
                evt.preventDefault();
                navigate(1);
            } else if (evt.key === 'ArrowUp') {
                evt.preventDefault();
                navigate(-1);
            } else if (evt.key === 'Enter') {
                if (state.highlighted >= 0) {
                    evt.preventDefault();
                    selectItem(state.filtrada[state.highlighted]);
                }
            } else if (evt.key === 'Escape') {
                close();
            } else if (evt.key === 'Tab') {
                close();
            }
        });
        document.addEventListener('click', evt => {
            if (!wrapper.contains(evt.target) && !dropdown.contains(evt.target)) {
                close();
            }
        });
        dropdown.addEventListener('mousedown', evt => {
            const btn = evt.target.closest('button[data-id]');
            if (!btn) return;
            evt.preventDefault();
            const item = state.lista.find(cli => String(cli.id) === String(btn.dataset.id));
            selectItem(item);
        });
        const api = {
            setData(list) {
                state.lista = Array.isArray(list) ? list.map(cli => {
                    const nome = cli.razao_social || cli.nome_fantasia || cli.nome || `Cliente #${cli.id}`;
                    const doc = cli.cnpj || cli.cpf || cli.documento || '';
                    return {
                        id: cli.id,
                        nome,
                        doc,
                        search: `${nome} ${doc} #${cli.id}`.toLowerCase()
                    };
                }) : [];
                input.disabled = false;
                input.placeholder = 'Digite para buscar cliente...';
                if (clienteSelect.value) {
                    api.selectById(clienteSelect.value);
                } else {
                    input.value = '';
                }
            },
            selectById(id) {
                const item = state.lista.find(cli => String(cli.id) === String(id));
                if (item) {
                    selectItem(item);
                    return true;
                }
                return false;
            },
            clearValue() {
                input.value = '';
                input.dataset.selectedId = '';
                clienteSelect.value = '';
                close();
            },
            close,
            inputEl: input
        };
        clienteSelect.addEventListener('change', () => {
            if (!clienteSelect.value) {
                input.value = '';
                input.dataset.selectedId = '';
                return;
            }
            api.selectById(clienteSelect.value);
        });
        return api;
    })();
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = new bootstrap.Modal(confirmationModalEl);
    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const modalItemName = document.getElementById('modal-item-name');
    // Referência da área de erro do modal (faltava e causava ReferenceError)
    const deleteError = document.getElementById('delete-error');
    const modalGerirItens = new bootstrap.Modal(document.getElementById('modal-gerir-itens'));
    const itensAtuaisContainer = document.getElementById('itens-atuais-container');
    const tabelaProdutosModalBody = document.getElementById('tabela-produtos-modal-body');
    const searchProdutosModal = document.getElementById('search-produtos-modal');
    const formExportar = document.getElementById('form-exportar');
    const formImportar = document.getElementById('form-importar');
    const btnConfirmarImportacao = document.getElementById('btn-confirmar-importacao');
    // NOVO: referência global ao botão de submit da requisição para spinner/disable
    const submitBtnRequisicao = document.querySelector('#modal-requisicao .modal-footer button[type="submit"]');
    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;

    const state = {
        rows: [],
        meta: { page:1, per_page:defaultPerPage, total:0, total_pages:1, status_options:[] },
        filters: { busca:'', status:'' },
        loading: false
    };

    // Padronização de rótulos legíveis para status
    const STATUS_LABELS = {
        'pendente_aprovacao': 'Pendente de aprovação',
        'em_analise': 'Em análise',
        'aberta': 'Aberta',
        'fechada': 'Fechada',
        'aprovada': 'Aprovada',
        'rejeitada': 'Rejeitada'
    };
    function humanizeStatus(v){ if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/\b\w/g, c=> c.toUpperCase()); }
    function statusLabel(v){ return STATUS_LABELS[v] || humanizeStatus(v); }

    // NOVO: formatação segura de datas vindas do MySQL (evita Invalid Date)
    function formatDateBr(val){
        if(!val) return '-';
        try{
            const s = String(val).trim().replace(' ', 'T');
            const d = new Date(s);
            if (isNaN(d.getTime())) return '-';
            return d.toLocaleDateString('pt-BR');
        } catch(e){ return '-'; }
    }

    let itensExistentes = new Set();
    let produtosSelecionados = new Map(); // id -> { id, nome, ncm, quantidade }
    let produtosJaAdicionados = []; // detalhes dos itens já presentes na requisição atual
    let mapaItemParaProduto = new Map(); // item_id -> produto_id para updates/remocoes

    function atualizarResumoSelecao(){
        const resumo = document.getElementById('resumo-selecao-produtos');
        const btnAddSel = document.getElementById('btn-adicionar-selecionados');
        const btnLimpar = document.getElementById('btn-limpar-selecao');
        const countSpan = document.getElementById('count-selecionados');
        const total = produtosSelecionados.size;
        if(countSpan) countSpan.textContent = total;
        if(total === 0){
            if(resumo) resumo.style.display='none';
            if(btnAddSel) btnAddSel.disabled = true;
            if(btnLimpar) btnLimpar.disabled = true;
        } else {
            let totalQtd = 0; produtosSelecionados.forEach(p=> totalQtd += parseFloat(p.quantidade)||0);
            if(resumo){ resumo.textContent = `${total} produto(s) selecionado(s) | Quantidade total: ${totalQtd}`; resumo.style.display='block'; }
            if(btnAddSel) btnAddSel.disabled = false;
            if(btnLimpar) btnLimpar.disabled = false;
        }
    }

    function toggleSelecaoProduto(produto, checked){
        if(!produto || produto.jaAdicionado) return;
        if(checked){
            produtosSelecionados.set(produto.id, { ...produto, quantidade: produto.quantidade || 1 });
        } else {
            produtosSelecionados.delete(produto.id);
        }
        atualizarResumoSelecao();
    }

    function aplicarQuantidadeSelecao(qtd){
        if(!qtd || qtd <= 0) return;
        produtosSelecionados.forEach(p=> p.quantidade = qtd);
        // atualizar inputs da tabela
        produtosSelecionados.forEach(p=>{
            const input = document.getElementById('qtd-prod-'+p.id);
            if(input) input.value = p.quantidade;
        });
        atualizarResumoSelecao();
    }

    async function adicionarSelecionadosBatch(){
        if(produtosSelecionados.size === 0) return;
        const adicionaveis = [...produtosSelecionados.values()].filter(p=> !itensExistentes.has(p.id));
        if(adicionaveis.length === 0){ showToast('Nenhum produto novo para adicionar.', 'warning'); return; }
        let sucesso = 0; let falhas = 0;
        for(const prod of adicionaveis){
            const quantidade = prod.quantidade || 1;
            try{
                const res = await fetch('../api/requisicao_itens.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ requisicao_id: requisicaoAtivaId, produto_id: prod.id, quantidade })
                });
                const data = await res.json();
                if(data && data.success){ sucesso++; itensExistentes.add(prod.id); }
                else { falhas++; }
            }catch(_){ falhas++; }
        }
        showToast(`${sucesso} produto(s) adicionados${falhas? ', '+falhas+' falha(s)':''}.`, sucesso? (falhas? 'warning':'success') : 'danger');
        if(sucesso){ carregarItensDaRequisicao(requisicaoAtivaId); }
        produtosSelecionados.clear(); atualizarResumoSelecao();
        popularModalAdicionarItens(document.getElementById('search-produtos-modal')?.value||'');
    }

    let clientesCarregados = false;

    const parseDatasetItem = (raw) => {
        if (!raw) return null;
        try {
            const normalized = raw.replace(/&quot;/g, '"').replace(/&apos;/g, "'");
            return JSON.parse(normalized);
        } catch (error) {
            console.error('Falha ao interpretar dataset item:', error);
            return null;
        }
    };

    function atualizarModoModal(isEdit = false) {
        if (modalTitle) {
            modalTitle.innerHTML = isEdit
                ? '<i class="bi bi-pencil-square me-2"></i>Editar Requisição'
                : '<i class="bi bi-plus-circle me-2"></i>Criar Nova Requisição';
        }
        if (submitBtnRequisicao) {
            submitBtnRequisicao.innerHTML = isEdit
                ? '<i class="bi bi-check-circle me-2"></i>Atualizar Requisição'
                : '<i class="bi bi-check-circle me-2"></i>Salvar Requisição';
        }
    }

    function prepararFormularioNovo() {
        editandoId = null;
        if (form) {
            form.reset();
        }
        if (clienteAutocomplete) {
            clienteAutocomplete.clearValue?.();
            if (clienteAutocomplete.inputEl) {
                clienteAutocomplete.inputEl.classList.remove('is-invalid');
            }
        }
        atualizarModoModal(false);
    }

    function preencherFormularioEdicao(item) {
        if (!form || !item) return;
        if (form.titulo) {
            form.titulo.value = item.titulo || '';
        }
        if (form.status) {
            const valorStatus = item.status || 'aberta';
            const options = Array.from(form.status.options || []);
            if (valorStatus && !options.some(opt => opt.value === valorStatus)) {
                const opt = document.createElement('option');
                opt.value = valorStatus;
                opt.textContent = statusLabel(valorStatus);
                form.status.appendChild(opt);
            }
            form.status.value = valorStatus;
        }
        if (clienteSelect) {
            if (item.cliente_id) {
                let opt = clienteSelect.querySelector(`option[value="${item.cliente_id}"]`);
                if (!opt) {
                    opt = document.createElement('option');
                    opt.value = item.cliente_id;
                    opt.textContent = `Cliente #${item.cliente_id}`;
                    clienteSelect.appendChild(opt);
                }
                clienteSelect.value = item.cliente_id;
                const selecionado = clienteAutocomplete?.selectById?.(item.cliente_id);
                if (!selecionado && clienteAutocomplete?.inputEl) {
                    clienteAutocomplete.inputEl.value = item.cliente_nome || `Cliente #${item.cliente_id}`;
                    clienteAutocomplete.inputEl.dataset.selectedId = item.cliente_id;
                }
            } else {
                clienteSelect.value = '';
                clienteAutocomplete?.clearValue?.();
            }
        }
    }

    function ativarModoEdicao(item) {
        if (!item) {
            showToast('Não foi possível carregar os dados da requisição selecionada.', 'danger');
            return;
        }
        editandoId = item.id;
        preencherFormularioEdicao(item);
        atualizarModoModal(true);
        carregarClientes();
    }

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
            const computedTotalPages = metaRaw.total_pages ?? (perPage ? Math.max(1, Math.ceil(totalRows / perPage)) : 1);

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
        if (total === 0) {
            paginationContainer.innerHTML = '';
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

    const updatePaginationHint = () => {
        if (!paginationHint) return;
        const meta = state.meta || {};
        if (!meta.total) {
            paginationHint.textContent = `Nenhuma ${pageConfig.singular.toLowerCase()} encontrada`;
            return;
        }
        const start = ((meta.page || 1) - 1) * (meta.per_page || defaultPerPage) + 1;
        const end = start + state.rows.length - 1;
        paginationHint.innerHTML = `Mostrando <strong>${start}-${end}</strong> de <strong>${meta.total}</strong> ${pageConfig.plural}`;
    };

    const preencherFiltroStatus = (options = []) => {
        if (!filtroStatus) return;
        const current = filtroStatus.value;
        let html = '<option value="">Todos os Status</option>';
        const values = [];
        options.forEach(opt => {
            if (!opt || !opt.value) return;
            values.push(opt.value);
            html += `<option value="${opt.value}">${opt.label || statusLabel(opt.value)}</option>`;
        });
        filtroStatus.innerHTML = html;
        if (current && values.includes(current)) {
            filtroStatus.value = current;
        } else if (current && current !== '') {
            filtroStatus.insertAdjacentHTML('beforeend', `<option value="${current}" selected>${statusLabel(current)}</option>`);
        }
    };

    const buildQueryParams = () => {
        const params = new URLSearchParams();
        params.set('page', state.meta.page || 1);
        params.set('per_page', state.meta.per_page || defaultPerPage);
        if (state.filters.busca) params.set('busca', state.filters.busca);
        if (state.filters.status) params.set('status', state.filters.status);
        return params;
    };

    const fetchIdsFiltrados = () => {
        const params = new URLSearchParams();
        params.set('mode', 'ids');
        if (state.filters.busca) params.set('busca', state.filters.busca);
        if (state.filters.status) params.set('status', state.filters.status);
        return fetch(`${pageConfig.endpoint}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(res => res.json().catch(() => null))
            .then(payload => Array.isArray(payload?.ids) ? payload.ids.map(id => parseInt(id, 10)).filter(Boolean) : [])
            .catch(() => []);
    };

    const fetchRequisicoes = (pageOverride) => {
        if (pageOverride) state.meta.page = pageOverride;
        const params = buildQueryParams();
        setLoading(true);
        return fetch(`${pageConfig.endpoint}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(async res => {
                const data = await res.json().catch(() => null);
                if (!res.ok || !data) {
                    throw new Error(data?.erro || `Erro ao carregar ${pageConfig.plural}.`);
                }
                const normalized = normalizeApiResponse(data);
                state.rows = Array.isArray(normalized.rows) ? normalized.rows : [];
                state.meta = normalized.meta;
                preencherFiltroStatus(state.meta.status_options);
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
                console.error(err);
            })
            .finally(() => setLoading(false));
    };

    const carregarClientes = () => {
        if (!clienteSelect || clientesCarregados) return Promise.resolve();
        clienteSelect.innerHTML = '<option value="" disabled selected>Carregando clientes...</option>';
        return fetch('../api/clientes.php', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(res => res.json().catch(() => null))
            .then(clientes => {
                const lista = Array.isArray(clientes?.data) ? clientes.data : (Array.isArray(clientes) ? clientes : []);
                clienteSelect.innerHTML = '<option value="" disabled selected>Selecione um cliente...</option>';
                lista.forEach(cliente => {
                    if (!cliente?.id) return;
                    const option = document.createElement('option');
                    option.value = cliente.id;
                    const nome = cliente.razao_social || cliente.nome_fantasia || `Cliente #${cliente.id}`;
                    option.textContent = `${nome} (ID: ${cliente.id})`;
                    clienteSelect.appendChild(option);
                });
                clienteAutocomplete?.setData(lista);
                clientesCarregados = true;
            })
            .catch(() => {
                clienteSelect.innerHTML = '<option value="" disabled selected>Não foi possível carregar clientes</option>';
                clienteAutocomplete?.clearValue?.();
                if (clienteAutocomplete?.inputEl) {
                    clienteAutocomplete.inputEl.disabled = true;
                    clienteAutocomplete.inputEl.placeholder = 'Não foi possível carregar clientes';
                }
            });
    };

    const carregarDadosIniciais = (pageOverride) => fetchRequisicoes(pageOverride || state.meta.page || 1);

    function criarLinhaTabela(item) {
        const itemJson = JSON.stringify(item).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
        // Melhor mapeamento de status
        let statusClass = 'secondary';
        let statusIcon = 'bi-dot';
        switch (item.status) {
            case 'aberta': statusClass='info'; statusIcon='bi-unlock-fill'; break;
            case 'pendente_aprovacao': statusClass='warning text-dark'; statusIcon='bi-hourglass-split'; break;
            case 'fechada': statusClass='success'; statusIcon='bi-check-circle-fill'; break;
            case 'rejeitada': statusClass='danger'; statusIcon='bi-x-circle-fill'; break;
            default: statusClass='secondary'; statusIcon='bi-dot';
        }
        const dataFormatada = formatDateBr(item.criado_em);
        const tituloFinal = item.titulo && item.titulo.trim() !== '' ? item.titulo : `Requisição #${item.id}`;
        const tituloEscapado = escapeHtml(tituloFinal);
        const labelLegivel = statusLabel(item.status);
        // Botões adicionais para aprovação quando pendente_aprovacao
        const botoesAprovacao = (item.status === 'pendente_aprovacao')
          ? `<button class="btn btn-sm btn-outline-success btn-action-approve" title="Aprovar" data-id="${item.id}"><i class="bi bi-check2-circle"></i></button>
             <button class="btn btn-sm btn-outline-danger btn-action-reject ms-1" title="Rejeitar" data-id="${item.id}"><i class="bi bi-x-circle"></i></button>`
          : '';
        return `
            <tr>
                <td><span class="font-monospace">#${item.id}</span></td>
                <td>${tituloEscapado}</td>
                <td>Cliente #${item.cliente_id || '-'}</td>
                <td>${dataFormatada}</td>
                <td><i class="bi ${statusIcon} ${statusClass} me-2"></i> ${labelLegivel}</td>
                <td class="text-end">
                    ${botoesAprovacao}
                    <button class="btn btn-sm btn-outline-light btn-action-details ms-1" title="Gerir Itens" data-id="${item.id}" data-title="${tituloEscapado}" data-bs-toggle="modal" data-bs-target="#modal-gerir-itens"><i class="bi bi-cart-plus-fill"></i></button>
                    <button class="btn btn-sm btn-outline-light btn-action-edit" title="Editar Requisição" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-requisicao"><i class="bi bi-pencil-fill"></i></button>
                    <button class="btn btn-sm btn-outline-light btn-action-link" title="Copiar Link Público" data-id="${item.id}"><i class="bi bi-link-45deg"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-light btn-action-delete" title="Excluir Requisição" data-id="${item.id}" data-name="${tituloEscapado}" data-bs-toggle="modal" data-bs-target="#confirmationModal"><i class="bi bi-trash-fill"></i></button>
                </td>
            </tr>`;
    }

    function carregarItensDaRequisicao(reqId) {
        requisicaoAtivaId = reqId;
        itensAtuaisContainer.innerHTML = '<p class="text-center">A carregar itens...</p>';
        itensExistentes.clear();
        produtosJaAdicionados = [];
        mapaItemParaProduto.clear();
        fetch(`../api/requisicao_itens.php?requisicao_id=${reqId}`)
            .then(res => res.json())
            .then(itens => {
                if (!Array.isArray(itens) || itens.length === 0) {
                    itensAtuaisContainer.innerHTML = '<p class="text-center text-secondary my-3">Esta requisição ainda não tem itens.</p>';
                    produtosJaAdicionados = [];
                    popularModalAdicionarItens(document.getElementById('search-produtos-modal')?.value||'');
                    return;
                }
                let html = '<ul class="list-group list-group-flush">';
                itens.forEach(item => {
                    const pid = item.produto_id || item.id_produto || item.id_produto_fk || item.id;
                    const itemId = item.id; // id do registro em requisicao_itens
                    itensExistentes.add(pid);
                    mapaItemParaProduto.set(itemId, pid);
                    produtosJaAdicionados.push({
                        id: pid,
                        nome: item.nome || item.titulo || `Produto ${pid}`,
                        ncm: item.ncm || item.NCM || item.codigo_ncm || '',
                        quantidade: item.quantidade || 1
                    });
                    html += `<li class="list-group-item d-flex justify-content-between align-items-center bg-transparent px-0">
                        <span class="text-black"><i class="bi bi-box me-2"></i>${item.nome}</span>
                        <div class="d-flex align-items-center gap-2">
                            <input type="number" class="form-control form-control-sm" value="${item.quantidade}" min="1" style="width:70px; background:#ffffff; border-color: var(--matrix-border); color:#000;" onchange="atualizarQuantidade(${itemId}, this.value)">
                            <button class="btn btn-sm btn-outline-danger" onclick="removerItem(${itemId})" title="Remover item"><i class="bi bi-trash"></i></button>
                        </div>
                    </li>`;
                });
                html += '</ul>';
                itensAtuaisContainer.innerHTML = html;
                // Atualiza tabela (exibe já adicionados se não houver filtro)
                popularModalAdicionarItens(document.getElementById('search-produtos-modal')?.value||'');
            });
    }

    function popularModalAdicionarItens(filtro = '') {
        const filtroLowerCase = (filtro || '').toLowerCase().trim();
        tabelaProdutosModalBody.innerHTML = '';
        // Sem filtro: mostrar somente produtos já adicionados
        if(!filtroLowerCase){
            if(produtosJaAdicionados.length === 0){
                tabelaProdutosModalBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary">Nenhum produto listado. Digite para buscar novos produtos.</td></tr>';
                return;
            }
            const linhasExistentes = produtosJaAdicionados.map(p=>{
                const qtdSel = p.quantidade || 1;
                return `<tr data-id="${p.id}" class="produto-adicionado-row">\n                    <td>${p.nome}<span class="badge bg-success ms-2">Adicionado</span></td>\n                    <td>${p.ncm || '-'}</td>\n                    <td class="text-center"><input type="number" class="form-control form-control-sm" value="${qtdSel}" style="width:80px" disabled></td>\n                    <td class="text-end"><button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-check2"></i></button></td>\n                </tr>`;
            }).join('');
            tabelaProdutosModalBody.innerHTML = linhasExistentes;
            return;
        }
        // Com filtro: trabalhar sobre a lista carregada pela busca remota (nome OU NCM no mesmo campo)
        const produtosFiltrados = todosOsProdutos.filter(p => {
            const nomeOk = (p.nome||'').toLowerCase().includes(filtroLowerCase);
            const ncmOk = (p.ncm||'').toLowerCase().includes(filtroLowerCase);
            return (nomeOk || ncmOk);
        });
        if (produtosFiltrados.length === 0) {
            tabelaProdutosModalBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary">Nenhum produto encontrado.</td></tr>';
            return;
        }
        const linhas = produtosFiltrados.map(p => {
            const jaAdicionado = itensExistentes.has(p.id);
            const qtdSel = 1;
            const badge = jaAdicionado ? '<span class="badge bg-success ms-2">Adicionado</span>' : '';
            return `<tr data-id="${p.id}">\n                <td>${p.nome}${badge}</td>\n                <td>${p.ncm || '-'} </td>\n                <td class="text-center"><input type="number" min="1" class="form-control form-control-sm qtd-input" id="qtd-prod-${p.id}" style="width:80px" value="${qtdSel}" ${jaAdicionado?'disabled':''}></td>\n                <td class="text-end">${jaAdicionado ? '<button class="btn btn-sm btn-outline-secondary" disabled><i class="bi bi-check2"></i></button>' : `<button class=\"btn btn-sm btn-matrix-primary btn-add-single\" data-id=\"${p.id}\"><i class=\"bi bi-plus-lg\"></i></button>`}</td>\n            </tr>`;
        }).join('');
        tabelaProdutosModalBody.innerHTML = linhas;
    }

    // NOVO: handler delegado para adicionar um único produto (já que barra de seleção em lote foi removida)
    if(tabelaProdutosModalBody){
        tabelaProdutosModalBody.addEventListener('click', async (e)=>{
            const btn = e.target.closest('.btn-add-single');
            if(!btn) return;
            const prodId = btn.dataset.id;
            if(!requisicaoAtivaId || !prodId) { showToast('Requisição ou produto inválido.', 'danger'); return; }
            if(itensExistentes.has(parseInt(prodId))) { showToast('Produto já adicionado.', 'warning'); return; }
            const row = btn.closest('tr');
            const qtdInput = row ? row.querySelector('.qtd-input') : null;
            const quantidade = parseFloat(qtdInput?.value) > 0 ? parseFloat(qtdInput.value) : 1;
            btn.disabled = true; btn.classList.add('disabled');
            try{
                const res = await fetch('../api/requisicao_itens.php', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ requisicao_id: requisicaoAtivaId, produto_id: prodId, quantidade })
                });
                const out = await res.json();
                if(out && out.success){
                    showToast('Produto adicionado.', 'success');
                    itensExistentes.add(parseInt(prodId));
                    // Recarrega itens e re-renderiza tabela mantendo filtro atual
                    carregarItensDaRequisicao(requisicaoAtivaId);
                } else {
                    showToast(out?.erro || 'Falha ao adicionar.', 'danger');
                    btn.disabled = false; btn.classList.remove('disabled');
                }
            }catch(_){
                showToast('Erro de rede ao adicionar.', 'danger');
                btn.disabled = false; btn.classList.remove('disabled');
            }
        });
    }
    /* ========================= AUTOCOMPLETE DE PRODUTOS (MODAL GERIR ITENS) ========================= */
    (function initProdutoBuscaSimples(){
        if(!searchProdutosModal) return;
        const MIN_CHARS = 1; // pode ajustar para 2 se necessário performance
        let debounceTimer = null;
        let abortCtrl = null;
        let ultimaQuery = '';

        function executarBusca(term){
            const q = term.trim();
            if(q.length < MIN_CHARS){
                todosOsProdutos = [];
                popularModalAdicionarItens('');
                return;
            }
            if(abortCtrl) abortCtrl.abort();
            abortCtrl = new AbortController();
            searchProdutosModal.classList.add('loading');
            fetch(`../api/produtos.php?q=${encodeURIComponent(q)}&limit=100`, { signal: abortCtrl.signal, headers:{'Accept':'application/json'} })
              .then(r => r.ok ? r.json() : [])
              .then(lista => {
                  // Normalizar campos esperados
                  todosOsProdutos = Array.isArray(lista) ? lista.map(p => ({
                      id: p.id,
                      nome: p.nome || p.titulo || '',
                      ncm: p.ncm || p.NCM || p.codigo_ncm || ''
                  })) : [];
                  popularModalAdicionarItens(q);
              })
              .catch(()=>{ todosOsProdutos = []; popularModalAdicionarItens(q); })
              .finally(()=> searchProdutosModal.classList.remove('loading'));
        }

        searchProdutosModal.addEventListener('input', () => {
            const val = searchProdutosModal.value;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(()=> executarBusca(val), 300);
        });
        // Enter força busca imediata
        searchProdutosModal.addEventListener('keydown', (e)=>{
            if(e.key === 'Enter'){
                e.preventDefault();
                clearTimeout(debounceTimer);
                executarBusca(searchProdutosModal.value);
            }
        });
        // Ao abrir modal mostrar mensagem vazia
        const modalElLocal = document.getElementById('modal-gerir-itens');
        if(modalElLocal){
            modalElLocal.addEventListener('shown.bs.modal', ()=>{
                if(searchProdutosModal.value.trim()===''){
                    popularModalAdicionarItens('');
                    searchProdutosModal.focus();
                } else {
                    executarBusca(searchProdutosModal.value);
                }
            });
            modalElLocal.addEventListener('hidden.bs.modal', ()=>{
                // limpar resultados e campo
                searchProdutosModal.value='';
                todosOsProdutos = [];
            });
        }
    })();
    /* ======================= FIM AUTOCOMPLETE / BUSCA PRODUTOS ======================= */

    // Ajustar busca local caso usuário apague tudo e pressione Enter (fallback)
    if(searchProdutosModal){
        searchProdutosModal.addEventListener('keydown', (e)=>{ if(e.key==='Enter' && !e.defaultPrevented && searchProdutosModal.value.trim()===''){ popularModalAdicionarItens(''); }});
    }

    // Helpers de aprovação/rejeição (escopo DOMContentLoaded)
    function getReqById(id){
        if(!id) return null;
        return state.rows.find(r => String(r.id) === String(id)) || null;
    }

    async function enviarStatusRequisicao(req, statusDestino, mensagens){
        if(!req){ showToast('Requisição não encontrada no cache.', 'danger'); return false; }
        let confirmar = true;
        if (window.confirmDialog) {
            confirmar = await window.confirmDialog({
                title: mensagens.titulo,
                message: mensagens.pergunta,
                variant: mensagens.variant,
                confirmText: mensagens.confirmar,
                cancelText: 'Cancelar'
            });
        } else {
            confirmar = window.confirm(mensagens.perguntaPlain);
        }
        if(!confirmar){ return false; }

        const payload = new URLSearchParams();
        payload.set('id', req.id);
        payload.set('status', statusDestino);
        if (req.cliente_id) payload.set('cliente_id', req.cliente_id);
        if (req.titulo) payload.set('titulo', req.titulo);
        payload.set('_method', 'PUT');

        const requestUrl = pageConfig.endpoint;
        try{
            const response = await fetch(requestUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin',
                body: payload.toString()
            });
            const raw = await response.text();
            let data = null;
            if (raw) {
                try { data = JSON.parse(raw); } catch(_) {}
            }
            if(!response.ok || !data){
                const msg = data?.erro || data?.error || (raw ? raw.replace(/<[^>]+>/g,'').slice(0,160) : '');
                throw new Error(msg || `Erro HTTP ${response.status}`);
            }
            if(data.success === false){
                throw new Error(data.erro || mensagens.falhaPadrao);
            }
            showToast(mensagens.sucesso, mensagens.toastVariant);
            carregarDadosIniciais();
            return true;
        }catch(e){
            showToast(e.message || mensagens.falhaPadrao, 'danger');
            return false;
        }
    }

    async function aprovarRequisicao(id){
        const req = getReqById(id);
        await enviarStatusRequisicao(req, 'aberta', {
            titulo: 'Aprovar requisição',
            pergunta: `Aprovar a requisição #${id}?`,
            perguntaPlain: `Aprovar a requisição #${id}?`,
            confirmar: 'Aprovar',
            variant: 'primary',
            sucesso: 'Requisição aprovada.',
            falhaPadrao: 'Falha ao aprovar.',
            toastVariant: 'success'
        });
    }

    async function rejeitarRequisicao(id){
        const req = getReqById(id);
        await enviarStatusRequisicao(req, 'rejeitada', {
            titulo: 'Rejeitar requisição',
            pergunta: `Rejeitar a requisição #${id}?`,
            perguntaPlain: `Rejeitar a requisição #${id}?`,
            confirmar: 'Rejeitar',
            variant: 'danger',
            sucesso: 'Requisição rejeitada.',
            falhaPadrao: 'Falha ao rejeitar.',
            toastVariant: 'warning'
        });
    }

    if (formExportar) {
        formExportar.addEventListener('submit', async e => {
            e.preventDefault();
            const formato = formExportar.formato?.value || 'csv';
            const intervalo = formExportar.intervalo?.value || 'todos';
            let idsString = '';
            try {
                if (intervalo === 'pagina_atual') {
                    const ids = state.rows.map(r => parseInt(r.id, 10)).filter(Boolean);
                    if (!ids.length) {
                        showToast('Nenhuma requisição na página atual para exportar.', 'warning');
                        return;
                    }
                    idsString = ids.join(',');
                } else if (intervalo === 'filtrados') {
                    const ids = await fetchIdsFiltrados();
                    if (!ids.length) {
                        showToast('Nenhuma requisição encontrada com os filtros atuais.', 'warning');
                        return;
                    }
                    idsString = ids.join(',');
                }

                const hiddenForm = document.createElement('form');
                hiddenForm.method = 'POST';
                hiddenForm.action = 'exportador.php';
                hiddenForm.target = '_blank';
                hiddenForm.innerHTML = `
                    <input type="hidden" name="modulo" value="${modulo}">
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

    if (form) {
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const titulo = form.titulo?.value?.trim() || '';
            const clienteId = clienteSelect?.value || '';
            const statusValor = form.status?.value || 'aberta';
            if (!clienteId) {
                showToast('Selecione um cliente para continuar.', 'warning');
                if (clienteAutocomplete?.inputEl) {
                    clienteAutocomplete.inputEl.classList.add('is-invalid');
                    clienteAutocomplete.inputEl.focus();
                }
                return;
            }
            if (clienteAutocomplete?.inputEl) {
                clienteAutocomplete.inputEl.classList.remove('is-invalid');
            }
            const payload = { titulo, cliente_id: clienteId, status: statusValor };
            const estavaEditando = Boolean(editandoId);
            let originalHtml = null;
            if (submitBtnRequisicao) {
                submitBtnRequisicao.disabled = true;
                originalHtml = submitBtnRequisicao.innerHTML;
                const actionLabel = estavaEditando ? 'Atualizando...' : 'Salvando...';
                submitBtnRequisicao.innerHTML = `<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>${actionLabel}`;
            }
            let requestUrl = pageConfig.endpoint;
            const headers = { 'Accept': 'application/json' };
            let body;
            if (estavaEditando) {
                payload.id = editandoId;
                const formData = new URLSearchParams();
                Object.entries(payload).forEach(([key, value]) => {
                    if (value !== undefined && value !== null) {
                        formData.append(key, value);
                    }
                });
                body = formData.toString();
                headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                requestUrl = `${pageConfig.endpoint}?_method=PUT`;
            } else {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(payload);
            }
            const paginaParaRecarregar = estavaEditando ? state.meta.page : 1;
            try {
                const resp = await fetch(requestUrl, {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    body
                });
                const data = await resp.json().catch(() => null);
                if (!resp.ok || !data) {
                    throw new Error(data?.erro || 'Erro ao salvar requisição.');
                }
                if (data.success === false) {
                    throw new Error(data.erro || 'Não foi possível salvar a requisição.');
                }
                showToast(`Requisição ${estavaEditando ? 'atualizada' : 'salva'} com sucesso.`, 'success');
                modalRequisicaoInstance?.hide();
                prepararFormularioNovo();
                carregarDadosIniciais(paginaParaRecarregar);
            } catch (err) {
                showToast(err.message || 'Erro ao salvar requisição.', 'danger');
            } finally {
                if (submitBtnRequisicao) {
                    submitBtnRequisicao.disabled = false;
                    if (originalHtml !== null) {
                        submitBtnRequisicao.innerHTML = originalHtml;
                    }
                }
            }
        });
    }

    if (formImportar) {
        formImportar.addEventListener('submit', e => {
            e.preventDefault();
            const arquivoInput = document.getElementById('arquivo-importacao');
            if (!arquivoInput?.files?.length) {
                showToast('Selecione um ficheiro para importar.', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('arquivo_importacao', arquivoInput.files[0]);
            fd.append('modulo', modulo);
            const spinner = btnConfirmarImportacao?.querySelector('.spinner-border');
            spinner?.classList.remove('d-none');
            if (btnConfirmarImportacao) btnConfirmarImportacao.disabled = true;
            fetch(importEndpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(res => res.json().catch(() => null).then(data => ({ ok: res.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || !data?.success) throw new Error(data?.erro || 'Erro ao importar.');
                    showToast(data.message || 'Importação concluída.', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('modal-importar'))?.hide();
                    formImportar.reset();
                    return fetchRequisicoes(state.meta.page || 1);
                })
                .catch(err => {
                    showToast(err.message || 'Erro ao importar.', 'danger');
                    console.error('Import requisicoes erro:', err);
                })
                .finally(() => {
                    spinner?.classList.add('d-none');
                    if (btnConfirmarImportacao) btnConfirmarImportacao.disabled = false;
                });
        });
    }

    modalEl?.addEventListener('show.bs.modal', e => {
        const isEditTrigger = e.relatedTarget?.classList?.contains('btn-action-edit');
        if (!isEditTrigger) {
            prepararFormularioNovo();
        } else {
            atualizarModoModal(true);
        }
    });

    modalEl?.addEventListener('hidden.bs.modal', () => {
        prepararFormularioNovo();
    });

    carregarClientes();
    carregarDadosIniciais();

    paginationContainer?.addEventListener('click', e => {
        const btn = e.target.closest('button.page-link[data-page]');
        if (!btn) return;
        e.preventDefault();
        const targetPage = parseInt(btn.dataset.page, 10);
        if (!targetPage || targetPage === state.meta.page) return;
        fetchRequisicoes(Math.max(1, targetPage));
    });

    searchInput?.addEventListener('input', debounce(() => {
        const newValue = searchInput.value.trim();
        if (state.filters.busca === newValue) return;
        state.filters.busca = newValue;
        fetchRequisicoes(1);
    }, 400));

    filtroStatus?.addEventListener('change', () => {
        if (state.filters.status === filtroStatus.value) return;
        state.filters.status = filtroStatus.value;
        fetchRequisicoes(1);
    });

    clearFiltersBtn?.addEventListener('click', () => {
        const hadFilters = !!(state.filters.busca || state.filters.status);
        if (!hadFilters) return;
        state.filters.busca = '';
        state.filters.status = '';
        if (searchInput) searchInput.value = '';
        if (filtroStatus) filtroStatus.value = '';
        fetchRequisicoes(1);
    });

    // NOVO: Delegação de eventos para Aprovar / Rejeitar / Copiar Link
    if(tableBody){
        tableBody.addEventListener('click', (e)=>{
            const btnEdit = e.target.closest('.btn-action-edit');
            if (btnEdit) {
                e.preventDefault();
                const itemData = parseDatasetItem(btnEdit.dataset.item);
                ativarModoEdicao(itemData);
                return;
            }
            const btnDelete = e.target.closest('.btn-action-delete');
            if (btnDelete) {
                itemParaRemoverId = parseInt(btnDelete.dataset.id, 10) || null;
                itemParaRemoverNome = btnDelete.dataset.name || `${pageConfig.singular} #${itemParaRemoverId}`;
                if (modalItemName) modalItemName.textContent = itemParaRemoverNome;
                if (deleteError) {
                    deleteError.classList.add('d-none');
                    deleteError.textContent = '';
                }
                return;
            }

            const btnApprove = e.target.closest('.btn-action-approve');
            if(btnApprove){ e.preventDefault(); aprovarRequisicao(btnApprove.dataset.id); return; }
            const btnReject = e.target.closest('.btn-action-reject');
            if(btnReject){ e.preventDefault(); rejeitarRequisicao(btnReject.dataset.id); return; }
            const btnLink = e.target.closest('.btn-action-link');
            if(btnLink){ e.preventDefault(); gerarOuCopiarLink(btnLink.dataset.id); return; }
        });
    }

    if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', () => {
            if (itemParaRemoverId === null) {
                return;
            }
            if (deleteError) {
                deleteError.classList.add('d-none');
                deleteError.textContent = '';
            }
            const originalHtml = confirmDeleteBtn.innerHTML;
            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Excluindo';

            const requestUrl = pageConfig.endpoint + '?_method=DELETE';
            const payload = new URLSearchParams({ id: String(itemParaRemoverId) });

            fetch(requestUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: payload.toString()
            })
            .then(async response => {
                const raw = await response.text();
                let data = null;
                if (raw) {
                    try { data = JSON.parse(raw); } catch (_) { data = null; }
                }
                if (!response.ok || !data) {
                    const message = data?.erro || data?.error || (raw ? raw.replace(/<[^>]+>/g, '').slice(0, 160) : '');
                    throw new Error(message || `Erro ao remover (HTTP ${response.status})`);
                }
                return data;
            })
            .then(data => {
                if (data?.success) {
                    showToast(`${pageConfig.singular} removida com sucesso!`, 'success');
                    carregarDadosIniciais();
                    if (confirmationModal) confirmationModal.hide();
                    itemParaRemoverId = null;
                    itemParaRemoverNome = '';
                } else {
                    throw new Error(data?.erro || 'Erro ao remover.');
                }
            })
            .catch(err => {
                const message = err?.message || 'Erro ao remover.';
                showToast(message, 'danger');
                if (deleteError) {
                    deleteError.textContent = message;
                    deleteError.classList.remove('d-none');
                }
            })
            .finally(() => {
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.innerHTML = originalHtml;
            });
        });
    }

    // === NOVO: vincular abertura do modal para carregar itens da requisicao selecionada ===
    (function bindModalGerirItens(){
        const modalGerir = document.getElementById('modal-gerir-itens');
        if(!modalGerir) return;
        modalGerir.addEventListener('show.bs.modal', ev => {
            const trigger = ev.relatedTarget;
            if(trigger && trigger.dataset && trigger.dataset.id){
                const rid = trigger.dataset.id;
                // Limpa seleção anterior
                produtosSelecionados.clear(); atualizarResumoSelecao();
                searchProdutosModal.value='';
                carregarItensDaRequisicao(rid);
            }
        });
    })();

    // === NOVO: funcoes globais para remover e atualizar quantidade de itens existentes ===
    if(!window.atualizarQuantidade){
        window.atualizarQuantidade = async function(itemId, novaQtd){
            novaQtd = parseFloat(novaQtd);
            if(!itemId || !novaQtd || novaQtd <= 0){ showToast('Quantidade inválida.', 'warning'); return; }
            // itemId refere-se a registro requisicao_itens; precisamos descobrir produto_id
            // Buscar no DOM a li correspondente para evitar manter mapa separado (fallback)
            const payload = new URLSearchParams();
            payload.set('id', itemId);
            // Não alteramos produto_id (mantido o mesmo); backend exige -> tentamos obter via fetch leve se necessário
            // Para simplificar manter produto_id igual ao próprio itemId não é correto; então omitir e backend atual requer? Ele exige produto_id no PUT.
            let prodId = null;
            const candidato = produtosJaAdicionados.find(p => String(p.id) === String(itemId) );
            if(candidato){ prodId = candidato.id; }
            // fallback: usa primeiro item com mesmo index
            if(!prodId && produtosJaAdicionados.length){ prodId = produtosJaAdicionados[0].id; }
            if(!prodId){ showToast('Produto associado não encontrado.', 'danger'); return; }
            payload.set('produto_id', prodId);
            payload.set('quantidade', novaQtd);
            try{
                const res = await fetch('../api/requisicao_itens.php', { method:'PUT', body: payload.toString() });
                const out = await res.json();
                if(out && out.success){ showToast('Quantidade atualizada.', 'success'); }
                else { showToast(out.erro || 'Falha ao atualizar.', 'danger'); }
            }catch(_){ showToast('Erro de rede ao atualizar.', 'danger'); }
        };
    }
    if(!window.removerItem){
        window.removerItem = async function(itemId){
            if(!itemId) return;
            if(!confirm('Remover este item da requisição?')) return;
            const payload = new URLSearchParams(); payload.set('id', itemId);
            try{
                const res = await fetch('../api/requisicao_itens.php', { method:'DELETE', body: payload.toString() });
                const out = await res.json();
                if(out && out.success){ showToast('Item removido.', 'success'); if(requisicaoAtivaId) carregarItensDaRequisicao(requisicaoAtivaId); }
                else { showToast(out.erro || 'Falha ao remover.', 'danger'); }
            }catch(_){ showToast('Erro de rede ao remover.', 'danger'); }
        };
    }
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

// NOVO: helper robusto de cópia para área de transferência (com fallback)
async function copyToClipboard(text){
    if(!text) return false;
    try{
        if(navigator.clipboard && window.isSecureContext){
            await navigator.clipboard.writeText(text);
            return true;
        }
    }catch(_){ /* fallback abaixo */ }
    try{
        const ta = document.createElement('textarea');
        ta.value = text; ta.setAttribute('readonly','');
        ta.style.position='fixed'; ta.style.left='-9999px';
        document.body.appendChild(ta); ta.select();
        const ok = document.execCommand('copy'); ta.remove();
        return !!ok;
    }catch(_){ return false; }
}

function gerarOuCopiarLink(id){
        const endpoint = document.getElementById('page-container')?.dataset.endpoint || '../api/requisicoes.php';
        const payload = new URLSearchParams();
        payload.set('_action', 'tracking_link');
        payload.set('id', id);
        fetch(endpoint, {
            method:'POST',
            headers:{
                'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
                'Accept':'application/json'
            },
            credentials: 'same-origin',
            body: payload.toString()
        })
          .then(async r => {
              const data = await r.json().catch(() => null);
              if (r.status === 401) {
                  throw new Error('Sessão expirada. Faça login novamente.');
              }
              if(!r.ok || !data){
                  throw new Error(data?.erro || 'Falha ao gerar token.');
              }
              if(!data.success){
                  throw new Error(data.erro || 'Falha ao gerar token.');
              }
              return data;
          })
          .then(async d=>{ 
              const url = `${location.origin}${location.pathname.replace(/requisicoes\.php$/, '')}acompanhar_requisicao.php?token=${d.token}`;
              const ok = await copyToClipboard(url);
              showToast(ok ? 'Link copiado!' : 'Não foi possível copiar o link.', ok ? 'success' : 'warning');
          })
          .catch(err=> showToast(err?.message || 'Erro ao gerar token.','danger'));
    }

function escapeHtml(str){
    return String(str)
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}
</script>
</body>
</html>