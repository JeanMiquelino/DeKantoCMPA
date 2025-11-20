<?php
session_start();
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/auth.php';
$u = auth_usuario();
if(!$u){ header('Location: ../login.php'); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ header('Location: ../index.php'); exit; }
$requisicao_id = (int)($_GET['id'] ?? 0);
if(!$requisicao_id){ header('Location: requisicoes.php'); exit; }
$app_name = $branding['app_name'] ?? 'Dekanto';
$theme = isset($_GET['theme']) ? ($_GET['theme']==='dark' ? 'dark' : 'light') : ($_COOKIE['theme'] ?? 'light');
if(isset($_GET['theme'])) setcookie('theme',$theme,time()+3600*24*30,'/');
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="<?=$theme?>">
<head>
<meta charset="UTF-8">
<title>Acompanhar Requisição #<?= (int)$requisicao_id ?> - <?= htmlspecialchars($app_name) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../../assets/css/nexus.css">
<style>
/* Paletas dependentes do tema */
html[data-bs-theme='dark'] { 
  --track-bg:#202031; --track-border:#2a2a3b; --track-active:#6366f1; --track-done:#49d18b; --track-text:#c5c7da; 
  --card-bg:#181824; --card-border:#242434; --card-shadow:0 4px 16px -6px rgba(0,0,0,.55);
  --badge-bg:#29293a; --badge-border:#34344a; --badge-text:#c5c7da;
  --line-bg:#1c1c29; --line-border:#272738;
}
html[data-bs-theme='light'] { 
  --track-bg:#f1f5fb; --track-border:#d8e2ee; --track-active:#4f46e5; --track-done:#2f9e6e; --track-text:#495057; 
  --card-bg:#ffffff; --card-border:#e1e8f0; --card-shadow:0 2px 8px -2px rgba(0,0,0,.12);
  --badge-bg:#f1f5f9; --badge-border:#d5dde7; --badge-text:#495057;
  --line-bg:#e9eef5; --line-border:#d8e2ee;
}
:root { --transition: .25s cubic-bezier(.4,.0,.2,1); }
.page-title{display:flex;align-items:center;gap:.6rem}
.progress-wrapper{overflow-x:auto; padding:.3rem 0 .5rem; width:100%;}
.progress-wrapper::-webkit-scrollbar{height:6px;}
.progress-wrapper::-webkit-scrollbar-thumb{background:rgba(0,0,0,.25); border-radius:999px;}
.progress-wrapper::-webkit-scrollbar-track{background:transparent;}
.progress-steps{display:flex; gap:.75rem; margin:1.25rem 0 1rem; position:relative; min-width:680px;}
.progress-steps::before{content:""; position:absolute; top:26px; left:0; right:0; height:4px; background:var(--line-bg); border:1px solid var(--line-border); border-radius:4px; z-index:0; transition:background var(--transition),border-color var(--transition);} 
.step{flex:1; position:relative; text-align:center;}
.step .circle{width:52px;height:52px; border-radius:16px; background:var(--track-bg); border:1px solid var(--track-border); display:flex; align-items:center; justify-content:center; margin:0 auto .5rem; font-size:1.2rem; color:var(--track-text); font-weight:500; position:relative; box-shadow:0 2px 6px -2px rgba(0,0,0,.25); z-index:2; transition:background var(--transition),color var(--transition),border-color var(--transition),box-shadow var(--transition);} 
.step.done .circle{background:linear-gradient(145deg,var(--track-done),#57e6ad); color:#0f251b; border-color:#57e6ad; box-shadow:0 4px 14px -4px rgba(47,158,110,.45);} 
/* PASSO ATIVO: vermelho terciário sólido em degradê (não confundir com cancelado) */
.step.active .circle{ 
  background:linear-gradient(145deg,#d14b4b 0%, #e86d6d 100%)!important; 
  border-color:#d14b4b!important; 
  color:#fff!important; 
  box-shadow:0 4px 14px -6px rgba(209,75,75,.55),0 0 0 1px rgba(255,255,255,.08) inset!important;
}
html[data-bs-theme='dark'] .step.active .circle{ 
  background:linear-gradient(145deg,#c24545 0%, #dd5e5e 100%)!important; 
  border-color:#c24545!important; 
  color:#fff!important; 
  box-shadow:0 4px 18px -8px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.07) inset!important;
}
.step.cancelado .circle{background:linear-gradient(145deg,#dc3545,#ff5170); color:#fff; border-color:#ff5170;}
.step .label{font-size:.62rem; font-weight:600; letter-spacing:.55px; text-transform:uppercase; color:var(--track-text); max-width:90px; margin:0 auto; line-height:1.15; transition:color var(--transition);} 
.step.done .label{color:#17894f;} 
.step.active .label{color:#d14b4b!important;} 
html[data-bs-theme='dark'] .step.active .label{color:#dd5e5e!important;} 
.step.cancelado .label{color:#dc3545;}
.step .bar-fill{position:absolute; top:26px; left:50%; right:-50%; height:4px; background:linear-gradient(90deg,var(--track-done),#57e6ad); z-index:1; transition:background var(--transition);} 
.step.active .bar-fill{background:linear-gradient(90deg,#d14b4b 0%, #e86d6d 100%)!important;} 
html[data-bs-theme='dark'] .step.active .bar-fill{background:linear-gradient(90deg,#c24545 0%, #dd5e5e 100%)!important;} 
@supports (background: color-mix(in srgb, red, blue)) {
  .step.active .circle{background:linear-gradient(145deg,color-mix(in srgb,#d14b4b 92%, #ffffff) 0%, color-mix(in srgb,#e86d6d 88%, #000000) 100%)!important;}
  html[data-bs-theme='dark'] .step.active .circle{background:linear-gradient(145deg,color-mix(in srgb,#c24545 90%, #000000) 0%, color-mix(in srgb,#dd5e5e 80%, #000000) 100%)!important;}
  .step.active .bar-fill{background:linear-gradient(90deg,color-mix(in srgb,#d14b4b 95%, #fff), color-mix(in srgb,#e86d6d 85%, #000))!important;}
  html[data-bs-theme='dark'] .step.active .bar-fill{background:linear-gradient(90deg,color-mix(in srgb,#c24545 92%, #000), color-mix(in srgb,#dd5e5e 78%, #000))!important;}
}
.card-track{background:var(--card-bg); border:1px solid var(--card-border); border-radius:1rem; padding:1.25rem 1.2rem; box-shadow:var(--card-shadow); transition:background var(--transition),border-color var(--transition),box-shadow var(--transition);} 
.card-track h2{font-size:1rem; margin:0 0 .85rem; font-weight:600; letter-spacing:.4px;}
.badge-status{display:inline-flex; align-items:center; gap:.4rem; font-size:.6rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; padding:.4rem .7rem; border-radius:1rem; background:var(--badge-bg); border:1px solid var(--badge-border); color:var(--badge-text); transition:background var(--transition),color var(--transition),border-color var(--transition);} 
.badge-status.ok{background:rgba(47,158,110,.15); border-color:rgba(47,158,110,.4); color:var(--track-done);} 
.badge-status.prog{background:rgba(181,168,134,.18); border-color:rgba(181,168,134,.45); color:var(--matrix-tertiary,#b5a886);} 
html[data-bs-theme='light'] .badge-status.prog{background:rgba(181,168,134,.15); border-color:rgba(181,168,134,.35); color:#7a6d48;}
/* Opcional: suporte moderno color-mix (sobrescreve se disponível) */
@supports (background: color-mix(in srgb, red, blue)) {
  .badge-status.prog{background:color-mix(in srgb, var(--matrix-tertiary,#b5a886) 20%, transparent); border-color:color-mix(in srgb, var(--matrix-tertiary,#b5a886) 55%, transparent);} 
  html[data-bs-theme='light'] .badge-status.prog{background:color-mix(in srgb, var(--matrix-tertiary,#b5a886) 22%, white);} 
}
.badge-status.warn{background:rgba(255,193,7,.18); border-color:rgba(255,193,7,.45); color:#b58105;} 
.badge-status.err{background:rgba(220,53,69,.15); border-color:rgba(220,53,69,.5); color:#c83444;}
.items-table{width:100%; border-collapse:collapse;}
.items-table th, .items-table td{padding:.55rem .65rem; font-size:.8rem; border-bottom:1px solid var(--card-border); transition:background var(--transition),color var(--transition),border-color var(--transition);} 
.items-table th{position:sticky; top:0; background:var(--track-bg); text-transform:uppercase; font-size:.6rem; letter-spacing:.55px; color:var(--track-text);} 
.items-table tbody tr:last-child td{border-bottom:0;}
/* Botão de toggle opcional */
.theme-toggle{position:fixed; bottom:1rem; right:1rem; z-index:1040;}
/* REMOÇÃO DO OVERRIDE indevido: cor suave só para passos futuros */
.step:not(.done):not(.active):not(.cancelado) .circle{ 
  background:rgba(181,168,134,.20); 
  border-color:rgba(181,168,134,.45); 
  color:var(--matrix-tertiary,#b5a886); 
  box-shadow:0 2px 6px -2px rgba(0,0,0,.25),0 0 0 1px rgba(181,168,134,.25) inset;
}
html[data-bs-theme='light'] .step:not(.done):not(.active):not(.cancelado) .circle{ 
  background:rgba(181,168,134,.18); 
  border-color:rgba(181,168,134,.35); 
  color:#6d603d; 
  box-shadow:0 2px 4px -2px rgba(0,0,0,.10),0 0 0 1px rgba(181,168,134,.18) inset;
}
.step:not(.done):not(.active):not(.cancelado) .label{color:var(--matrix-tertiary,#b5a886);} 
html[data-bs-theme='light'] .step:not(.done):not(.active):not(.cancelado) .label{color:#6d603d;}
/* Cancelado mantém cores de alerta */
.step.cancelado .circle{background:linear-gradient(145deg,#dc3545,#ff5170); border-color:#ff5170; color:#fff;}
.step.cancelado .label{color:#dc3545;}
/* Suporte opcional color-mix para SUAVIZAR apenas passos futuros */
@supports (background: color-mix(in srgb, red, blue)) {
  .step:not(.done):not(.active):not(.cancelado) .circle { background:color-mix(in srgb,var(--matrix-terciary,#b5a886) 22%, transparent); }
  html[data-bs-theme='light'] .step:not(.done):not(.active):not(.cancelado) .circle { background:color-mix(in srgb,var(--matrix-terciary,#b5a886) 20%, white); }
}
  .actions-bar{gap:1rem; flex-wrap:wrap;}
  @media (max-width: 768px){
    .progress-wrapper{margin:0 -0.5rem; padding:0.2rem 0 0.6rem;}
    .actions-bar{flex-direction:column; align-items:flex-start!important;}
    .actions-bar .btn{width:100%;}
  }
  @media (max-width: 640px){
    .progress-wrapper{overflow-x:visible; margin:0;}
    .progress-steps{flex-direction:column; gap:1rem; min-width:auto; padding-left:1.8rem;}
    .progress-steps::before{display:none;}
    .step{display:flex; align-items:center; text-align:left; padding-left:.5rem;}
    .step::after{content:""; position:absolute; left:18px; top:52px; bottom:-14px; width:2px; background:var(--line-border);}
    .step:last-child::after{display:none;}
    .step .circle{width:42px; height:42px; margin:0 0.75rem 0 0;}
    .step .label{max-width:none; text-align:left;}
    .step .bar-fill{display:none!important;}
  }
</style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<div class="container py-4" id="app" style="max-width:1100px;">
  <div class="d-flex justify-content-between align-items-center mb-2 actions-bar">
    <h3 class="page-title mb-0"><i class="bi bi-geo me-2"></i>Acompanhar Requisição <small class="text-secondary">#<?= (int)$requisicao_id ?></small></h3>
    <div class="d-flex gap-2">
  <button id="btnRefresh" class="btn btn-client-action"><span class="btn-icon"><i class="bi bi-arrow-clockwise"></i></span><span>Atualizar</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></button>
  <button id="btnCopiar" class="btn btn-client-action"><span class="btn-icon"><i class="bi bi-link-45deg"></i></span><span>Copiar link</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></button>
      <a href="requisicoes.php" class="btn btn-matrix-secondary"><i class="bi bi-arrow-left me-1"></i>Voltar</a>
    </div>
  </div>
  <div class="progress-wrapper">
    <div id="progressContainer" class="progress-steps"></div>
  </div>
  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card-track" id="painelStatus">
        <h2>Status Atual</h2>
        <p id="statusAtual" class="mb-2">Carregando...</p>
        <div id="statusBadges" class="d-flex flex-wrap gap-2"></div>
        <hr class="border-secondary-subtle my-3">
        <p class="small text-secondary mb-2"><i class="bi bi-hash me-1"></i>#<?= (int)$requisicao_id ?></p>
        <p class="small text-secondary mb-0"><i class="bi bi-clock me-1"></i><span id="reqCriado"></span></p>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card-track">
        <h2>Itens da Requisição</h2>
        <div class="table-responsive" style="max-height:360px;">
          <table class="items-table" id="itensTable">
            <thead><tr><th style="width:70px;">ID</th><th>Produto</th><th style="width:90px;" class="text-end">Qtd</th></tr></thead>
            <tbody id="itensBody"><tr><td colspan="3" class="text-center text-secondary py-3">Carregando...</td></tr></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<div class="theme-toggle">
  <a class="btn btn-sm btn-outline-secondary" href="?id=<?= (int)$requisicao_id ?>&theme=<?= $theme==='dark'?'light':'dark' ?>" title="Alternar tema"><i class="bi bi-<?= $theme==='dark'?'sun':'moon' ?>"></i></a>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const requisicaoId = <?= (int)$requisicao_id ?>;
const steps = [
  {id:'requisicao_criada', icon:'bi-clipboard', label:'Requisição'},
  {id:'cotacao', icon:'bi-clipboard-data', label:'Cotação'},
  {id:'proposta_recebida', icon:'bi-file-earmark-text', label:'Proposta'},
  {id:'aguardando_aprovacao_cliente', icon:'bi-person-check', label:'Aprovação do Cliente'},
  {id:'pedido_pendente', icon:'bi-hourglass-split', label:'Pedido pendente'},
  {id:'pedido_emitido', icon:'bi-receipt', label:'Pedido emitido'},
  {id:'pedido_em_producao', icon:'bi-gear', label:'Em produção'},
  {id:'pedido_enviado_cliente', icon:'bi-truck', label:'Pedido enviado'},
  {id:'pedido_entregue', icon:'bi-box2-heart', label:'Entregue'}
];
// Helper robusto de cópia
async function copyToClipboard(text){
  try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(text); return true; } }catch(e){}
  try{ const ta=document.createElement('textarea'); ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.left='-9999px'; document.body.appendChild(ta); ta.select(); const ok=document.execCommand('copy'); ta.remove(); return !!ok; }catch(e){ return false; }
}
const PEDIDO_STATUS_LABELS = { emitido:'Emitido', pendente:'Pendente', em_producao:'Em produção', enviado:'Enviado', entregue:'Entregue', cancelado:'Cancelado', aguardando_aprovacao_cliente:'Aguardando aprovação do cliente' };
function humanizeStatus(v){ if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/\b\w/g, c=> c.toUpperCase()); }
function statusLabelPedido(v){ v=(v||'').toLowerCase(); return PEDIDO_STATUS_LABELS[v] || humanizeStatus(v); }
function deriveOverallStatus(data){
  let status='requisicao_criada';
  if(data.cotacoes?.length) status='cotacao';
  if(data.propostas?.length) status='proposta_recebida';
  if(data.pedidos?.length){
    const ped = [...data.pedidos].sort((a,b)=> b.id - a.id)[0];
    const st = (ped.status||'').toLowerCase();
    if(st==='aguardando_aprovacao_cliente') status='aguardando_aprovacao_cliente';
    else if(st==='pendente') status='pedido_pendente';
    else if(st==='emitido') status='pedido_emitido';
    else if(st==='em_producao') status='pedido_em_producao';
    else if(st==='enviado') status='pedido_enviado_cliente';
    else if(st==='entregue') status='pedido_entregue';
    else if(st==='cancelado') status='pedido_cancelado';
  }
  return status;
}
function renderProgress(current){
  const container = document.getElementById('progressContainer');
  let idx = steps.findIndex(s=> s.id===current);
  const cancelMode = current === 'pedido_cancelado';
  const cancelIdx = steps.findIndex(s=> s.id==='pedido_pendente');
  if(idx === -1 && !cancelMode){
    idx = steps.findIndex(s=> s.id==='proposta_recebida');
    if(idx === -1) idx = 0;
  }
  container.innerHTML = steps.map((s,i)=>{
    let state = '';
    if(cancelMode){
      if(cancelIdx !== -1){
        if(i < cancelIdx) state = 'done';
        else if(i === cancelIdx) state = 'cancelado';
      }
    } else {
      if(i < idx) state = 'done';
      else if(i === idx) state = 'active';
    }
    const opacity = (!cancelMode && i<idx) ? 1 : (!cancelMode && i===idx ? 0.5 : 0);
    return `<div class="step ${state}"><div class="circle"><i class="bi ${s.icon}"></i></div><div class="label">${s.label}</div>${i<steps.length-1? `<div class='bar-fill' style='opacity:${opacity}'></div>`:''}</div>`;
  }).join('');
}
function badge(txt,type){return `<span class='badge-status ${type||''}'>${txt}</span>`;}
function formatDate(str){ if(!str) return '-'; const d=new Date(str); return isNaN(d)? '-' : d.toLocaleString('pt-BR'); }
function loadData(){
  let url = '../../api/acompanhar_requisicao.php?requisicao_id='+encodeURIComponent(requisicaoId);
  fetch(url,{cache:'no-store'}).then(r=>r.json()).then(data=>{
    if(!data.success){ showToast(data.erro||'Erro ao carregar','danger'); return; }
    document.getElementById('reqCriado').textContent = formatDate(data.requisicao.criado_em);
    const overall = deriveOverallStatus(data);
    renderProgress(overall);
  const statusMap = {
    requisicao_criada:'Requisição criada',
    cotacao:'Cotação em andamento',
    proposta_recebida:'Proposta recebida',
    aguardando_aprovacao_cliente:'Aguardando aprovação do cliente',
    pedido_pendente:'Pedido pendente',
    pedido_emitido:'Pedido emitido',
    pedido_em_producao:'Pedido em produção',
    pedido_enviado_cliente:'Pedido enviado',
    pedido_entregue:'Pedido entregue',
    pedido_cancelado:'Pedido cancelado'
  };
    document.getElementById('statusAtual').textContent = statusMap[overall] || overall;
    const badges = [];
    badges.push(badge('Itens '+(data.itens?.length||0),'prog'));
    badges.push(badge('Cotações '+(data.cotacoes?.length||0), data.cotacoes?.length? 'prog':'warn'));
    badges.push(badge('Propostas '+(data.propostas?.length||0), data.propostas?.length? 'prog':'warn'));
    if(data.pedidos?.length){ const ped = [...data.pedidos].sort((a,b)=> b.id-a.id)[0]; const stLabel = statusLabelPedido(ped.status); badges.push(badge('Pedido '+stLabel, ped.status==='entregue'?'ok':(ped.status==='cancelado'?'err':'prog'))); }
    document.getElementById('statusBadges').innerHTML = badges.join('');
    const body = document.getElementById('itensBody');
    if(!data.itens?.length){ body.innerHTML = `<tr><td colspan='3' class='text-center text-secondary py-4'>Sem itens.</td></tr>`; }
    else { body.innerHTML = data.itens.map(i=> `<tr><td>#${i.id}</td><td>${(i.produto_nome||'-').replaceAll('<','&lt;').replaceAll('>','&gt;')}</td><td class='text-end'>${i.quantidade}</td></tr>`).join(''); }
  }).catch(err=>{ console.error(err); showToast('Falha ao carregar','danger'); });
}
function showToast(msg,type='info'){ const c=document.querySelector('.toast-container'); if(!c) return; const id='t'+Date.now(); c.insertAdjacentHTML('beforeend',`<div id='${id}' class='toast align-items-center text-white bg-${type} border-0'><div class='d-flex'><div class='toast-body'>${msg}</div><button class='btn-close btn-close-white me-2 m-auto' data-bs-dismiss='toast'></button></div></div>`); const el=document.getElementById(id); const t=new bootstrap.Toast(el,{delay:3000}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove()); }
// Eventos
 document.getElementById('btnRefresh').addEventListener('click', loadData);
 // Copiar link público: gerar token e copiar URL
 document.getElementById('btnCopiar').addEventListener('click', async ()=>{
   try{
     const res = await fetch('../../api/requisicoes_tracking.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: requisicaoId }) });
     const out = await res.json();
     if(!out.success){ showToast(out.erro||'Falha ao gerar link','danger'); return; }
     const base = location.pathname.replace(/cliente\/acompanhar_requisicao\.php$/, '');
     const url = `${location.origin}${base}acompanhar_requisicao.php?token=${encodeURIComponent(out.token)}`;
     const ok = await copyToClipboard(url);
     showToast(ok? 'Link copiado!' : 'Não foi possível copiar o link.', ok? 'success' : 'warning');
   }catch(e){ showToast('Erro de rede ao gerar link','danger'); }
 });
loadData();
</script>
</body>
</html>
