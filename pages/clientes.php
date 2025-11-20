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
    <title>Clientes - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
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
    data-endpoint="../api/clientes.php" 
    data-singular="Cliente"
    data-plural="Clientes">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Clientes</h1>
            <p class="text-secondary mb-0">Adicione, edite e organize a sua carteira de clientes.</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-importar">
                <i class="bi bi-upload me-2"></i>Importar
            </button>
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-exportar">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#modal-cliente">
                <i class="bi bi-plus-circle me-2"></i>Novo Cliente
            </button>
        </div>
    </header>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Clientes Cadastrados</span>
            <span class="text-secondary small">Use os filtros dentro da tabela para refinar os resultados.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome / Razão Social</th>
                        <th>CPF / CNPJ</th>
                        <th>E-mail</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    <tr class="table-filters bg-body-secondary-subtle align-middle">
                        <th colspan="3">
                            <div class="input-group input-group-sm shadow-sm" style="min-width:260px">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-start-0" placeholder="Buscar por nome, CPF/CNPJ..." id="searchInput">
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
            <div id="pagination-hint" class="text-secondary small">Carregando clientes...</div>
            <nav aria-label="Paginação de clientes" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-cliente" tabindex="-1" aria-labelledby="modal-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content card-matrix">
        <div class="modal-header card-header-matrix">
            <h5 class="modal-title" id="modal-title"><i class="bi bi-plus-circle me-2"></i>Adicionar Novo Cliente</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body form-container-gestao">
            <form id="form-main" novalidate>
                <input type="hidden" name="usuario_id" id="usuario_id" value="">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label for="razao_social" class="form-label">Nome / Razão Social</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                            <input type="text" class="form-control" id="razao_social" name="razao_social" placeholder="Nome completo ou Razão Social" required>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <label for="nome_fantasia" class="form-label">Nome Fantasia</label>
                        <div class="input-group">
                             <span class="input-group-text"><i class="bi bi-tag-fill"></i></span>
                            <input type="text" class="form-control" id="nome_fantasia" name="nome_fantasia" placeholder="Apelido ou nome fantasia">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="cnpj" class="form-label">CPF / CNPJ</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-card-heading"></i></span>
                            <input type="text" class="form-control" id="cnpj" name="cnpj" placeholder="00.000.000/0001-00" required maxlength="18">
                        </div>
                    </div>
                     <div class="col-md-6">
                        <label for="ie" class="form-label">IE / RG</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-file-text"></i></span>
                            <input type="text" class="form-control" id="ie" name="ie" placeholder="Inscrição Estadual ou RG">
                        </div>
                    </div>
                    <div class="col-12">
                         <label for="endereco" class="form-label">Endereço</label>
                         <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-geo-alt-fill"></i></span>
                            <input type="text" class="form-control" id="endereco" name="endereco" placeholder="Rua, Número, Bairro, Cidade - Estado">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">E-mail</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-envelope-fill"></i></span>
                            <input type="email" class="form-control" id="email" name="email" placeholder="contato@cliente.com">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="telefone" class="form-label">Telefone</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-telephone-fill"></i></span>
                            <input type="text" class="form-control" id="telefone" name="telefone" placeholder="(00) 90000-0000" maxlength="15">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="status" class="form-label">Status</label>
                        <select class="d-none" id="status" name="status">
                            <option value="ativo" selected>Ativo</option>
                            <option value="inativo">Inativo</option>
                        </select>
                        <div class="dropdown" id="custom-status-dropdown">
                            <button class="form-select text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="status-dropdown-button">Ativo</button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" data-value="ativo"><i class="bi bi-check-circle-fill text-success me-2"></i>Ativo</a></li>
                                <li><a class="dropdown-item" href="#" data-value="inativo"><i class="bi bi-x-circle-fill text-danger me-2"></i>Inativo</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Acesso ao Portal do Cliente -->
                    <div class="col-12 pt-2" id="portal-access-fields">
                        <hr>
                        <div class="d-flex align-items-center justify-content-between">
                            <h6 class="mb-2"><i class="bi bi-shield-lock-fill me-2"></i>Acesso ao Portal do Cliente</h6>
                            <span class="text-secondary small">Um usuário será criado para acesso ao portal.</span>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="usuario_nome" class="form-label">Nome do Usuário</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                    <input type="text" class="form-control" id="usuario_nome" name="usuario_nome" placeholder="Nome do contato" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="usuario_email" class="form-label">E-mail de Acesso</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-at"></i></span>
                                    <input type="email" class="form-control" id="usuario_email" name="usuario_email" placeholder="email@cliente.com" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="senha_inicial" class="form-label">Senha Inicial</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                                    <input type="text" class="form-control" id="senha_inicial" name="senha_inicial" placeholder="Mínimo 3 caracteres" minlength="3">
                                </div>
                                <div class="form-text">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Deixe em branco para gerar automaticamente. A senha será enviada por e-mail.
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Fim Acesso ao Portal do Cliente -->
                </div>
            </form>
        </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-main" id="btn-submit">Salvar Cliente</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-detalhes" tabindex="-1" aria-labelledby="modalDetalhesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalDetalhesLabel"><i class="bi bi-person-vcard-fill me-2"></i>Detalhes do Cliente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="modal-detalhes-body">
        </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger">
        <h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar Exclusão</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Tem a certeza de que deseja excluir permanentemente o cliente <strong id="modal-item-name" class="text-warning"></strong>?</p>
        <p class="text-secondary small">Esta ação não pode ser desfeita.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-matrix-danger" id="confirm-delete-btn">Confirmar Exclusão</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-importar" tabindex="-1" aria-labelledby="modalImportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalImportarLabel"><i class="bi bi-upload me-2"></i>Importar Clientes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-importar" enctype="multipart/form-data">
            <p class="text-secondary">
                O ficheiro deve estar no formato CSV ou XLSX e a primeira linha deve conter os cabeçalhos corretos.
            </p>
            <div class="mb-3">
                <a href="baixar_exemplo.php?modulo=clientes&formato=csv" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .csv
                </a>
                <a href="baixar_exemplo.php?modulo=clientes&formato=xlsx" class="btn btn-sm btn-outline-secondary">
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
            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
            Importar Agora
        </button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-exportar" tabindex="-1" aria-labelledby="modalExportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalExportarLabel"><i class="bi bi-download me-2"></i>Exportar Clientes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <form id="form-exportar">
            <input type="hidden" name="modulo" value="clientes">
            <div class="mb-4">
              <label class="form-label">Formato do Ficheiro</label>
              <div class="export-options d-flex gap-3">
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-csv-cliente" value="csv" checked>
                  <label class="form-check-label" for="export-csv-cliente"><i class="bi bi-filetype-csv me-2"></i>CSV</label>
                </div>
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-cliente" value="xlsx">
                  <label class="form-check-label" for="export-xlsx-cliente"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label>
                </div>
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-cliente" value="pdf">
                  <label class="form-check-label" for="export-pdf-cliente"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label>
                </div>
              </div>
            </div>
            <div>
              <label class="form-label">Intervalo de Dados</label>
              <select class="form-select" name="intervalo">
                <option value="todos">Todos os Clientes</option>
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
const debounce = (fn, delay = 300) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

function formatCNPJ(v) {
    v = v.replace(/\D/g, "");
    v = v.replace(/^(\d{2})(\d)/, "$1.$2");
    v = v.replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3");
    v = v.replace(/\.(\d{3})(\d)/, ".$1/$2");
    v = v.replace(/(\d{4})(\d)/, "$1-$2");
    return v.slice(0, 18);
}

function formatCPF(v) {
    v = v.replace(/\D/g, "");
    v = v.replace(/(\d{3})(\d)/, "$1.$2");
    v = v.replace(/(\d{3})(\d)/, "$1.$2");
    v = v.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
    return v.slice(0, 14);
}

function formatTelefone(v) {
    v = v.replace(/\D/g, "");
    v = v.replace(/^(\d{2})(\d)/, "($1) $2");
    v = v.replace(/(\d)(\d{4})$/, "$1-$2");
    return v.slice(0, 15);
}

function showToast(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;
    const toastId = 'toast-' + Date.now();
    toastContainer.insertAdjacentHTML('beforeend', `
        <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button>
            </div>
        </div>`);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3500 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}

document.addEventListener('DOMContentLoaded', () => {
    const pageContainer = document.getElementById('page-container');
    if(!pageContainer) return;

    const endpoint = pageContainer.dataset.endpoint;
    const singular = pageContainer.dataset.singular || 'Cliente';
    const plural = pageContainer.dataset.plural || 'Clientes';
    const pluralLower = plural.toLowerCase();
    const importEndpoint = '../api/importador.php';
    const defaultPerPage = 15;

    const tableBody = document.getElementById('tabela-main-body');
    const searchInput = document.getElementById('searchInput');
    const filtroStatus = document.getElementById('filtroStatus');
    const clearFiltersBtn = document.getElementById('btn-clear-filtros');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const modalEl = document.getElementById('modal-cliente');
    const modalTitle = document.getElementById('modal-title');
    const form = document.getElementById('form-main');
    const submitBtnGlobal = document.getElementById('btn-submit');
    const statusDropdownButton = document.getElementById('status-dropdown-button');
    const hiddenStatusSelect = document.getElementById('status');
    const dropdownItems = document.querySelectorAll('#custom-status-dropdown .dropdown-item');
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = confirmationModalEl ? new bootstrap.Modal(confirmationModalEl) : null;
    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const modalItemName = document.getElementById('modal-item-name');
    const modalDetalhesBody = document.getElementById('modal-detalhes-body');
    const formExportar = document.getElementById('form-exportar');
    const formImportar = document.getElementById('form-importar');
    const btnConfirmarImportacao = document.getElementById('btn-confirmar-importacao');
    const portalFields = document.getElementById('portal-access-fields');
    const usuarioIdInput = document.getElementById('usuario_id');
    const usuarioNomeInput = document.getElementById('usuario_nome');
    const usuarioEmailInput = document.getElementById('usuario_email');
    const senhaInicialInput = document.getElementById('senha_inicial');
    const cpfCnpjInput = document.getElementById('cnpj');
    const telefoneInput = document.getElementById('telefone');

    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;

    let editandoId = null;
    let itemParaRemoverId = null;

    const state = {
        rows: [],
        meta: { page:1, per_page:defaultPerPage, total:0, total_pages:1, status_options:[] },
        filters: { busca:'', status:'' },
        loading: false
    };

    const STATUS_LABELS = { ativo:'Ativo', inativo:'Inativo' };
    const humanizeStatus = (v) => {
        if(!v) return '-';
        return (v+'').replace(/_/g,' ').replace(/^.|\s\w/g, s => s.toUpperCase());
    };
    const statusLabel = (v) => STATUS_LABELS[v] || humanizeStatus(v);

    const normalizeApiResponse = (payload) => {
        let rows = [];
        let meta = {
            page: state.meta.page || 1,
            per_page: state.meta.per_page || defaultPerPage,
            total: state.meta.total || 0,
            total_pages: state.meta.total_pages || 1,
            status_options: state.meta.status_options || []
        };
        if(Array.isArray(payload)){
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
        if(payload && typeof payload === 'object'){
            if(Array.isArray(payload.data)) rows = payload.data;
            else if(Array.isArray(payload.rows)) rows = payload.rows;
            else if(Array.isArray(payload.lista)) rows = payload.lista;

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
        if(isLoading && tableBody){
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Carregando ${pluralLower}...</td></tr>`;
        }
    };

    const statusBadge = (status) => {
        const val = (status||'').toLowerCase();
        const map = {
            ativo: { icon:'bi-check-circle-fill', cls:'text-success' },
            inativo: { icon:'bi-x-circle-fill', cls:'text-danger' }
        };
        const cfg = map[val] || { icon:'bi-dot', cls:'text-secondary' };
        return `<i class="bi ${cfg.icon} ${cfg.cls} me-2"></i>${statusLabel(val)}`;
    };

    const createRow = (item) => {
        const itemJson = JSON.stringify(item).replace(/'/g,'&apos;').replace(/"/g,'&quot;');
        const acessoBtn = item.usuario_id
            ? `<a class="btn btn-sm btn-outline-warning btn-action-access" href="usuarios.php?open_id=${item.usuario_id}" title="Gerenciar acesso" target="_blank"><i class="bi bi-person-lock"></i></a>`
            : '';
        return `<tr>
            <td><span class="font-monospace">#${item.id}</span></td>
            <td>${item.razao_social || '-'}</td>
            <td>${item.cnpj || '-'}</td>
            <td>${item.email || '-'}</td>
            <td>${statusBadge(item.status)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-light btn-action-details" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-detalhes"><i class="bi bi-eye-fill"></i></button>
                ${acessoBtn}
                <button class="btn btn-sm btn-outline-light btn-action-edit" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-cliente"><i class="bi bi-pencil-fill"></i></button>
                <button class="btn btn-sm btn-outline-light btn-action-delete" data-id="${item.id}" data-name="${item.razao_social || 'Cliente #'+item.id}"><i class="bi bi-trash-fill"></i></button>
            </td>
        </tr>`;
    };

    const renderRows = () => {
        if(!tableBody) return;
        if(!state.rows.length){
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Nenhum ${singular.toLowerCase()} encontrado.</td></tr>`;
            return;
        }
        tableBody.innerHTML = state.rows.map(createRow).join('');
    };

    const createPageItem = (label, targetPage, { disabled=false, active=false } = {}) => {
        const classes = ['page-item'];
        if(disabled) classes.push('disabled');
        if(active) classes.push('active');
        const content = disabled
            ? `<span class="page-link">${label}</span>`
            : `<button type="button" class="page-link" data-page="${targetPage}">${label}</button>`;
        return `<li class="${classes.join(' ')}">${content}</li>`;
    };

    const renderPagination = () => {
        if(!paginationContainer) return;
        const meta = state.meta || {};
        const total = Number(meta.total) || 0;
        const perPage = Number(meta.per_page) || defaultPerPage;
        const totalPages = Math.max(1, Number(meta.total_pages) || Math.ceil((total || 1) / perPage));
        const current = Math.min(Math.max(1, Number(meta.page) || 1), totalPages);
        if(total === 0){
            paginationContainer.innerHTML = '';
            return;
        }
        const items = [];
        items.push(createPageItem('«', 1, { disabled: current === 1 }));
        items.push(createPageItem('‹', Math.max(1, current - 1), { disabled: current === 1 }));
        const windowSize = 5;
        let startPage = Math.max(1, current - Math.floor(windowSize / 2));
        let endPage = startPage + windowSize - 1;
        if(endPage > totalPages){
            endPage = totalPages;
            startPage = Math.max(1, endPage - windowSize + 1);
        }
        for(let i=startPage; i<=endPage; i++){
            items.push(createPageItem(String(i), i, { active: i === current }));
        }
        items.push(createPageItem('›', Math.min(totalPages, current + 1), { disabled: current === totalPages }));
        items.push(createPageItem('»', totalPages, { disabled: current === totalPages }));
        paginationContainer.innerHTML = items.join('');
    };

    const updatePaginationHint = () => {
        if(!paginationHint) return;
        const meta = state.meta || {};
        if(!meta.total){
            paginationHint.textContent = 'Nenhum cliente encontrado';
            return;
        }
        const start = ((meta.page || 1) - 1) * (meta.per_page || defaultPerPage) + 1;
        const end = start + state.rows.length - 1;
        paginationHint.innerHTML = `Mostrando <strong>${start}-${end}</strong> de <strong>${meta.total}</strong> ${pluralLower}`;
    };

    const preencherFiltroStatus = (options=[]) => {
        if(!filtroStatus) return;
        const current = filtroStatus.value;
        let html = '<option value="">Todos os Status</option>';
        const values = [];
        options.forEach(opt => {
            if(!opt || !opt.value) return;
            values.push(opt.value);
            html += `<option value="${opt.value}">${opt.label || statusLabel(opt.value)}</option>`;
        });
        filtroStatus.innerHTML = html;
        if(current && values.includes(current)){
            filtroStatus.value = current;
        } else if(current && current !== ''){
            filtroStatus.insertAdjacentHTML('beforeend', `<option value="${current}" selected>${statusLabel(current)}</option>`);
            filtroStatus.value = current;
        }
    };

    const buildQueryParams = () => {
        const params = new URLSearchParams();
        params.set('page', state.meta.page || 1);
        params.set('per_page', state.meta.per_page || defaultPerPage);
        if(state.filters.busca) params.set('busca', state.filters.busca);
        if(state.filters.status) params.set('status', state.filters.status);
        return params;
    };

    const fetchClientes = (pageOverride) => {
        if(pageOverride) state.meta.page = pageOverride;
        const params = buildQueryParams();
        setLoading(true);
        return fetch(`${endpoint}?${params.toString()}`, { headers:{'Accept':'application/json'}, credentials:'same-origin' })
            .then(async res => {
                const data = await res.json().catch(() => null);
                if(!res.ok || !data){
                    throw new Error(data?.erro || `Erro ao carregar ${pluralLower}`);
                }
                const normalized = normalizeApiResponse(data);
                state.rows = Array.isArray(normalized.rows) ? normalized.rows : [];
                state.meta = normalized.meta;
                preencherFiltroStatus(state.meta.status_options);
                renderRows();
                renderPagination();
                updatePaginationHint();
            })
            .catch(err => {
                if(tableBody){
                    tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Erro ao carregar ${pluralLower}.</td></tr>`;
                }
                if(paginationHint) paginationHint.textContent = 'Erro ao carregar';
                if(paginationContainer) paginationContainer.innerHTML='';
                showToast(err.message || `Erro ao carregar ${pluralLower}`,'danger');
                console.error(err);
            })
            .finally(() => setLoading(false));
    };

    paginationContainer?.addEventListener('click', e => {
        const btn = e.target.closest('button.page-link[data-page]');
        if(!btn) return;
        e.preventDefault();
        const page = parseInt(btn.dataset.page, 10);
        if(!page || page === state.meta.page) return;
        fetchClientes(Math.max(1,page));
    });

    searchInput?.addEventListener('input', debounce(() => {
        state.filters.busca = searchInput.value.trim();
        fetchClientes(1);
    }, 400));

    filtroStatus?.addEventListener('change', () => {
        state.filters.status = filtroStatus.value;
        fetchClientes(1);
    });

    clearFiltersBtn?.addEventListener('click', () => {
        const hadFilters = !!(state.filters.busca || state.filters.status);
        if(searchInput) searchInput.value = '';
        if(filtroStatus) filtroStatus.value = '';
        state.filters.busca = '';
        state.filters.status = '';
        if(hadFilters){ fetchClientes(1); }
    });

    const parseDatasetItem = (raw) => {
        if(!raw) return null;
        try {
            const safe = raw.replace(/&quot;/g,'"').replace(/&apos;/g,"'");
            return JSON.parse(safe);
        } catch(err){ return null; }
    };

    const updateStatusDisplay = (value) => {
        const item = document.querySelector(`#custom-status-dropdown .dropdown-item[data-value="${value}"]`);
        if(item){
            statusDropdownButton.innerHTML = item.innerHTML;
            hiddenStatusSelect.value = value;
        }
    };

    tableBody?.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if(!btn) return;
        const dataAttr = parseDatasetItem(btn.dataset.item);
        if(btn.classList.contains('btn-action-details') && dataAttr){
            const acessoHtml = dataAttr.usuario_id
                ? `<div class="row"><dt class="col-sm-4">Acesso Portal</dt><dd class="col-sm-8"><span class="badge bg-warning text-dark"><i class="bi bi-person-lock me-1"></i> Usuário #${dataAttr.usuario_id}${dataAttr.email? ' • '+dataAttr.email:''}</span> <a class="ms-2 small" href="usuarios.php?open_id=${dataAttr.usuario_id}" target="_blank">Gerenciar</a></dd></div>`
                : `<div class="row"><dt class="col-sm-4">Acesso Portal</dt><dd class="col-sm-8 text-secondary">Sem usuário vinculado</dd></div>`;
            modalDetalhesBody.innerHTML = `
                <dl>
                    <div class='row'><dt class='col-sm-4'>ID</dt><dd class='col-sm-8 font-monospace'>#${dataAttr.id}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Nome / Razão Social</dt><dd class='col-sm-8'>${dataAttr.razao_social||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Nome Fantasia</dt><dd class='col-sm-8'>${dataAttr.nome_fantasia||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>CPF / CNPJ</dt><dd class='col-sm-8'>${dataAttr.cnpj||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>IE / RG</dt><dd class='col-sm-8'>${dataAttr.ie||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Endereço</dt><dd class='col-sm-8'>${dataAttr.endereco||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Telefone</dt><dd class='col-sm-8'>${dataAttr.telefone||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>E-mail</dt><dd class='col-sm-8'>${dataAttr.email||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Status</dt><dd class='col-sm-8'>${statusLabel(dataAttr.status)}</dd></div>
                    ${acessoHtml}
                </dl>`;
        } else if(btn.classList.contains('btn-action-edit') && dataAttr){
            editandoId = dataAttr.id;
            Object.keys(dataAttr).forEach(k => { if(form.elements[k]) form.elements[k].value = dataAttr[k] ?? ''; });
            updateStatusDisplay(dataAttr.status);
            if(usuarioIdInput){ usuarioIdInput.value = dataAttr.usuario_id || ''; }
            if(portalFields){
                portalFields.classList.add('d-none');
                if(usuarioNomeInput){ usuarioNomeInput.required=false; usuarioNomeInput.value=''; }
                if(usuarioEmailInput){ usuarioEmailInput.required=false; usuarioEmailInput.value=''; }
                if(senhaInicialInput) senhaInicialInput.value='';
            }
        } else if(btn.classList.contains('btn-action-delete')){
            itemParaRemoverId = btn.dataset.id;
            modalItemName.textContent = btn.dataset.name || ('#'+btn.dataset.id);
            confirmationModal?.show();
        }
    });

    const razaoSocialInput = document.getElementById('razao_social');
    const emailClienteInput = document.getElementById('email');
    if(razaoSocialInput && usuarioNomeInput){
        razaoSocialInput.addEventListener('blur', () => {
            if(!editandoId && !usuarioNomeInput.value){
                usuarioNomeInput.value = razaoSocialInput.value;
            }
        });
    }
    if(emailClienteInput && usuarioEmailInput){
        emailClienteInput.addEventListener('blur', () => {
            if(!editandoId && !usuarioEmailInput.value){
                usuarioEmailInput.value = emailClienteInput.value;
            }
        });
    }

    form?.addEventListener('submit', e => {
        e.preventDefault();
        if(submitBtnGlobal){
            if(submitBtnGlobal.disabled) return;
            submitBtnGlobal.disabled = true;
            submitBtnGlobal.dataset.oldHtml = submitBtnGlobal.innerHTML;
            submitBtnGlobal.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Salvando...';
        }
        const data = Object.fromEntries(new FormData(form).entries());
        if(!editandoId){
            if(!data.usuario_nome || !data.usuario_email){
                showToast('Informe Nome do Usuário e E-mail de Acesso.','warning');
                if(submitBtnGlobal){ submitBtnGlobal.disabled=false; submitBtnGlobal.innerHTML = submitBtnGlobal.dataset.oldHtml; }
                return;
            }
            if(data.senha_inicial && data.senha_inicial.length < 3){
                showToast('A senha inicial deve ter pelo menos 3 caracteres.','warning');
                if(submitBtnGlobal){ submitBtnGlobal.disabled=false; submitBtnGlobal.innerHTML = submitBtnGlobal.dataset.oldHtml; }
                return;
            }
        }
        const headers = { 'Accept':'application/json' };
        let body;
        let targetUrl = endpoint;
        if(editandoId){
            data.id = editandoId;
            data._action = 'update';
            headers['Content-Type'] = 'application/x-www-form-urlencoded';
            body = new URLSearchParams(data).toString();
        } else {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(data);
        }
        const reloadPage = editandoId ? state.meta.page : 1;
        fetch(targetUrl, { method:'POST', headers, credentials:'same-origin', body })
            .then(res => res.json().catch(()=>({ success:false, erro:'Resposta inválida' })))
            .then(payload => {
                if(!payload.success){ throw new Error(payload.erro || 'Erro ao salvar.'); }
                let msg = `${singular} ${editandoId ? 'atualizado' : 'adicionado'} com sucesso!`;
                if(!editandoId && (payload.email_senha_enviado || payload.email_notificacao_enviado)){
                    msg += ' Notificações enviadas por e-mail.';
                }
                showToast(msg,'success');
                bootstrap.Modal.getInstance(modalEl).hide();
                form.reset();
                editandoId = null;
                updateStatusDisplay('ativo');
                if(portalFields){
                    portalFields.classList.remove('d-none');
                    if(usuarioNomeInput) usuarioNomeInput.required = true;
                    if(usuarioEmailInput) usuarioEmailInput.required = true;
                }
                return fetchClientes(reloadPage);
            })
            .catch(err => showToast(err.message,'danger'))
            .finally(() => {
                if(submitBtnGlobal){
                    submitBtnGlobal.disabled = false;
                    submitBtnGlobal.innerHTML = submitBtnGlobal.dataset.oldHtml;
                }
            });
    });

    modalEl?.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        if(btn && btn.classList.contains('btn-action-edit')){
            modalTitle.innerHTML = `<i class="bi bi-pencil-square me-2"></i>Editar ${singular}`;
            submitBtnGlobal.innerHTML = `<i class="bi bi-check-circle me-2"></i>Atualizar ${singular}`;
        } else {
            editandoId = null;
            form.reset();
            updateStatusDisplay('ativo');
            if(usuarioIdInput){ usuarioIdInput.value = ''; }
            modalTitle.innerHTML = `<i class="bi bi-plus-circle me-2"></i>Novo ${singular}`;
            submitBtnGlobal.innerHTML = `<i class="bi bi-check-circle me-2"></i>Salvar ${singular}`;
            if(portalFields){
                portalFields.classList.remove('d-none');
                if(usuarioNomeInput) usuarioNomeInput.required = true;
                if(usuarioEmailInput) usuarioEmailInput.required = true;
            }
        }
    });

    confirmDeleteBtn?.addEventListener('click', () => {
        if(!itemParaRemoverId) return;
        const originalHtml = confirmDeleteBtn.innerHTML;
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Excluindo';
        const body = new URLSearchParams({ id: String(itemParaRemoverId), _action: 'delete' }).toString();
        fetch(endpoint, {
            method: 'POST',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded', 'Accept':'application/json' },
            credentials:'same-origin',
            body
        })
        .then(res => res.json().catch(()=>null).then(data => ({ ok: res.ok, data })))
        .then(({ ok, data }) => {
            if(!ok || !data?.success){ throw new Error(data?.erro || 'Erro ao remover.'); }
            showToast(`${singular} removido com sucesso!`,'success');
            return fetchClientes(state.meta.page);
        })
        .catch(err => showToast(err.message,'danger'))
        .finally(() => {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = originalHtml;
            confirmationModal?.hide();
            itemParaRemoverId = null;
        });
    });

    dropdownItems.forEach(item => item.addEventListener('click', e => {
        e.preventDefault();
        updateStatusDisplay(item.dataset.value);
    }));

    if(formExportar){
        const fetchIdsFiltrados = () => {
            const params = new URLSearchParams();
            params.set('mode','ids');
            if(state.filters.busca) params.set('busca', state.filters.busca);
            if(state.filters.status) params.set('status', state.filters.status);
            return fetch(`${endpoint}?${params.toString()}`, { headers:{'Accept':'application/json'}, credentials:'same-origin' })
                .then(res => res.json().catch(()=>null))
                .then(data => Array.isArray(data?.ids) ? data.ids.map(id => parseInt(id,10)).filter(Boolean) : []);
        };

        formExportar.addEventListener('submit', async e => {
            e.preventDefault();
            const formato = formExportar.formato.value;
            const intervalo = formExportar.intervalo.value;
            let idsString = '';
            if(intervalo === 'pagina_atual'){
                const ids = state.rows.map(r => r.id).filter(Boolean);
                if(!ids.length){ showToast('Nenhum cliente na página atual.','warning'); return; }
                idsString = ids.join(',');
            } else if(intervalo === 'filtrados'){
                const ids = await fetchIdsFiltrados();
                if(!ids.length){ showToast('Nenhum cliente encontrado para exportar.','warning'); return; }
                idsString = ids.join(',');
            }
            const hiddenForm = document.createElement('form');
            hiddenForm.method = 'POST';
            hiddenForm.action = 'exportador.php';
            hiddenForm.innerHTML = `
                <input type="hidden" name="modulo" value="clientes">
                <input type="hidden" name="formato" value="${formato}">
                <input type="hidden" name="intervalo" value="${intervalo}">
                ${idsString ? `<input type="hidden" name="ids" value="${idsString}">` : ''}`;
            document.body.appendChild(hiddenForm);
            hiddenForm.submit();
            hiddenForm.remove();
            showToast(`Gerando ${formato.toUpperCase()}...`,'success');
            bootstrap.Modal.getInstance(document.getElementById('modal-exportar')).hide();
        });
    }

    if(formImportar){
        formImportar.addEventListener('submit', e => {
            e.preventDefault();
            const arquivo = document.getElementById('arquivo-importacao');
            if(!arquivo?.files?.length){ showToast('Selecione um ficheiro.','warning'); return; }
            const fd = new FormData();
            fd.append('arquivo_importacao', arquivo.files[0]);
            fd.append('modulo', 'clientes');
            const spinner = btnConfirmarImportacao?.querySelector('.spinner-border');
            spinner?.classList.remove('d-none');
            if(btnConfirmarImportacao) btnConfirmarImportacao.disabled = true;
            fetch(importEndpoint, { method:'POST', body:fd, credentials:'same-origin' })
                .then(res => res.json().catch(()=>null).then(data => ({ ok: res.ok, data })))
                .then(({ ok, data }) => {
                    if(!ok || !data?.success){ throw new Error(data?.erro || 'Erro ao importar.'); }
                    showToast(data.message || 'Importação concluída.','success');
                    bootstrap.Modal.getInstance(document.getElementById('modal-importar')).hide();
                    formImportar.reset();
                    return fetchClientes(state.meta.page);
                })
                .catch(err => {
                    showToast(err.message,'danger');
                    console.error('Import error:', err);
                })
                .finally(() => {
                    spinner?.classList.add('d-none');
                    if(btnConfirmarImportacao) btnConfirmarImportacao.disabled = false;
                });
        });
    }

    cpfCnpjInput?.addEventListener('input', e => {
        const digits = e.target.value.replace(/\D/g,'');
        e.target.value = digits.length > 11 ? formatCNPJ(digits) : formatCPF(digits);
    });
    telefoneInput?.addEventListener('input', e => {
        e.target.value = formatTelefone(e.target.value || '');
    });

    fetchClientes(1);
});
</script>

</body>
</html>