<?php
// Página pública para aceite/rejeição de pedido via token
session_start();
require_once __DIR__ . '/../includes/branding.php';
$token = $_GET['token'] ?? '';
?><!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aceite de Pedido<?= isset($branding['app_name'])? ' - '.htmlspecialchars($branding['app_name']):''; ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../assets/css/nexus.css">
  <style>
    .card-public { max-width:720px; margin: 10vh auto; background: var(--matrix-surface-transparent); border:1px solid var(--matrix-border); border-radius: 12px; }
    .lead-muted { color: var(--matrix-text-secondary); }
  </style>
</head>
<body>
<div class="container">
  <div class="card card-public">
    <div class="card-header card-header-matrix d-flex align-items-center gap-2">
      <i class="bi bi-bag-check"></i>
      <span>Aceite de Pedido</span>
    </div>
    <div class="card-body">
      <div id="view-loading" class="text-center py-5">
        <div class="spinner-border text-light" role="status"></div>
        <p class="mt-3 lead-muted">Carregando informações do pedido…</p>
      </div>
      <div id="view-error" class="d-none text-center py-5">
        <div class="text-danger"><i class="bi bi-shield-exclamation" style="font-size:2rem;"></i></div>
        <h5 class="mt-3">Link inválido ou expirado</h5>
        <p class="lead-muted">Verifique se o link está correto. Caso o problema persista, solicite um novo link ao seu contato.</p>
      </div>
      <div id="view-pendente" class="d-none">
        <h4 class="mb-2">Pedido <span id="pedido-id-label" class="font-monospace"></span></h4>
        <p class="lead lead-muted">Revise as informações do seu pedido e confirme sua decisão.</p>
        <div class="alert alert-warning d-flex align-items-center" role="alert">
          <i class="bi bi-hourglass-split me-2"></i>
          <div>Pendente de aprovação do cliente.</div>
        </div>
        <div class="d-flex gap-2">
          <button id="btn-aceitar" class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Aceitar Pedido</button>
          <button id="btn-rejeitar" class="btn btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Rejeitar Pedido</button>
        </div>
      </div>
      <div id="view-final" class="d-none text-center py-4">
        <div id="final-icon" class="mb-2" style="font-size:2rem;"></div>
        <h5 id="final-title" class="mb-2"></h5>
        <p id="final-desc" class="lead-muted mb-0"></p>
      </div>
    </div>
  </div>
</div>
<script>
const token = <?php echo json_encode($token); ?>;
const el = (id)=> document.getElementById(id);
function show(id){ ['view-loading','view-error','view-pendente','view-final'].forEach(x=> el(x).classList.add('d-none')); el(id).classList.remove('d-none'); }
async function carregar(){
  if(!token){ show('view-error'); return; }
  try {
    const r = await fetch('../api/pedidos_aceite.php?token='+encodeURIComponent(token));
    if(!r.ok){ show('view-error'); return; }
    const j = await r.json();
    if(!j || !j.pedido_id){ show('view-error'); return; }
    el('pedido-id-label').textContent = '#'+j.pedido_id;
    const st = (j.status||'').toLowerCase();
    if(st==='pendente'){ show('view-pendente'); }
    else if(st==='aceito'){ renderFinal('success','Pedido aceito','Obrigado! Seu pedido foi confirmado.'); }
    else if(st==='rejeitado'){ renderFinal('danger','Pedido rejeitado','Seu pedido foi rejeitado conforme sua solicitação.'); }
    else { show('view-error'); }
  } catch(e){ show('view-error'); }
}
function setBusy(btn,busy){ if(!btn) return; if(busy){ btn.disabled=true; btn.dataset.oldHtml=btn.innerHTML; btn.innerHTML='<span class="spinner-border spinner-border-sm"></span>'; } else { btn.disabled=false; if(btn.dataset.oldHtml) btn.innerHTML=btn.dataset.oldHtml; } }
el('btn-aceitar')?.addEventListener('click', ()=> decidir('aceitar', el('btn-aceitar')));
el('btn-rejeitar')?.addEventListener('click', ()=> decidir('rejeitar', el('btn-rejeitar')));
async function decidir(acao, btn){ try { setBusy(btn,true); const fd = new FormData(); fd.set('token', token); fd.set('acao', acao); const r = await fetch('../api/pedidos_aceite.php', { method:'POST', body: fd }); const j = await r.json(); if(j && j.success){ if(j.status==='aceito') renderFinal('success','Pedido aceito','Obrigado! Seu pedido foi confirmado.'); else renderFinal('secondary','Pedido rejeitado','Seu pedido foi rejeitado.'); } else { alert((j&&j.erro)||'Falha ao registrar sua decisão.'); } } catch(e){ alert('Erro de rede.'); } finally { setBusy(btn,false); } }
function renderFinal(type,title,desc){ show('view-final'); const iconMap={ success:'bi bi-check-circle-fill text-success', danger:'bi bi-x-octagon-fill text-danger', secondary:'bi bi-dash-circle-fill text-secondary' }; el('final-icon').innerHTML = `<i class="${iconMap[type]||iconMap.secondary}"></i>`; el('final-title').textContent = title; el('final-desc').textContent = desc; }
carregar();
</script>
</body>
</html>
