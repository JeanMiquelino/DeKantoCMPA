<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php'; // added for role/type checking
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
// Restrict access for supplier or client accounts
$__u = auth_usuario();
if ($__u && in_array(($__u['tipo'] ?? ''), ['cliente','fornecedor'], true)) {
    if (($__u['tipo'] ?? '') === 'cliente') {
        header('Location: cliente/index.php');
    } else {
        header('Location: fornecedor/index.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">

    <style>
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
    data-endpoint="../api/produtos.php" 
    data-singular="Produto"
    data-plural="Produtos">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Produtos</h1>
            <p class="text-secondary mb-0">Adicione, edite e organize o seu catálogo de produtos.</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-importar">
                <i class="bi bi-upload me-2"></i>Importar
            </button>
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-exportar">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#modal-produto">
                <i class="bi bi-plus-circle me-2"></i>Novo Produto
            </button>
        </div>
    </header>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Produtos Cadastrados</span>
            <span class="text-secondary small">Use os filtros dentro da tabela para refinar os resultados.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>NCM</th>
                        <th>Unidade</th>
                        <th>Preço Base</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    <tr class="table-filters bg-body-secondary-subtle align-middle">
                        <th colspan="3">
                            <div class="input-group input-group-sm shadow-sm" style="min-width:260px">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-start-0" placeholder="Buscar por nome, código ou NCM" id="searchInput">
                            </div>
                        </th>
                        <th>
                            <select class="form-select form-select-sm" id="filtroUnidade">
                                <option value="">Todas as Unidades</option>
                            </select>
                        </th>
                        <th colspan="2" class="text-end">
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
            <div id="pagination-hint" class="text-secondary small">Carregando produtos...</div>
            <nav aria-label="Paginação de produtos" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-produto" tabindex="-1" aria-labelledby="modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content card-matrix">
            <div class="modal-header card-header-matrix">
                <h5 class="modal-title" id="modal-title"><i class="bi bi-plus-circle me-2"></i>Adicionar Novo Produto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body form-container-gestao">
                <form id="form-main" novalidate>
                    <div class="row g-3">
                        <div class="col-md-8"><label for="nome" class="form-label">Nome do Produto</label><div class="input-group"><span class="input-group-text"><i class="bi bi-box-seam"></i></span><input type="text" class="form-control" id="nome" name="nome" placeholder="Ex: Parafuso Sextavado M12" required></div></div>
                        <div class="col-md-4"><label for="ncm" class="form-label">NCM</label><div class="input-group"><span class="input-group-text"><i class="bi bi-hash"></i></span><input type="text" class="form-control" id="ncm" name="ncm" placeholder="Ex: 7318.15.00" required></div></div>
                        <div class="col-md-6"><label for="unidade" class="form-label">Unidade de Medida</label><select class="form-select" id="unidade" name="unidade"><option value="UN" selected>Unidade (UN)</option><option value="KG">Quilograma (KG)</option><option value="M">Metro (M)</option><option value="L">Litro (L)</option><option value="CX">Caixa (CX)</option></select></div>
                        <div class="col-md-6"><label for="preco_base" class="form-label">Preço Base (R$)</label><div class="input-group"><span class="input-group-text">R$</span><input type="number" step="0.01" class="form-control" id="preco_base" name="preco_base" placeholder="0,00" required></div></div>
                        <div class="col-12"><label for="descricao" class="form-label">Descrição</label><textarea class="form-control" id="descricao" name="descricao" rows="3" placeholder="Informações adicionais sobre o produto..."></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-matrix-primary" form="form-main">Salvar Produto</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-detalhes" tabindex="-1" aria-labelledby="modalDetalhesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalDetalhesLabel"><i class="bi bi-box-seam-fill me-2"></i>Detalhes do Produto</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="modal-detalhes-body"></div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger"><h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar Exclusão</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><p>Tem a certeza de que deseja excluir permanentemente o produto <strong id="modal-item-name" class="text-warning"></strong>?</p><p class="text-secondary small">Esta ação não pode ser desfeita.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-matrix-danger" id="confirm-delete-btn">Confirmar Exclusão</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-importar" tabindex="-1" aria-labelledby="modalImportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalImportarLabel"><i class="bi bi-upload me-2"></i>Importar Produtos</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form id="form-importar" enctype="multipart/form-data">
            <p class="text-secondary">O ficheiro deve estar no formato CSV ou XLSX e a primeira linha deve conter os cabeçalhos corretos.</p>
            <div class="mb-3">
                <a href="baixar_exemplo.php?modulo=produtos&formato=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .csv</a>
                <a href="baixar_exemplo.php?modulo=produtos&formato=xlsx" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .xlsx</a>
            </div>
            <div class="mb-3"><label for="arquivo-importacao" class="form-label">Ficheiro para importação</label><input class="form-control" type="file" id="arquivo-importacao" name="arquivo_importacao" accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required></div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-matrix-primary" form="form-importar" id="btn-confirmar-importacao"><span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span> Importar Agora</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-exportar" tabindex="-1" aria-labelledby="modalExportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalExportarLabel"><i class="bi bi-download me-2"></i>Exportar Produtos</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form id="form-exportar">
            <input type="hidden" name="modulo" value="produtos">
            <div class="mb-4">
                <label class="form-label">Formato do Ficheiro</label>
                <div class="export-options d-flex gap-3">
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-csv-produto" value="csv" checked><label class="form-check-label" for="export-csv-produto"><i class="bi bi-filetype-csv me-2"></i>CSV</label></div>
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-produto" value="xlsx"><label class="form-check-label" for="export-xlsx-produto"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label></div>
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-produto" value="pdf"><label class="form-check-label" for="export-pdf-produto"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label></div>
                </div>
            </div>
            <div>
                <label class="form-label">Intervalo de Dados</label>
                 <select class="form-select" name="intervalo">
                    <option value="todos">Todos os Produtos</option>
                    <option value="pagina_atual">Apenas a página atual</option>
                    <option value="filtrados">Apenas os resultados da busca</option>
                </select>
            </div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-matrix-primary" form="form-exportar">Exportar Agora</button></div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const debounce = (fn, delay = 350) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

const formatCurrencyBRL = (value) => {
    const num = Number(value);
    if (!Number.isFinite(num)) return 'R$ 0,00';
    return num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
};

document.addEventListener('DOMContentLoaded', () => {
    const pageContainer = document.getElementById('page-container');
    if (!pageContainer) return;

    const endpoint = pageContainer.dataset.endpoint;
    const singular = pageContainer.dataset.singular || 'Produto';
    const plural = pageContainer.dataset.plural || 'Produtos';
    const pluralLower = plural.toLowerCase();
    const importEndpoint = '../api/importador.php';
    const defaultPerPage = 15;

    const tableBody = document.getElementById('tabela-main-body');
    const searchInput = document.getElementById('searchInput');
    const filtroUnidade = document.getElementById('filtroUnidade');
    const clearFiltersBtn = document.getElementById('btn-clear-filtros');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const modalEl = document.getElementById('modal-produto');
    const modalTitle = document.getElementById('modal-title');
    const form = document.getElementById('form-main');
    const submitBtnGlobal = document.querySelector('#modal-produto .modal-footer button[type="submit"]');
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = confirmationModalEl ? new bootstrap.Modal(confirmationModalEl) : null;
    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const modalItemName = document.getElementById('modal-item-name');
    const modalDetalhesBody = document.getElementById('modal-detalhes-body');
    const formExportar = document.getElementById('form-exportar');
    const formImportar = document.getElementById('form-importar');
    const btnConfirmarImportacao = document.getElementById('btn-confirmar-importacao');
    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;

    let editandoId = null;
    let itemParaRemoverId = null;

    const state = {
        rows: [],
        meta: { page: 1, per_page: defaultPerPage, total: 0, total_pages: 1, unidade_options: [] },
        filters: { busca: '', unidade: '' },
        loading: false
    };

    const normalizeApiResponse = (payload) => {
        let rows = [];
        let meta = { ...state.meta };
        if (Array.isArray(payload)) {
            rows = payload;
            meta = {
                page: 1,
                per_page: rows.length || defaultPerPage,
                total: rows.length,
                total_pages: 1,
                unidade_options: meta.unidade_options
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
                unidade_options: metaRaw.unidade_options ?? meta.unidade_options
            };
        }
        return { rows, meta };
    };

    const setLoading = (isLoading) => {
        state.loading = isLoading;
        if (isLoading && tableBody) {
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Carregando ${pluralLower}...</td></tr>`;
        }
    };

    const preencherFiltroUnidade = (options = []) => {
        if (!filtroUnidade) return;
        const current = filtroUnidade.value;
        let html = '<option value="">Todas as Unidades</option>';
        const values = [];
        options.forEach(opt => {
            if (!opt || !opt.value) return;
            values.push(opt.value);
            html += `<option value="${opt.value}">${opt.label || opt.value}</option>`;
        });
        filtroUnidade.innerHTML = html;
        if (current && values.includes(current)) {
            filtroUnidade.value = current;
        } else if (current && current !== '') {
            filtroUnidade.insertAdjacentHTML('beforeend', `<option value="${current}" selected>${current}</option>`);
        }
    };

    const createRow = (item) => {
        const itemJson = JSON.stringify(item).replace(/'/g, '&apos;').replace(/"/g, '&quot;');
        return `<tr>
            <td><span class="font-monospace">#${item.id}</span></td>
            <td>${item.nome || '-'}</td>
            <td>${item.ncm || '-'}</td>
            <td>${item.unidade || '-'}</td>
            <td>${formatCurrencyBRL(item.preco_base)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-light btn-action-details" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-detalhes"><i class="bi bi-eye-fill"></i></button>
                <button class="btn btn-sm btn-outline-light btn-action-edit" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-produto"><i class="bi bi-pencil-fill"></i></button>
                <button class="btn btn-sm btn-outline-light btn-action-delete" data-id="${item.id}" data-name="${item.nome || 'Produto #'+item.id}"><i class="bi bi-trash-fill"></i></button>
            </td>
        </tr>`;
    };

    const renderRows = () => {
        if (!tableBody) return;
        if (!state.rows.length) {
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Nenhum ${singular.toLowerCase()} encontrado.</td></tr>`;
            return;
        }
        tableBody.innerHTML = state.rows.map(createRow).join('');
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
            paginationHint.textContent = `Nenhum ${singular.toLowerCase()} encontrado`;
            return;
        }
        const start = ((meta.page || 1) - 1) * (meta.per_page || defaultPerPage) + 1;
        const end = start + state.rows.length - 1;
        paginationHint.innerHTML = `Mostrando <strong>${start}-${end}</strong> de <strong>${meta.total}</strong> ${pluralLower}`;
    };

    const buildQueryParams = () => {
        const params = new URLSearchParams();
        params.set('page', state.meta.page || 1);
        params.set('per_page', state.meta.per_page || defaultPerPage);
        if (state.filters.busca) params.set('busca', state.filters.busca);
        if (state.filters.unidade) params.set('unidade', state.filters.unidade);
        return params;
    };

    const fetchProdutos = (pageOverride) => {
        if (pageOverride) state.meta.page = pageOverride;
        const params = buildQueryParams();
        setLoading(true);
        return fetch(`${endpoint}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(async res => {
                const data = await res.json().catch(() => null);
                if (!res.ok || !data) {
                    throw new Error(data?.erro || `Erro ao carregar ${pluralLower}.`);
                }
                const normalized = normalizeApiResponse(data);
                state.rows = Array.isArray(normalized.rows) ? normalized.rows : [];
                state.meta = normalized.meta;
                preencherFiltroUnidade(state.meta.unidade_options);
                renderRows();
                renderPagination();
                updatePaginationHint();
            })
            .catch(err => {
                if (tableBody) {
                    tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Erro ao carregar ${pluralLower}.</td></tr>`;
                }
                if (paginationHint) paginationHint.textContent = 'Erro ao carregar';
                if (paginationContainer) paginationContainer.innerHTML = '';
                showToast(err.message || `Erro ao carregar ${pluralLower}.`, 'danger');
                console.error(err);
            })
            .finally(() => setLoading(false));
    };

    paginationContainer?.addEventListener('click', e => {
        const btn = e.target.closest('button.page-link[data-page]');
        if (!btn) return;
        e.preventDefault();
        const page = parseInt(btn.dataset.page, 10);
        if (!page || page === state.meta.page) return;
        fetchProdutos(Math.max(1, page));
    });

    searchInput?.addEventListener('input', debounce(() => {
        state.filters.busca = searchInput.value.trim();
        fetchProdutos(1);
    }, 400));

    filtroUnidade?.addEventListener('change', () => {
        state.filters.unidade = filtroUnidade.value;
        fetchProdutos(1);
    });

    clearFiltersBtn?.addEventListener('click', () => {
        const hadFilters = !!(state.filters.busca || state.filters.unidade);
        if (searchInput) searchInput.value = '';
        if (filtroUnidade) filtroUnidade.value = '';
        state.filters.busca = '';
        state.filters.unidade = '';
        if (hadFilters) fetchProdutos(1);
    });

    const parseDatasetItem = (raw) => {
        if (!raw) return null;
        try {
            const safe = raw.replace(/&quot;/g, '"').replace(/&apos;/g, "'");
            return JSON.parse(safe);
        } catch (err) {
            return null;
        }
    };

    tableBody?.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const dataAttr = parseDatasetItem(btn.dataset.item);
        if (btn.classList.contains('btn-action-details') && dataAttr) {
            modalDetalhesBody.innerHTML = `
                <dl>
                    <div class="row"><dt class="col-sm-4">ID</dt><dd class="col-sm-8 font-monospace">#${dataAttr.id}</dd></div>
                    <div class="row"><dt class="col-sm-4">Nome</dt><dd class="col-sm-8">${dataAttr.nome || 'Não informado'}</dd></div>
                    <div class="row"><dt class="col-sm-4">NCM</dt><dd class="col-sm-8">${dataAttr.ncm || 'Não informado'}</dd></div>
                    <div class="row"><dt class="col-sm-4">Unidade</dt><dd class="col-sm-8">${dataAttr.unidade || 'Não informado'}</dd></div>
                    <div class="row"><dt class="col-sm-4">Preço Base</dt><dd class="col-sm-8">${formatCurrencyBRL(dataAttr.preco_base)}</dd></div>
                    <div class="row"><dt class="col-sm-4">Descrição</dt><dd class="col-sm-8">${dataAttr.descricao || 'Não informado'}</dd></div>
                </dl>`;
        } else if (btn.classList.contains('btn-action-edit') && dataAttr) {
            editandoId = dataAttr.id;
            Object.keys(dataAttr).forEach(key => {
                if (form.elements[key]) form.elements[key].value = dataAttr[key] ?? '';
            });
        } else if (btn.classList.contains('btn-action-delete')) {
            itemParaRemoverId = btn.dataset.id;
            modalItemName.textContent = btn.dataset.name || (`#${btn.dataset.id}`);
            confirmationModal?.show();
        }
    });

    form?.addEventListener('submit', e => {
        e.preventDefault();
        if (submitBtnGlobal) {
            if (submitBtnGlobal.disabled) return;
            submitBtnGlobal.disabled = true;
            submitBtnGlobal.dataset.oldHtml = submitBtnGlobal.innerHTML;
            submitBtnGlobal.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Salvando...';
        }
        const data = Object.fromEntries(new FormData(form).entries());
        const headers = { 'Accept': 'application/json' };
        let body;
        if (editandoId) {
            data.id = editandoId;
            data._action = 'update';
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            body = new URLSearchParams(data).toString();
        } else {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(data);
        }
        const reloadPage = editandoId ? state.meta.page : 1;
        fetch(endpoint, { method:'POST', headers, credentials: 'same-origin', body })
            .then(res => res.json().catch(() => ({ success: false, erro: 'Resposta inválida' })))
            .then(payload => {
                if (!payload.success) throw new Error(payload.erro || 'Erro ao salvar.');
                showToast(`${singular} ${editandoId ? 'atualizado' : 'adicionado'} com sucesso!`, 'success');
                bootstrap.Modal.getInstance(modalEl).hide();
                form.reset();
                editandoId = null;
                return fetchProdutos(reloadPage);
            })
            .catch(err => showToast(err.message, 'danger'))
            .finally(() => {
                if (submitBtnGlobal) {
                    submitBtnGlobal.disabled = false;
                    submitBtnGlobal.innerHTML = submitBtnGlobal.dataset.oldHtml || submitBtnGlobal.innerHTML;
                }
            });
    });

    modalEl?.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        if (btn && btn.classList.contains('btn-action-edit')) {
            modalTitle.innerHTML = `<i class="bi bi-pencil-square me-2"></i>Editar ${singular}`;
            if (submitBtnGlobal) submitBtnGlobal.innerHTML = `<i class="bi bi-check-circle me-2"></i>Atualizar ${singular}`;
        } else {
            editandoId = null;
            form.reset();
            modalTitle.innerHTML = `<i class="bi bi-plus-circle me-2"></i>Novo ${singular}`;
            if (submitBtnGlobal) submitBtnGlobal.innerHTML = `<i class="bi bi-check-circle me-2"></i>Salvar ${singular}`;
        }
    });

    confirmDeleteBtn?.addEventListener('click', () => {
        if (!itemParaRemoverId) return;
        const originalHtml = confirmDeleteBtn.innerHTML;
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Excluindo';
        const body = new URLSearchParams({ id: String(itemParaRemoverId), _action: 'delete' }).toString();
        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body
        })
        .then(res => res.json().catch(() => null).then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if (!ok || !data?.success) throw new Error(data?.erro || 'Erro ao remover.');
            showToast(`${singular} removido com sucesso!`, 'success');
            return fetchProdutos(state.meta.page);
        })
        .catch(err => showToast(err.message, 'danger'))
        .finally(() => {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = originalHtml;
            confirmationModal?.hide();
            itemParaRemoverId = null;
        });
    });

    if (formExportar) {
        const fetchIdsFiltrados = () => {
            const params = new URLSearchParams();
            params.set('mode', 'ids');
            if (state.filters.busca) params.set('busca', state.filters.busca);
            if (state.filters.unidade) params.set('unidade', state.filters.unidade);
            return fetch(`${endpoint}?${params.toString()}`, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(res => res.json().catch(() => null))
                .then(data => Array.isArray(data?.ids) ? data.ids.map(id => parseInt(id, 10)).filter(Boolean) : []);
        };

        formExportar.addEventListener('submit', async e => {
            e.preventDefault();
            const formato = formExportar.formato.value;
            const intervalo = formExportar.intervalo.value;
            let idsString = '';
            if (intervalo === 'pagina_atual') {
                const ids = state.rows.map(r => r.id).filter(Boolean);
                if (!ids.length) { showToast('Nenhum produto na página atual.', 'warning'); return; }
                idsString = ids.join(',');
            } else if (intervalo === 'filtrados') {
                const ids = await fetchIdsFiltrados();
                if (!ids.length) { showToast('Nenhum produto encontrado para exportar.', 'warning'); return; }
                idsString = ids.join(',');
            }
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            hiddenForm.action = 'exportador.php';
            hiddenForm.innerHTML = `
                <input type="hidden" name="modulo" value="produtos">
                <input type="hidden" name="formato" value="${formato}">
                <input type="hidden" name="intervalo" value="${intervalo}">
                ${idsString ? `<input type="hidden" name="ids" value="${idsString}">` : ''}`;
            document.body.appendChild(hiddenForm);
            hiddenForm.submit();
            hiddenForm.remove();
            showToast(`Gerando ${formato.toUpperCase()}...`, 'success');
            bootstrap.Modal.getInstance(document.getElementById('modal-exportar')).hide();
        });
    }

    if (formImportar) {
        formImportar.addEventListener('submit', e => {
            e.preventDefault();
            const arquivo = document.getElementById('arquivo-importacao');
            if (!arquivo?.files?.length) { showToast('Selecione um ficheiro.', 'warning'); return; }
            const fd = new FormData();
            fd.append('arquivo_importacao', arquivo.files[0]);
            fd.append('modulo', 'produtos');
            const spinner = btnConfirmarImportacao?.querySelector('.spinner-border');
            spinner?.classList.remove('d-none');
            if (btnConfirmarImportacao) btnConfirmarImportacao.disabled = true;
            fetch(importEndpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(res => res.json().catch(() => null).then(data => ({ ok: res.ok, data })))
                .then(({ ok, data }) => {
                    if (!ok || !data?.success) throw new Error(data?.erro || 'Erro ao importar.');
                    showToast(data.message || 'Importação concluída.', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('modal-importar')).hide();
                    formImportar.reset();
                    return fetchProdutos(state.meta.page);
                })
                .catch(err => {
                    showToast(err.message, 'danger');
                    console.error('Import produtos erro:', err);
                })
                .finally(() => {
                    spinner?.classList.add('d-none');
                    if (btnConfirmarImportacao) btnConfirmarImportacao.disabled = false;
                });
        });
    }

    fetchProdutos(1);
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