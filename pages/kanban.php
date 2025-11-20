<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php'; // add auth for user type
if (!isset($_SESSION['usuario_id'])) { header('Location: login.php'); exit; }
$__u = auth_usuario();
if ($__u && in_array(($__u['tipo'] ?? ''), ['cliente','fornecedor'], true)) {
    if(($__u['tipo'] ?? '') === 'cliente') { header('Location: cliente/index.php'); } else { header('Location: fornecedor/index.php'); }
    exit;
}
$app_name = $branding['app_name'] ?? 'Dekanto';
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<title>Kanban - <?php echo htmlspecialchars($app_name); ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/nexus.css">
<link rel="stylesheet" href="../assets/css/kanban.css">
<style>
/* === Ajuste: inputs desabilitados no modal devem ficar em tema claro === */
/* Escopo apenas dentro do modal principal para não afetar o resto do tema escuro */
[data-bs-theme="dark"] #modalCard .form-control[disabled],
[data-bs-theme="dark"] #modalCard .form-select[disabled],
[data-bs-theme="dark"] #modalCard textarea.form-control[disabled] {
  background-color: #ffffff !important;
  color: #000 !important;
  -webkit-text-fill-color: #000; /* Safari */
  border: 1px solid #d0d0d0 !important;
  opacity: 1 !important; /* Mantém contraste pleno */
}
[data-bs-theme="dark"] #modalCard .form-control[disabled]::placeholder {
  color: #555 !important;
}
/* Labels também em tom escuro para coerência visual */
[data-bs-theme="dark"] #modalCard label.form-label.small-head {
  color: #222 !important;
}
/* Pequeno destaque diferenciado se quiser indicar visualmente readonly */
[data-bs-theme="dark"] #modalCard .form-control[disabled] {
  box-shadow: inset 0 0 0 1px #ececec;
}
/* === Kanban: tabela de itens integrada ao tema Matrix === */
.requisicao-itens-wrapper {
  border: 1px solid var(--matrix-border);
  border-radius: 1rem;
  background: var(--matrix-surface);
  box-shadow: 0 6px 18px -8px rgba(0,0,0,.2);
  overflow: hidden;
}
.requisicao-itens-wrapper .wrapper-header {
  padding: .85rem 1rem;
  border-bottom: 1px solid var(--matrix-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  text-transform: uppercase;
  letter-spacing: .08em;
  font-size: .68rem;
  font-weight: 600;
  color: var(--matrix-text-secondary);
  background: linear-gradient(120deg, rgba(255,255,255,.92), rgba(248,248,248,.4));
}
.requisicao-itens-count {
  font-size: .66rem;
  letter-spacing: .04em;
  border-radius: 999px;
  padding: .2rem .85rem;
  text-transform: none;
  border: 1px solid var(--matrix-border);
  background: var(--matrix-bg);
  color: var(--matrix-text-secondary);
  transition: all .2s ease;
}
.requisicao-itens-count[data-state="loading"] {
  color: var(--matrix-text-secondary);
  opacity: .7;
}
.requisicao-itens-count[data-state="empty"] {
  background: rgba(220,53,69,.08);
  border-color: rgba(220,53,69,.35);
  color: #7f3037;
}
.requisicao-itens-count[data-state="filled"] {
  background: rgba(181,168,134,.18);
  border-color: rgba(181,168,134,.4);
  color: var(--matrix-text-primary);
}
.requisicao-itens-count[data-state="error"] {
  background: rgba(220,53,69,.14);
  border-color: rgba(220,53,69,.45);
  color: #7f3037;
}
.requisicao-itens-scroll {
  max-height: 320px;
  overflow: auto;
}
.requisicao-itens-scroll::-webkit-scrollbar {
  width: 6px;
}
.requisicao-itens-scroll::-webkit-scrollbar-thumb {
  background: rgba(0,0,0,.15);
  border-radius: 999px;
}
.requisicao-itens-table {
  font-size: .9rem;
  border-color: transparent;
}
.requisicao-itens-table thead th {
  text-transform: uppercase;
  letter-spacing: .07em;
  font-size: .68rem;
  color: var(--matrix-text-secondary);
  border-bottom: 1px solid var(--matrix-border);
  background: var(--matrix-surface);
  position: sticky;
  top: 0;
  z-index: 2;
}
.requisicao-itens-table tbody tr {
  transition: background .2s ease;
}
.requisicao-itens-table tbody tr:hover {
  background: rgba(0,0,0,.03);
}
[data-bs-theme="dark"] .requisicao-itens-table tbody tr:hover {
  background: rgba(255,255,255,.03);
}
.requisicao-itens-table tbody td {
  border-top-color: var(--matrix-border);
}
.requisicao-itens-table .empty-row td {
  padding: 1.5rem 0;
  color: var(--matrix-text-secondary);
}
.req-item-id-pill {
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  padding: .2rem .65rem;
  border-radius: 999px;
  border: 1px solid var(--matrix-border);
  background: rgba(0,0,0,.02);
  font-size: .72rem;
  font-weight: 600;
  color: var(--matrix-text-primary);
}
.req-item-produto small {
  font-size: .7rem;
}
.req-item-qtd {
  background: rgba(181,168,134,.18);
  color: var(--matrix-text-primary);
  font-weight: 700;
  min-width: 48px;
  display: inline-flex;
  justify-content: center;
  align-items: center;
  border-radius: 999px;
}
.req-item-actions {
  display: flex;
  justify-content: flex-end;
  gap: .35rem;
}
.btn-table-action {
  width: 32px;
  height: 32px;
  border-radius: .6rem;
  border: 1px solid var(--matrix-border);
  background: var(--matrix-surface);
  color: var(--matrix-text-secondary);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: all .15s ease;
  box-shadow: 0 1px 3px rgba(0,0,0,.08);
}
.btn-table-action:hover {
  color: var(--matrix-text-primary);
  border-color: var(--matrix-primary);
  background: rgba(181,168,134,.12);
  box-shadow: 0 0 0 3px rgba(181,168,134,.2);
}
.requisicao-itens-table tr.req-editing {
  background: rgba(181,168,134,.08);
  box-shadow: inset 0 0 0 1px rgba(181,168,134,.35);
}
.req-item-edit-input .input-group-text {
  background: var(--matrix-bg);
  border-color: var(--matrix-border);
  font-weight: 600;
  font-size: .75rem;
}
.req-item-edit-input input[type="number"] {
  border-color: var(--matrix-border);
}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container-fluid py-3">
    <div id="kanban-board"></div>
</div>

<!-- Modal Edição / Criação -->
<div class="modal fade" id="modalCard" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalCardLabel">Editar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <form id="formCard">
            <input type="hidden" name="entity" id="form-entity">
            <input type="hidden" name="id" id="form-id">
            <div id="group-titulo" class="mb-3">
                <label class="form-label small-head">Título / Referência</label>
                <input type="text" class="form-control" id="form-titulo" placeholder="Título ou referência">
            </div>
            <div id="group-cliente" class="mb-3">
                <label class="form-label small-head">Cliente ID</label>
                <input type="number" class="form-control" id="form-cliente-id" min="1">
            </div>
            <div id="group-requisicao" class="mb-3">
                <label class="form-label small-head">Requisição ID</label>
                <input type="number" class="form-control" id="form-requisicao-id" min="1" disabled>
            </div>
            <div id="group-cotacao" class="mb-3">
                <label class="form-label small-head">Cotação ID</label>
                <input type="number" class="form-control" id="form-cotacao-id" min="1" disabled>
            </div>
            <div id="group-proposta" class="mb-3">
                <label class="form-label small-head">Proposta ID</label>
                <input type="number" class="form-control" id="form-proposta-id" min="1" disabled>
            </div>
            <div id="group-fornecedor" class="mb-3">
                <label class="form-label small-head">Fornecedor ID</label>
                <input type="number" class="form-control" id="form-fornecedor-id" min="1">
            </div>
            <div id="group-valor" class="mb-3">
                <label class="form-label small-head">Valor Total (Proposta)</label>
                <input type="number" step="0.01" class="form-control" id="form-valor-total">
            </div>
            <div id="group-prazo" class="mb-3">
                <label class="form-label small-head">Prazo Entrega (dias)</label>
                <input type="number" class="form-control" id="form-prazo-entrega" min="0">
            </div>
            <div id="group-observacoes" class="mb-3">
                <label class="form-label small-head">Observações</label>
                <textarea class="form-control" id="form-observacoes" rows="3"></textarea>
            </div>
            <div id="group-imagem" class="mb-3">
                <label class="form-label small-head">Imagem / Anexo URL (Proposta)</label>
                <input type="text" class="form-control" id="form-imagem-url" placeholder="https://...">
            </div>
            <div id="group-pdf" class="mb-3">
                <label class="form-label small-head">PDF URL Pedido</label>
                <input type="text" class="form-control" id="form-pdf-url" placeholder="https://...">
            </div>
            <div class="mb-3" id="group-rodada">
                <label class="form-label small-head">Rodada Cotação</label>
                <input type="number" class="form-control" id="form-rodada" min="1">
            </div>
            <div class="mb-3" id="group-token">
                <label class="form-label small-head d-flex justify-content-between align-items-center">Token Cotação <button type="button" class="btn btn-sm btn-matrix-secondary-outline" id="btn-regenerar-token"><i class="bi bi-arrow-repeat"></i></button></label>
                <input type="text" class="form-control" id="form-token" disabled>
            </div>
            <div class="mb-3" id="group-token-expira">
                <label class="form-label small-head">Token Expira Em</label>
                <input type="text" class="form-control" id="form-token-expira" disabled>
            </div>
            <div class="mb-3" id="group-status">
                <label class="form-label small-head">Status</label>
                <select class="form-select" id="form-status"></select>
                <!-- Aviso dinâmico quando pedido bloqueado aguardando aceite -->
                <div id="pedido-locked-hint" class="alert alert-warning small mt-2 d-none">Este pedido está aguardando aceite do cliente. O status não pode ser alterado até o aceite.</div>
            </div>
            <div id="group-items" class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label small-head mb-0">Itens da Proposta</label>
                    <button type="button" class="btn btn-sm btn-matrix-secondary-outline" id="btn-carregar-itens"><i class="bi bi-list-ul"></i></button>
                </div>
                <div class="border rounded p-2 items-scroll">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Produto</th><th class="text-end">Qtd</th><th class="text-end">Preço</th></tr></thead>
                        <tbody id="proposta-itens-body"><tr><td colspan="3" class="text-secondary small">Clique em carregar.</td></tr></tbody>
                    </table>
                </div>
            </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-matrix-primary" id="btnSalvarCard">Salvar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Itens Requisição -->
<div class="modal fade" id="modalItensRequisicao" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalItensRequisicaoLabel">Itens da Requisição</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3 mb-3 align-items-end">
          <div class="col-md-6">
            <label class="form-label small-head mb-1">Produto</label>
            <select id="req-item-produto" class="form-select form-select-sm"></select>
          </div>
          <div class="col-md-2">
            <label class="form-label small-head mb-1">Qtd</label>
            <input type="number" id="req-item-qtd" class="form-control form-control-sm" value="1" min="1">
          </div>
          <div class="col-md-3 d-grid">
            <button class="btn btn-sm btn-matrix-primary" id="btnAddReqItem"><i class="bi bi-plus-lg me-1"></i>Adicionar</button>
          </div>
        </div>
        <div class="requisicao-itens-wrapper">
          <div class="wrapper-header">
            <div class="d-flex align-items-center gap-2 text-uppercase fw-semibold">
              <i class="bi bi-grid-3x3-gap"></i>
              <span>Itens adicionados</span>
            </div>
            <span class="requisicao-itens-count badge rounded-pill" id="req-itens-count" data-state="loading">carregando</span>
          </div>
          <div class="table-responsive requisicao-itens-scroll">
            <table class="table table-sm align-middle mb-0 requisicao-itens-table">
              <thead><tr><th style="width:110px;">ID</th><th>Produto</th><th style="width:90px;" class="text-end">Qtd</th><th style="width:90px;"></th></tr></thead>
              <tbody id="req-itens-body"><tr class="empty-row"><td colspan="4" class="text-center">Carregando...</td></tr></tbody>
            </table>
          </div>
        </div>
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
// ==================== STATE & CONSTANTS ====================
const boardEl = document.getElementById('kanban-board');
const modalEl = document.getElementById('modalCard');
const modal = new bootstrap.Modal(modalEl);
const formEntity = document.getElementById('form-entity');
const formId = document.getElementById('form-id');
const formTitulo = document.getElementById('form-titulo');
const formClienteId = document.getElementById('form-cliente-id');
const formRequisicaoId = document.getElementById('form-requisicao-id');
const formCotacaoId = document.getElementById('form-cotacao-id');
const formPropostaId = document.getElementById('form-proposta-id');
const formFornecedorId = document.getElementById('form-fornecedor-id');
const formValorTotal = document.getElementById('form-valor-total');
const formPrazoEntrega = document.getElementById('form-prazo-entrega');
const formObservacoes = document.getElementById('form-observacoes');
const formImagemUrl = document.getElementById('form-imagem-url');
const formPdfUrl = document.getElementById('form-pdf-url');
const formRodada = document.getElementById('form-rodada');
const formToken = document.getElementById('form-token');
const formTokenExpira = document.getElementById('form-token-expira');
const itensBody = document.getElementById('proposta-itens-body');
const btnCarregarItens = document.getElementById('btn-carregar-itens');
const btnRegenerarToken = document.getElementById('btn-regenerar-token');
const SEARCH = document.getElementById('kanban-search');
const formStatus = document.getElementById('form-status'); // <-- Adicionado para evitar ReferenceError
const pedidoLockedHint = document.getElementById('pedido-locked-hint');
const btnSalvarCard = document.getElementById('btnSalvarCard');
const modalItensEl = document.getElementById('modalItensRequisicao');
const modalItens = modalItensEl ? new bootstrap.Modal(modalItensEl) : null;
const reqItensBody = document.getElementById('req-itens-body');
const reqItensCount = document.getElementById('req-itens-count');
const reqProdutoSelect = document.getElementById('req-item-produto');
const reqItemQtd = document.getElementById('req-item-qtd');
const btnAddReqItem = document.getElementById('btnAddReqItem');

// Helper
function isPedidoLocked(status){ return (status||'').toLowerCase()==='aguardando_aprovacao_cliente'; }

const GROUPS = {
  titulo: document.getElementById('group-titulo'),
  cliente: document.getElementById('group-cliente'),
  requisicao: document.getElementById('group-requisicao'),
  cotacao: document.getElementById('group-cotacao'),
  proposta: document.getElementById('group-proposta'),
  fornecedor: document.getElementById('group-fornecedor'),
  valor: document.getElementById('group-valor'),
  prazo: document.getElementById('group-prazo'),
  observacoes: document.getElementById('group-observacoes'),
  imagem: document.getElementById('group-imagem'),
  pdf: document.getElementById('group-pdf'),
  rodada: document.getElementById('group-rodada'),
  token: document.getElementById('group-token'),
  tokenExpira: document.getElementById('group-token-expira'),
  items: document.getElementById('group-items'),
  status: document.getElementById('group-status'),
};

let STATE = { requisicoes: [], cotacoes: [], propostas: [], pedidos: [] };
let REQ_EDITING_ROW = null;
let REQUISICAO_ITENS_REQUISICAO_ID = null;
let PRODUTOS_CACHE = [];

async function ensureCotacaoToken(cotacaoId){
  if(!cotacaoId) return null;
  const existing = STATE.cotacoes.find(c => String(c.id) === String(cotacaoId));
  if(existing && existing.token){ return existing.token; }
  try {
    const res = await fetch(`../api/cotacoes.php?include_token=1&id=${encodeURIComponent(cotacaoId)}`, { credentials:'same-origin' });
    const payload = await res.json().catch(()=>null);
    if(!res.ok || !payload){ throw new Error('Resposta inválida'); }
    let selected = null;
    if(Array.isArray(payload?.data)){
      selected = payload.data.find(c => String(c.id) === String(cotacaoId));
    } else if(Array.isArray(payload)) {
      selected = payload.find(c => String(c.id) === String(cotacaoId));
    } else if(payload && typeof payload === 'object' && payload.id) {
      selected = payload;
    }
    const token = selected?.token || null;
    if(token){
      if(existing){ existing.token = token; }
      else if(selected){ STATE.cotacoes.push(selected); }
    }
    return token;
  } catch(err){
    console.error('Falha ao buscar token da cotação', err);
    return null;
  }
}

// ==================== META ====================
const PHASE_META = { requisicao:{icon:'bi-journal-text'}, cotacao:{icon:'bi-clipboard-data'}, proposta:{icon:'bi-file-earmark-text'}, pedido:{icon:'bi-truck'} };
// Adiciona novo status ao STATUS_LABELS
const STATUS_LABELS = {
  // Requisições
  pendente_aprovacao: 'Pendente de aprovação',
  em_analise: 'Em análise',
  aberta: 'Aberta',
  fechada: 'Fechada',
  aprovada: 'Aprovada',
  rejeitada: 'Rejeitada',
  // Cotações
  encerrada: 'Encerrada',
  // Propostas
  enviada: 'Enviada',
  // Pedidos
  aguardando_aprovacao_cliente: 'Aguardando aprovação do cliente',
  pendente: 'Pendente',
  emitido: 'Emitido',
  em_producao: 'Em produção',
  enviado: 'Enviado',
  entregue: 'Entregue',
  cancelado: 'Cancelado'
};
window.STATUS_COLOR = (entity,status)=>{
  status = (status||'').toLowerCase();
  if(entity==='pedido'){
    switch(status){
      case 'aguardando_aprovacao_cliente': return 'warning';
      case 'pendente': return 'pendente';
      case 'emitido': return 'emitido';
      case 'em_producao': return 'em_producao';
      case 'enviado': return 'enviado';
      case 'entregue': return 'entregue';
      case 'cancelado': return 'cancelado';
      default: return 'secondary';
    }
  }
  if(entity==='requisicao') return status==='aberta'? 'warning':'secondary';
  if(entity==='cotacao') return status==='aberta'? 'info':'secondary';
  if(entity==='proposta'){ if(status==='aprovada') return 'success'; if(status==='rejeitada') return 'secondary'; if(status==='enviada') return 'info'; return 'secondary'; }
  return 'secondary';
};

// Mapas de rótulos legíveis para status padronizados
function statusLabel(v){
  v = (v||'').toLowerCase();
  if(STATUS_LABELS[v]) return STATUS_LABELS[v];
  return v ? v.replace(/_/g,' ').replace(/\b\w/g, c=> c.toUpperCase()) : '';
}

// Helper: preencher opções de status e pré-selecionar o valor atual (se existir)
function setStatusOptions(options, current){
  try {
    const norm = (s)=> (s||'').toString().toLowerCase();
    formStatus.innerHTML = (options||[]).map(v=>`<option value="${v}">${statusLabel(v)}</option>`).join('');
    if(current){
      const cur = norm(current);
      const found = Array.from(formStatus.options).find(o=> norm(o.value)===cur);
      if(found){ formStatus.value = found.value; }
      else {
        const opt = document.createElement('option');
        opt.value = current;
        opt.textContent = statusLabel(current);
        formStatus.prepend(opt);
        formStatus.value = current;
      }
    }
  } catch(e){ /* noop */ }
}

// === DEFINIÇÃO DAS COLUNAS (ADDED) ===
const COLS = [
  // Fases principais
  { key:'req',  phase:'requisicao', entity:'requisicao', title:'Requisições', source:'requisicoes', filter: x=>true, allowCreate:true },
  { key:'cot',  phase:'cotacao',    entity:'cotacao',    title:'Cotações',    source:'cotacoes',    filter: x=>true, allowCreate:false },
  { key:'prop', phase:'proposta',   entity:'proposta',   title:'Propostas',   source:'propostas',   filter: x=> ((x.status||'').toLowerCase()!=='aprovada'), allowCreate:false },
  // Buckets de pedidos por status
  { key:'ped_aguard',  phase:'pedido', entity:'pedido', title:'Pedidos · Aguardando aprovação', source:'pedidos', status:'aguardando_aprovacao_cliente', filter:x=> (x.status||'').toLowerCase()==='aguardando_aprovacao_cliente' },
  { key:'ped_pend',    phase:'pedido', entity:'pedido', title:'Pedidos · Pendentes',             source:'pedidos', status:'pendente',                    filter:x=> (x.status||'').toLowerCase()==='pendente' },
  { key:'ped_emit',    phase:'pedido', entity:'pedido', title:'Pedidos · Emitidos',              source:'pedidos', status:'emitido',                     filter:x=> (x.status||'').toLowerCase()==='emitido' },
  { key:'ped_prod',    phase:'pedido', entity:'pedido', title:'Pedidos · Em produção',            source:'pedidos', status:'em_producao',                 filter:x=> (x.status||'').toLowerCase()==='em_producao' },
  { key:'ped_env',     phase:'pedido', entity:'pedido', title:'Pedidos · Enviados',               source:'pedidos', status:'enviado',                     filter:x=> (x.status||'').toLowerCase()==='enviado' },
  { key:'ped_entr',    phase:'pedido', entity:'pedido', title:'Pedidos · Entregues',              source:'pedidos', status:'entregue',                    filter:x=> (x.status||'').toLowerCase()==='entregue' },
  { key:'ped_canc',    phase:'pedido', entity:'pedido', title:'Pedidos · Cancelados',             source:'pedidos', status:'cancelado',                   filter:x=> (x.status||'').toLowerCase()==='cancelado' },
];

// ==================== RENDER ====================
function statusChip(entity,status){ const color=STATUS_COLOR(entity,status); const display = statusLabel(status); return `<span class="status-chip" data-color="${color}"><i class="bi bi-circle-fill" style="font-size:.35rem;"></i>${display}</span>`; }
function columnTemplate(col){ const icon=PHASE_META[col.phase]?.icon||'bi-columns'; return `<div class="kanban-column fade-in" data-phase="${col.phase}" data-col="${col.key}" data-entity="${col.entity}" ${col.status? `data-status='${col.status}'`:''}><div class="column-header"><div class="title-wrap"><span class="icon-badge"><i class="bi ${icon}"></i></span><span>${col.title}</span></div><span class="count-pill" id="count-${col.key}"><i class="bi bi-collection"></i><b>0</b></span></div><div class="kanban-scroll" data-list></div>${col.allowCreate? `<div class='column-footer'><button class='add-card-btn' data-add="${col.entity}"><i class="bi bi-plus-lg"></i><span>Criar ${col.entity==='requisicao'?'Requisição':'Card'}</span></button></div>`:''}</div>`; }
function buildCard(entity,obj){ let title='',meta=''; switch(entity){case 'requisicao': title=`Req #${obj.id}`; meta=obj.titulo||''; break; case 'cotacao': title=`Cot #${obj.id}`; meta=`Req ${obj.requisicao_id}`; break; case 'proposta': title=`Prop #${obj.id}`; meta=`Cot ${obj.cotacao_id} · Forn ${obj.fornecedor_id||'-'}${obj.valor_total? ' · R$'+parseFloat(obj.valor_total).toFixed(2):''}`; break; case 'pedido': title=`Ped #${obj.id}`; meta=`Prop ${obj.proposta_id}`; break;} const badge=statusChip(entity,(obj.status||'').toLowerCase()); const isPedAguard = (entity==='pedido' && isPedidoLocked(obj.status)); return `<div class="kanban-card" draggable="true" data-entity="${entity}" data-id="${obj.id}" data-status="${obj.status||''}" data-cotacao="${obj.cotacao_id||''}" data-requisicao="${obj.requisicao_id||''}" data-proposta="${obj.proposta_id||''}" ${entity==='cotacao'&&obj.token? `data-token='${obj.token}'`:''}>
    <div class="d-flex justify-content-between align-items-start">
      <div class="small fw-semibold lh-sm" style="max-width:80%;">
        ${title}
        <div class="text-secondary fw-normal small text-truncate">${meta||''}</div>
      </div>
    </div>
    <div class="kanban-card-footer">${badge}</div>
    <div class="card-actions">
      ${isPedAguard ? `<button class="btn btn-action" disabled data-bs-toggle="tooltip" title="Aguardando aceite do cliente"><i class="bi bi-lock"></i></button>` : `<button class="btn btn-action" data-edit data-bs-toggle="tooltip" title="Editar"><i class="bi bi-pencil"></i></button>`}
      <button class="btn btn-action" data-del data-bs-toggle="tooltip" title="Remover"><i class="bi bi-x"></i></button>
      ${entity!=='pedido'? `<button class="btn btn-action" data-advance data-bs-toggle="tooltip" title="Avançar"><i class="bi bi-arrow-right"></i></button>`:''}
      ${entity==='requisicao'? `<button class="btn btn-action" data-items data-bs-toggle="tooltip" title="Itens"><i class="bi bi-list-check"></i></button>`:''}
      
      ${isPedAguard? `<button class="btn btn-action" data-enviar-aceite data-bs-toggle="tooltip" title="Enviar para aprovação do cliente"><i class="bi bi-send"></i></button>`:''}
    </div>
  </div>`; }
function renderBoard(){
  // ...existing code building columns...
  boardEl.innerHTML = COLS.map(columnTemplate).join('');
  COLS.forEach(col=>{try{const list=boardEl.querySelector(`[data-col='${col.key}'] [data-list]`); let source=STATE[col.source]; if(!Array.isArray(source)){ console.warn('Fonte não array', col.source, source); source=[]; }
    let data=source.filter(col.filter); list.innerHTML = data.map(d=> buildCard(col.entity,d)).join(''); boardEl.querySelector(`#count-${col.key} b`).textContent=data.length; if(!data.length){ list.innerHTML += `<div class='text-center text-secondary small py-2'>Vazio</div>`; }}catch(err){ console.error('Erro render col', col.key, err); }});
  attachCardEvents(); initTooltips();
}
function initTooltips(){ document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=> new bootstrap.Tooltip(el)); }

// Helper robusto de cópia
async function copyToClipboard(text){
  try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; } }catch(e){}
  try{ const ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.top='-9999px'; ta.style.left='-9999px'; ta.style.opacity='0'; document.body.appendChild(ta); ta.focus({preventScroll:true}); ta.select(); const ok=document.execCommand('copy'); ta.remove(); return !!ok; }catch(e){ return false; }
}

function updateReqItensCount(state, count=0){
  if(!reqItensCount) return;
  reqItensCount.dataset.state = state || '';
  switch(state){
    case 'loading':
      reqItensCount.textContent = 'carregando';
      break;
    case 'empty':
      reqItensCount.textContent = 'sem itens';
      break;
    case 'filled':
      reqItensCount.textContent = `${count} item${count===1?'':'s'}`;
      break;
    case 'error':
      reqItensCount.textContent = 'erro';
      break;
    default:
      reqItensCount.textContent = '';
  }
}

// Toast helper (ADDED)
function showToast(message, variant='primary'){
  try{
    const container = document.querySelector('.toast-container') || document.body;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = `<div class="toast align-items-center text-bg-${variant} border-0" role="alert" aria-live="assertive" aria-atomic="true">`
      +`<div class="d-flex"><div class="toast-body">${message||''}</div>`
      +`<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`;
    const toastEl = wrapper.firstElementChild;
    container.appendChild(toastEl);
    const t = new bootstrap.Toast(toastEl,{ delay: 3000 });
    toastEl.addEventListener('hidden.bs.toast', ()=> toastEl.remove());
    t.show();
  }catch(e){ console.log(message); }
}

function buildFormParams(data={}){
  const params = new URLSearchParams();
  Object.entries(data || {}).forEach(([key,value])=>{
    if(value === undefined || value === null) return;
    let val = value;
    if (typeof val === 'boolean') { val = val ? '1' : '0'; }
    params.append(key, val);
  });
  return params;
}

function sendFormRequest(url, data={}, overrideMethod=null){
  const params = buildFormParams(data);
  if(overrideMethod){ params.append('_method', overrideMethod.toUpperCase()); }
  return fetch(url, {
    method:'POST',
    credentials:'same-origin',
    headers:{
      'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
      'Accept':'application/json'
    },
    body: params.toString()
  });
}

async function parseJsonResponse(response){
  const raw = await response.text();
  let data = null;
  if(raw){
    try { data = JSON.parse(raw); }
    catch(_) { data = null; }
  }
  return { data, raw };
}

// ==================== DRAG ====================
let dragPlaceholder=document.createElement('div'); dragPlaceholder.className='drag-placeholder';
function getDragAfterElement(container,y){ const els=[...container.querySelectorAll('.kanban-card:not(.dragging)')]; return els.reduce((closest,child)=>{const box=child.getBoundingClientRect(); const offset=y-box.top-box.height/2; if(offset<0 && offset>closest.offset) return {offset,element:child}; else return closest; }, {offset:-Infinity}).element; }
function attachCardEvents(){
  boardEl.querySelectorAll('.kanban-card').forEach(card=>{
    card.addEventListener('dragstart', e=>{card.classList.add('dragging'); e.dataTransfer.effectAllowed='move';});
    card.addEventListener('dragend', ()=>{card.classList.remove('dragging'); dragPlaceholder.remove();});
    card.addEventListener('dblclick', onEditCard);
  });
  boardEl.querySelectorAll('[data-list]').forEach(list=>{
    list.addEventListener('dragover', e=>{e.preventDefault(); const dragging=document.querySelector('.kanban-card.dragging'); if(!dragging)return; list.classList.add('drop-target'); const after=getDragAfterElement(list,e.clientY); if(!after) list.appendChild(dragPlaceholder); else list.insertBefore(dragPlaceholder,after);});
    list.addEventListener('dragleave', ()=> list.classList.remove('drop-target'));
    list.addEventListener('drop', e=>{e.preventDefault(); list.classList.remove('drop-target'); const dragging=document.querySelector('.kanban-card.dragging'); if(dragging){ list.insertBefore(dragging, dragPlaceholder); dragPlaceholder.remove(); handleMove(dragging,list.closest('.kanban-column')); }});
  });
  boardEl.querySelectorAll('[data-edit]').forEach(btn=> btn.addEventListener('click', onEditCard));
  boardEl.querySelectorAll('[data-del]').forEach(btn=> btn.addEventListener('click', onDeleteCard));
  boardEl.querySelectorAll('[data-advance]').forEach(btn=> btn.addEventListener('click', onAdvance));
  boardEl.querySelectorAll('[data-add]').forEach(btn=> btn.addEventListener('click', ()=> abrirModalCriar(btn.dataset.add)));
  boardEl.querySelectorAll('[data-items]').forEach(btn=> btn.addEventListener('click', e=>{ const card=e.target.closest('.kanban-card'); abrirItensRequisicao(card.dataset.id); }));
  // Novo: enviar pedido para aceite do cliente
  boardEl.querySelectorAll('[data-enviar-aceite]').forEach(btn=> btn.addEventListener('click', async e=>{
    const card = e.target.closest('.kanban-card');
    const id = card?.dataset?.id;
    if(!id) return;
    btn.disabled = true; const original = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await fetch('../api/pedidos_aceite.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({pedido_id: id}) });
      const j = await r.json();
      if(j.success){ showToast('Pedido enviado para aprovação do cliente','success'); }
      else { showToast(j.erro || 'Falha ao enviar para aprovação','danger'); }
    } catch(err){ console.error(err); showToast('Erro de rede','danger'); }
    finally { btn.disabled=false; btn.innerHTML = original; }
  }));
}

// ==================== ACTIONS ====================
function onAdvance(e){ const card=e.target.closest('.kanban-card'); const entity=card.dataset.entity; if(entity==='requisicao'){ criarCotacao(card.dataset.id); } else if(entity==='cotacao'){ fetchPropostas(card.dataset.id).then(()=>{ renderBoard(); showToast('Propostas carregadas','info');}); } }
async function updatePedidoStatus(id,newStatus,card){
  try {
    const response = await sendFormRequest('../api/pedidos.php',{ id, status:newStatus },'PUT');
    const { data, raw } = await parseJsonResponse(response);
    if(!response.ok || !data){
      const message = data?.erro || (raw ? raw.replace(/<[^>]+>/g,'').slice(0,180) : 'Resposta inválida do servidor');
      throw new Error(message || `Erro ao atualizar status (HTTP ${response.status})`);
    }
    if(data.success){
      // Atualiza objeto no STATE antes de re-render
      const pedidoObj = STATE.pedidos.find(p => String(p.id) === String(id));
      if(pedidoObj){ pedidoObj.status = newStatus; }
      // Atualiza dataset e badge visual imediato
      if(card){
        card.dataset.status=newStatus;
        const footer=card.querySelector('.kanban-card-footer');
        if(footer){ footer.innerHTML = statusChip('pedido', newStatus); }
      }
      showToast('Status atualizado','success');
      // Re-render agora refletirá o novo status (sem precisar refresh da página)
      renderBoard();
    } else {
      throw new Error(data.erro || 'Falha ao atualizar status');
    }
  } catch(e){
    console.error(e);
    showToast(e.message || 'Erro ao atualizar status','danger');
    refresh();
  }
}
async function handleMove(card,newColumn){
  const fromEntity = card.dataset.entity;
  const toEntity = newColumn.dataset.entity;
  // Movimento dentro da mesma entidade
  if(fromEntity === toEntity){
    // Caso especial: pedidos mudando de bucket de status
    if(fromEntity === 'pedido') {
      const oldStatus = (card.dataset.status||'').toLowerCase();
      const newStatus = (newColumn.dataset.status||'').toLowerCase();
      // Bloqueia alteração se aguardando aceite do cliente
      if(oldStatus === 'aguardando_aprovacao_cliente' && newStatus !== oldStatus){
        showToast('Pedido aguardando aceite do cliente. Alteração de status bloqueada.', 'warning');
        refresh();
        return;
      }
      if(newStatus && newStatus !== oldStatus){
        await updatePedidoStatus(card.dataset.id,newStatus,card);
      }
      return; // reorder visual ou já tratamos
    }
    return; // outras entidades ignoram reorder
  }
  // ---------------- PROGRESSÃO DE FASES ----------------
  if(fromEntity === 'requisicao' && toEntity === 'cotacao'){
    // Criar cotação para a requisição arrastada
    criarCotacao(card.dataset.id); // já faz refresh e toast
    return;
  }
  if(fromEntity === 'cotacao' && toEntity === 'proposta'){
    // Carregar propostas relativas à cotação (similar ao botão avançar)
    fetchPropostas(card.dataset.id).then(()=>{ renderBoard(); showToast('Propostas carregadas','info'); });
    return;
  }
  if(fromEntity === 'proposta' && toEntity === 'pedido'){
    // Aprovar proposta (gera pedido com status aguardando_aprovacao_cliente)
    const id = card.dataset.id;
    try {
      const r = await sendFormRequest('../api/propostas.php',{
        id,
        status:'aprovada',
        pedido_status:'aguardando_aprovacao_cliente'
      },'PUT');
      const j = await r.json();
      if(j.success){ showToast('Proposta aprovada, pedido aguardando aprovação do cliente','success'); refresh(); }
      else showToast('Falha ao aprovar','danger');
    } catch(e){ console.error(e); showToast('Erro rede','danger'); }
    return;
  }
  if(fromEntity === 'pedido'){
    // Mantém lógica existente de alteração de status via prompt ao arrastar para outra coluna (não permitido) – revertendo
    showToast('Não é possível mover pedidos para outra fase','warning');
    refresh();
    return;
  }
  // Caso não mapeado, revert
  showToast('Movimento não permitido','warning');
  refresh();
}
async function criarCotacao(requisicao_id){ const body=JSON.stringify({requisicao_id,status:'aberta'}); const res=await fetch('../api/cotacoes.php',{method:'POST',headers:{'Content-Type':'application/json'},body}); const j=await res.json(); if(j.success){ showToast('Cotação criada','success'); refresh(); } else showToast('Erro ao criar','danger'); }

// ==================== MODAL HELPERS ====================
function hideAllGroups(){ Object.values(GROUPS).forEach(g=> g.style.display='none'); }
function abrirModalCriar(entity){
  hideAllGroups();
  formEntity.value=entity; formId.value=''; formStatus.innerHTML='';
  if(entity==='requisicao'){
    GROUPS.titulo.style.display='block';
    GROUPS.cliente.style.display='block';
    GROUPS.status.style.display='block';
    setStatusOptions(['aberta','fechada'],'aberta');
  }
  modal.show();
  document.getElementById('modalCardLabel').textContent='Nova '+entity.charAt(0).toUpperCase()+entity.slice(1);
}
async function onEditCard(e){
  const card=e.target.closest('.kanban-card');
  const entity=card.dataset.entity;
  hideAllGroups();
  formEntity.value=entity; formId.value=card.dataset.id; formStatus.innerHTML='';
  formTitulo.value=''; formClienteId.value=''; formRequisicaoId.value=''; formCotacaoId.value=''; formPropostaId.value=''; formFornecedorId.value=''; formValorTotal.value=''; formPrazoEntrega.value=''; formObservacoes.value=''; formPdfUrl.value=''; formRodada.value=''; formImagemUrl.value=''; formToken.value=''; formTokenExpira.value=''; itensBody.innerHTML='<tr><td colspan="3" class="text-secondary small">Clique em carregar.</td></tr>';
  const curStatus = (card.dataset.status||'');
  if(entity==='requisicao'){
    GROUPS.titulo.style.display='block';
    GROUPS.cliente.style.display='block';
    GROUPS.status.style.display='block';
    const req = STATE.requisicoes.find(r => String(r.id) === String(card.dataset.id));
    formTitulo.value = req?.titulo || '';
    if(formClienteId){
      const value = req?.cliente_id ? String(req.cliente_id) : '';
      const optionList = Array.from(formClienteId.options || []);
      if(value && !optionList.some(opt => String(opt.value) === value)){
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = `Cliente #${value}`;
        formClienteId.appendChild(opt);
      }
      formClienteId.value = value;
    }
    setStatusOptions(['aberta','fechada'], req?.status || curStatus);
  }
  else if(entity==='cotacao'){
    const cot = STATE.cotacoes.find(c=> c.id==card.dataset.id);
    GROUPS.requisicao.style.display='block';
    GROUPS.rodada.style.display='block';
    GROUPS.status.style.display='block';
    GROUPS.token.style.display='block';
    GROUPS.tokenExpira.style.display='block';
    formRequisicaoId.value=cot?.requisicao_id||'';
    formRodada.value=cot?.rodada||1;
    formToken.value=cot?.token||'';
    formTokenExpira.value=cot?.token_expira_em||'';
    setStatusOptions(['aberta','encerrada'], cot?.status || curStatus);
  }
  else if(entity==='proposta'){
    const p = STATE.propostas.find(p=> p.id==card.dataset.id);
    if(p){
      GROUPS.cotacao.style.display='block';
      GROUPS.proposta.style.display='block';
      GROUPS.fornecedor.style.display='block';
      GROUPS.valor.style.display='block';
      GROUPS.prazo.style.display='block';
      GROUPS.observacoes.style.display='block';
      GROUPS.imagem.style.display='block';
      GROUPS.items.style.display='block';
      GROUPS.status.style.display='block';
      formCotacaoId.value=p.cotacao_id; formPropostaId.value=p.id; formFornecedorId.value=p.fornecedor_id; formValorTotal.value=p.valor_total; formPrazoEntrega.value=p.prazo_entrega; formObservacoes.value=p.observacoes||''; formImagemUrl.value=p.imagem_url||'';
      setStatusOptions(['enviada','aprovada','rejeitada'], p.status || curStatus);
    }
  }
  else if(entity==='pedido'){
    const pedido = STATE.pedidos.find(p=> p.id==card.dataset.id);
    if(pedido){
      GROUPS.proposta.style.display='block';
      GROUPS.pdf.style.display='block';
      GROUPS.status.style.display='block';
      formPropostaId.value=pedido.proposta_id; formPdfUrl.value=pedido.pdf_url||'';
  setStatusOptions(['aguardando_aprovacao_cliente','pendente','emitido','em_producao','enviado','entregue','cancelado'], pedido.status || curStatus);
      // Bloqueio visual e de ação se aguardando aceite
      const locked = isPedidoLocked(pedido.status||curStatus);
      formStatus.disabled = !!locked;
      if(pedidoLockedHint){ pedidoLockedHint.classList.toggle('d-none', !locked); }
      if(btnSalvarCard){ btnSalvarCard.disabled = !!locked; }
    }
  }
  document.getElementById('modalCardLabel').textContent='Editar '+entity; modal.show();
  if(entity==='proposta'){
    setTimeout(()=>{ if(formId.value) loadPropostaItens(formId.value); },150);
  }
}

// ==================== SAVE ====================
async function salvarForm(){
  const entity=formEntity.value;
  if(entity==='requisicao'){
    const payload={ titulo: formTitulo.value, cliente_id: formClienteId.value||'', status: formStatus.value };
    if(formId.value){
      const res=await sendFormRequest('../api/requisicoes.php',{ id: formId.value, ...payload },'PUT');
      const j=await res.json();
      if(j.success){ showToast('Requisição atualizada','success'); modal.hide(); refresh(); }
      else showToast('Erro','danger');
    } else {
      const res=await fetch('../api/requisicoes.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      const j=await res.json();
      if(j.success){ showToast('Requisição criada','success'); modal.hide(); refresh(); }
      else showToast('Erro','danger');
    }
  }
  else if(entity==='cotacao'){
    const res=await sendFormRequest('../api/cotacoes.php',{
      id: formId.value,
      status: formStatus.value,
      rodada: formRodada.value||1
    },'PUT');
    const j=await res.json();
    if(j.success){ showToast('Cotação atualizada','success'); modal.hide(); refresh(); }
    else showToast('Erro','danger');
  }
  else if(entity==='proposta'){
    const res=await sendFormRequest('../api/propostas.php',{
      id: formId.value,
      status: formStatus.value,
      valor_total: formValorTotal.value||'',
      prazo_entrega: formPrazoEntrega.value||'',
      observacoes: formObservacoes.value||'',
      imagem_url: formImagemUrl.value||'',
      fornecedor_id: formFornecedorId.value||''
    },'PUT');
    const j=await res.json();
    if(j.success){ showToast('Proposta atualizada','success'); modal.hide(); refresh(); }
    else showToast('Erro','danger');
  }
  else if(entity==='pedido'){
    // Verificação de bloqueio antes de enviar
    const pedido = STATE.pedidos.find(p=> String(p.id)===String(formId.value));
    if(pedido && isPedidoLocked(pedido.status) && formStatus.value !== 'aguardando_aprovacao_cliente'){
      showToast('Pedido aguardando aceite do cliente. Alteração de status bloqueada.', 'warning');
      return;
    }
    const res=await sendFormRequest('../api/pedidos.php',{
      id: formId.value,
      status: formStatus.value,
      pdf_url: formPdfUrl.value||''
    },'PUT');
    const j=await res.json();
    if(j.success){ showToast('Pedido atualizado','success'); modal.hide(); refresh(); }
    else showToast('Erro','danger');
  }
}

// ==================== DELETE ====================
async function onDeleteCard(e){ 
  let ok = true; 
  if(window.confirmDialog){ ok = await window.confirmDialog({ title:'Remover', message:'Remover item?', variant:'danger', confirmText:'Remover', cancelText:'Cancelar' }); }
  else { ok = confirm('Remover item?'); }
  if(!ok) return; 
  const card=e.target.closest('.kanban-card'); const entity=card.dataset.entity; const id=card.dataset.id; let endpoint=''; if(entity==='requisicao') endpoint='../api/requisicoes.php'; else if(entity==='cotacao') endpoint='../api/cotacoes.php'; else if(entity==='pedido') endpoint='../api/pedidos.php'; else if(entity==='proposta'){ showToast('Remoção de proposta não implementada aqui','warning'); return; } const res=await sendFormRequest(endpoint,{ id },'DELETE'); const j=await res.json(); if(j.success){ showToast('Removido','success'); refresh(); } else showToast('Erro','danger'); }

// ==================== PROPOSTA ITENS LOADER (NEW) ====================
function loadPropostaItens(propostaId, opts={force:false}){
  if(!propostaId){ itensBody.innerHTML='<tr><td colspan="3" class="text-secondary small">ID inválido</td></tr>'; return; }
  itensBody.innerHTML='<tr><td colspan="3" class="text-secondary small">Carregando...</td></tr>';
  fetch('../api/proposta_itens.php?proposta_id='+encodeURIComponent(propostaId))
    .then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
    .then(itens=>{
      if(!Array.isArray(itens) || !itens.length){ itensBody.innerHTML='<tr><td colspan="3" class="text-secondary small">Sem itens</td></tr>'; return; }
      let total = 0;
      itensBody.innerHTML = itens.map(it=>{
        const preco = it.preco_unitario? parseFloat(it.preco_unitario):0; total += preco * (parseFloat(it.quantidade)||0);
        return `<tr><td class='text-truncate' title='${it.nome||it.produto_id}'>${it.nome||('Prod '+it.produto_id)}</td><td class='text-end'>${it.quantidade}</td><td class='text-end'>${preco? preco.toFixed(2):'-'}</td></tr>`; }).join('');
      // Atualiza valor total visual se campo presente e vazio
      if(formEntity.value==='proposta' && formValorTotal && (!formValorTotal.value || opts.forceTotal)){
        formValorTotal.value = total.toFixed(2);
      }
    })
    .catch(err=>{ console.error('Falha itens proposta', err); itensBody.innerHTML='<tr><td colspan="3" class="text-danger small">Erro ao carregar</td></tr>'; });
}

// ==================== SEARCH ====================
function filterKanbanCards(query){
  const q=(query||'').toLowerCase();
  const list = document.getElementById('kanban-board');
  if(!list) return;
  list.querySelectorAll('.kanban-card').forEach(c=>{
    const text=c.textContent.toLowerCase();
    c.style.display=text.includes(q)?'flex':'none';
  });
}
// (Listener attached later in SAFE BINDINGS)

// ==================== DATA LOADERS (ADDED) ====================
async function fetchAll(){
  try{
    const [requisicoes, cotacoes, propostas, pedidos] = await Promise.all([
      fetch('../api/requisicoes.php').then(r=>r.json()),
      fetch('../api/cotacoes.php?include_token=1').then(r=>r.json()),
      fetch('../api/propostas.php').then(r=>r.json()),
      fetch('../api/pedidos.php').then(r=>r.json()),
    ]);
    STATE.requisicoes = Array.isArray(requisicoes)? requisicoes : [];
    STATE.cotacoes    = Array.isArray(cotacoes)?    cotacoes    : [];
    STATE.propostas   = Array.isArray(propostas)?   propostas   : [];
    STATE.pedidos     = Array.isArray(pedidos)?     pedidos     : [];
    renderBoard();
  }catch(err){ console.error(err); showToast('Erro ao carregar o Kanban','danger'); }
}

async function fetchPropostas(cotacaoId){
  try{
    const list = await fetch('../api/propostas.php?cotacao_id='+encodeURIComponent(cotacaoId)).then(r=>r.json());
    if(Array.isArray(list)){
      STATE.propostas = STATE.propostas.filter(p=> String(p.cotacao_id)!==String(cotacaoId)).concat(list);
    }
  }catch(err){ console.error(err); showToast('Erro ao carregar propostas','danger'); }
}

function cancelReqItemEdit(){
  if(!REQ_EDITING_ROW) return;
  const tr = REQ_EDITING_ROW;
  const qtyCell = tr.querySelector('[data-col="qty"]');
  const actionsCell = tr.querySelector('[data-col="actions"]');
  if(qtyCell && tr.dataset.originalQtyHtml){ qtyCell.innerHTML = tr.dataset.originalQtyHtml; }
  if(actionsCell && tr.dataset.originalActionsHtml){ actionsCell.innerHTML = tr.dataset.originalActionsHtml; }
  tr.classList.remove('req-editing');
  delete tr.dataset.originalQtyHtml;
  delete tr.dataset.originalActionsHtml;
  REQ_EDITING_ROW = null;
}

function enterReqItemEdit(tr){
  if(!tr) return;
  if(REQ_EDITING_ROW && REQ_EDITING_ROW!==tr){ cancelReqItemEdit(); }
  const qtyCell = tr.querySelector('[data-col="qty"]');
  const actionsCell = tr.querySelector('[data-col="actions"]');
  if(!qtyCell || !actionsCell) return;
  tr.dataset.originalQtyHtml = qtyCell.innerHTML;
  tr.dataset.originalActionsHtml = actionsCell.innerHTML;
  tr.classList.add('req-editing');
  const currentQty = tr.querySelector('.req-item-qtd')?.textContent?.trim() || '1';
  qtyCell.innerHTML = `<div class="input-group input-group-sm req-item-edit-input">
    <span class="input-group-text">Qtd</span>
    <input type="number" min="1" class="form-control" value="${currentQty}" aria-label="Quantidade">
  </div>`;
  actionsCell.innerHTML = `<div class="req-item-actions">
    <button type="button" class="btn btn-outline-secondary btn-sm" data-cancel-inline>Cancelar</button>
    <button type="button" class="btn btn-matrix-primary btn-sm" data-save-inline>Salvar</button>
  </div>`;
  REQ_EDITING_ROW = tr;
  const input = qtyCell.querySelector('input');
  if(input){ input.focus(); input.select(); }
}

async function carregarProdutosRequisicao(force=false){
  if(PRODUTOS_CACHE.length && !force) return;
  try {
    const r = await fetch('../api/produtos.php');
    if(!r.ok) throw new Error('HTTP '+r.status);
    PRODUTOS_CACHE = await r.json();
    reqProdutoSelect.innerHTML = PRODUTOS_CACHE.map(p=>`<option value="${p.id}">${p.nome}</option>`).join('');
  } catch(e){ console.error(e); showToast('Erro produtos','danger'); }
}

async function listarItensRequisicao(){
  if(!REQUISICAO_ITENS_REQUISICAO_ID){
    updateReqItensCount('empty');
    return;
  }
  cancelReqItemEdit();
  updateReqItensCount('loading');
  reqItensBody.innerHTML = `<tr class=\"empty-row\"><td colspan=\"4\" class=\"text-center\">Carregando...</td></tr>`;
  try {
    const r = await fetch('../api/requisicao_itens.php?requisicao_id='+REQUISICAO_ITENS_REQUISICAO_ID);
    if(!r.ok) throw new Error('HTTP '+r.status);
    const itens = await r.json();
    if(!Array.isArray(itens) || !itens.length){
      reqItensBody.innerHTML = `<tr class=\"empty-row\"><td colspan=\"4\" class=\"text-center\">Sem itens</td></tr>`;
      updateReqItensCount('empty');
      return;
    }
    reqItensBody.innerHTML = itens.map(it=>{
      const nome = it.nome || `Produto ${it.produto_id || ''}`;
      const subtitle = it.produto_id ? `ID produto ${it.produto_id}` : '';
  return `<tr data-id="${it.id}" data-produto="${it.produto_id || ''}">
        <td>
          <span class="req-item-id-pill"><i class="bi bi-hash"></i>${it.id}</span>
        </td>
        <td class="req-item-produto text-truncate" title="${nome}">
          <div class="fw-semibold">${nome}</div>
          ${subtitle ? `<small class="text-secondary">${subtitle}</small>` : ''}
        </td>
        <td class="text-end" data-col="qty">
          <span class="req-item-qtd badge rounded-pill">${it.quantidade}</span>
        </td>
        <td class="text-end" data-col="actions">
          <div class="req-item-actions">
            <button type="button" class="btn-table-action" data-edit-item title="Editar quantidade"><i class="bi bi-pencil"></i></button>
            <button type="button" class="btn-table-action" data-del-item title="Remover"><i class="bi bi-x-lg"></i></button>
          </div>
        </td>
      </tr>`;
    }).join('');
    updateReqItensCount('filled', itens.length);
  } catch(e){
    console.error(e);
    reqItensBody.innerHTML = `<tr class=\"empty-row\"><td colspan=\"4\" class=\"text-danger text-center\">Erro</td></tr>`;
    updateReqItensCount('error');
  }
}

function abrirItensRequisicao(requisicaoId){
  REQUISICAO_ITENS_REQUISICAO_ID = requisicaoId;
  document.getElementById('modalItensRequisicaoLabel').textContent = 'Itens da Requisição #'+requisicaoId;
  carregarProdutosRequisicao();
  listarItensRequisicao();
  if(modalItens) modalItens.show();
}

if(btnAddReqItem){
  btnAddReqItem.addEventListener('click', async e=>{
    e.preventDefault();
    if(!REQUISICAO_ITENS_REQUISICAO_ID) return;
    const produto_id = reqProdutoSelect.value;
    const quantidade = parseInt(reqItemQtd.value||'1',10);
    if(!produto_id || quantidade<=0){ showToast('Dados inválidos','warning'); return; }
    btnAddReqItem.disabled = true;
    try {
      const res = await fetch('../api/requisicao_itens.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({requisicao_id: REQUISICAO_ITENS_REQUISICAO_ID, produto_id, quantidade})});
      const j = await res.json();
      if(j.success){ showToast('Item adicionado','success'); reqItemQtd.value='1'; listarItensRequisicao(); }
      else showToast('Falha ao adicionar','danger');
    } catch(err){ console.error(err); showToast('Erro rede','danger'); }
    finally { btnAddReqItem.disabled=false; }
  });
}

if(reqItensBody){
  reqItensBody.addEventListener('click', async e=>{
    if(e.target.closest('[data-cancel-inline]')){
      e.preventDefault();
      cancelReqItemEdit();
      return;
    }
    if(e.target.closest('[data-save-inline]')){
      e.preventDefault();
      const tr = REQ_EDITING_ROW;
      if(!tr) return;
      const input = tr.querySelector('[data-col="qty"] input');
      const quantidade = parseInt(input?.value||'0',10);
      const targetId = tr.dataset.id;
      if(!targetId || isNaN(quantidade) || quantidade<=0){ showToast('Qtd inválida','warning'); return; }
      let produto_id = tr?.dataset?.produto || '';
      if(!produto_id){
        const nomeCell = tr?.querySelector('.req-item-produto .fw-semibold');
        const linhaProdutoNome = (nomeCell?.textContent || '').trim();
        if(linhaProdutoNome){
          produto_id = PRODUTOS_CACHE.find(p=> p.nome===linhaProdutoNome)?.id;
          if(!produto_id){
            await carregarProdutosRequisicao(true);
            produto_id = PRODUTOS_CACHE.find(p=> p.nome===linhaProdutoNome)?.id || '';
          }
        }
      }
      if(!produto_id && reqProdutoSelect){
        produto_id = reqProdutoSelect.value;
      }
      const saveBtn = e.target.closest('[data-save-inline]');
      const originalLabel = saveBtn?.innerHTML;
      if(saveBtn){ saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
      try {
        const r = await sendFormRequest('../api/requisicao_itens.php',{ id: targetId, produto_id, quantidade },'PUT');
        const j = await r.json();
        if(j.success){ showToast('Atualizado','success'); cancelReqItemEdit(); listarItensRequisicao(); }
        else showToast('Erro','danger');
      } catch(err){ console.error(err); showToast('Erro','danger'); }
      finally {
        if(saveBtn){ saveBtn.disabled=false; saveBtn.innerHTML = originalLabel || 'Salvar'; }
      }
      return;
    }
    const tr = e.target.closest('tr[data-id]');
    if(!tr) return;
    const id = tr.dataset.id;
    if(e.target.closest('[data-del-item]')){
      cancelReqItemEdit();
      let ok = true;
      if(window.confirmDialog){ ok = await window.confirmDialog({ title:'Remover', message:'Remover item?', variant:'danger', confirmText:'Remover', cancelText:'Cancelar' }); }
      else { ok = confirm('Remover item?'); }
      if(!ok) return;
      try {
        const r = await sendFormRequest('../api/requisicao_itens.php',{ id },'DELETE');
        const j=await r.json();
        if(j.success){ showToast('Removido','success'); listarItensRequisicao(); }
        else showToast('Erro','danger');
      } catch(err){ console.error(err); showToast('Erro','danger'); }
    } else if(e.target.closest('[data-edit-item]')){
      enterReqItemEdit(tr);
    }
  });
}

// Expose abrirItensRequisicao globally for existing handlers
window.abrirItensRequisicao = abrirItensRequisicao;

// Regenerar token dentro do modal de edição de cotação (se aberto)
if(typeof btnRegenerarToken !== 'undefined' && btnRegenerarToken){
  btnRegenerarToken.addEventListener('click', async ()=>{
    if(!formId.value || formEntity.value!=='cotacao'){ showToast('Abra uma cotação para regenerar','warning'); return; }
    btnRegenerarToken.disabled=true; const original=btnRegenerarToken.innerHTML; btnRegenerarToken.innerHTML='<span class="spinner-border spinner-border-sm"></span>';
    try {
      const r = await sendFormRequest('../api/cotacoes.php',{ id: formId.value, regenerar_token: 1 },'PUT');
      const j = await r.json();
      if(j.success){
        formToken.value = j.token; formTokenExpira.value = j.expira;
        // Atualiza STATE e card dataset
        const cot = STATE.cotacoes.find(c=> c.id==formId.value);
        if(cot){ cot.token=j.token; cot.token_expira_em=j.expira; }
        const card = document.querySelector(`.kanban-card[data-entity='cotacao'][data-id='${formId.value}']`);
        if(card) card.dataset.token = j.token;
        showToast('Token regenerado','success');
      } else showToast('Falha ao regenerar','danger');
    } catch(err){ console.error(err); showToast('Erro rede','danger'); }
    finally { btnRegenerarToken.disabled=false; btnRegenerarToken.innerHTML=original; }
  });
}

// Carregar itens de proposta no modal (botão listar itens) - UPDATED
if(typeof btnCarregarItens !== 'undefined' && btnCarregarItens){
  btnCarregarItens.addEventListener('click', ()=>{
    if(formEntity.value!=='proposta' || !formId.value){ showToast('Abra uma proposta','warning'); return; }
    loadPropostaItens(formId.value, {force:true});
  });
}

// Removida redefinição duplicada de onEditCard; apenas carregamento adicional ao mostrar modal.
modalEl.addEventListener('shown.bs.modal', ()=>{
  if(formEntity.value==='proposta' && formId.value){ loadPropostaItens(formId.value); }
});
</script>
<script>
// ==================== SAFE BINDINGS (toolbar moved to navbar or on-page) ====================
(function(){
  try{
    const salvarBtnEl = document.getElementById('btnSalvarCard');
    if(salvarBtnEl) salvarBtnEl.addEventListener('click', salvarForm);
  }catch(e){}
  try{
    const novaReqBtn = document.getElementById('btnNovaRequisicao');
    if(novaReqBtn) novaReqBtn.addEventListener('click', ()=> abrirModalCriar('requisicao'));
  }catch(e){}
  try{
    const refreshBtn = document.getElementById('btnRefresh');
    if(refreshBtn) refreshBtn.addEventListener('click', refresh);
  }catch(e){}
  try{
    const SEARCH = document.getElementById('kanban-search');
    if(SEARCH) SEARCH.addEventListener('input', ()=> filterKanbanCards(SEARCH.value));
  }catch(e){}
  try{ if(typeof fetchAll==='function') fetchAll(); }catch(e){}
})();
</script>
</body>
</html>
