<?php
session_start();
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
$u = auth_usuario();
if(!$u){ header('Location: ../login.php'); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ header('Location: ../index.php'); exit; }
$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if(!$id){ header('Location: requisicoes.php'); exit; }

// Carregar requisicao e validar ownership
$sql = "SELECT r.*, COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id)) AS titulo_exib\n        FROM requisicoes r WHERE r.id=? AND r.cliente_id=?";
$st=$db->prepare($sql); $st->execute([$id,$clienteId]); $req = $st->fetch(PDO::FETCH_ASSOC);
if(!$req){ header('Location: requisicoes.php'); exit; }
// Pode editar SOMENTE quando pendente de aprovação (antes do admin aprovar/rejeitar)
$podeEditar = in_array(($req['status'] ?? ''), ['pendente_aprovacao'], true);

// Carregar últimas cotações e pedidos relacionados
$sqlC = "SELECT c.id, c.status, c.criado_em, COUNT(pr.id) as propostas\n         FROM cotacoes c LEFT JOIN propostas pr ON pr.cotacao_id=c.id\n         WHERE c.requisicao_id=? GROUP BY c.id ORDER BY c.id DESC";
$st=$db->prepare($sqlC); $st->execute([$id]); $cots=$st->fetchAll(PDO::FETCH_ASSOC);
$sqlP = "SELECT p.id, p.cliente_aceite_status, p.cliente_aceite_em, p.criado_em\n         FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id\n         WHERE c.requisicao_id=? ORDER BY p.id DESC";
$st=$db->prepare($sqlP); $st->execute([$id]); $peds=$st->fetchAll(PDO::FETCH_ASSOC);

// Mapas de rótulos
$STATUS_LABELS = [ 'pendente_aprovacao'=>'Pendente de aprovação','em_analise'=>'Em análise','aberta'=>'Aberta','fechada'=>'Fechada','aprovada'=>'Aprovada','rejeitada'=>'Rejeitada' ];
$ACEITE_LABELS = [ 'pendente'=>'Pendente', 'aceito'=>'Aceito', 'rejeitado'=>'Rejeitado' ];
$reqStatus = (string)($req['status'] ?? '');
$reqBadge = 'secondary'; if($reqStatus==='aberta') $reqBadge='info'; elseif($reqStatus==='em_analise' || $reqStatus==='pendente_aprovacao') $reqBadge='warning text-dark'; elseif($reqStatus==='fechada' || $reqStatus==='aprovada') $reqBadge='success'; elseif($reqStatus==='rejeitada') $reqBadge='danger';
$reqLabel = $STATUS_LABELS[$reqStatus] ?? ucwords(str_replace('_',' ', $reqStatus));
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($req['titulo_exib']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/nexus.css">
  <style>.timeline{border-left:2px solid rgba(128,114,255,.35);margin-left:10px;padding-left:15px}.timeline .item{position:relative;margin-bottom:14px}.timeline .item:before{content:'';position:absolute;left:-10px;top:8px;width:10px;height:10px;border-radius:50%;background:#8072ff;box-shadow:0 0 0 3px rgba(128,114,255,.22)} .meta{color:#aab} .evt{display:flex;align-items:center;gap:.5rem}.evt .icon{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:6px;background:rgba(128,114,255,.15)} .evt .badge{margin-left:.35rem} .evt-desc{color:#cbd5e1}
  /* Removed custom autocomplete skin to use global dropdown look */
  .produto-autocomplete-wrapper{position:relative}
  .produto-autocomplete-wrapper .dropdown-menu{max-height:300px;overflow:auto;width:100%;}
  .produto-autocomplete-wrapper .dropdown-item small{font-size:.65rem;opacity:.7;margin-left:.4rem;text-transform:uppercase;letter-spacing:.5px}
  .btn-ghost-danger{border:0;background:rgba(220,53,69,.12);color:#ef6d7a;font-weight:600;font-size:.75rem;border-radius:999px;padding:.25rem .85rem;display:inline-flex;align-items:center;gap:.35rem;transition:.2s ease;box-shadow:0 0 0 0 rgba(239,109,122,.35)}
  .btn-ghost-danger i{font-size:.85rem}
  .btn-ghost-danger:hover{background:rgba(220,53,69,.18);color:#ef6d7a;box-shadow:0 6px 16px -6px rgba(220,53,69,.55);transform:translateY(-1px)}
  .btn-ghost-danger:focus-visible{outline:2px solid rgba(239,109,122,.65);outline-offset:2px}
  </style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0"><?= htmlspecialchars($req['titulo_exib']) ?> <small class="text-secondary">#<?= (int)$req['id'] ?></small></h3>
    <span class="badge bg-<?= $reqBadge ?>">Status: <?= htmlspecialchars($reqLabel) ?></span>
  </div>
  <div class="row g-3">
    <div class="col-lg-8">
      <!-- Itens da Requisição -->
      <div class="card-matrix p-3 mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="mb-0">Itens da Requisição</h5>
          <?php if(!$podeEditar): ?><span class="badge bg-secondary">Somente leitura</span><?php endif; ?>
        </div>
        <?php if($podeEditar): ?>
        <form id="formItem" class="row g-2 align-items-end mb-3" autocomplete="off">
          <div class="col-md-6">
            <label class="form-label">Produto</label>
            <div class="produto-autocomplete-wrapper">
              <select class="form-select" id="produto" style="display:none"></select>
              <input type="text" id="produto_search" class="form-control" placeholder="Buscar por nome ou NCM" aria-autocomplete="list" aria-expanded="false" aria-owns="produto_menu" role="combobox" />
              <div id="produto_menu" class="dropdown-menu"></div>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label">Quantidade</label>
            <div class="input-group">
              <input type="number" min="1" value="1" class="form-control" id="quantidade">
              <span class="input-group-text" id="unidade_atual">un</span>
            </div>
          </div>
          <div class="col-md-3">
            <button class="btn btn-matrix-primary w-100" type="submit">Adicionar</button>
          </div>
        </form>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Produto</th><th class="text-end">Qtd</th><th>Unid.</th><?php if($podeEditar): ?><th class="text-end">Ações</th><?php endif; ?></tr></thead>
            <tbody id="itensBody"><tr><td colspan="5" class="text-secondary text-center">Carregando...</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="card-matrix p-3">
        <h5 class="mb-3">Linha do Tempo</h5>
        <div id="timeline" class="timeline small"></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card-matrix p-3 mb-3">
        <h6 class="mb-2">Cotações</h6>
        <?php if(!$cots){ echo '<div class="text-secondary">Sem cotações ainda.</div>'; } else { echo '<ul class="mb-0">'; foreach($cots as $c){ echo '<li>cotação #'.(int)$c['id'].' · propostas: '.(int)$c['propostas'].'</li>'; } echo '</ul>'; } ?>
      </div>
      <div class="card-matrix p-3">
        <h6 class="mb-2">Pedidos</h6>
        <?php if(!$peds){ echo '<div class="text-secondary">Sem pedidos.</div>'; } else { echo '<ul class="mb-0">'; foreach($peds as $p){ $s=(string)($p['cliente_aceite_status']??''); $b=$s==='aceito'?'success':($s==='rejeitado'?'danger':'warning text-dark'); $l=$ACEITE_LABELS[$s]??ucwords(str_replace('_',' ',$s)); echo '<li>pedido #'.(int)$p['id'].' · aceite: <span class="badge bg-'.$b.'">'.htmlspecialchars($l).'</span></li>'; } echo '</ul>'; } ?>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- Utilidades ---
function escapeHtml(s){ return (s||'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;'); }

// --- Autocomplete de Produtos ---
let produtoCache = new Map(); // id -> produto
let searchAbort = null;
let currentActiveIndex = -1;
let currentResults = [];

function clearMenu(){ const m=document.getElementById('produto_menu'); m.innerHTML=''; m.classList.remove('show'); m.setAttribute('aria-expanded','false'); currentResults=[]; currentActiveIndex=-1; }
function renderLoading(){ const m=document.getElementById('produto_menu'); m.innerHTML='<div class="dropdown-item disabled"><span class="spinner-border spinner-border-sm me-2"></span>Buscando...</div>'; m.classList.add('show'); }
function renderEmpty(msg='Nada encontrado'){ const m=document.getElementById('produto_menu'); m.innerHTML='<span class="dropdown-item disabled">'+escapeHtml(msg)+'</span>'; m.classList.add('show'); }
function renderResults(list){ const m=document.getElementById('produto_menu'); if(!list.length){ renderEmpty(); return; } currentResults=list; currentActiveIndex=-1; m.innerHTML=''; for(let i=0;i<list.length;i++){ const p=list[i]; const a=document.createElement('button'); a.type='button'; a.className='dropdown-item d-flex justify-content-between align-items-center'; a.setAttribute('data-id',p.id); a.innerHTML='<span>'+escapeHtml(p.nome)+'</span><small>'+escapeHtml(p.ncm||'')+' '+escapeHtml(p.unidade||'')+'</small>'; a.addEventListener('mousedown', (ev)=>{ ev.preventDefault(); chooseProduto(p); }); m.appendChild(a); } m.classList.add('show'); m.setAttribute('aria-expanded','true'); }
function highlightActive(){ const m=document.getElementById('produto_menu'); const items=[...m.querySelectorAll('.dropdown-item')]; items.forEach((el,i)=>{ el.classList.toggle('active', i===currentActiveIndex); if(i===currentActiveIndex) el.scrollIntoView({block:'nearest'}); }); }
function chooseProduto(p){ const hiddenSel=document.getElementById('produto'); hiddenSel.innerHTML=''; const opt=document.createElement('option'); opt.value=p.id; opt.textContent=p.nome + (p.unidade?` (${p.unidade})`: ''); opt.setAttribute('data-unidade', p.unidade||''); hiddenSel.appendChild(opt); hiddenSel.selectedIndex=0; produtoCache.set(p.id, p); document.getElementById('produto_search').value=p.nome; atualizarUnidadeAtual(); clearMenu(); }

async function searchProdutos(term){ if(searchAbort){ searchAbort.abort(); }
  term=term.trim(); if(term.length < 2){ clearMenu(); return; }
  const ctrl=new AbortController(); searchAbort=ctrl; renderLoading();
  try{ const res = await fetch(`../../api/produtos.php?q=${encodeURIComponent(term)}`, {signal: ctrl.signal}); if(!res.ok){ renderEmpty('Erro na busca'); return; } const list=await res.json(); if(ctrl.signal.aborted) return; renderResults(Array.isArray(list)?list:[]); }catch(e){ if(ctrl.signal.aborted) return; renderEmpty('Falha de rede'); }
}

function initProdutoAutocomplete(){ const input=document.getElementById('produto_search'); const menu=document.getElementById('produto_menu');
  input.addEventListener('input', (e)=>{ const v=e.target.value; if(v.trim().length>=2){ searchProdutos(v); } else { clearMenu(); } });
  input.addEventListener('keydown', (e)=>{ const menu=document.getElementById('produto_menu'); if(!menu.classList.contains('show')) return; if(e.key==='ArrowDown'){ e.preventDefault(); currentActiveIndex=Math.min(currentActiveIndex+1, currentResults.length-1); highlightActive(); } else if(e.key==='ArrowUp'){ e.preventDefault(); currentActiveIndex=Math.max(currentActiveIndex-1, 0); highlightActive(); } else if(e.key==='Enter'){ if(currentActiveIndex>=0 && currentActiveIndex<currentResults.length){ e.preventDefault(); chooseProduto(currentResults[currentActiveIndex]); } } else if(e.key==='Escape'){ clearMenu(); }
  });
  document.addEventListener('click', (ev)=>{ const wrap=document.querySelector('.produto-autocomplete-wrapper'); if(wrap && !wrap.contains(ev.target)){ clearMenu(); } });
}

function atualizarUnidadeAtual(){ const sel=document.getElementById('produto'); const un=sel?.selectedOptions?.[0]?.getAttribute('data-unidade') || 'un'; const span=document.getElementById('unidade_atual'); if(span) span.textContent=un; }

async function loadItens(){
  try{
    const res = await fetch('../../api/requisicao_itens.php?requisicao_id=<?= (int)$req['id'] ?>');
    const itens = await res.json();
    const body = document.getElementById('itensBody');
    body.innerHTML = '';
    if(!Array.isArray(itens) || itens.length===0){ body.innerHTML = '<tr><td colspan="5" class="text-secondary text-center">Sem itens.</td></tr>'; return; }
    for(const it of itens){
      const tr = document.createElement('tr');
  tr.innerHTML = `<td>${it.id}</td><td>${escapeHtml(it.nome||'')}</td><td class="text-end">${it.quantidade}</td><td>${escapeHtml(it.unidade||'')}</td><?php if($podeEditar): ?>`+
         `<td class="text-end"><button class="btn btn-ghost-danger" data-del="${it.id}" title="Remover item"><i class="bi bi-trash3"></i><span>Excluir</span></button></td><?php endif; ?>`;
      body.appendChild(tr);
    }
    <?php if($podeEditar): ?>
    body.querySelectorAll('button[data-del]').forEach(btn=>{
      btn.addEventListener('click', async ev=>{
        const id = ev.currentTarget.getAttribute('data-del');
        let ok = true;
        if(window.confirmDialog){ ok = await window.confirmDialog({ title:'Remover item', message:'Remover item #'+id+'?', variant:'danger', confirmText:'Remover', cancelText:'Cancelar' }); }
        else { ok = confirm('Remover item #'+id+'?'); }
        if(!ok) return;
        try{
          const r = await fetch('../../api/requisicao_itens.php', { method:'DELETE', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: new URLSearchParams({ id }) });
          const out = await r.json().catch(()=>null);
          if(r.ok && out && out.success){ loadItens(); loadTimeline(); }
          else { showToast((out && out.erro) ? out.erro : 'Não foi possível remover o item.', 'danger'); }
        }catch(e){ showToast('Erro de rede ao remover item.', 'danger'); }
      });
    });
    <?php endif; ?>
  }catch(e){ console.error(e); }
}
<?php if($podeEditar): ?>
document.getElementById('formItem')?.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const sel = document.getElementById('produto');
  if(!sel.value){ showToast('Selecione um produto.', 'warning'); return; }
  const produto_id = sel.value;
  const quantidade = document.getElementById('quantidade').value;
  const payload = { requisicao_id: <?= (int)$req['id'] ?>, produto_id, quantidade };
  try{
    const res = await fetch('../../api/requisicao_itens.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
    const out = await res.json().catch(()=>null);
    if(res.ok && out && out.success){ document.getElementById('quantidade').value = 1; loadItens(); loadTimeline(); }
    else { showToast((out && out.erro) ? out.erro : 'Não foi possível adicionar o item.', 'danger'); }
  }catch(e){ showToast('Erro de rede ao adicionar item.', 'danger'); }
});
<?php endif; ?>
async function loadTimeline(){
  try{
    const res = await fetch('../../api/timeline.php?entidade=requisicao&id=<?= (int)$req['id'] ?>');
    const data = await res.json();
    const el = document.getElementById('timeline');
    el.innerHTML = '';
    if(!Array.isArray(data) || data.length===0){ el.innerHTML = '<div class="text-secondary">Sem eventos.</div>'; return; }
    for(const it of data){
      const d = document.createElement('div'); d.className='item';
      const when = it.criado_em ? new Date(it.criado_em).toLocaleString() : '';
      const info = mapEvento(it);
      const desc = info.descExtra ? `${escapeHtml(it.descricao||info.label)} · <span class="evt-desc">${escapeHtml(info.descExtra)}</span>` : `${escapeHtml(it.descricao||info.label)}`;
      d.innerHTML = `
        <div class="evt mb-1">
          <span class="icon">${info.icon}</span>
          <strong>${escapeHtml(info.label)}</strong>
          <span class="meta ms-2">${escapeHtml(when)}</span>
          ${info.badge ? `<span class="badge ${info.badge.cls}">${escapeHtml(info.badge.text)}</span>` : ''}
        </div>
        <div>${desc}</div>`;
      el.appendChild(d);
    }
  }catch(e){ console.error(e); }
}
function mapEvento(ev){
  const tipo=(ev.tipo||'').toString();
  const fonte = ev.fonte || '';
  const meta = ev.meta || {};
  const mAntes = meta.antes || {};
  const mDepois = meta.depois || meta || {};
  const icon = (name)=>`<i class="bi ${name}"></i>`;
  let label = tipo.replaceAll('_',' ');
  let ic = icon('bi-clock-history');
  let badge = null;
  let descExtra = '';
  switch(tipo){
    case 'cotacao_convite_enviado': label='Convite de cotação enviado'; ic=icon('bi-envelope-paper'); break;
    case 'cotacao_resposta_recebida': label='Proposta recebida'; ic=icon('bi-reply-fill'); descExtra=metaDetalhe(mDepois,['proposta_id','fornecedor_id']); break;
    case 'proposta_criada': label='Proposta criada'; ic=icon('bi-file-earmark-plus'); descExtra=metaDetalhe(mDepois,['proposta_id','fornecedor_id']); break;
    case 'proposta_atualizada': label='Proposta atualizada'; ic=icon('bi-pencil-square'); descExtra=metaDetalhe(mDepois,['proposta_id','status']); break;
    case 'pedido_criado': label='Pedido emitido'; ic=icon('bi-receipt'); break;
    case 'pedido_enviado_cliente': label='Pedido enviado ao cliente'; ic=icon('bi-send-check'); descExtra=metaDetalhe(mDepois,['pedido_id']); break;
    case 'pedido_aceito': label='Pedido aceito pelo cliente'; ic=icon('bi-hand-thumbs-up-fill'); badge={cls:'bg-success',text:'Aceito'}; descExtra=metaDetalhe(mDepois,['pedido_id']); break;
    case 'pedido_rejeitado': label='Pedido rejeitado pelo cliente'; ic=icon('bi-hand-thumbs-down-fill'); badge={cls:'bg-danger',text:'Rejeitado'}; descExtra=metaDetalhe(mDepois,['pedido_id']); break;
    case 'requisicao_status_alterado': label='Status atualizado'; ic=icon('bi-arrow-repeat'); descExtra=metaDetalhe({de:mAntes?.status,para:mDepois?.status},['de','para']); break;
    case 'anexo_enviado': label='Anexo enviado'; ic=icon('bi-paperclip'); break;
    case 'cotacao_convites_resumo': label='Resumo de convites'; ic=icon('bi-people'); descExtra=metaDetalhe(mDepois,['enviados','responded','expirados','cancelados']); break;
    case 'propostas_resumo': label='Resumo de propostas'; ic=icon('bi-list-check'); descExtra=metaDetalhe(mDepois,['total','aprovadas','rejeitadas']); break;
    default:
      if(tipo.startsWith('followup')){ label='Follow-up'; ic=icon('bi-bell'); }
      break;
  }
  if(fonte==='followup_logs'){ badge = { cls:'bg-warning text-dark', text:'Follow-up' }; }
  return {label, icon: ic, badge, descExtra};
}
function metaDetalhe(obj,keys){ const parts=[]; if(!obj) return ''; for(const k of keys){ if(obj[k]!==undefined && obj[k]!==null && obj[k]!==''){ parts.push(`${k.replace('_',' ')}: ${obj[k]}`); } } return parts.join(' · '); }

loadTimeline();
loadItens();
initProdutoAutocomplete();
</script>
</body>
</html>
