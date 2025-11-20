<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php';
$u = auth_usuario();
if (!isset($_SESSION['usuario_id']) || !$u || ($u['tipo'] ?? '') === 'cliente') {
    header('Location: login.php');
    exit;
}
$app = $branding['app_name'] ?? 'Dekanto';
$reqIdFromQuery = isset($_GET['requisicao_id']) ? (int)$_GET['requisicao_id'] : 0;
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ranking de Cota��es - <?= htmlspecialchars($app) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap/icons.css">
<link rel="stylesheet" href="../assets/css/nexus.css">
<style>
.ranking-card{overflow:hidden}
.badge-score{background:rgba(128,114,255,.15);border:1px solid var(--matrix-border);color:#fff;font-weight:700;letter-spacing:.4px}
.breakdown{font-size:.75rem;color:#cdd0ff}
.table-ranking tbody tr.best{background:rgba(77,171,181,.08)}
.table-ranking tbody tr.best td{border-top:2px solid rgba(77,171,181,.35)}
.table-ranking tbody tr td .small{color:#9aa0c8}
/* Barras de contribui��o */
.stack-bars{display:flex;gap:2px;justify-content:flex-end;margin-top:4px}
.stack-bars .bar{height:6px;border-radius:3px}
.stack-bars .bar-preco{background:#7c3aed}
.stack-bars .bar-prazo{background:#06b6d4}
.stack-bars .bar-pag{background:#10b981}
/* Filtros */
.filters-wrap{display:flex;gap:.5rem;align-items:center}
/* Pesos */
.pesos-panel{background:rgba(128,114,255,.08); border:1px solid var(--matrix-border); border-radius:12px; padding:.75rem}
.pesos-panel .form-range{height:1.1rem}
.pesos-panel .peso-item{display:grid; grid-template-columns:120px 1fr 70px; align-items:center; gap:.6rem; margin:.35rem 0}
.peso-badge{font-size:.7rem; color:#cfd2ff}
/* A��es em Lote */
.bulk-actions{background:rgba(128,114,255,.08);border:1px solid var(--matrix-border);border-radius:12px;padding:.6rem .8rem;margin:.75rem 0;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
.bulk-actions .form-control{min-width:260px}
.bulk-actions .count{font-size:.8rem;color:#cfd2ff}
.table-ranking th.sel-col, .table-ranking td.sel-col{width:36px}
</style>
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container py-4">
  <header class="d-flex justify-content-between align-items-end mb-3">
    <div>
      <h1 class="page-title h3 mb-1">Ranking de Cota��es</h1>
      <p class="text-secondary mb-0">Compare propostas por pre�o, prazo e pagamento.</p>
    </div>
    <div class="filters-wrap">
      <div class="input-group input-group-sm" style="width:220px;">
        <span class="input-group-text bg-transparent border-secondary-subtle"><i class="bi bi-search"></i></span>
        <input id="filtroFornecedor" type="text" class="form-control" placeholder="Filtrar fornecedor...">
      </div>
      <select id="ordem" class="form-select form-select-sm" style="width:180px;">
        <option value="score_desc" selected>Score (maior ? menor)</option>
        <option value="preco_asc">Pre�o (menor ? maior)</option>
        <option value="prazo_asc">Prazo (menor ? maior)</option>
        <option value="pag_desc">Pagamento (maior ? menor)</option>
      </select>
      <button id="btnTogglePesos" class="btn btn-matrix-secondary-outline btn-sm"><i class="bi bi-sliders"></i> Ajustar Pesos</button>
      <button id="btnRecarregar" class="btn btn-matrix-secondary-outline btn-sm"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
  </header>

  <div id="pesosWrap" class="pesos-panel mb-3 d-none">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <div class="fw-semibold">Pesos do Score (visual, n�o persiste)</div>
      <div class="peso-badge">Soma: <span id="pesoSoma">1.00</span></div>
    </div>
    <div class="peso-item">
      <label class="nx-label mb-0">Pre�o</label>
      <input type="range" class="form-range" id="pesoPreco" min="0" max="1" step="0.05">
      <input type="number" id="pesoPrecoNum" class="form-control form-control-sm" step="0.05" min="0" max="1">
    </div>
    <div class="peso-item">
      <label class="nx-label mb-0">Prazo</label>
      <input type="range" class="form-range" id="pesoPrazo" min="0" max="1" step="0.05">
      <input type="number" id="pesoPrazoNum" class="form-control form-control-sm" step="0.05" min="0" max="1">
    </div>
    <div class="peso-item">
      <label class="nx-label mb-0">Pagamento</label>
      <input type="range" class="form-range" id="pesoPag" min="0" max="1" step="0.05">
      <input type="number" id="pesoPagNum" class="form-control form-control-sm" step="0.05" min="0" max="1">
    </div>
    <div class="d-flex gap-2 mt-2">
      <button id="btnAplicarPesos" class="btn btn-matrix-primary btn-sm"><i class="bi bi-check2"></i> Aplicar</button>
      <button id="btnResetPesos" class="btn btn-matrix-secondary btn-sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
      <span class="text-secondary small ms-auto">Servidor usa valores configurados; esta visualiza��o recalcula localmente.</span>
    </div>
  </div>

  <div class="card-matrix ranking-card">
    <div class="card-header-matrix d-flex flex-wrap gap-2 align-items-center">
      <span><i class="bi bi-list-ol me-2"></i>Selecione a Requisi��o</span>
      <div class="ms-auto d-flex gap-2 align-items-center">
        <select id="selectRequisicao" class="form-select" style="min-width:280px">
          <option value="" disabled selected>Carregando...</option>
        </select>
        <span id="kpiResumo" class="badge badge-score px-3 py-2 d-none"></span>
        <div class="btn-group btn-group-sm">
          <button id="btnExportCsv" class="btn btn-matrix-secondary-outline" disabled><i class="bi bi-filetype-csv me-1"></i>CSV</button>
          <button id="btnExportXlsx" class="btn btn-matrix-secondary-outline" disabled><i class="bi bi-file-earmark-excel me-1"></i>XLSX</button>
          <button id="btnExportPdf" class="btn btn-matrix-secondary-outline" disabled><i class="bi bi-file-earmark-pdf me-1"></i>PDF</button>
        </div>
      </div>
    </div>
    <div class="p-3">
      <div id="alertSemDados" class="alert alert-warning d-none">Nenhuma proposta encontrada para esta requisi��o.</div>

      <div class="bulk-actions d-none" id="bulkBar">
        <span class="count" id="selCount">0 selecionadas</span>
        <input id="txtJustificativa" type="text" class="form-control form-control-sm" placeholder="Justificativa (opcional) para decis�o">
        <div class="btn-group btn-group-sm">
          <button id="btnAprovarUma" class="btn btn-matrix-primary" title="Aprovar exatamente 1 selecionada"><i class="bi bi-trophy me-1"></i>Definir vencedora</button>
          <button id="btnRejeitarSel" class="btn btn-matrix-danger" title="Rejeitar todas as selecionadas"><i class="bi bi-x-circle me-1"></i>Rejeitar selecionadas</button>
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover table-ranking mb-0">
          <thead>
            <tr>
              <th class="sel-col"><input type="checkbox" id="chkAll"></th>
              <th style="width:70px">#</th>
              <th>Fornecedor</th>
              <th class="text-end" style="width:140px">Valor Total</th>
              <th class="text-end" style="width:130px">Prazo (dias)</th>
              <th class="text-end" style="width:130px">Pagamento (dias)</th>
              <th class="text-end" style="width:160px">Score</th>
              <th class="text-end" style="width:220px">A��es</th>
            </tr>
          </thead>
          <tbody id="tbodyRanking">
            <tr><td colspan="8" class="text-center text-secondary py-4">Selecione uma requisi��o acima.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Modal Detalhes Proposta -->
<div class="modal fade" id="modalProposta" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title"><i class="bi bi-file-earmark-text me-2"></i>Detalhes da Proposta</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div id="detalhesPropostaBody">Carregando...</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const selectRequisicao = document.getElementById('selectRequisicao');
const tbody = document.getElementById('tbodyRanking');
const alertSemDados = document.getElementById('alertSemDados');
const kpiResumo = document.getElementById('kpiResumo');
const btnRecarregar = document.getElementById('btnRecarregar');
const filtroFornecedor = document.getElementById('filtroFornecedor');
const ordemSel = document.getElementById('ordem');
const btnCsv = document.getElementById('btnExportCsv');
const btnXlsx = document.getElementById('btnExportXlsx');
const btnPdf = document.getElementById('btnExportPdf');
const modalProposta = new bootstrap.Modal(document.getElementById('modalProposta'));
const detalhesPropostaBody = document.getElementById('detalhesPropostaBody');
const requisicaoQuery = <?= (int)$reqIdFromQuery ?>;
const btnTogglePesos = document.getElementById('btnTogglePesos');
const pesosWrap = document.getElementById('pesosWrap');
const pesoSoma = document.getElementById('pesoSoma');
const pesoPreco = document.getElementById('pesoPreco');
const pesoPrazo = document.getElementById('pesoPrazo');
const pesoPag = document.getElementById('pesoPag');
const pesoPrecoNum = document.getElementById('pesoPrecoNum');
const pesoPrazoNum = document.getElementById('pesoPrazoNum');
const pesoPagNum = document.getElementById('pesoPagNum');
const btnAplicarPesos = document.getElementById('btnAplicarPesos');
const btnResetPesos = document.getElementById('btnResetPesos');
const bulkBar = document.getElementById('bulkBar');
const selCountEl = document.getElementById('selCount');
const txtJust = document.getElementById('txtJustificativa');
const chkAll = document.getElementById('chkAll');
const btnAprovarUma = document.getElementById('btnAprovarUma');
const btnRejeitarSel = document.getElementById('btnRejeitarSel');

let RANKING_CACHE = [];
let PESOS = { preco: 0.6, prazo: 0.25, pag: 0.15 };
let SELECIONADAS = new Set();

function syncPesoInputs(){
  pesoPreco.value = PESOS.preco; pesoPrazo.value = PESOS.prazo; pesoPag.value = PESOS.pag;
  pesoPrecoNum.value = PESOS.preco.toFixed(2); pesoPrazoNum.value = PESOS.prazo.toFixed(2); pesoPagNum.value = PESOS.pag.toFixed(2);
  pesoSoma.textContent = (PESOS.preco + PESOS.prazo + PESOS.pag).toFixed(2);
}
function setPesosFromInputs(){
  PESOS.preco = Math.max(0, Math.min(1, parseFloat(pesoPreco.value||'0')));
  PESOS.prazo = Math.max(0, Math.min(1, parseFloat(pesoPrazo.value||'0')));
  PESOS.pag = Math.max(0, Math.min(1, parseFloat(pesoPag.value||'0')));
  syncPesoInputs();
}
function setPesosFromNumberInputs(){
  PESOS.preco = Math.max(0, Math.min(1, parseFloat(pesoPrecoNum.value||'0')));
  PESOS.prazo = Math.max(0, Math.min(1, parseFloat(pesoPrazoNum.value||'0')));
  PESOS.pag = Math.max(0, Math.min(1, parseFloat(pesoPagNum.value||'0')));
  syncPesoInputs();
}

btnTogglePesos.addEventListener('click', ()=>{ pesosWrap.classList.toggle('d-none'); });
['input','change'].forEach(evt=>{
  pesoPreco.addEventListener(evt, setPesosFromInputs);
  pesoPrazo.addEventListener(evt, setPesosFromInputs);
  pesoPag.addEventListener(evt, setPesosFromInputs);
  pesoPrecoNum.addEventListener(evt, setPesosFromNumberInputs);
  pesoPrazoNum.addEventListener(evt, setPesosFromNumberInputs);
  pesoPagNum.addEventListener(evt, setPesosFromNumberInputs);
});
btnAplicarPesos.addEventListener('click', ()=>{ renderRanking(); showToast('Pesos aplicados na visualiza��o','success'); });
btnResetPesos.addEventListener('click', ()=>{ PESOS = {preco:0.6, prazo:0.25, pag:0.15}; syncPesoInputs(); renderRanking(); });

syncPesoInputs();

function updateBulkBar(){
  const n = SELECIONADAS.size;
  selCountEl.textContent = n + (n===1? ' selecionada':' selecionadas');
  bulkBar.classList.toggle('d-none', n===0);
}

function toggleSelect(id, checked){
  if(checked) SELECIONADAS.add(String(id)); else SELECIONADAS.delete(String(id));
  updateBulkBar();
}

function bindRowSelection(){
  document.querySelectorAll('.row-select').forEach(chk=>{
    chk.addEventListener('change', ()=> toggleSelect(chk.dataset.id, chk.checked));
  });
}

chkAll.addEventListener('change', ()=>{
  const checks = document.querySelectorAll('.row-select');
  checks.forEach(ch=>{ ch.checked = chkAll.checked; toggleSelect(ch.dataset.id, ch.checked); });
});

btnAprovarUma.addEventListener('click', async ()=>{
  if(SELECIONADAS.size !== 1){ showToast('Selecione exatamente 1 proposta para aprovar.','warning'); return; }
  const id = Array.from(SELECIONADAS)[0];
  const justificativa = txtJust.value.trim();
  const observ = justificativa? `[Ranking] ${justificativa}` : '[Ranking] Aprova��o via ranking';
  try{
    const body = `id=${encodeURIComponent(id)}&status=aprovada&observacoes=${encodeURIComponent(observ)}`;
    const r = await fetch('../api/propostas.php',{method:'PUT', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
    const j = await r.json();
    if(j.success){ showToast('Vencedora aprovada. Pedido emitido.','success'); SELECIONADAS.clear(); txtJust.value=''; carregarRanking(); }
    else showToast(j.erro||'Falha ao aprovar','danger');
  }catch(e){ console.error(e); showToast('Erro de rede','danger'); }
});

btnRejeitarSel.addEventListener('click', async ()=>{
  if(SELECIONADAS.size === 0){ showToast('Nenhuma proposta selecionada.','warning'); return; }
  let ok = true;
  if(window.confirmDialog){ ok = await window.confirmDialog({ title:'Rejeitar propostas', message:'Rejeitar '+SELECIONADAS.size+' proposta(s)?', variant:'danger', confirmText:'Rejeitar', cancelText:'Cancelar' }); }
  else { ok = confirm('Rejeitar '+SELECIONADAS.size+' proposta(s)?'); }
  if(!ok) return;
  const justificativa = txtJust.value.trim();
  const observ = justificativa? `[Ranking] ${justificativa}` : '[Ranking] Rejeição via ranking';
  const ids = Array.from(SELECIONADAS);
  try{
    const reqs = ids.map(id=>{
      const body = `id=${encodeURIComponent(id)}&status=rejeitada&observacoes=${encodeURIComponent(observ)}`;
      return fetch('../api/propostas.php',{method:'PUT', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body}).then(r=>r.json());
    });
    const results = await Promise.all(reqs);
    const ok = results.filter(x=> x && x.success).length;
    showToast(ok+' rejeitada(s).','success');
    SELECIONADAS.clear(); txtJust.value=''; carregarRanking();
  }catch(e){ console.error(e); showToast('Erro de rede','danger'); }
});

function breakdownHtml(comp){
  if(!comp) return '';
  const wPreco = Math.max(2, Math.round((comp.preco||0)*100));
  const wPrazo = Math.max(2, Math.round((comp.prazo||0)*100));
  const wPag = Math.max(2, Math.round((comp.pagamento||0)*100));
  return `<div class="breakdown text-secondary text-end">
    <span class="me-2">Pre�o: <b>${(comp.preco??0).toFixed(3)}</b></span>
    <span class="me-2">Prazo: <b>${(comp.prazo??0).toFixed(3)}</b></span>
    <span>Pagamento: <b>${(comp.pagamento??0).toFixed(2)}</b></span>
  </div>
  <div class="stack-bars">
    <div class="bar bar-preco" style="width:${wPreco}px"></div>
    <div class="bar bar-prazo" style="width:${wPrazo}px"></div>
    <div class="bar bar-pag" style="width:${wPag}px"></div>
  </div>`;
}

function scoreLocal(d){
  const c = d.componentes || {preco:0,prazo:0,pagamento:0};
  return (c.preco * PESOS.preco) + (c.prazo * PESOS.prazo) + (c.pagamento * PESOS.pag);
}

function applyFiltersAndSort(list){
  let data = list.map(x=> ({...x, score_local: scoreLocal(x)}));
  const f = (filtroFornecedor.value||'').toLowerCase().trim();
  if(f){ data = data.filter(d => (d.fornecedor_nome||'').toLowerCase().includes(f) || String(d.fornecedor_id||'').includes(f)); }
  const ord = ordemSel.value;
  data.sort((a,b)=>{
    switch(ord){
      case 'preco_asc': return (a.valor_total??Infinity) - (b.valor_total??Infinity);
      case 'prazo_asc': return (a.prazo_entrega??Infinity) - (b.prazo_entrega??Infinity);
      case 'pag_desc': return (b.pagamento_dias??-Infinity) - (a.pagamento_dias??-Infinity);
      case 'score_desc': default: return (b.score_local??0) - (a.score_local??0);
    }
  });
  return data;
}

function actionsCol(d, idx){
  const encerrarBtn = (d.cotacao_id && idx===0) ? `<button class=\"btn btn-matrix-danger btn-sm\" data-encerrar data-cot=\"${d.cotacao_id}\"><i class=\"bi bi-lock me-1\"></i>Encerrar</button>` : '';
  return `<div class=\"btn-group btn-group-sm\">${idx===0? `<button class=\"btn btn-matrix-primary\" data-aprovar data-id=\"${d.proposta_id}\"><i class=\"bi bi-trophy me-1\"></i>Aprovar</button>` : `<button class=\"btn btn-matrix-secondary-outline\" data-aprovar data-id=\"${d.proposta_id}\"><i class=\"bi bi-check2-circle me-1\"></i>Selecionar</button>`}<button class=\"btn btn-matrix-secondary-outline\" data-detalhes data-id=\"${d.proposta_id}\"><i class=\"bi bi-eye\"></i></button>${encerrarBtn}</div>`;
}

function rowSelectCell(id){ return `<input type='checkbox' class='form-check-input row-select' data-id='${id}'>`; }

function renderRanking(){
  const data = applyFiltersAndSort(RANKING_CACHE);
  if(!data.length){ alertSemDados.classList.remove('d-none'); tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">Sem propostas ap�s filtro.</td></tr>'; btnCsv.disabled=btnXlsx.disabled=btnPdf.disabled=true; bulkBar.classList.add('d-none'); return; }
  alertSemDados.classList.add('d-none');
  const precoMin = Math.min(...data.map(d=> d.valor_total||Infinity));
  tbody.innerHTML = data.map((d,idx)=>{
    const best = idx===0 ? 'best' : '';
    const fornecedor = escapeHtml(d.fornecedor_nome || ('Fornecedor #'+(d.fornecedor_id||'-')));
    return `<tr class="${best}">
      <td class='sel-col'>${rowSelectCell(d.proposta_id)}</td>
      <td><span class="font-monospace">${idx+1}�</span></td>
      <td>
        <div class="fw-semibold">${fornecedor}</div>
        <div class="small">Proposta #${d.proposta_id}${d.status? ' � '+escapeHtml(d.status): ''}</div>
      </td>
      <td class="text-end">R$ ${(Number(d.valor_total)||0).toLocaleString('pt-BR',{minimumFractionDigits:2, maximumFractionDigits:2})}${Number(d.valor_total)===precoMin? ' <span class="badge bg-success ms-1">menor</span>':''}</td>
      <td class="text-end">${d.prazo_entrega ?? '-'} </td>
      <td class="text-end">${d.pagamento_dias ?? '-'} </td>
      <td class="text-end">
        <div class="fw-bold">${Number(d.score_local).toFixed(4)} <span class="text-secondary small">(local)</span></div>
        ${breakdownHtml(d.componentes)}
      </td>
      <td class="text-end">${actionsCol(d, idx)}</td>
    </tr>`;
  }).join('');
  btnCsv.disabled=btnXlsx.disabled=btnPdf.disabled=false;
  // Rebind selection
  bindRowSelection();
  updateBulkBar();
}

async function carregarRequisicoes(){
  selectRequisicao.innerHTML = '<option disabled selected>Carregando...</option>';
  try{
    const r = await fetch('../api/requisicoes.php');
    const list = await r.json();
    if(!Array.isArray(list) || !list.length){ selectRequisicao.innerHTML = '<option disabled selected>Nenhuma requisi��o</option>'; return; }
    selectRequisicao.innerHTML = '<option value="" disabled selected>Selecione uma requisi��o...</option>' +
      list.map(r => `<option value="${r.id}">#${r.id} � ${escapeHtml(r.titulo||('Requisi��o '+r.id))}</option>`).join('');
    if(requisicaoQuery){ selectRequisicao.value = String(requisicaoQuery); if(selectRequisicao.value){ carregarRanking(); } }
  }catch(e){ console.error(e); showToast('Erro ao carregar requisi��es','danger'); }
}

function showToast(message, type='info'){
  const c=document.querySelector('.toast-container');
  const id='t'+Date.now();
  c.insertAdjacentHTML('beforeend',`<div id="${id}" class="toast align-items-center text-white bg-${type} border-0"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
  const el=document.getElementById(id); const t=new bootstrap.Toast(el,{delay:3000}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}

async function carregarRanking(){
  const id = selectRequisicao.value;
  if(!id){ tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">Selecione uma requisi��o acima.</td></tr>'; return; }
  tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">Carregando...</td></tr>';
  alertSemDados.classList.add('d-none');
  kpiResumo.classList.add('d-none');
  try{
    const r = await fetch('../api/cotacoes_ranking.php?requisicao_id='+encodeURIComponent(id));
    const data = await r.json();
    RANKING_CACHE = Array.isArray(data) ? data : [];
    if(!RANKING_CACHE.length){ alertSemDados.classList.remove('d-none'); tbody.innerHTML = '<tr><td colspan="8" class="text-center text-secondary py-4">Sem propostas para ranquear.</td></tr>'; btnCsv.disabled=btnXlsx.disabled=btnPdf.disabled=true; return; }
    kpiResumo.textContent = `Propostas ranqueadas: ${RANKING_CACHE.length}`;
    kpiResumo.classList.remove('d-none');
    renderRanking();
  }catch(e){ console.error(e); showToast('Erro ao gerar ranking','danger'); }
}

function escapeHtml(s){ return String(s||'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#39;'); }

async function aprovarProposta(id){
  let ok = true;
  if(window.confirmDialog){ ok = await window.confirmDialog({ title:'Aprovar proposta', message:'Aprovar esta proposta e gerar pedido?', variant:'primary', confirmText:'Aprovar', cancelText:'Cancelar' }); }
  else { ok = confirm('Aprovar esta proposta e gerar pedido?'); }
  if(!ok) return;
  try{
    const body = `id=${encodeURIComponent(id)}&status=aprovada`;
    const r = await fetch('../api/propostas.php',{method:'PUT', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body});
    const j = await r.json();
    if(j.success){ showToast('Proposta aprovada. Pedido emitido.','success'); carregarRanking(); }
    else showToast(j.erro||'Falha ao aprovar','danger');
  }catch(e){ console.error(e); showToast('Erro de rede','danger'); }
}

function exportar(formato){
  const ids = applyFiltersAndSort(RANKING_CACHE).map(x=> x.proposta_id).join(',');
  if(!ids){ showToast('Nada para exportar','warning'); return; }
  const f=document.createElement('form');
  f.method='POST';
  f.action='exportador.php';
  f.innerHTML = `<input type='hidden' name='modulo' value='ranking'>
                 <input type='hidden' name='requisicao_id' value='${selectRequisicao.value}'>
                 <input type='hidden' name='formato' value='${formato}'>
                 <input type='hidden' name='intervalo' value='filtrados'>
                 <input type='hidden' name='ids' value='${ids}'>
                 <input type='hidden' name='peso_preco' value='${PESOS.preco}'>
                 <input type='hidden' name='peso_prazo' value='${PESOS.prazo}'>
                 <input type='hidden' name='peso_pagamento' value='${PESOS.pag}'>`;
  document.body.appendChild(f); f.submit(); f.remove();
}

async function verDetalhesProposta(id){
  detalhesPropostaBody.innerHTML = 'Carregando...';
  try{
    const all = await fetch('../api/propostas.php');
    const arr = await all.json();
    const p = (Array.isArray(arr)? arr: []).find(x=> String(x.id) === String(id));
    if(!p){ detalhesPropostaBody.innerHTML = '<div class="text-danger">Proposta n�o encontrada.</div>'; modalProposta.show(); return; }
    const linhas = [
      ['ID', '#'+p.id],
      ['Cota��o', '#'+p.cotacao_id],
      ['Fornecedor', escapeHtml(p.nome_fantasia||p.razao_social||('Fornecedor #'+p.fornecedor_id))],
      ['Valor Total', p.valor_total? ('R$ '+Number(p.valor_total).toLocaleString('pt-BR',{minimumFractionDigits:2})):'-'],
      ['Prazo (dias)', p.prazo_entrega ?? '-'],
      ['Pagamento (dias)', p.pagamento_dias ?? '-'],
      ['Status', p.status || '-'],
      ['Observa��es', escapeHtml(p.observacoes||'-')],
      ['Imagem', p.imagem_url? `<a href='${escapeHtml(p.imagem_url)}' target='_blank'>Abrir</a>`:'-']
    ];
    detalhesPropostaBody.innerHTML = `<div class='table-responsive'><table class='table table-dark table-sm'>${linhas.map(l=>`<tr><th style='width:180px'>${l[0]}</th><td>${l[1]}</td></tr>`).join('')}</table></div>`;
  }catch(e){ console.error(e); detalhesPropostaBody.innerHTML = '<div class="text-danger">Erro ao carregar detalhes.</div>'; }
  modalProposta.show();
}

// Eventos
selectRequisicao.addEventListener('change', carregarRanking);
document.addEventListener('click', e=>{
  const btnA = e.target.closest('[data-aprovar]');
  if(btnA){ aprovarProposta(btnA.dataset.id); }
  const btnD = e.target.closest('[data-detalhes]');
  if(btnD){ verDetalhesProposta(btnD.dataset.id); }
});
btnRecarregar.addEventListener('click', carregarRanking);
filtroFornecedor.addEventListener('input', renderRanking);
ordemSel.addEventListener('change', renderRanking);
btnCsv.addEventListener('click', ()=> exportar('csv'));
btnXlsx.addEventListener('click', ()=> exportar('xlsx'));
btnPdf.addEventListener('click', ()=> exportar('pdf'));

carregarRequisicoes();
</script>
</body>
</html>
