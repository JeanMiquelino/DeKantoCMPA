<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php';
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
// Restringir acesso: apenas usuarios internos (nem cliente nem fornecedor)
$__u = auth_usuario();
if($__u && in_array(($__u['tipo']??''), ['cliente','fornecedor'], true)){
    if(($__u['tipo']??'') === 'cliente'){
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
    <title>Fornecedores - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
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
<body
><?php include 'navbar.php'; ?>

<div class="container py-4" id="page-container" 
    data-endpoint="../api/fornecedores.php" 
    data-singular="Fornecedor"
    data-plural="Fornecedores">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Fornecedores</h1>
            <p class="text-secondary mb-0">Adicione, edite e organize os seus parceiros de negócio.</p>
        </div>
        <div class="header-actions">
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-importar">
                <i class="bi bi-upload me-2"></i>Importar
            </button>
            <button class="btn btn-matrix-secondary-outline" data-bs-toggle="modal" data-bs-target="#modal-exportar">
                <i class="bi bi-download me-2"></i>Exportar
            </button>
            <button class="btn btn-matrix-primary" data-bs-toggle="modal" data-bs-target="#modal-fornecedor">
                <i class="bi bi-plus-circle me-2"></i>Novo Fornecedor
            </button>
        </div>
    </header>

    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Fornecedores Cadastrados</span>
            <span class="text-secondary small">Use os filtros diretamente na tabela para refinar os resultados.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Razão Social</th>
                        <th>Nome Fantasia</th>
                        <th>CNPJ</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                    <tr class="table-filters bg-body-secondary-subtle align-middle">
                        <th colspan="3">
                            <div class="input-group input-group-sm shadow-sm" style="min-width:260px">
                                <span class="input-group-text bg-transparent border-end-0 text-secondary"><i class="bi bi-search"></i></span>
                                <input type="text" class="form-control border-start-0" placeholder="Buscar por nome, CNPJ..." id="searchInput">
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
            <div id="pagination-hint" class="text-secondary small">Carregando fornecedores...</div>
            <nav aria-label="Paginação de fornecedores" data-bs-theme="light" class="ms-md-auto">
                <ul id="pagination-container" class="pagination pagination-sm mb-0 justify-content-end pagination-matrix"></ul>
            </nav>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-fornecedor" tabindex="-1" aria-labelledby="modal-title-fornecedor" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content card-matrix">
            <div class="modal-header card-header-matrix">
                <h5 class="modal-title" id="modal-title"><i class="bi bi-plus-circle me-2"></i>Adicionar Novo Fornecedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body form-container-gestao">
                <form id="form-main" novalidate>
                    <div class="row g-3">
                        <div class="col-md-7"><label for="razao_social" class="form-label">Razão Social</label><div class="input-group"><span class="input-group-text"><i class="bi bi-building"></i></span><input type="text" class="form-control" id="razao_social" name="razao_social" placeholder="Ex: Acme Corporation Ltda." required></div></div>
                        <div class="col-md-5"><label for="nome_fantasia" class="form-label">Nome Fantasia</label><div class="input-group"><span class="input-group-text"><i class="bi bi-tag-fill"></i></span><input type="text" class="form-control" id="nome_fantasia" name="nome_fantasia" placeholder="Ex: Acme Corp."></div></div>
                        <div class="col-md-6"><label for="cnpj" class="form-label">CNPJ</label><div class="input-group"><span class="input-group-text"><i class="bi bi-card-heading"></i></span><input type="text" class="form-control" id="cnpj" name="cnpj" placeholder="00.000.000/0001-00" required maxlength="18"><button class="btn btn-outline-secondary" type="button" id="btn-cnpj-lookup" title="Buscar dados"><i class="bi bi-search"></i></button></div></div>
                        <div class="col-md-6"><label for="ie" class="form-label">Inscrição Estadual</label><div class="input-group"><span class="input-group-text"><i class="bi bi-file-text"></i></span><input type="text" class="form-control" id="ie" name="ie" placeholder="Isento ou número" maxlength="20"></div></div>
                        <div class="col-12"><label for="endereco" class="form-label">Endereço</label><div class="input-group"><span class="input-group-text"><i class="bi bi-geo-alt-fill"></i></span><input type="text" class="form-control" id="endereco" name="endereco" placeholder="Rua, Número, Bairro, Cidade - Estado"></div></div>
                        <div class="col-md-6"><label for="email" class="form-label">E-mail</label><div class="input-group"><span class="input-group-text"><i class="bi bi-envelope-fill"></i></span><input type="email" class="form-control" id="email" name="email" placeholder="contato@empresa.com"></div></div>
                        <div class="col-md-6"><label for="telefone" class="form-label">Telefone</label><div class="input-group"><span class="input-group-text"><i class="bi bi-telephone-fill"></i></span><input type="text" class="form-control" id="telefone" name="telefone" placeholder="(00) 90000-0000" maxlength="15"></div></div>
                        <div class="col-md-6"><label for="status" class="form-label">Status</label><select class="d-none" id="status" name="status"><option value="ativo" selected>Ativo</option><option value="inativo">Inativo</option></select><div class="dropdown" id="custom-status-dropdown"><button class="form-select text-start" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="status-dropdown-button">Ativo</button><ul class="dropdown-menu"><li><a class="dropdown-item" href="#" data-value="ativo"><i class="bi bi-check-circle-fill text-success me-2"></i>Ativo</a></li><li><a class="dropdown-item" href="#" data-value="inativo"><i class="bi bi-x-circle-fill text-danger me-2"></i>Inativo</a></li></ul></div></div>

                        <!-- Acesso ao Portal do Fornecedor (Onboarding) -->
                        <div class="col-12 pt-2" id="portal-access-fields">
                            <hr>
                            <div class="d-flex align-items-center justify-content-between">
                                <h6 class="mb-2"><i class="bi bi-shield-lock-fill me-2"></i>Acesso ao Portal do Fornecedor</h6>
                                <span class="text-secondary small">Será criado um usuário para acesso ao portal.</span>
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
                                        <input type="email" class="form-control" id="usuario_email" name="usuario_email" placeholder="email@empresa.com" required>
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
                        <!-- Fim Acesso Portal Fornecedor -->
                    </div>
                </form>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-matrix-primary" form="form-main">Salvar Fornecedor</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-detalhes" tabindex="-1" aria-labelledby="modalDetalhesLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalDetalhesLabel"><i class="bi bi-building-fill-check me-2"></i>Detalhes do Fornecedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body" id="modal-detalhes-body"></div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header modal-header-danger"><h5 class="modal-title" id="confirmationModalLabel"><i class="bi bi-exclamation-triangle-fill me-2"></i>Confirmar Exclusão</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><p>Tem a certeza de que deseja excluir permanentemente o fornecedor <strong id="modal-item-name" class="text-warning"></strong>?</p><p class="text-secondary small">Esta ação não pode ser desfeita.</p></div>
      <div class="modal-footer"><button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-matrix-danger" id="confirm-delete-btn">Confirmar Exclusão</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-importar" tabindex="-1" aria-labelledby="modalImportarLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalImportarLabel"><i class="bi bi-upload me-2"></i>Importar Fornecedores</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form id="form-importar" enctype="multipart/form-data">
            <p class="text-secondary">O ficheiro deve estar no formato CSV ou XLSX e a primeira linha deve conter os cabeçalhos corretos.</p>
            <div class="mb-3">
                <a href="baixar_exemplo.php?modulo=fornecedores&formato=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .csv</a>
                <a href="baixar_exemplo.php?modulo=fornecedores&formato=xlsx" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-arrow-down me-1"></i> Baixar exemplo .xlsx</a>
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
      <div class="modal-header card-header-matrix"><h5 class="modal-title" id="modalExportarLabel"><i class="bi bi-download me-2"></i>Exportar Fornecedores</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form id="form-exportar">
            <input type="hidden" name="modulo" value="fornecedores">
            <div class="mb-4">
                <label class="form-label">Formato do Ficheiro</label>
                <div class="export-options d-flex gap-3">
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-csv-fornecedor" value="csv" checked><label role="button" tabindex="0" class="form-check-label" for="export-csv-fornecedor"><i class="bi bi-filetype-csv me-2"></i>CSV</label></div>
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-fornecedor" value="xlsx"><label role="button" tabindex="0" class="form-check-label" for="export-xlsx-fornecedor"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label></div>
                    <div class="form-check flex-fill"><input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-fornecedor" value="pdf"><label role="button" tabindex="0" class="form-check-label" for="export-pdf-fornecedor"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label></div>
                </div>
            </div>
            <div>
                <label class="form-label">Intervalo de Dados</label>
                 <select class="form-select" name="intervalo">
                    <option value="todos">Todos os Fornecedores</option>
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
function showToast(msg, tipo='info') {
    const container = document.querySelector('.toast-container');
    if (!container) return alert(msg);
    const map = { success:'bg-success', danger:'bg-danger', warning:'bg-warning text-dark', info:'bg-primary' };
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-white border-0 show ' + (map[tipo]||map.info);
    el.setAttribute('role','alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body">'+msg+'</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    container.appendChild(el);
    setTimeout(()=> el.remove(), 4500);
}
function formatCNPJ(v){ const d=v.replace(/\D/g,'').slice(0,14); if(d.length<=2)return d; if(d.length<=5)return d.replace(/(\d{2})(\d+)/,'$1.$2'); if(d.length<=8)return d.replace(/(\d{2})(\d{3})(\d+)/,'$1.$2.$3'); if(d.length<=12)return d.replace(/(\d{2})(\d{3})(\d{3})(\d+)/,'$1.$2.$3/$4'); return d.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2}).*/,'$1.$2.$3/$4-$5'); }
function formatPhone(v){ const d=v.replace(/\D/g,'').slice(0,11); if(d.length<=2)return d; if(d.length<=6)return d.replace(/(\d{2})(\d+)/,'($1) $2'); if(d.length<=10)return d.replace(/(\d{2})(\d{4})(\d+)/,'($1) $2-$3'); return d.replace(/(\d{2})(\d)(\d{4})(\d{4}).*/,'($1) $2 $3-$4'); }
function apenasDigitos(v){ return (v||'').replace(/\D/g,''); }
const debounce = (fn, delay=300) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};

document.addEventListener('DOMContentLoaded', () => {
    const pageContainer = document.getElementById('page-container');
    if(!pageContainer) return;
    const endpoint = pageContainer.dataset.endpoint;
    const singular = pageContainer.dataset.singular || 'Fornecedor';
    const plural = pageContainer.dataset.plural || 'Fornecedores';
    const pluralLower = plural.toLowerCase();
    const defaultPerPage = 15;

    const tableBody = document.getElementById('tabela-main-body');
    const searchInput = document.getElementById('searchInput');
    const filtroStatus = document.getElementById('filtroStatus');
    const paginationContainer = document.getElementById('pagination-container');
    const paginationHint = document.getElementById('pagination-hint');
    const modalEl = document.getElementById('modal-fornecedor');
    const modalTitle = document.getElementById('modal-title');
    const form = document.getElementById('form-main');
    const submitBtnGlobal = document.querySelector('button[type="submit"][form="form-main"]');
    const statusDropdownButton = document.getElementById('status-dropdown-button');
    const hiddenStatusSelect = document.getElementById('status');
    const dropdownItems = document.querySelectorAll('#custom-status-dropdown .dropdown-item');
    const confirmationModalEl = document.getElementById('confirmationModal');
    const confirmationModal = new bootstrap.Modal(confirmationModalEl);
    const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
    const modalItemName = document.getElementById('modal-item-name');
    const modalDetalhesBody = document.getElementById('modal-detalhes-body');
    const formExportar = document.getElementById('form-exportar');
    const formImportar = document.getElementById('form-importar');
    const btnConfirmarImportacao = document.getElementById('btn-confirmar-importacao');
    const cnpjInput = document.getElementById('cnpj');
    const cnpjLookupBtn = document.getElementById('btn-cnpj-lookup');
    const telefoneInput = document.getElementById('telefone');
    const clearFiltersBtn = document.getElementById('btn-clear-filtros');

    const portalFields = document.getElementById('portal-access-fields');
    const usuarioNomeInput = document.getElementById('usuario_nome');
    const usuarioEmailInput = document.getElementById('usuario_email');
    const senhaInicialInput = document.getElementById('senha_inicial');

    const STATUS_LABELS = { 'ativo': 'Ativo', 'inativo': 'Inativo' };
    const humanizeStatus = (v) => { if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/^.|\s\w/g, s=> s.toUpperCase()); };
    const statusLabel = (v) => STATUS_LABELS[v] || humanizeStatus(v);

    const columnCount = tableBody?.closest('table')?.querySelectorAll('thead th').length || 6;
    const state = {
        rows: [],
        meta: { page:1, per_page:defaultPerPage, total:0, total_pages:1, status_options:[] },
        filters: { busca:'', status:'' },
        loading: false
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
            else if(Array.isArray(payload.resultados)) rows = payload.resultados;

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

    let editandoId = null;
    let itemParaRemoverId = null;

    if(cnpjInput){ cnpjInput.addEventListener('input', ()=> { cnpjInput.value = formatCNPJ(cnpjInput.value); }); }
    if(telefoneInput){ telefoneInput.addEventListener('input', ()=> { telefoneInput.value = formatPhone(telefoneInput.value); }); }

    const setLoading = (isLoading) => {
        state.loading = isLoading;
        if(isLoading && tableBody){
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Carregando ${pluralLower}...</td></tr>`;
        }
    };

    const statusBadge = (status) => {
        const val = (status||'').toLowerCase();
        const map = { ativo:'success', inativo:'danger' };
        const cls = map[val] || 'secondary';
        return `<span class="badge bg-${cls}">${statusLabel(val)}</span>`;
    };

    const createRow = (item) => {
        const itemJson = JSON.stringify(item).replace(/'/g,'&apos;').replace(/"/g,'&quot;');
        return `<tr>
            <td><span class="font-monospace">#${item.id}</span></td>
            <td>${item.razao_social||'-'}</td>
            <td>${item.nome_fantasia||'-'}</td>
            <td>${item.cnpj||'-'}</td>
            <td>${statusBadge(item.status)}</td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-light btn-action-details" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-detalhes"><i class="bi bi-eye-fill"></i></button>
                <button class="btn btn-sm btn-outline-light btn-action-edit" data-item='${itemJson}' data-bs-toggle="modal" data-bs-target="#modal-fornecedor"><i class="bi bi-pencil-fill"></i></button>
                <button class="btn btn-sm btn-outline-light btn-action-delete" data-id="${item.id}" data-name="${item.razao_social||item.nome_fantasia||('Fornecedor #'+item.id)}"><i class="bi bi-trash-fill"></i></button>
            </td>
        </tr>`;
    };

    const renderRows = () => {
        if(!tableBody) return;
        if(!state.rows.length){
            tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-secondary py-4">Nenhum fornecedor encontrado.</td></tr>`;
            return;
        }
        tableBody.innerHTML = state.rows.map(createRow).join('');
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
            paginationHint.textContent = 'Nenhum fornecedor encontrado';
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

    const fetchFornecedores = (pageOverride) => {
        if(pageOverride) state.meta.page = pageOverride;
        const params = buildQueryParams();
        setLoading(true);
        return fetch(`${endpoint}?${params.toString()}`, { headers:{'Accept':'application/json'}, credentials:'same-origin' })
            .then(async res => {
                const data = await res.json().catch(() => null);
                if(!res.ok || !data){
                    throw new Error(data?.erro || 'Erro ao carregar fornecedores');
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
                    tableBody.innerHTML = `<tr><td colspan="${columnCount}" class="text-center text-danger py-4">Erro ao carregar fornecedores.</td></tr>`;
                }
                if(paginationHint) paginationHint.textContent = 'Erro ao carregar';
                if(paginationContainer) paginationContainer.innerHTML='';
                console.error('Falha ao carregar fornecedores:', err);
                showToast(err.message || 'Erro ao carregar fornecedores','danger');
            })
            .finally(()=> setLoading(false));
    };

    paginationContainer?.addEventListener('click', e => {
        const btn = e.target.closest('button.page-link[data-page]');
        if(!btn) return;
        e.preventDefault();
        const page = parseInt(btn.dataset.page, 10);
        if(!page || page === state.meta.page) return;
        fetchFornecedores(Math.max(1,page));
    });

    if(searchInput){
        searchInput.addEventListener('input', debounce(() => {
            state.filters.busca = searchInput.value.trim();
            fetchFornecedores(1);
        }, 400));
    }

    filtroStatus?.addEventListener('change', () => {
        state.filters.status = filtroStatus.value;
        fetchFornecedores(1);
    });

    clearFiltersBtn?.addEventListener('click', () => {
        const hadFilters = !!(state.filters.busca || state.filters.status);
        if(searchInput) searchInput.value = '';
        if(filtroStatus) filtroStatus.value = '';
        state.filters.busca = '';
        state.filters.status = '';
        if(hadFilters){
            fetchFornecedores(1);
        }
    });

    const parseDatasetItem = (raw) => {
        if(!raw) return null;
        try {
            const safe = raw.replace(/&quot;/g,'"').replace(/&apos;/g,"'");
            return JSON.parse(safe);
        } catch(err){
            return null;
        }
    };

    tableBody?.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if(!btn) return;
        const dataAttr = parseDatasetItem(btn.dataset.item);
        if(btn.classList.contains('btn-action-details') && dataAttr){
            const d = dataAttr;
            modalDetalhesBody.innerHTML = `
                <dl>
                    <div class='row'><dt class='col-sm-4'>ID</dt><dd class='col-sm-8 font-monospace'>#${d.id}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Razão Social</dt><dd class='col-sm-8'>${d.razao_social||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Nome Fantasia</dt><dd class='col-sm-8'>${d.nome_fantasia||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>CNPJ</dt><dd class='col-sm-8'>${d.cnpj||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>IE</dt><dd class='col-sm-8'>${d.ie||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Endereço</dt><dd class='col-sm-8'>${d.endereco||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Telefone</dt><dd class='col-sm-8'>${d.telefone||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>E-mail</dt><dd class='col-sm-8'>${d.email||'Não informado'}</dd></div>
                    <div class='row'><dt class='col-sm-4'>Status</dt><dd class='col-sm-8'>${statusLabel(d.status)}</dd></div>
                </dl>`;
        } else if(btn.classList.contains('btn-action-edit') && dataAttr){
            editandoId = dataAttr.id;
            Object.keys(dataAttr).forEach(k=> { if(form.elements[k]) form.elements[k].value = dataAttr[k] ?? ''; });
            updateStatusDisplay(dataAttr.status);
            if(portalFields){
                portalFields.classList.add('d-none');
                if(usuarioNomeInput) { usuarioNomeInput.required=false; usuarioNomeInput.value=''; }
                if(usuarioEmailInput){ usuarioEmailInput.required=false; usuarioEmailInput.value=''; }
                if(senhaInicialInput) senhaInicialInput.value='';
            }
        } else if(btn.classList.contains('btn-action-delete')){
            itemParaRemoverId = btn.dataset.id;
            modalItemName.textContent = btn.dataset.name || ('#'+btn.dataset.id);
            confirmationModal.show();
        }
    });

    const updateStatusDisplay = (value) => {
        const item = document.querySelector(`#custom-status-dropdown .dropdown-item[data-value="${value}"]`);
        if(item){
            statusDropdownButton.innerHTML = item.innerHTML;
            hiddenStatusSelect.value = value;
        }
    };

    dropdownItems.forEach(it => it.addEventListener('click', e => {
        e.preventDefault();
        updateStatusDisplay(it.dataset.value);
    }));

    confirmDeleteBtn.addEventListener('click', () => {
        if(!itemParaRemoverId) return;
        const originalHtml = confirmDeleteBtn.innerHTML;
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Excluindo';
        const body = new URLSearchParams({ id: String(itemParaRemoverId) }).toString();
        fetch(`${endpoint}?_method=DELETE`, {
            method: 'POST',
            headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8', 'Accept':'application/json' },
            credentials:'same-origin',
            body
        })
        .then(async res => {
            const data = await res.json().catch(()=>null);
            if(!res.ok || !data){ throw new Error(data?.erro || 'Erro ao remover.'); }
            if(!data.success) throw new Error(data.erro || 'Erro ao remover.');
            showToast(singular+' removido.','success');
            return fetchFornecedores(state.meta.page);
        })
        .catch(err => showToast(err.message || 'Erro ao remover.','danger'))
        .finally(() => {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = originalHtml;
            itemParaRemoverId = null;
            confirmationModal.hide();
        });
    });

    const razaoSocialInput = document.getElementById('razao_social');
    const emailFornecedorInput = document.getElementById('email');
    if(razaoSocialInput && usuarioNomeInput){
        razaoSocialInput.addEventListener('blur', () => {
            if(!editandoId && usuarioNomeInput && !usuarioNomeInput.value){
                usuarioNomeInput.value = razaoSocialInput.value;
            }
        });
    }
    if(emailFornecedorInput && usuarioEmailInput){
        emailFornecedorInput.addEventListener('blur', () => {
            if(!editandoId && usuarioEmailInput && !usuarioEmailInput.value){
                usuarioEmailInput.value = emailFornecedorInput.value;
            }
        });
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        if(!form.razao_social.value.trim() || !form.cnpj.value.trim()){
            showToast('Razão Social e CNPJ são obrigatórios.','warning');
            return;
        }
        if(!editandoId){
            if(!usuarioNomeInput.value.trim() || !usuarioEmailInput.value.trim()){
                showToast('Informe Nome do Usuário e E-mail de Acesso.','warning'); return;
            }
            if(senhaInicialInput.value && senhaInicialInput.value.length < 3){
                showToast('Senha inicial deve ter pelo menos 3 caracteres.','warning'); return;
            }
        }
    const reloadPage = editandoId ? state.meta.page : 1;
    const payload = Object.fromEntries(new FormData(form).entries());
        if(editandoId){ payload.id = editandoId; }
        payload.cnpj = cnpjInput?.value.trim();
        payload.telefone = telefoneInput?.value.trim() || '';
    const targetUrl = editandoId ? `${endpoint}?_method=PUT` : endpoint;
        if(submitBtnGlobal){
            submitBtnGlobal.disabled = true;
            submitBtnGlobal.dataset.oldHtml = submitBtnGlobal.innerHTML;
            submitBtnGlobal.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Salvando';
        }
        fetch(targetUrl, {
            method: 'POST',
            headers:{ 'Content-Type':'application/json', 'Accept':'application/json' },
            credentials:'same-origin',
            body: JSON.stringify(payload)
        })
        .then(async res => {
            const data = await res.json().catch(()=>null);
            if(!res.ok || !data || data.success === false){
                throw new Error(data?.erro || 'Erro ao salvar fornecedor.');
            }
            let msg = singular + (editandoId ? ' atualizado' : ' adicionado') + ' com sucesso!';
            if(!editandoId && (data.email_senha_enviado || data.email_notificacao_enviado)){
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
            return fetchFornecedores(reloadPage);
        })
        .catch(err => showToast(err.message,'danger'))
        .finally(() => {
            if(submitBtnGlobal){
                submitBtnGlobal.disabled = false;
                submitBtnGlobal.innerHTML = submitBtnGlobal.dataset.oldHtml;
            }
        });
    });

    modalEl.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        if(btn && btn.classList.contains('btn-action-edit')){
            modalTitle && (modalTitle.innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar '+singular);
            submitBtnGlobal && (submitBtnGlobal.innerHTML = '<i class="bi bi-check-circle me-2"></i>Atualizar '+singular);
        } else {
            editandoId = null;
            form.reset();
            updateStatusDisplay('ativo');
            modalTitle && (modalTitle.innerHTML = '<i class="bi bi-plus-circle me-2"></i>Novo '+singular);
            submitBtnGlobal && (submitBtnGlobal.innerHTML = '<i class="bi bi-check-circle me-2"></i>Salvar '+singular);
            if(portalFields){
                portalFields.classList.remove('d-none');
                if(usuarioNomeInput) usuarioNomeInput.required = true;
                if(usuarioEmailInput) usuarioEmailInput.required = true;
            }
        }
    });

    const lookupCNPJ = (auto=false) => {
        if(!cnpjInput) return;
        const dig = apenasDigitos(cnpjInput.value);
        if(dig.length !== 14){ if(!auto) showToast('CNPJ inválido','warning'); return; }
        cnpjLookupBtn.disabled = true;
        const oldHtml = cnpjLookupBtn.innerHTML;
        cnpjLookupBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('../api/cnpj.php?cnpj='+dig, { credentials:'same-origin' })
            .then(r => r.json())
            .then(data => {
                if(data.erro) throw new Error(data.erro);
                const rs = form.razao_social;
                const nf = form.nome_fantasia;
                const end = form.endereco;
                if(rs && !rs.value.trim() && data.razao_social) rs.value = data.razao_social;
                if(nf && !nf.value.trim()) nf.value = data.nome_fantasia || data.razao_social || '';
                if(end && !end.value.trim()){
                    const partes=[data.logradouro,data.numero,data.bairro,(data.municipio&&data.uf)? data.municipio+' - '+data.uf : data.municipio,data.cep];
                    end.value = partes.filter(Boolean).join(', ');
                }
                showToast('CNPJ carregado.','success');
            })
            .catch(err => showToast(err.message,'danger'))
            .finally(() => {
                cnpjLookupBtn.disabled = false;
                cnpjLookupBtn.innerHTML = oldHtml;
            });
    };
    cnpjLookupBtn?.addEventListener('click', () => lookupCNPJ(false));
    cnpjInput?.addEventListener('blur', () => lookupCNPJ(true));

    if(formExportar){
        const checkedInicial = formExportar.querySelector('input[name="formato"]:checked');
        if(checkedInicial){
            const lblInit = formExportar.querySelector('label[for="'+checkedInicial.id+'"]');
            if(lblInit) lblInit.classList.add('active');
        }
        formExportar.querySelectorAll('.form-check-label').forEach(lbl => {
            lbl.style.cursor='pointer';
            lbl.addEventListener('click', e => {
                e.preventDefault();
                const targetId = lbl.getAttribute('for');
                if(!targetId) return;
                const input = document.getElementById(targetId);
                if(input){
                    input.checked = true;
                    formExportar.querySelectorAll('.form-check-label').forEach(l=> l.classList.remove('active'));
                    lbl.classList.add('active');
                }
            });
            lbl.addEventListener('keydown', e=> { if(e.key==='Enter' || e.key===' '){ e.preventDefault(); lbl.click(); }});
        });

        const fetchIdsFiltrados = () => {
            const params = new URLSearchParams();
            params.set('mode','ids');
            if(state.filters.busca) params.set('busca', state.filters.busca);
            if(state.filters.status) params.set('status', state.filters.status);
            return fetch(`${endpoint}?${params.toString()}`, { headers:{'Accept':'application/json'}, credentials:'same-origin' })
                .then(r => r.json().catch(()=>null))
                .then(data => Array.isArray(data?.ids) ? data.ids.map(id => parseInt(id,10)).filter(Boolean) : []);
        };

        formExportar.addEventListener('submit', async e => {
            e.preventDefault();
            const formato = formExportar.formato.value;
            const intervalo = formExportar.intervalo.value;
            let idsString = '';
            if(intervalo === 'pagina_atual'){
                const ids = state.rows.map(r => r.id).filter(Boolean);
                if(!ids.length){ showToast('Nenhum fornecedor na página atual.','warning'); return; }
                idsString = ids.join(',');
            } else if(intervalo === 'filtrados'){
                const ids = await fetchIdsFiltrados();
                if(!ids.length){ showToast('Nenhum fornecedor encontrado para exportar.','warning'); return; }
                idsString = ids.join(',');
            }

            const formHidden = document.createElement('form');
            formHidden.method = 'POST';
            formHidden.action = 'exportador.php';
            formHidden.innerHTML = `
                <input type="hidden" name="modulo" value="${pluralLower}">
                <input type="hidden" name="formato" value="${formato}">
                <input type="hidden" name="intervalo" value="${intervalo}">
                ${idsString ? `<input type="hidden" name="ids" value="${idsString}">` : ''}`;
            document.body.appendChild(formHidden);
            formHidden.submit();
            formHidden.remove();
            showToast('Gerando '+formato.toUpperCase()+'...','success');
            bootstrap.Modal.getInstance(document.getElementById('modal-exportar')).hide();
        });
    }

    if(formImportar){
        formImportar.addEventListener('submit', e => {
            e.preventDefault();
            const arquivo = document.getElementById('arquivo-importacao');
            if(!arquivo.files.length){ showToast('Selecione um ficheiro.','warning'); return; }
            const fd = new FormData();
            fd.append('arquivo_importacao', arquivo.files[0]);
            fd.append('modulo', pluralLower);
            const spinner = btnConfirmarImportacao.querySelector('.spinner-border');
            spinner.classList.remove('d-none');
            btnConfirmarImportacao.disabled = true;
            fetch('../api/importador.php',{ method:'POST', body:fd })
                .then(r => r.json().catch(()=>({success:false,erro:'Resposta inválida'})))
                .then(data => {
                    if(!data.success) throw new Error(data.erro || 'Falha na importação');
                    showToast('Importação concluída.','success');
                    bootstrap.Modal.getInstance(document.getElementById('modal-importar')).hide();
                    formImportar.reset();
                    return fetchFornecedores(state.meta.page);
                })
                .catch(err => showToast(err.message,'danger'))
                .finally(() => {
                    spinner.classList.add('d-none');
                    btnConfirmarImportacao.disabled = false;
                });
        });
    }

    fetchFornecedores(1);
});
</script>
</body>
</html>