<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php';
$token = $_GET['token'] ?? '';
$requisicao_id = (int)($_GET['id'] ?? 0);
$publico = $token !== '';
if(!$publico){
    $u = auth_usuario();
    if(!$u){ header('Location: login.php'); exit; }
}
$app_name = $branding['app_name'] ?? 'Dekanto';
$theme = isset($_GET['theme']) ? ($_GET['theme']==='dark' ? 'dark' : 'light') : 'light';
if(isset($_GET['theme'])){
    setcookie('public_req_theme', $theme, time()+86400*30, '/');
} elseif(isset($_COOKIE['public_req_theme'])){
    $cookieTheme = $_COOKIE['public_req_theme'];
    if(in_array($cookieTheme, ['light','dark'], true)){
        $theme = $cookieTheme;
    }
}
$favicon = null;
$logo = $branding['logo'] ?? null;
if(!$logo || trim($logo)===''){
    if(file_exists(__DIR__.'/../assets/images/logo.png')) $logo = '../assets/images/logo.png';
    elseif(file_exists(__DIR__.'/../assets/images/logo_site.jpg')) $logo = '../assets/images/logo_site.jpg';
}
if($logo && !preg_match('~^(https?:)?/|^data:~',$logo)){
    if(str_starts_with($logo,'assets/')) $logo = '../'.$logo;
}
$candidates = [
    __DIR__.'/../assets/images/favicon.png',
    __DIR__.'/../assets/images/favicon.jpg',
    __DIR__.'/../assets/images/favicon.ico',
];
foreach($candidates as $c){
    if(file_exists($c)){ $favicon = '../assets/images/'.basename($c); break; }
}
if(!$favicon && $logo){
    $favicon = $logo;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8">
<title>Acompanhar Requisição <?= $token? '(Token)' : '#'.(int)$requisicao_id ?> - <?= htmlspecialchars($app_name) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/nexus.css">
<?php if(!empty($favicon)): ?>
<link rel="icon" href="<?=htmlspecialchars($favicon)?>" type="image/<?=pathinfo(parse_url($favicon, PHP_URL_PATH)??'', PATHINFO_EXTENSION)?:'png'?>">
<link rel="shortcut icon" href="<?=htmlspecialchars($favicon)?>">
<link rel="apple-touch-icon" href="<?=htmlspecialchars($favicon)?>">
<?php endif; ?>
<style>
html[data-bs-theme='dark'] { 
  --track-bg:#202031; --track-border:#2a2a3b; --track-active:#6366f1; --track-done:#49d18b; --track-text:#c5c7da; 
  --card-bg:#181824; --card-border:#242434; --card-shadow:0 4px 16px -6px rgba(0,0,0,.55);
  --badge-bg:#29293a; --badge-border:#34344a; --badge-text:#c5c7da;
  --line-bg:#1c1c29; --line-border:#272738;
  --brand-bg:#141625; --brand-border:#1f2334; --brand-text:#e5e7ef; --brand-sub:#8a90a8;
}
html[data-bs-theme='light'] { 
  --track-bg:#f1f5fb; --track-border:#d8e2ee; --track-active:#4f46e5; --track-done:#2f9e6e; --track-text:#495057; 
  --card-bg:#ffffff; --card-border:#e1e8f0; --card-shadow:0 2px 8px -2px rgba(0,0,0,.12);
  --badge-bg:#f1f5f9; --badge-border:#d5dde7; --badge-text:#495057;
  --line-bg:#e9eef5; --line-border:#d8e2ee;
  --brand-bg:#ffffff; --brand-border:#dfe6f0; --brand-text:#1f2430; --brand-sub:#6b7280;
}
body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,'Helvetica Neue',Arial,sans-serif; background:var(--body-bg,#f5f7fb);}
:root { --transition: .25s cubic-bezier(.4,.0,.2,1); }
html[data-bs-theme='dark'] body{--body-bg:#11121c;color:#f8fafc;}
html[data-bs-theme='light'] body{--body-bg:#f5f7fb;color:#1f2933;}
.brand-banner{background:var(--brand-bg); border-bottom:1px solid var(--brand-border);}
.brand-banner .brand-block{display:flex; flex-direction:column; align-items:center; gap:.5rem;}
.brand-logo{max-height:74px; width:auto; filter:drop-shadow(0 6px 18px rgba(0,0,0,.35));}
html[data-bs-theme='light'] .brand-logo{filter:none;}
.brand-title{margin:0; font-size:1.35rem; font-weight:600; color:var(--brand-text); letter-spacing:.4px;}
.brand-sub{font-size:.72rem; text-transform:uppercase; letter-spacing:.45px; color:var(--brand-sub); font-weight:600;}
.page-title{display:flex;align-items:center;gap:.6rem;font-weight:600;}
.page-title{display:flex;align-items:center;gap:.6rem;font-weight:600;}
.progress-steps{display:flex; gap:.75rem; margin:1.25rem 0 1rem; position:relative;}
.progress-steps::before{content:""; position:absolute; top:26px; left:0; right:0; height:4px; background:var(--line-bg); border:1px solid var(--line-border); border-radius:4px; z-index:0; transition:background var(--transition),border-color var(--transition);} 
.progress-wrapper{overflow-x:auto; padding:.3rem 0 .5rem;}
.progress-wrapper::-webkit-scrollbar{height:6px;}
.progress-wrapper::-webkit-scrollbar-thumb{background:rgba(0,0,0,.25); border-radius:999px;}
.progress-wrapper::-webkit-scrollbar-track{background:transparent;}
.step{flex:1; position:relative; text-align:center;}
.step .circle{width:52px;height:52px; border-radius:16px; background:var(--track-bg); border:1px solid var(--track-border); display:flex; align-items:center; justify-content:center; margin:0 auto .5rem; font-size:1.2rem; color:var(--track-text); font-weight:500; position:relative; box-shadow:0 2px 6px -2px rgba(0,0,0,.25); z-index:2; transition:background var(--transition),color var(--transition),border-color var(--transition),box-shadow var(--transition);} 
.step.done .circle{background:linear-gradient(145deg,var(--track-done),#57e6ad); color:#0f251b; border-color:#57e6ad; box-shadow:0 4px 14px -4px rgba(47,158,110,.45);} 
.step.active .circle{background:linear-gradient(145deg,#d14b4b 0%, #e86d6d 100%)!important; border-color:#d14b4b!important; color:#fff!important; box-shadow:0 4px 14px -6px rgba(209,75,75,.55),0 0 0 1px rgba(255,255,255,.08) inset!important;}
html[data-bs-theme='dark'] .step.active .circle{background:linear-gradient(145deg,#c24545 0%, #dd5e5e 100%)!important; border-color:#c24545!important; color:#fff!important; box-shadow:0 4px 18px -8px rgba(0,0,0,.65),0 0 0 1px rgba(255,255,255,.07) inset!important;}
.step.cancelado .circle{background:linear-gradient(145deg,#dc3545,#ff5170); color:#fff; border-color:#ff5170;}
.step .label{font-size:.62rem; font-weight:600; letter-spacing:.55px; text-transform:uppercase; color:var(--track-text); max-width:90px; margin:0 auto; line-height:1.15; transition:color var(--transition);} 
.step.done .label{color:#17894f;} 
.step.active .label{color:#d14b4b!important;} 
html[data-bs-theme='dark'] .step.active .label{color:#dd5e5e!important;} 
.step.cancelado .label{color:#dc3545;}
.step .bar-fill{position:absolute; top:26px; left:50%; right:-50%; height:4px; background:linear-gradient(90deg,var(--track-done),#57e6ad); z-index:1; transition:background var(--transition);} 
.step.active .bar-fill{background:linear-gradient(90deg,#d14b4b 0%, #e86d6d 100%)!important;} 
html[data-bs-theme='dark'] .step.active .bar-fill{background:linear-gradient(90deg,#c24545 0%, #dd5e5e 100%)!important;} 
.step:not(.done):not(.active):not(.cancelado) .circle{background:rgba(181,168,134,.20); border-color:rgba(181,168,134,.45); color:var(--matrix-tertiary,#b5a886); box-shadow:0 2px 6px -2px rgba(0,0,0,.25),0 0 0 1px rgba(181,168,134,.25) inset;}
html[data-bs-theme='light'] .step:not(.done):not(.active):not(.cancelado) .circle{background:rgba(181,168,134,.18); border-color:rgba(181,168,134,.35); color:#6d603d; box-shadow:0 2px 4px -2px rgba(0,0,0,.10),0 0 0 1px rgba(181,168,134,.18) inset;}
.step:not(.done):not(.active):not(.cancelado) .label{color:var(--matrix-tertiary,#b5a886);} 
html[data-bs-theme='light'] .step:not(.done):not(.active):not(.cancelado) .label{color:#6d603d;}
@supports (background: color-mix(in srgb, red, blue)) {
  .step.active .circle{background:linear-gradient(145deg,color-mix(in srgb,#d14b4b 92%, #ffffff) 0%, color-mix(in srgb,#e86d6d 88%, #000000) 100%)!important;}
  html[data-bs-theme='dark'] .step.active .circle{background:linear-gradient(145deg,color-mix(in srgb,#c24545 90%, #000000) 0%, color-mix(in srgb,#dd5e5e 80%, #000000) 100%)!important;}
  .step.active .bar-fill{background:linear-gradient(90deg,color-mix(in srgb,#d14b4b 95%, #fff), color-mix(in srgb,#e86d6d 85%, #000))!important;}
  html[data-bs-theme='dark'] .step.active .bar-fill{background:linear-gradient(90deg,color-mix(in srgb,#c24545 92%, #000), color-mix(in srgb,#dd5e5e 78%, #000))!important;}
}
.progress-wrapper{width:100%;}
.card-track{background:var(--card-bg); border:1px solid var(--card-border); border-radius:1rem; padding:1.25rem 1.2rem; box-shadow:var(--card-shadow); transition:background var(--transition),border-color var(--transition),box-shadow var(--transition);} 
.card-track h2{font-size:1rem; margin:0 0 .85rem; font-weight:600; letter-spacing:.4px;}
.badge-status{display:inline-flex; align-items:center; gap:.4rem; font-size:.6rem; font-weight:600; letter-spacing:.5px; text-transform:uppercase; padding:.4rem .7rem; border-radius:1rem; background:var(--badge-bg); border:1px solid var(--badge-border); color:var(--badge-text); transition:background var(--transition),color var(--transition),border-color var(--transition);} 
.badge-status.ok{background:rgba(47,158,110,.15); border-color:rgba(47,158,110,.4); color:var(--track-done);} 
.badge-status.prog{background:rgba(181,168,134,.18); border-color:rgba(181,168,134,.45); color:var(--matrix-tertiary,#b5a886);} 
html[data-bs-theme='light'] .badge-status.prog{background:rgba(181,168,134,.15); border-color:rgba(181,168,134,.35); color:#7a6d48;}
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
.footer-note{color:#6b7280; font-size:.65rem; margin-top:1.5rem; text-align:center;}
.timeline-list{max-height:360px; overflow:auto; display:flex; flex-direction:column; gap:1rem; padding-left:.5rem;}
.timeline-item{display:flex; gap:.75rem; position:relative; padding-left:1.25rem;}
.timeline-item::before{content:""; position:absolute; left:.3rem; top:.2rem; bottom:-.5rem; width:2px; background:var(--line-border);}
.timeline-item:last-child::before{bottom:0;}
.timeline-dot{width:10px; height:10px; border-radius:50%; background:var(--track-active); position:absolute; left:0; top:.35rem; box-shadow:0 0 0 4px color-mix(in srgb, var(--track-active), transparent 70%);}
.timeline-title{font-size:.85rem; font-weight:600; margin-bottom:.05rem;}
.timeline-desc{font-size:.78rem; color:#6c757d; margin-bottom:.1rem;}
.timeline-date{font-size:.72rem; color:#98a2b3;}
.actions-bar .btn{font-size:.78rem; text-transform:uppercase; font-weight:600; letter-spacing:.4px;}
@media (max-width: 768px){
  .progress-wrapper{margin:0 -0.5rem; padding:0.2rem 0 0.6rem;}
  .progress-steps{min-width:640px;}
  .actions-bar{flex-direction:column; align-items:flex-start !important; gap:.5rem;}
}
@media (max-width: 640px){
  .progress-wrapper{overflow-x:visible; margin:0;}
  .progress-steps{flex-direction:column; gap:1rem; position:relative; padding-left:1.8rem; min-width:auto;}
  .progress-steps::before{display:none;}
  .step{display:flex; align-items:center; text-align:left; padding-left:.5rem;}
  .step::after{content:""; position:absolute; left:18px; top:52px; bottom:-14px; width:2px; background:var(--line-border);}
  .step:last-child::after{display:none;}
  .step .circle{width:42px; height:42px; margin:0 0.75rem 0 0;}
  .step .label{max-width:none; text-align:left;}
  .step .bar-fill{display:none!important;}
  .card-track{padding:1rem;}
  .actions-bar .btn{width:100%;}
}
</style>
</head>
<body>
<?php if($logo): ?>
<header class="brand-banner py-4">
  <div class="container">
    <div class="brand-block text-center">
      <img src="<?=htmlspecialchars($logo)?>" alt="Logo <?=htmlspecialchars($app_name)?>" class="brand-logo">
      <h1 class="brand-title"><?=htmlspecialchars($app_name)?></h1>
      <div class="brand-sub">Rastreamento da Requisição</div>
    </div>
  </div>
</header>
<?php endif; ?>
<div class="container py-4" id="app" style="max-width:1100px;">
  <div class="d-flex justify-content-between align-items-center mb-3 actions-bar">
    <div>
      <h3 class="page-title mb-1"><i class="bi bi-geo me-2"></i>Acompanhar Requisição <small class="text-secondary" id="reqIdLabel">#<?= $requisicao_id ?: '—' ?></small></h3>
  <?php if($publico): ?><span class="badge bg-secondary-subtle text-secondary-emphasis small" data-bs-theme="light">Link público</span><?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <button id="btnRefresh" class="btn btn-matrix-secondary-outline"><i class="bi bi-arrow-clockwise me-1"></i>Atualizar</button>
      <?php if($publico): ?>
        <button id="btnCopiar" class="btn btn-matrix-secondary-outline"><i class="bi bi-link-45deg me-1"></i>Copiar Link</button>
      <?php endif; ?>
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
        <p class="small text-secondary mb-2"><i class="bi bi-hash me-1"></i><span id="reqIdTexto">—</span></p>
        <p class="small text-secondary mb-0"><i class="bi bi-clock me-1"></i><span id="reqCriado"></span></p>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card-track" id="painelItens">
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
  <div class="row g-4 mt-1">
    <div class="col-lg-12">
      <div class="card-track" id="timelineCard">
        <h2>Linha do Tempo</h2>
        <div id="timelineList" class="timeline-list">
          <div class="text-secondary small">Carregando eventos...</div>
        </div>
      </div>
    </div>
  </div>
  <?php if($publico): ?><p class="footer-note">Este link é público e expira automaticamente conforme configuração. Mantenha-o em sigilo.</p><?php endif; ?>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- Mobile Tables: transformar tabelas em "cards" no celular ---
(function(){
  function applyMobileTableCards(){
    const tables = document.querySelectorAll('table');
    tables.forEach(tbl => {
      if (tbl.dataset.mobileCardsApplied === '1') return;
      const headers = Array.from(tbl.querySelectorAll('thead th')).map(th => th.textContent.trim());
      if (headers.length === 0) return;
      tbl.querySelectorAll('tbody tr').forEach(tr => {
        Array.from(tr.children).forEach((cell, idx) => {
          if (cell.tagName && cell.tagName.toLowerCase() === 'td') {
            if (!cell.hasAttribute('data-label') && headers[idx]) {
              cell.setAttribute('data-label', headers[idx]);
            }
          }
        });
      });
      let wrapper = tbl.closest('.table-responsive');
      if (wrapper) {
        wrapper.classList.add('table-mobile-cards');
      } else {
        const div = document.createElement('div');
        div.className = 'table-mobile-cards';
        tbl.parentNode && tbl.parentNode.insertBefore(div, tbl);
        div.appendChild(tbl);
      }
      tbl.dataset.mobileCardsApplied = '1';
    });
  }
  window.addEventListener('DOMContentLoaded', applyMobileTableCards);
})();
const requisicaoId = <?= (int)$requisicao_id ?>;
const tokenTracking = <?= json_encode($token, JSON_UNESCAPED_SLASHES); ?>;
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
const TIMELINE_LABELS = {
  requisicao_criada:'Requisição criada',
  requisicao_status_alterado:'Status da requisição',
  cotacao_criada:'Cotação criada',
  cotacao_resposta_recebida:'Resposta de cotação recebida',
  proposta_criada:'Proposta registrada',
  proposta_aprovada:'Proposta aprovada',
  pedido_criado:'Pedido criado',
  pedido_atualizado:'Pedido atualizado',
  pedido_enviado_cliente:'Pedido enviado ao cliente',
  pedido_aceito:'Pedido aceito pelo cliente',
  pedido_rejeitado:'Pedido rejeitado pelo cliente',
  pedido_removido:'Pedido removido',
  tracking_token_gerado:'Link público gerado'
};
async function copyToClipboard(text){
  try{
    if(navigator.clipboard && window.isSecureContext){
      await navigator.clipboard.writeText(text);
      return true;
    }
  }catch(e){ /* continua para fallback */ }
  try{
    const ta=document.createElement('textarea');
    ta.value=text; ta.setAttribute('readonly',''); ta.style.position='fixed'; ta.style.top='-9999px'; ta.style.left='-9999px'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.focus({preventScroll:true}); ta.select();
    const ok=document.execCommand('copy');
    ta.remove();
    return !!ok;
  }catch(e){ return false; }
}
// Padronização de rótulos do status de Pedido
const PEDIDO_STATUS_LABELS = { emitido:'Emitido', pendente:'Pendente', em_producao:'Em produção', enviado:'Enviado', entregue:'Entregue', cancelado:'Cancelado', aguardando_aprovacao_cliente:'Aguardando aprovação do cliente' };
function humanizeStatus(v){ if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/\b\w/g, c=> c.toUpperCase()); }
function statusLabelPedido(v){ v=(v||'').toLowerCase(); return PEDIDO_STATUS_LABELS[v] || humanizeStatus(v); }
function deriveOverallStatus(data){
  let status='requisicao_criada';
  if(data.cotacoes?.length) status='cotacao';
  if(data.propostas?.length) status='proposta_recebida';
  if(data.pedidos?.length){
    // Seleciona o pedido de maior id (mais recente)
    const ped = getLatestPedido(data.pedidos);
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
  const params = new URLSearchParams();
  if(tokenTracking){ params.append('token', tokenTracking); }
  else if(requisicaoId){ params.append('requisicao_id', requisicaoId); }
  params.append('timeline','1');
  let url = '../api/acompanhar_requisicao.php?'+params.toString();
  fetch(url,{cache:'no-store'}).then(r=>r.json()).then(data=>{
    if(!data.success){ showToast(data.erro||'Erro ao carregar','danger'); return; }
    document.getElementById('reqIdLabel').textContent = '#'+data.requisicao.id;
    document.getElementById('reqIdTexto').textContent = '#'+data.requisicao.id;
    document.getElementById('reqCriado').textContent = formatDate(data.requisicao.criado_em);
    if(tokenTracking){ document.title = 'Rastreamento Requisição #'+data.requisicao.id+' - <?php echo addslashes($app_name); ?>'; }
    // Status geral
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
  const latestPedido = getLatestPedido(data.pedidos);
  if(latestPedido){ const stLabel = statusLabelPedido(latestPedido.status); badges.push(badge('Pedido '+stLabel, latestPedido.status==='entregue'?'ok':(latestPedido.status==='cancelado'?'err':'prog'))); }
    document.getElementById('statusBadges').innerHTML = badges.join('');
  const timeline = Array.isArray(data.timeline)? data.timeline : [];
  renderTimeline(timeline);
  // Itens
    const body = document.getElementById('itensBody');
    if(!data.itens?.length){ body.innerHTML = `<tr><td colspan='3' class='text-center text-secondary py-4'>Sem itens.</td></tr>`; }
    else { body.innerHTML = data.itens.map(i=> `<tr><td>#${i.id}</td><td>${i.produto_nome||'-'}</td><td class='text-end'>${i.quantidade}</td></tr>`).join(''); }
  const copyBtn = document.getElementById('btnCopiar');
  if(copyBtn && tokenTracking){
      copyBtn.onclick = async ()=>{
        const ok = await copyToClipboard(location.href);
        showToast(ok? 'Link copiado' : 'Não foi possível copiar', ok? 'success':'warning');
      };
    }
  }).catch(err=>{ console.error(err); showToast('Falha ao carregar','danger'); });
}
function showToast(msg,type='info'){ const c=document.querySelector('.toast-container'); if(!c) return; const id='t'+Date.now(); c.insertAdjacentHTML('beforeend',`<div id='${id}' class='toast align-items-center text-white bg-${type} border-0'><div class='d-flex'><div class='toast-body'>${msg}</div><button class='btn-close btn-close-white me-2 m-auto' data-bs-dismiss='toast'></button></div></div>`); const el=document.getElementById(id); const t=new bootstrap.Toast(el,{delay:3000}); t.show(); el.addEventListener('hidden.bs.toast',()=>el.remove()); }
 document.getElementById('btnRefresh').addEventListener('click', loadData);
loadData();

function getLatestPedido(list){
  if(!Array.isArray(list) || !list.length) return null;
  return [...list].sort((a,b)=> b.id - a.id)[0];
}

function parseDadosDepois(raw){
  if(!raw) return null;
  if(typeof raw === 'object') return raw;
  try { return JSON.parse(raw); } catch(_){ return null; }
}

function renderTimeline(events){
  const list = document.getElementById('timelineList');
  if(!list) return;
  if(!Array.isArray(events) || !events.length){
    list.innerHTML = `<div class="text-secondary small">Nenhum evento registrado ainda.</div>`;
    return;
  }
  const items = events.slice(0,12).map(ev=>{
    const type = (ev.tipo_evento||'').toLowerCase();
    const label = TIMELINE_LABELS[type] || humanizeStatus(type);
    const after = parseDadosDepois(ev.dados_depois);
    let details = '';
    if(ev.descricao){ details = `<div class="timeline-desc">${ev.descricao}</div>`; }
    else if(after && after.status && type.startsWith('pedido')){ details = `<div class="timeline-desc">Status: ${statusLabelPedido(after.status)}</div>`; }
    return `<div class="timeline-item">
      <div class="timeline-dot"></div>
      <div>
        <div class="timeline-title">${label}</div>
        ${details}
        <div class="timeline-date">${formatDate(ev.criado_em)}</div>
      </div>
    </div>`;
  }).join('');
  list.innerHTML = items;
}
</script>
</body>
</html>
