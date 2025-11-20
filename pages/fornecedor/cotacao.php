<?php
session_start();
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    header('Location: ../login.php');
    exit;
}
// Buscar dados do fornecedor para pré-preencher (fluxo interno evita redigitação como no legacy público)
$db = get_db_connection();
$fornecedorRow = null;
try {
    $fid = (int)($u['fornecedor_id'] ?? 0);
    if($fid>0){
        $stF = $db->prepare('SELECT id, razao_social, nome_fantasia, cnpj FROM fornecedores WHERE id=? LIMIT 1');
        $stF->execute([$fid]);
        $fornecedorRow = $stF->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if(!$fornecedorRow){ // fallback via usuario_id
        $stF2 = $db->prepare('SELECT id, razao_social, nome_fantasia, cnpj FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF2->execute([$u['id']]);
        $fornecedorRow = $stF2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch(Throwable $e){ /* silencioso */ }
function format_cnpj($c){ $d=preg_replace('/\D/','',$c??''); if(strlen($d)!==14)return $c; return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2); }
$fornecedorNome = $fornecedorRow['razao_social'] ?? ($fornecedorRow['nome_fantasia'] ?? '');
$fornecedorCnpj = isset($fornecedorRow['cnpj']) ? format_cnpj($fornecedorRow['cnpj']) : '';
$cotacaoId = isset($_GET['id'])? (int)$_GET['id'] : 0;
if($cotacaoId<=0){ header('Location: cotacoes.php'); exit; }
$currentNav='cotacoes';
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cotação #<?php echo $cotacaoId; ?> - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../../assets/css/nexus.css">
<style>
/* Ajustes para aproximar da UI legacy (cotacao_responder.php) */
.page-header-gestao { margin-bottom: 2.1rem; padding-bottom:.6rem; border-bottom:1px solid var(--matrix-border); }
.page-title { font-size:1.7rem; }
.card-matrix .card-body { padding: 1.25rem 1.25rem; }
.table-cotacao th { white-space: nowrap; }
.table-cotacao input { min-width:110px; }
.valor-total-box { font-size:1.05rem; font-weight:600; }
.drop-zone{border:2px dashed var(--matrix-border,#333);padding:1.35rem;border-radius:.9rem;text-align:center;cursor:pointer;transition:.25s;background:rgba(255,255,255,.02)}
.drop-zone:hover{background:rgba(255,255,255,.06)}
.drop-zone.dragover{background:rgba(13,110,253,.08);border-color:#0d6efd}
.drop-zone.disabled{opacity:.55;pointer-events:none}
.drop-zone.pending{background:rgba(255,193,7,.08);border-color:#ffc107}
.drop-zone.pending:after{content:' (pendente de envio)';font-size:.7rem;color:#ffc107;display:block;margin-top:.25rem}
.card-anexos-list a{text-decoration:none;font-size:.8rem;display:flex;align-items:center;gap:.45rem;padding:.35rem .55rem;border-radius:6px;border:1px solid var(--matrix-border,#333);background:rgba(255,255,255,.03)}
.card-anexos-list a:hover{background:rgba(255,255,255,.07)}
.card-anexos-list .empty{font-size:.7rem;opacity:.7;padding:.25rem .4rem}
.card-anexos-list .anexo-entry{display:flex;align-items:center;gap:.45rem;margin-bottom:.35rem}
.card-anexos-list .anexo-entry:last-child{margin-bottom:0}
.card-anexos-list .btn-remove-anexo{border:none;background:rgba(248,113,113,.08);color:#f87171;border-radius:6px;padding:.3rem .45rem;display:inline-flex;align-items:center;justify-content:center;transition:.2s;line-height:1}
.card-anexos-list .btn-remove-anexo:hover,.card-anexos-list .btn-remove-anexo:focus-visible{background:rgba(248,113,113,.22);color:#dc2626}
#anexo-progress{height:6px} #anexo-progress .progress-bar{transition:width .25s}
.field-required:after { content:' *'; color:#ef4444; }
</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<div class="container py-4" id="cotacao-view" data-id="<?php echo $cotacaoId; ?>">
    <header class="page-header-gestao d-flex flex-wrap gap-3 justify-content-between align-items-start align-items-md-center">
        <div class="me-3">
            <h1 class="page-title mb-2">Responder Cotação</h1>
            <p class="text-secondary mb-0">Preencha preços e condições. Layout igual ao link público.</p>
        </div>
    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="badge bg-info-subtle text-info-emphasis" id="cot-status" data-bs-theme="light">-</span>
        </div>
    </header>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card-matrix mb-4">
                <div class="card-header-matrix"><i class="bi bi-info-circle me-2"></i>Dados da Cotação</div>
                <div class="card-body small text-secondary" id="cot-detalhes">
                    <div class="row g-3">
                        <div class="col-md-3"><strong>ID Cotação:</strong> #<?php echo $cotacaoId; ?></div>
                        <div class="col-md-3"><strong>ID Requisição:</strong> <span id="cot-req">-</span></div>
                        <div class="col-md-3"><strong>Rodada:</strong> <span id="cot-rodada">-</span></div>
                        <div class="col-md-3"><strong>Status:</strong> <span id="cot-status-inline">-</span></div>
                    </div>
                </div>
            </div>

            <form id="form-proposta" class="needs-validation" novalidate>
                <div class="card-matrix mb-4">
                    <div class="card-header-matrix"><i class="bi bi-building me-2"></i>Dados do Fornecedor</div>
                    <div class="card-body row g-3">
                        <div class="col-md-4">
                            <label class="form-label field-required">CNPJ</label>
                            <input type="text" class="form-control" name="cnpj" id="cnpj" value="<?php echo htmlspecialchars($fornecedorCnpj); ?>" readonly>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label field-required">Nome do Fornecedor</label>
                            <input type="text" class="form-control" name="fornecedor_nome" id="fornecedor_nome" value="<?php echo htmlspecialchars($fornecedorNome); ?>" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label field-required">Prazo Entrega (dias)</label>
                            <input type="number" min="0" class="form-control" name="prazo_entrega" required>
                            <div class="invalid-feedback">Informe o prazo.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pagamento (dias)</label>
                            <input type="number" min="0" class="form-control" name="pagamento_dias">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo de Frete</label>
                            <select class="form-select" name="tipo_frete" id="tipo_frete">
                                <option value="" selected disabled>(selecione)</option>
                                <option value="CIF">CIF</option>
                                <option value="FOB">FOB</option>
                                <option value="EXW">EXW</option>
                                <option value="DAP">DAP</option>
                                <option value="DDP">DDP</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Observações</label>
                            <textarea class="form-control" rows="3" name="observacoes" maxlength="1000" placeholder="Condições comerciais, impostos..."></textarea>
                        </div>
                    </div>
                    <div class="card-body pt-0 small text-secondary">Dados fixos do seu cadastro interno (CNPJ e Nome) – somente leitura no portal.</div>
                </div>

                <div class="card-matrix mb-4" id="card-itens">
                    <div class="card-header-matrix d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-2"></i>Itens da Cotação</span>
                        <span class="valor-total-box">Total: <span id="valor_total_display">R$ 0,00</span></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover table-cotacao mb-0" id="tabela-itens">
                            <thead>
                                <tr>
                                    <th>Produto</th>
                                    <th style="width:120px">Qtd</th>
                                    <th>Unid</th>
                                    <th style="width:150px">Preço Unit.</th>
                                    <th style="width:140px">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody><tr><td colspan="5" class="text-center text-secondary small py-3">Carregando itens...</td></tr></tbody>
                        </table>
                    </div>
                    <div class="card-body pt-0 small text-secondary">Quantidade fixa conforme requisição. Informe o preço unitário (mínimo um preço > 0).</div>
                    <input type="hidden" name="valor_total" id="valor_total_hidden" value="0">
                </div>

                <div class="card-matrix mb-4">
                    <div class="card-header-matrix"><i class="bi bi-paperclip me-2"></i>Anexo (Opcional)</div>
                    <div class="card-body">
                        <label class="form-label">Arquivo (imagem ou PDF)</label>
                        <!-- ALTERADO: remove estado inicial disabled para permitir seleção antes do envio -->
                        <div class="drop-zone" id="anexo-drop" data-disabled="0">
                            <i class="bi bi-cloud-upload fs-4 d-block mb-2"></i>
                            <span id="anexo-drop-text">Arraste ou clique para selecionar (1 arquivo). Será enviado juntamente com a proposta.</span>
                            <input type="file" class="d-none" id="anexo-arquivo" accept="image/*,application/pdf">
                        </div>
                        <div class="mt-2 small text-secondary" id="anexo-hint">Você pode escolher o arquivo antes de enviar a proposta. Ele será enviado automaticamente após a proposta ser criada.</div>
                        <div class="mt-3 card-anexos-list" id="lista-anexos"><div class="empty">Nenhum anexo ainda.</div></div>
                        <div class="mt-3 d-none" id="anexo-progress-wrap"><div class="progress" id="anexo-progress"><div class="progress-bar bg-success" style="width:0%"></div></div></div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-matrix-primary" id="btn-enviar-proposta"><i class="bi bi-send me-1"></i>Enviar Proposta</button>
                    <button type="button" class="btn btn-matrix-primary d-none" id="btn-salvar-edicao"><i class="bi bi-check2 me-1"></i>Salvar Alterações</button>
                    <button type="button" class="btn btn-matrix-secondary-outline d-none" id="btn-cancelar-edicao"><i class="bi bi-x-lg me-1"></i>Cancelar</button>
                </div>
                <div id="proposta-info-existente" class="d-none small text-secondary mt-n2 mb-4"></div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card-matrix mb-4">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-trophy me-2"></i>Ranking (Banda)</span>
                    <div id="proposta-acoes" class="d-none d-flex gap-2">
                        <button class="btn btn-sm btn-matrix-secondary-outline" id="btn-editar" title="Editar"><i class="bi bi-pencil"></i></button>
                        <a id="btn-exportar-pdf" target="_blank" class="btn btn-sm btn-matrix-secondary-outline disabled" aria-disabled="true" title="Exportar PDF"><i class="bi bi-filetype-pdf"></i></a>
                        <a id="btn-exportar-xlsx" target="_blank" class="btn btn-sm btn-matrix-secondary-outline disabled" aria-disabled="true" title="Exportar Excel"><i class="bi bi-file-earmark-spreadsheet"></i></a>
                    </div>
                </div>
                <div class="card-body" id="ranking-box">
                    <p class="mb-2 small text-secondary">Sua posição é exibida em bandas para preservar anonimato dos concorrentes.</p>
                    <div id="ranking-frase" class="fs-5 fw-semibold">—</div>
                    <div id="ranking-range" class="text-secondary small"></div>
                    <div id="ranking-meta" class="mt-3 small text-secondary d-flex align-items-center gap-2">
                        <span id="ranking-status-text">Aguardando...</span>
                        <span id="ranking-last-updated" class="opacity-75"></span>
                    </div>
                </div>
            </div>
            <div class="card-matrix">
                <div class="card-header-matrix"><i class="bi bi-info-circle me-2"></i>Regras</div>
                <div class="card-body small">
                    <ul class="mb-0 ps-3">
                        <li>Uma proposta por rodada.</li>
                        <li>Edite enquanto status = aberta.</li>
                        <li>Ranking: menor valor, incoterm, maior pagamento, menor prazo.</li>
                        <li>Layout igual ao link de convite público.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-confirm-remove" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" data-bs-theme="light">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-trash me-2 text-danger"></i>Remover anexo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Tem certeza de que deseja remover este anexo? Essa ação não pode ser desfeita.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger" data-confirm-remocao>Remover</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
/******************** VARIÁVEIS GLOBAIS ********************/ 
const cotacaoId = parseInt(document.getElementById('cotacao-view').dataset.id,10);
let requisicaoId = null; // setado em carregarCotacao
let propostaAtual = null; let editMode = false;
let itensRequisicao = [];
let anexos = []; let anexosUploading = 0;
let rankingTimer = null; const rankingIntervalBase = 30000; // 30s
let pendingAnexoFile = null; // NOVO: arquivo selecionado antes de existir proposta
const PROPOSTA_STATUS_EDITAVEIS = ['','enviada'];

function getStatusLabel(status){
  const map = { aprovada: 'aprovada', rejeitada: 'rejeitada', cancelada: 'cancelada', pendente: 'pendente' };
  const key = (status||'').toLowerCase();
  return map[key] || key || 'enviada';
}

function isPropostaEditavel(){
  if(!propostaAtual) return true;
  const status = (propostaAtual.status || '').toLowerCase();
  return PROPOSTA_STATUS_EDITAVEIS.includes(status);
}

/******************** UTIL ***************************/
function showToast(message,type='info'){
  const c=document.querySelector('.toast-container'); if(!c) return;
  const id='t'+Date.now();
  c.insertAdjacentHTML('beforeend',`<div id="${id}" class="toast text-white bg-${type} border-0" data-bs-delay="3000"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`);
  const el=document.getElementById(id); new bootstrap.Toast(el).show(); el.addEventListener('hidden.bs.toast',()=>el.remove());
}
function formatBRL(v){return v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});}

/******************** COTAÇÃO & PROPOSTA ********************/ 
async function carregarCotacao(){
  try {
    const r = await fetch(`../../api/fornecedor/cotacoes.php?cotacao_id=${cotacaoId}`);
    const data = await r.json();
    if(data.erro){ showToast(data.erro,'danger'); return; }
    const c = data.cotacao||{}; window.__cotacaoCache = c;
    requisicaoId = c.requisicao_id || null;
    document.getElementById('cot-status').textContent = c.status || '-';
    document.getElementById('cot-status-inline').textContent = c.status || '-';
    document.getElementById('cot-req').textContent = '#' + (c.requisicao_id || '-');
    document.getElementById('cot-rodada').textContent = c.rodada || 1;
    propostaAtual = data.proposta || null;
    atualizarUIProposta();
    if(requisicaoId){ await carregarItens(); if(propostaAtual){ await listarAnexos(); } enableDropZone(); }
  } catch(e){ showToast('Falha ao carregar cotação','danger'); }
}

function atualizarUIProposta(){
  const form=document.getElementById('form-proposta');
  const btnEnviar=document.getElementById('btn-enviar-proposta');
  const btnSalvar=document.getElementById('btn-salvar-edicao');
  const btnCancelar=document.getElementById('btn-cancelar-edicao');
  const info=document.getElementById('proposta-info-existente');
  const acoes=document.getElementById('proposta-acoes');
  const pdfBtn=document.getElementById('btn-exportar-pdf');
  const xlsxBtn=document.getElementById('btn-exportar-xlsx');
  const tipoFreteSel = document.getElementById('tipo_frete');
  const statusAtual = (propostaAtual && propostaAtual.status) ? String(propostaAtual.status).toLowerCase() : '';
  const podeEditar = isPropostaEditavel();

  if(!propostaAtual){
    form.classList.remove('d-none');
    info.classList.add('d-none'); acoes.classList.add('d-none');
    pdfBtn.classList.add('disabled'); xlsxBtn.classList.add('disabled');
    Array.from(form.querySelectorAll('input:not([readonly]),textarea,select')).forEach(i=>i.disabled=false);
    document.querySelectorAll('#tabela-itens input').forEach(i=>i.disabled=false);
    document.getElementById('valor_total_display').textContent='R$ 0,00';
    document.getElementById('valor_total_hidden').value='0';
    return;
  }
  // Existe proposta
  acoes.classList.remove('d-none');
  pdfBtn.href=`../../pages/proposta_pdf.php?id=${propostaAtual.id}`; pdfBtn.classList.remove('disabled');
  xlsxBtn.href=`../../api/fornecedor/proposta_export.php?id=${propostaAtual.id}&formato=xlsx`; xlsxBtn.classList.remove('disabled');
  form.valor_total.value = propostaAtual.valor_total || '';
  form.prazo_entrega.value = propostaAtual.prazo_entrega || '';
  form.pagamento_dias.value = propostaAtual.pagamento_dias || '';
  form.observacoes.value = propostaAtual.observacoes || '';
  tipoFreteSel.value = propostaAtual.tipo_frete || '';
  Array.from(form.querySelectorAll('input,textarea,select')).forEach(i=>{ if(!i.readOnly) i.disabled=true; });
  document.querySelectorAll('#tabela-itens input').forEach(i=>i.disabled=true);
  btnEnviar.classList.add('d-none');
  if(podeEditar){
    info.textContent='Proposta enviada. Você pode editar enquanto a cotação estiver aberta.';
  } else {
    const label = getStatusLabel(statusAtual);
    info.innerHTML = `<strong>Proposta ${label}</strong>: este envio já foi processado pelo comprador e está bloqueado para edição.`;
  }
  info.classList.remove('d-none');
  if(btnEditar){
    btnEditar.classList.toggle('d-none', !podeEditar);
    btnEditar.disabled = !podeEditar;
  }
  if(!tipoFreteSel.value){ const cotTipo=(window.__cotacaoCache && window.__cotacaoCache.tipo_frete) ? window.__cotacaoCache.tipo_frete : ''; if(cotTipo){ tipoFreteSel.value=cotTipo; } }
}

// Evento enviar nova proposta (itemizada)
const formProposta=document.getElementById('form-proposta');
formProposta.addEventListener('submit', async e=>{
  e.preventDefault();
  if(propostaAtual) return;
  if(!itensRequisicao.length){ showToast('Itens não carregados','danger'); return; }
  if(!itensRequisicao.some(i=>i.preco>0)){ showToast('Informe ao menos um preço unitário','warning'); return; }
  const fd=new FormData(formProposta);
  calcularTotal(); // garante valor_total atualizado
  const payloadData = {
    valor_total: fd.get('valor_total') || '',
    prazo_entrega: fd.get('prazo_entrega') || '',
    pagamento_dias: fd.get('pagamento_dias') || '',
    observacoes: fd.get('observacoes') || '',
    tipo_frete: fd.get('tipo_frete') || ''
  };
  const payload = new URLSearchParams(payloadData);
  payload.set('cotacao_id', cotacaoId);
  try {
    const r=await fetch('../../api/fornecedor/proposta.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:payload.toString()});
    const j=await r.json();
    if(!j.success){ showToast(j.erro||'Erro ao enviar','danger'); return; }
    // Enviar itens
    try {
      for(const it of itensRequisicao){
        if(it.preco>0){
          const itemBody = new URLSearchParams({
            proposta_id: j.id,
            produto_id: it.produto_id,
            preco_unitario: it.preco,
            quantidade: it.quantidade_req
          });
          await fetch('../../api/proposta_itens.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:itemBody.toString()
          });
        }
      }
    } catch(err){}
    showToast('Proposta enviada!','success');
  propostaAtual={id:j.id,...payloadData,status:'enviada'}; atualizarUIProposta(); carregarRanking(false);
    // Se havia anexo pendente, enviar agora
    if(pendingAnexoFile){
      showToast('Enviando anexo pendente...','info');
      try { const an=await uploadAnexo(pendingAnexoFile); anexos.push(an); pendingAnexoFile=null; showToast('Anexo enviado','success'); }
      catch(err){ showToast('Falha ao enviar anexo pendente','danger'); }
    }
    await listarAnexos(); enableDropZone();
  } catch(err){ showToast('Falha de rede','danger'); }
});

// Editar proposta
const btnEditar=document.getElementById('btn-editar');
const btnSalvar=document.getElementById('btn-salvar-edicao');
const btnCancelar=document.getElementById('btn-cancelar-edicao');
btnEditar.addEventListener('click',()=>{
  if(!propostaAtual) return;
  if(!isPropostaEditavel()){ showToast('Esta proposta está bloqueada para edição.','warning'); return; }
  editMode=true;
  Array.from(formProposta.querySelectorAll('input,textarea,select')).forEach(i=>{ if(!i.readOnly) i.disabled=false; });
  document.querySelectorAll('#tabela-itens input').forEach(i=>i.disabled=false);
  btnSalvar.classList.remove('d-none'); btnCancelar.classList.remove('d-none');
  renderAnexos();
});
btnCancelar.addEventListener('click',()=>{ editMode=false; btnSalvar.classList.add('d-none'); btnCancelar.classList.add('d-none'); atualizarUIProposta(); renderAnexos(); });
btnSalvar.addEventListener('click', async ()=>{
  if(!propostaAtual) return;
  if(!isPropostaEditavel()){ showToast('Esta proposta está bloqueada para edição.','warning'); return; }
  calcularTotal();
  const fd=new FormData(formProposta);
  const body=new URLSearchParams();
  ['valor_total','prazo_entrega','pagamento_dias','observacoes','tipo_frete'].forEach(k=> body.set(k, fd.get(k)||''));
  body.set('id', propostaAtual.id);
  body.set('_method','PUT');
  try {
    const r=await fetch('../../api/fornecedor/proposta.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
    const j=await r.json();
    if(!j.success){ showToast(j.erro||'Erro ao atualizar','danger'); return; }
    propostaAtual.valor_total=fd.get('valor_total');
    propostaAtual.prazo_entrega=fd.get('prazo_entrega');
    propostaAtual.pagamento_dias=fd.get('pagamento_dias');
    propostaAtual.observacoes=fd.get('observacoes');
    propostaAtual.tipo_frete=fd.get('tipo_frete');
    for(const it of itensRequisicao){
      try {
        if(it.item_id){
          await fetch('../../api/proposta_itens.php',{method:'PUT',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({ id:it.item_id, preco_unitario:it.preco, quantidade:it.quantidade_req }).toString()});
        } else if(it.preco>0){
          const createPayload = new URLSearchParams({
            proposta_id: propostaAtual.id,
            produto_id: it.produto_id,
            preco_unitario: it.preco,
            quantidade: it.quantidade_req
          });
          const resp = await fetch('../../api/proposta_itens.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
            body:createPayload.toString()
          });
          const jr = await resp.json(); if(jr.success && jr.id){ it.item_id = jr.id; }
        }
      } catch(err){ }
    }
    editMode=false; btnSalvar.classList.add('d-none'); btnCancelar.classList.add('d-none'); atualizarUIProposta(); carregarRanking(false); renderAnexos();
    showToast('Proposta atualizada','success');
  } catch(err){ showToast('Falha de rede','danger'); }
});

/******************** ITENS DA REQUISIÇÃO ********************/ 
async function carregarItens(){
  if(!requisicaoId){ document.querySelector('#tabela-itens tbody').innerHTML='<tr><td colspan="5" class="text-center text-secondary small py-3">Sem requisição.</td></tr>'; return; }
  try {
    const r=await fetch(`../../api/requisicao_itens.php?requisicao_id=${requisicaoId}`);
    const data=await r.json();
    if(!Array.isArray(data)||!data.length){
      document.querySelector('#tabela-itens tbody').innerHTML='<tr><td colspan="5" class="text-center text-secondary small py-3">Sem itens.</td></tr>';
      return;
    }
    itensRequisicao = data.map(i=>({
      produto_id:i.produto_id,
      nome:i.nome,
      unidade:i.unidade,
      quantidade_req:parseFloat(i.quantidade)||0,
      preco:0
    }));
    renderItens();
  } catch(e){
    document.querySelector('#tabela-itens tbody').innerHTML='<tr><td colspan="5" class="text-center text-danger small py-3">Erro ao carregar itens.</td></tr>';
  }
}

async function carregarItensPropostaExistente(){
  if(!propostaAtual) return;
  try {
    const r=await fetch(`../../api/proposta_itens.php?proposta_id=${propostaAtual.id}`);
    const data=await r.json();
    if(Array.isArray(data)&&data.length){
      itensRequisicao = data.map(i=>({
        item_id:i.id,
        produto_id:i.produto_id,
        nome:i.nome,
        unidade:i.unidade,
        quantidade_req:parseFloat(i.quantidade)||0,
        preco:parseFloat(i.preco_unitario)||0,
        preco_original:parseFloat(i.preco_unitario)||0
      }));
      renderItens();
    }
  } catch(e){}
}

function renderItens(){
  const tb=document.querySelector('#tabela-itens tbody');
  tb.innerHTML = itensRequisicao.map(it=>{
    const sub = it.preco * it.quantidade_req;
    return `<tr data-prod="${it.produto_id}" data-item="${it.item_id||''}"><td>${it.nome}</td><td class="text-center">${it.quantidade_req}</td><td>${it.unidade||''}</td><td><input type="number" step="0.01" min="0" class="form-control form-control-sm inp-preco" value="${it.preco}"></td><td class="subtotal text-end small">${formatBRL(sub)}</td></tr>`;
  }).join('');
  calcularTotal();
  if(propostaAtual && !editMode){ document.querySelectorAll('#tabela-itens input').forEach(i=>i.disabled=true); }
}
function calcularTotal(){
  let tot=0;
  document.querySelectorAll('#tabela-itens tbody tr').forEach(tr=>{
    const id=parseInt(tr.dataset.prod,10);
    const item=itensRequisicao.find(i=>i.produto_id===id);
    if(!item) return;
    const sub=item.preco*item.quantidade_req;
    tot+=sub; tr.querySelector('.subtotal').textContent=formatBRL(sub);
  });
  const hidden=document.getElementById('valor_total_hidden');
  const disp=document.getElementById('valor_total_display');
  if(hidden) hidden.value=tot.toFixed(2);
  if(disp) disp.textContent=formatBRL(tot);
}

document.addEventListener('input',e=>{
  if(e.target.classList.contains('inp-preco')){
    const tr=e.target.closest('tr'); if(!tr) return;
    const id=parseInt(tr.dataset.prod,10);
    const item=itensRequisicao.find(i=>i.produto_id===id); if(!item) return;
    item.preco=parseFloat(e.target.value)||0;
    calcularTotal();
  }
});

/******************** ANEXOS ********************/ 
function renderAnexos(){
  const cont=document.getElementById('lista-anexos'); if(!cont) return;
  // Se há um arquivo pendente (antes de criar proposta)
  if(pendingAnexoFile && !propostaAtual){
    cont.innerHTML = `<div class="pending-entry"><i class="bi bi-hourglass-split"></i><span>${pendingAnexoFile.name}</span><em class="text-warning">(será enviado ao enviar a proposta)</em><button type="button" class="remove-pending" title="Remover" id="btn-remover-pendente"><i class="bi bi-x-lg"></i></button></div>`;
  } else if(!anexos.length){
    cont.innerHTML='<div class="empty">Nenhum anexo ainda.</div>';
  } else {
    const podeExcluir = Boolean(propostaAtual && editMode);
    cont.innerHTML = anexos.map(a=>{
      const ext=(a.nome_original||'').split('.').pop().toLowerCase();
      const icon=(e=>{if(['pdf'].includes(e))return'file-earmark-pdf';if(['png','jpg','jpeg','gif','webp'].includes(e))return'image';if(['xls','xlsx','csv'].includes(e))return'file-earmark-spreadsheet';if(['doc','docx'].includes(e))return'file-earmark-word';return'paperclip';})(ext);
      const sizeFmt=(s=>{if(!s)return'';if(s<1024)return s+' B';if(s<1024*1024)return(s/1024).toFixed(1)+' KB';return(s/1024/1024).toFixed(1)+' MB';})(a.tamanho);
      const link = `<a href="../../api/anexos_download.php?id=${a.id}" target="_blank"><i class="bi bi-${icon}"></i><span class="text-truncate" style="max-width:200px">${a.nome_original}</span><span class="ms-auto text-secondary">${sizeFmt}</span></a>`;
      const removeBtn = podeExcluir ? `<button type="button" class="btn-remove-anexo" data-id="${a.id}" title="Remover"><i class="bi bi-trash"></i></button>` : '';
      return `<div class="anexo-entry">${link}${removeBtn}</div>`;
    }).join('');
  }
  // Controle visual do drop-zone
  const dz=document.getElementById('anexo-drop'); const txt=document.getElementById('anexo-drop-text');
  if(dz){
    if(anexos.length>=1 || (pendingAnexoFile && !propostaAtual)){
      dz.classList.add('disabled'); dz.dataset.disabled='1';
      if(pendingAnexoFile && !propostaAtual){ dz.classList.add('pending'); if(txt) txt.textContent='Arquivo selecionado. Envie a proposta para concluir.'; }
      else if(anexos.length>=1){ if(txt) txt.textContent='Anexo já enviado'; }
    } else {
      dz.classList.remove('disabled','pending'); dz.dataset.disabled='0'; if(txt) txt.textContent='Arraste ou clique para selecionar (1 arquivo).';
    }
  }
  // Botão remover pendente
  const btnRem=document.getElementById('btn-remover-pendente');
  if(btnRem){ btnRem.addEventListener('click',()=>{ pendingAnexoFile=null; renderAnexos(); }); }
  if(propostaAtual && editMode){
    cont.querySelectorAll('.btn-remove-anexo').forEach(btn=>{
      btn.addEventListener('click',async()=>{
        const id=parseInt(btn.dataset.id,10);
        if(!id) return;
        await handleRemoveAnexo(id, btn);
      });
    });
  }
}

function askConfirmRemove(){
  const modalEl=document.getElementById('modal-confirm-remove');
  if(!modalEl || typeof bootstrap==='undefined' || !bootstrap.Modal){
    return Promise.resolve(window.confirm('Remover o anexo atual?'));
  }
  return new Promise(resolve=>{
    const modal=bootstrap.Modal.getOrCreateInstance(modalEl);
    const confirmBtn=modalEl.querySelector('[data-confirm-remocao]');
    if(!confirmBtn){ resolve(true); return; }
    let handled=false;
    const cleanup=()=>{
      confirmBtn.removeEventListener('click', onConfirm);
      modalEl.removeEventListener('hidden.bs.modal', onHidden);
    };
    const onConfirm=()=>{
      handled=true;
      cleanup();
      resolve(true);
      modal.hide();
    };
    const onHidden=()=>{
      cleanup();
      if(!handled) resolve(false);
    };
    confirmBtn.addEventListener('click', onConfirm);
    modalEl.addEventListener('hidden.bs.modal', onHidden);
    modal.show();
  });
}

async function handleRemoveAnexo(anexoId, triggerBtn){
  if(!anexoId) return;
  const confirmed = await askConfirmRemove();
  if(!confirmed){ return; }
  if(triggerBtn) triggerBtn.disabled=true;
  try {
    const body=new URLSearchParams({ id:String(anexoId) });
    const resp=await fetch('../../api/anexos_delete.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
    const data=await resp.json();
    if(resp.ok && data.success){
      showToast('Anexo removido','success');
      await listarAnexos();
    } else {
      showToast(data.erro||'Não foi possível remover','danger');
      renderAnexos();
    }
  } catch(err){
    showToast('Erro ao remover anexo','danger');
    renderAnexos();
  } finally {
    if(triggerBtn && triggerBtn.isConnected){ triggerBtn.disabled=false; }
  }
}

// === NOVO: Função de upload de anexo (faltava e causava 'Falha ao enviar anexo pendente') ===
async function uploadAnexo(file){
  return new Promise((resolve,reject)=>{
    if(!file) return reject('Arquivo inválido');
    if(!propostaAtual || !propostaAtual.id) return reject('Proposta ainda não criada');
    if(!requisicaoId) return reject('Requisição indefinida');
    const fd = new FormData();
    fd.append('arquivo', file);
    fd.append('requisicao_id', requisicaoId);
    fd.append('tipo_ref','proposta');
    fd.append('ref_id', propostaAtual.id);
    const wrap=document.getElementById('anexo-progress-wrap');
    const bar=document.querySelector('#anexo-progress .progress-bar');
    if(wrap) wrap.classList.remove('d-none');
    if(bar){ bar.style.width='0%'; }
    const xhr=new XMLHttpRequest();
    xhr.open('POST','../../api/anexos_upload.php');
    xhr.upload.onprogress=e=>{ if(e.lengthComputable && bar){ bar.style.width=Math.round(e.loaded/e.total*100)+'%'; } };
    xhr.onerror=()=>{ if(wrap) wrap.classList.add('d-none'); reject('Erro de rede'); };
    xhr.onreadystatechange=()=>{
      if(xhr.readyState===4){
        setTimeout(()=>{ if(wrap) wrap.classList.add('d-none'); },600);
        let resp={};
        try{ resp=JSON.parse(xhr.responseText||'{}'); }catch(_){ return reject('Resposta inválida'); }
        if(xhr.status===200 && resp.success && resp.anexo){ return resolve(resp.anexo); }
        // Tratar conflito (já existe) como erro amigável
        if(xhr.status===409){ return reject(resp.erro||'Já existe anexo'); }
        reject(resp.erro||'Falha no upload');
      }
    };
    xhr.send(fd);
  });
}
// === Fim função uploadAnexo ===

async function listarAnexos(){
  if(!requisicaoId){ return; }
  if(propostaAtual && propostaAtual.id){
    let url = `../../api/anexos_list.php?requisicao_id=${requisicaoId}&tipo_ref=proposta&ref_id=${propostaAtual.id}`;
    try { const r=await fetch(url); const j=await r.json(); if(j.success && Array.isArray(j.anexos)){ anexos=j.anexos; } } catch(e){}
  } else { anexos=[]; }
  renderAnexos();
}

function enableDropZone(){
  // Agora permitido mesmo sem proposta (apenas seleção pendente)
  renderAnexos();
}

(function initDrop(){
  const dz=document.getElementById('anexo-drop');
  const fi=document.getElementById('anexo-arquivo');
  const txt=document.getElementById('anexo-drop-text');
  if(!dz||!fi) return;
  dz.addEventListener('click',()=>{ if(dz.dataset.disabled==='1') return; fi.click(); });
  dz.addEventListener('dragover',e=>{e.preventDefault(); if(dz.dataset.disabled==='1') return; dz.classList.add('dragover');});
  dz.addEventListener('dragleave',()=>dz.classList.remove('dragover'));
  dz.addEventListener('drop',e=>{ e.preventDefault(); if(dz.dataset.disabled==='1') return; dz.classList.remove('dragover'); if(e.dataTransfer.files&&e.dataTransfer.files.length){ handleFiles(e.dataTransfer.files); } });
  fi.addEventListener('change',()=>{ if(fi.files.length){ handleFiles(fi.files); } });
  async function handleFiles(fileList){
    for(const file of fileList){
      if(anexos.length>=1 || pendingAnexoFile){ showToast('Apenas 1 anexo permitido','warning'); break; }
      if(!propostaAtual){
        // Armazena pendente
        pendingAnexoFile=file; showToast('Arquivo preparado: '+file.name,'info'); renderAnexos();
      } else {
        anexosUploading++;
        try { const anexo=await uploadAnexo(file); anexos.push(anexo); showToast('Anexo enviado: '+file.name,'success'); }
        catch(err){ showToast(err+' ('+file.name+')','danger'); }
        finally { anexosUploading--; }
        renderAnexos();
      }
    }
    fi.value='';
  }
})();

/******************** RANKING (AUTO REFRESH) ********************/ 
function clearRankingTimer(){ if(rankingTimer){ clearTimeout(rankingTimer); rankingTimer=null; } }
function scheduleRanking(ms){ clearRankingTimer(); rankingTimer=setTimeout(()=>{ carregarRanking(true); }, ms); }
function formatHora(d){ return d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }
async function carregarRanking(auto=false){
  const statusTxt=document.getElementById('ranking-status-text');
  const lastEl=document.getElementById('ranking-last-updated');
  statusTxt.textContent='Atualizando...';
  try {
    const r=await fetch(`../../api/fornecedor/ranking.php?cotacao_id=${cotacaoId}`);
    const data=await r.json();
    if(data.erro){
      document.getElementById('ranking-frase').textContent='—';
      document.getElementById('ranking-range').textContent='';
      statusTxt.textContent='Erro'; scheduleRanking(45000); return;
    }
    document.getElementById('ranking-frase').textContent=data.frase||'—';
    const rangeEl=document.getElementById('ranking-range');
    if(data.posicao_banda){ rangeEl.textContent=`Posição entre ${data.posicao_banda.min} e ${data.posicao_banda.max}`; } else { rangeEl.textContent=''; }
    const agora=new Date(); lastEl.textContent='Última: '+formatHora(agora);
    statusTxt.textContent='Auto';
    let prox=rankingIntervalBase; if(!auto) prox=15000;
    const statusCot=document.getElementById('cot-status').textContent.toLowerCase();
    if(statusCot!=='aberta'){ statusTxt.textContent='Congelado'; clearRankingTimer(); return; }
    if(document.hidden){ statusTxt.textContent='Pausado'; clearRankingTimer(); return; }
    scheduleRanking(prox);
  } catch(e){ statusTxt.textContent='Falha'; scheduleRanking(60000); }
}

document.addEventListener('visibilitychange',()=>{ if(!document.hidden){ if(!rankingTimer){ scheduleRanking(3000); } } });

/******************** SINCRONIZAR ITENS PROPOSTA EXISTENTE ********************/ 
async function syncItensPropostaDepois(){ if(propostaAtual){ await carregarItensPropostaExistente(); calcularTotal(); } }

/******************** INICIALIZAÇÃO ********************/ 
(async function init(){
  await carregarCotacao();
  await syncItensPropostaDepois();
  carregarRanking(true);
})();
</script>
</body>
</html>
