<?php
session_start();
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/auth.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){
    header('Location: ../login.php');
    exit;
}
$currentNav='dashboard';
?><!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Portal do Fornecedor - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link rel="stylesheet" href="../../assets/css/nexus.css">
<style>
body{--gap:1rem;}
main.container{max-width:1180px;}
#kpis{display:grid;gap:var(--gap);grid-template-columns:repeat(auto-fit,minmax(150px,1fr));margin:1.25rem 0 1.5rem;}
.kpi{background:var(--matrix-surface);border:1px solid var(--matrix-border);padding:.8rem .95rem;border-radius:.7rem;position:relative;min-height:90px;}
.kpi h6{margin:0;font-size:.58rem;letter-spacing:.05em;font-weight:600;text-transform:uppercase;color:var(--matrix-text-secondary);} 
.kpi .val{font-size:1.5rem;font-weight:600;line-height:1;margin-top:.35rem;}
.kpi.loading:after{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.04),rgba(255,255,255,.18),rgba(255,255,255,.04));background-size:200% 100%;animation:shimmer 1.1s linear infinite;border-radius:.7rem;}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.small-hint{font-size:.62rem;opacity:.7;margin-top:.15rem;display:block;}
.section{margin-bottom:1.75rem;}
.table-mini{font-size:.78rem;}
.badge-status{font-size:.65rem;letter-spacing:.05em;text-transform:uppercase;}
#debug-box{white-space:pre-wrap;font-family:monospace;font-size:.65rem;background:rgba(255,255,255,.05);border:1px solid var(--matrix-border);padding:.6rem;border-radius:.5rem;display:none;max-height:320px;overflow:auto;}
.rank-pill{display:inline-block;padding:.18rem .5rem;font-size:.62rem;border:1px solid var(--matrix-border);border-radius:1rem;margin:.2rem .35rem .2rem 0;background:var(--matrix-surface);}
.rank-pill.good{border-color:#28a74566;color:#28a745;}
.rank-pill.warn{border-color:#ffc10766;color:#ffc107;}
.rank-pill.bad{border-color:#dc354566;color:#dc3545;}
#wrap-flex{display:flex;flex-direction:row;gap:1.5rem;flex-wrap:wrap;}
#sec-propostas{flex:1 1 100%;min-width:420px;}
#sec-propostas .card-matrix{padding:0;border-radius:1.25rem;overflow:hidden;box-shadow:0 18px 40px rgba(15,15,15,.2);}
#sec-propostas .card-header-matrix{margin-bottom:0;border-bottom:1px solid var(--matrix-border);padding:1rem 1.5rem;}
#sec-propostas .table-responsive{max-height:420px;border-radius:0;padding:0 1.25rem 1rem;}
#sec-propostas .table-modern thead th{font-size:.62rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:var(--matrix-text-secondary);border-bottom:1px solid var(--matrix-border);background:transparent;}
#sec-propostas .table-modern tbody tr{border-radius:.75rem;}
#sec-propostas .table-modern tbody td{padding:.65rem .75rem;color:var(--matrix-text-secondary);border-bottom:1px solid rgba(255,255,255,.04);}
#sec-propostas .table-modern tbody tr:hover{background:rgba(181,168,134,.08);}
#sec-propostas .table-modern .font-monospace{font-size:.8rem;}
#sec-propostas .badge-status{padding:.15rem .5rem;border-radius:999px;}
#sec-propostas .table-modern td:last-child{white-space:nowrap;}
#sec-propostas .table-modern td:nth-child(4){font-weight:600;color:var(--matrix-text-primary);}
@media (max-width: 900px){#wrap-flex{flex-direction:column;} #sec-propostas{max-width:100%;}}
</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="container py-2">
  <div id="alert-erro" class="alert alert-danger small d-none"></div>
  <div id="kpis">
    <div class="kpi loading" id="kpi-abertas"><h6>Abertas</h6><div class="val">–</div></div>
    <div class="kpi loading" id="kpi-enviadas"><h6>Enviadas</h6><div class="val">–</div></div>
    <div class="kpi loading" id="kpi-aprovadas"><h6>Aprovadas</h6><div class="val">–</div><span class="small-hint" id="hint-rate"></span></div>
    <div class="kpi loading" id="kpi-ranking"><h6>Melhor Posição</h6><div class="val">–</div><span class="small-hint" id="hint-pos"></span></div>
    <div class="kpi loading" id="kpi-ticket"><h6>Ticket Médio</h6><div class="val">–</div></div>
    <div class="kpi loading" id="kpi-prazo"><h6>Prazo Médio</h6><div class="val">–</div></div>
  </div>

  <div id="wrap-flex">
    <section id="sec-propostas" class="section">
      <div class="card-matrix h-100">
        <div class="card-header-matrix d-flex justify-content-between align-items-center">
          <div class="text-uppercase text-secondary fw-semibold small"><i class="bi bi-card-checklist me-2"></i>Últimas Propostas</div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="btnReloadPropostas"><i class="bi bi-arrow-repeat me-1"></i>Atualizar</button>
        </div>
        <div class="table-responsive table-modern">
          <table class="table table-hover table-sm align-middle mb-0 table-mini" id="tbl-propostas">
            <thead>
              <tr><th>ID</th><th>Cotação</th><th>Status</th><th>Pos</th><th>Valor</th><th>Atividade</th></tr>
            </thead>
            <tbody><tr><td colspan="6" class="text-center text-secondary">Carregando...</td></tr></tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
  <div id="debug-box"></div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const debugMode = new URLSearchParams(location.search).has('debug');
const dbgStore=[]; function dbg(msg,data){dbgStore.push({t:Date.now(),msg,data}); if(debugMode) appendDebug(msg,data);} 
function appendDebug(msg,data){const box=document.getElementById('debug-box'); if(!box)return; box.style.display='block'; box.textContent += (box.textContent?'\n':'')+'['+new Date().toLocaleTimeString()+'] '+msg+(data? ' '+(typeof data==='string'?data:JSON.stringify(data)).slice(0,800):''); }
function setKpi(id,val){const el=document.getElementById(id); if(!el)return; el.classList.remove('loading'); const v=el.querySelector('.val'); if(v) v.textContent=val; }
function fmtMoney(v){ if(v==null) return '–'; const n=parseFloat(v)||0; return n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}); }
function fmtDate(s){ if(!s) return ''; try { return new Date(s.replace(' ','T')).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});}catch{return '';} }
function showErro(msg){const a=document.getElementById('alert-erro'); a.textContent=msg; a.classList.remove('d-none'); dbg('erro',msg);} 
function statusMsg(t){ const el=document.getElementById('status-msg'); if(el) el.textContent=t; }
function aplicarDados(d){
  if(!d){ showErro('Sem dados.'); return; }
  const m = d.metrics || d;
  const safe = (x,def=0)=> (x==null||isNaN(x)?def:x);
  setKpi('kpi-abertas', safe(m.abertas));
  setKpi('kpi-enviadas', safe(m.enviadas));
  setKpi('kpi-aprovadas', safe(m.aprovadas));
  const rate = m.aprovacao_rate!=null? (m.aprovacao_rate.toFixed? m.aprovacao_rate.toFixed(1):m.aprovacao_rate):0;
  const hintRate=document.getElementById('hint-rate'); if(hintRate) hintRate.textContent = rate+'%';
  setKpi('kpi-ticket', fmtMoney(m.ticket_medio_aprovado));
  setKpi('kpi-prazo', (safe(m.prazo_medio)).toFixed(1));
  if(m.melhor_posicao!=null){ setKpi('kpi-ranking','#'+m.melhor_posicao); const hp=document.getElementById('hint-pos'); if(hp) hp.textContent = d.posicao_frase || ''; } else { setKpi('kpi-ranking','–'); }
  if(Array.isArray(d.ultimas_propostas)){
    const tb=document.querySelector('#tbl-propostas tbody');
    if(tb){
      if(d.ultimas_propostas.length===0){ tb.innerHTML='<tr><td colspan="6" class="text-center text-secondary">Sem propostas</td></tr>'; }
      else {
        tb.innerHTML=d.ultimas_propostas.slice(0,50).map(p=>`<tr><td class=\"font-monospace\">#${p.id}</td><td>#${p.cotacao_id}</td><td><span class=\"badge bg-secondary badge-status\">${(p.status||'').replace(/_/g,' ')}</span></td><td>${p.pos||''}</td><td>${p.valor_total? fmtMoney(p.valor_total):'–'}</td><td>${fmtDate(p.atividade)}</td></tr>`).join('');
      }
    }
  }
  dbg('aplicar_dados_ok');
}
async function fetchJson(u){ dbg('fetch',u); const r=await fetch(u,{cache:'no-store'}); const txt=await r.text(); if(!r.ok){ dbg('http_fail',{u,status:r.status,body:txt.slice(0,180)}); throw new Error('HTTP '+r.status); } try { return JSON.parse(txt); } catch(e){ dbg('parse_fail',{u,body:txt.slice(0,180)}); throw new Error('JSON inválido'); } }
let dadosDash=null; async function carregar(){ statusMsg('Carregando...'); document.getElementById('alert-erro').classList.add('d-none'); let data=null; const base='../../api/fornecedor/'; try { data = await fetchJson(base+'dashboard_ext.php'+(debugMode?'?debug=1':'')); dbg('ext_ok'); } catch(e){ dbg('ext_fail',e.message); try { data = await fetchJson(base+'dashboard.php'+(debugMode?'?debug=1':'')); dbg('legacy_ok'); } catch(e2){ dbg('legacy_fail',e2.message); showErro('Falha ao carregar dashboard.'); statusMsg('Erro'); return; } } dadosDash=data; try { aplicarDados(data); statusMsg('Atualizado'); if(debugMode){ appendDebug('RAW',data); } } catch(e){ showErro('Erro ao processar dados'); dbg('process_fail',e.message); statusMsg('Erro'); }
}

window.addEventListener('DOMContentLoaded',()=>{
  carregar();
  document.getElementById('btnReloadPropostas')?.addEventListener('click',()=>{
    const tb=document.querySelector('#tbl-propostas tbody');
    if(tb) tb.innerHTML='<tr><td colspan="6" class="text-center text-secondary">Atualizando...</td></tr>';
    carregar();
  });
});
// Botões removidos (reload/debug) – fallback: recarregar via F5
window.dumpDashboardDebug = () => JSON.stringify(dbgStore,null,2);
</script>
</body>
</html>
