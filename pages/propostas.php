<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/auth.php'; // add for user type
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}
$__u = auth_usuario();
if ($__u && in_array(($__u['tipo'] ?? ''), ['cliente','fornecedor'], true)) {
    if(($__u['tipo'] ?? '') === 'cliente') { header('Location: cliente/index.php'); } else { header('Location: fornecedor/index.php'); }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Propostas - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
    /* Força visual claro para badges de ranking mesmo no tema escuro */
    #page-container .ranking-badge{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:0.35rem;
        min-width:56px;
        padding:0.35rem 0.85rem;
        border-radius:999px;
        font-weight:600;
        border:1px solid rgba(0,0,0,0.08);
        box-shadow:0 6px 16px rgba(0,0,0,0.08);
        color:#33250c !important;
        background:#fffefa;
    }
    #page-container .ranking-badge .bi{
        color:inherit;
        font-size:0.95rem;
    }
    #page-container .ranking-badge.ranking-1{
        background:#fff7eb;
        color:#3a2a05 !important;
        border-color:#f5d8a0;
    }
    #page-container .ranking-badge.ranking-2{
        background:#fffaef;
        color:#4a3a07 !important;
        border-color:#f9e4b4;
    }
    #page-container .ranking-badge.ranking-3{
        background:#f7fbff;
        color:#153044 !important;
        border-color:#c7dff5;
    }
    #page-container .ranking-badge.ranking-default{
        background:#f6f6f8;
        color:#2f3036 !important;
        border-color:#d9dbe2;
    }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-4" id="page-container" 
    data-endpoint="../api/propostas.php" 
    data-singular="Proposta"
    data-plural="Propostas">

    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Gestão de Propostas</h1>
            <p class="text-secondary mb-0">Analise, compare e aprove as propostas recebidas dos fornecedores.</p>
        </div>
    </header>

    <div class="card-matrix mb-4">
        <div class="card-body px-4 pb-4 pt-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="cotacao-select" class="form-label">Filtrar por Cotação</label>
                    <select id="cotacao-select" class="form-select">
                        <option value="">Selecione uma cotação...</option>
                    </select>
                </div>
                <div class="col-md-8">
                     <p class="text-secondary small mb-0">Selecione uma cotação para ver as propostas associadas, classificadas da mais barata para a mais cara.</p>
                </div>
            </div>
        </div>
    </div>


    <div class="card-matrix table-container-gestao">
        <div class="card-header-matrix d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-ul me-2"></i>Propostas Recebidas</span>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-matrix-secondary-outline" id="btn-exportar-propostas" data-bs-toggle="modal" data-bs-target="#modal-exportar-propostas">
                    <i class="bi bi-download me-1"></i>Exportar
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th class="text-center">Ranking</th>
                        <th>Fornecedor</th>
                        <th>Valor Total</th>
                        <th>Prazo (dias)</th>
                        <th>Tipo Frete</th>
                        <th>Pagto (dias)</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabela-main-body"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-detalhes-itens" tabindex="-1" aria-labelledby="modalDetalhesItensLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xl">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalDetalhesItensLabel"><i class="bi bi-card-checklist me-2"></i>Itens da Proposta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
            <div class="row mb-3">
                <div class="col-md-8" id="detalhes-proposta-info"></div> <!-- ALTERADO 6 -> 8 para mais largura -->
                <div class="col-md-4" id="detalhes-proposta-obs"></div> <!-- ALTERADO 6 -> 4 -->
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Produto</th>
                            <th class="text-center">Qtd.</th>
                            <th class="text-end">Preço Unit.</th>
                            <th class="text-end">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody id="tabela-itens-proposta-body"></tbody>
                </table>
            </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal-exportar-propostas" tabindex="-1" aria-labelledby="modalExportarPropostasLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content card-matrix">
      <div class="modal-header card-header-matrix">
        <h5 class="modal-title" id="modalExportarPropostasLabel"><i class="bi bi-download me-2"></i>Exportar Propostas</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form id="form-exportar-propostas">
            <input type="hidden" name="modulo" value="propostas">
            <div class="mb-4">
              <label class="form-label">Formato do Ficheiro</label>
              <div class="export-options d-flex gap-3">
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-csv-proposta" value="csv" checked>
                  <label class="form-check-label" for="export-csv-proposta"><i class="bi bi-filetype-csv me-2"></i>CSV</label>
                </div>
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-xlsx-proposta" value="xlsx">
                  <label class="form-check-label" for="export-xlsx-proposta"><i class="bi bi-file-earmark-excel me-2"></i>XLSX</label>
                </div>
                <div class="form-check flex-fill">
                  <input class="form-check-input d-none" type="radio" name="formato" id="export-pdf-proposta" value="pdf">
                  <label class="form-check-label" for="export-pdf-proposta"><i class="bi bi-file-earmark-pdf me-2"></i>PDF</label>
                </div>
              </div>
            </div>
            <div>
              <label class="form-label">Intervalo de Dados</label>
              <select class="form-select" name="intervalo" id="exp-prop-intervalo">
                <option value="cotacao_atual">Propostas da cotação selecionada</option>
                <option value="todas">Todas as propostas (todas as cotações)</option>
              </select>
            </div>
        </form>
        <p class="small text-secondary mt-3 mb-0" id="exp-prop-info"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-matrix-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-matrix-primary" form="form-exportar-propostas">Exportar Agora</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// --- INÍCIO DO SCRIPT COMPLETO E FINAL PARA PROPOSTAS ---

document.addEventListener('DOMContentLoaded', function() {
    // --- CONFIGURAÇÃO E VARIÁVEIS GLOBAIS ---
    const pageConfig = {
        endpoint: document.getElementById('page-container').dataset.endpoint,
        singular: document.getElementById('page-container').dataset.singular,
        plural: document.getElementById('page-container').dataset.plural.toLowerCase(),
    };

    // Prioridade Incoterm (menor = melhor)
    const INCOTERM_PRIORITY = { DDP:1, DAP:2, CIF:3, FOB:4, EXW:5 };
    const PAGAMENTO_DIAS_DIRECTION = 'DESC'; // antes 'ASC' -> agora maior prazo melhor

    function incotermScore(tf){ if(!tf) return 99; tf=tf.toUpperCase().trim(); return INCOTERM_PRIORITY[tf] || 98; }
    function pagamentoScore(d){ if(d===null || d===undefined || d==='') return 99999; const n=parseInt(d,10); if(isNaN(n)) return 99999; return n; }

    function comparePropostas(a,b){
        // 1 Valor total
        const va = parseFloat(a.valor_total||0), vb = parseFloat(b.valor_total||0);
        if(va!==vb) return va - vb;
        // 2 Incoterm
        const ia = incotermScore(a.tipo_frete), ib = incotermScore(b.tipo_frete);
        if(ia!==ib) return ia - ib;
        // 3 Pagamento dias
        if(PAGAMENTO_DIAS_DIRECTION==='ASC'){
            const pa = pagamentoScore(a.pagamento_dias), pb = pagamentoScore(b.pagamento_dias);
            if(pa!==pb) return pa - pb;
        } else {
            const pa = pagamentoScore(a.pagamento_dias), pb = pagamentoScore(b.pagamento_dias);
            if(pa!==pb) return pb - pa; // invertido
        }
        // 4 Prazo entrega
        const pea = parseInt(a.prazo_entrega||0,10), peb = parseInt(b.prazo_entrega||0,10);
        if(pea!==peb) return pea - peb;
        // 5 ID (estável)
        return (parseInt(a.id,10)||0) - (parseInt(b.id,10)||0);
    }

    let todasAsCotacoes = [];
    let propostasDaCotacaoAtual = []; // cache das propostas listadas
    
    // --- ELEMENTOS DO DOM ---
    const tableBody = document.getElementById('tabela-main-body');
    const cotacaoSelect = document.getElementById('cotacao-select');
    // MAPA GLOBAL DE REQUISIÇÕES (id -> título)
    let requisicoesMap = new Map();
    // --- AJUSTADO: Autocomplete para seleção de Cotação (agora inclui título da requisição) ---
    const cotacaoAutocomplete = (()=>{
        if(!cotacaoSelect) return null;
        cotacaoSelect.classList.add('d-none');
        const wrapper = document.createElement('div');
        wrapper.className = 'position-relative';
        cotacaoSelect.parentElement.insertBefore(wrapper, cotacaoSelect);
        wrapper.appendChild(cotacaoSelect);
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control mb-1';
        input.id = 'cotacao-search';
        input.placeholder = 'Digite para buscar cotação...';
        input.autocomplete = 'off';
        input.spellcheck = false;
        wrapper.insertBefore(input, cotacaoSelect);
        const dropdown = document.createElement('div');
        dropdown.id = 'dropdown-cotacoes';
        dropdown.className = 'list-group lookup-dropdown shadow';
        dropdown.style.cssText = 'position:fixed; z-index:1056; max-height:260px; overflow:auto; display:none;';
        document.body.appendChild(dropdown);
        const state = { lista:[], filtrada:[], aberto:false, highlighted:-1 };
        const MIN_CHARS = 1;
        function escapeHtml(str){ return String(str).replace(/[&<>"']/g, s=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[s])); }
        function highlight(label, term){ if(!term) return escapeHtml(label); const esc = term.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); return escapeHtml(label).replace(new RegExp('('+esc+')','ig'), '<mark>$1</mark>'); }
        function position(){ if(!state.aberto) return; const r = input.getBoundingClientRect(); dropdown.style.width = r.width+'px'; dropdown.style.left = r.left+'px'; dropdown.style.top = (r.bottom+4)+'px'; }
        const repositionHandler = ()=> position();
        function open(){ if(!state.aberto){ state.aberto=true; dropdown.style.display='block'; window.addEventListener('scroll', repositionHandler, true); window.addEventListener('resize', repositionHandler); } position(); }
        function close(){ if(state.aberto){ state.aberto=false; dropdown.style.display='none'; state.highlighted=-1; window.removeEventListener('scroll', repositionHandler, true); window.removeEventListener('resize', repositionHandler); } }
        function render(term){ const t=(term||'').toLowerCase(); if(t.length < MIN_CHARS){ dropdown.innerHTML=''; close(); return; } state.filtrada = state.lista.filter(r=> r.search.includes(t)); if(state.filtrada.length===0){ dropdown.innerHTML = '<div class="list-group-item text-secondary small">Nenhuma cotação encontrada.</div>'; open(); return; } dropdown.innerHTML = state.filtrada.map((r,i)=>{ const active = i===state.highlighted?'active':''; return `<button type="button" class="list-group-item list-group-item-action d-flex flex-column align-items-start ${active}" data-id="${r.id}">`+
            `<span class="fw-semibold">${highlight(r.labelCotacao,t)}</span>`+
            `<small class="text-secondary">Req #${r.requisicao_id}${r.titulo_req? ' - '+highlight(r.titulo_req,t):''}</small>`+
            `</button>`; }).join(''); open(); }
        function setData(lista){ state.lista = Array.isArray(lista)? lista.map(c=>{ const tituloReq = requisicoesMap.get(c.requisicao_id) || ''; const labelCotacao = `Cotação #${c.id}`; const search = (`cotacao #${c.id} requisicao #${c.requisicao_id||''} ${tituloReq}`).toLowerCase(); return { id:c.id, requisicao_id:c.requisicao_id, titulo_req: tituloReq, labelCotacao, search }; }):[]; render(input.value.trim()); }
        function selectItem(item){ if(!item) return; cotacaoSelect.value = item.id; input.value = `${item.labelCotacao} (Req #${item.requisicao_id}${item.titulo_req? ' - '+item.titulo_req:''})`; close(); const evt = new Event('change', { bubbles:true }); cotacaoSelect.dispatchEvent(evt); }
        function navigate(dir){ if(state.filtrada.length===0) return; state.highlighted = (state.highlighted + dir + state.filtrada.length) % state.filtrada.length; render(input.value.trim()); }
        input.addEventListener('input', ()=> render(input.value.trim()));
        input.addEventListener('focus', ()=>{ if(input.value.trim().length>=MIN_CHARS){ render(input.value.trim()); } });
        input.addEventListener('keydown', e=>{ if(e.key==='ArrowDown'){ e.preventDefault(); navigate(1);} else if(e.key==='ArrowUp'){ e.preventDefault(); navigate(-1);} else if(e.key==='Enter'){ if(state.highlighted>=0){ e.preventDefault(); selectItem(state.filtrada[state.highlighted]); }} else if(e.key==='Escape'){ close(); } else if(e.key==='Backspace' && input.value===''){ cotacaoSelect.value=''; const evt=new Event('change',{bubbles:true}); cotacaoSelect.dispatchEvent(evt);} });
        dropdown.addEventListener('mousedown', e=>{ const btn=e.target.closest('button.list-group-item'); if(!btn) return; e.preventDefault(); const id=btn.dataset.id; const item=state.lista.find(r=> String(r.id)===String(id)); selectItem(item); });
        document.addEventListener('click', e=>{ if(!wrapper.contains(e.target) && !dropdown.contains(e.target)){ close(); } });
        return { setData, input };
    })();
    const modalDetalhesItens = new bootstrap.Modal(document.getElementById('modal-detalhes-itens'));
    const modalDetalhesItensLabel = document.getElementById('modalDetalhesItensLabel');
    const tabelaItensPropostaBody = document.getElementById('tabela-itens-proposta-body');
    const detalhesPropostaInfo = document.getElementById('detalhes-proposta-info');
    const detalhesPropostaObs = document.getElementById('detalhes-proposta-obs');

    // --- FUNÇÕES DE LÓGICA E RENDERIZAÇÃO ---

    function carregarDadosIniciais() {
        // Agora busca cotações e requisições para incluir título da requisição no autocomplete
        Promise.all([
            fetch('../api/cotacoes.php').then(r=>r.json()).catch(()=>[]),
            fetch('../api/requisicoes.php').then(r=>r.json()).catch(()=>[])
        ]).then(([cotacoes, requisicoes])=>{
            requisicoesMap = new Map(Array.isArray(requisicoes)? requisicoes.map(r=>[r.id, r.titulo || 'Requisição sem título']) : []);
            todasAsCotacoes = Array.isArray(cotacoes)? cotacoes : [];
            cotacaoSelect.innerHTML = '<option value="">Selecione uma cotação para ver as propostas</option>';
            todasAsCotacoes.forEach(c => {
                const tituloReq = requisicoesMap.get(c.requisicao_id) || '';
                const option = document.createElement('option');
                option.value = c.id;
                option.textContent = `Cotação #${c.id} (Requisição #${c.requisicao_id}${tituloReq? ' - '+tituloReq:''})`;
                cotacaoSelect.appendChild(option);
            });
            if(cotacaoAutocomplete){ cotacaoAutocomplete.setData(todasAsCotacoes); }
        }).catch(()=> showToast('Erro ao carregar cotações/requisições.', 'danger'));
    }

    function carregarPropostas(cotacaoId) {
        if (!cotacaoId) {
            document.getElementById('tabela-main-body').innerHTML = `<tr><td colspan="8" class="text-center text-secondary py-4">Selecione uma cotação acima.</td></tr>`;
            return;
        }
        fetch(`${pageConfig.endpoint}?cotacao_id=${cotacaoId}`)
            .then(res => res.json())
            .then(propostas => {
                // Garantir ordenação client-side (mesmo que backend já ordene)
                propostas.sort(comparePropostas);
                propostasDaCotacaoAtual = propostas;
                const tb = document.getElementById('tabela-main-body');
                tb.innerHTML = '';
                if (propostas.length === 0) {
                    tb.innerHTML = `<tr><td colspan="8" class="text-center text-secondary py-4">Nenhuma proposta recebida para esta cotação.</td></tr>`;
                    return;
                }
                propostas.forEach((item, index) => {
                    tb.innerHTML += criarLinhaTabela(item, index + 1);
                });
            })
            .catch(err => showToast(`Erro ao carregar propostas.`, 'danger'));
    }

    function criarLinhaTabela(item, ranking) {
        const itemJson = JSON.stringify(item).replace(/'/g, "&apos;").replace(/"/g, "&quot;");
        const statusMap = {
            'enviada': { class: 'secondary', text: 'Enviada', icon: 'bi-hourglass-split' },
            'aprovada': { class: 'success', text: 'Aprovada', icon: 'bi-check-circle-fill' },
            'rejeitada': { class: 'danger', text: 'Rejeitada', icon: 'bi-x-circle-fill' },
        };
        const statusInfo = statusMap[item.status] || statusMap['enviada'];
        const rankingMap = {
            1: { class: 'ranking-badge ranking-1', text: '1º', icon: 'bi-trophy-fill' },
            2: { class: 'ranking-badge ranking-2', text: '2º', icon: 'bi-award-fill' },
            3: { class: 'ranking-badge ranking-3', text: '3º', icon: 'bi-medal-fill' },
        };
        const rankingInfo = rankingMap[ranking] || { class: 'ranking-badge ranking-default', text: `${ranking}º` };
        const valorFormatado = parseFloat(item.valor_total || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        const nomeFornecedor = (item.razao_social && item.razao_social.trim()) ? item.razao_social : ((item.nome_fantasia && item.nome_fantasia.trim()) ? item.nome_fantasia : `Fornecedor #${item.fornecedor_id}`);
        const tipoFrete = item.tipo_frete || '-';
        const pagamentoDias = (item.pagamento_dias !== null && item.pagamento_dias !== undefined) ? item.pagamento_dias : '-';
        let acoesHTML = `
            <button class="btn btn-sm btn-outline-light btn-action-details" title="Ver Detalhes" data-item='${itemJson}'><i class="bi bi-eye-fill"></i></button>
            <a href="proposta_pdf.php?id=${item.id}" target="_blank" class="btn btn-sm btn-outline-info btn-action-link" title="Gerar PDF da Proposta"><i class="bi bi-filetype-pdf"></i></a>
            <button class="btn btn-sm btn-outline-secondary btn-action-link" title="Exportar Esta Proposta" onclick="window.exportarPropostaUnica(${item.id})"><i class="bi bi-box-arrow-down"></i></button>`;
        if (item.status === 'enviada') {
            acoesHTML += `
                <button class="btn btn-sm btn-outline-success btn-action-approve" title="Aprovar Proposta" onclick="window.atualizarStatusProposta(${item.id}, 'aprovada')"><i class="bi bi-check-lg"></i></button>
                <button class="btn btn-sm btn-outline-danger btn-action-reject" title="Rejeitar Proposta" onclick="window.atualizarStatusProposta(${item.id}, 'rejeitada')"><i class="bi bi-x-lg"></i></button>`;
        }
        if (item.status === 'aprovada') {
            acoesHTML += `
                <a href="pedido_pdf.php?id=${item.id}" target="_blank" class="btn btn-sm btn-outline-warning btn-action-link" title="Gerar PDF do Pedido">
                    <i class="bi bi-file-earmark-pdf-fill"></i>
                </a>`;
        }
        return `
            <tr>
                <td class="text-center">
                    <span class="badge rounded-pill fs-6 ${rankingInfo.class}">
                        ${rankingInfo.icon ? `<i class="bi ${rankingInfo.icon} me-1"></i>` : ''}${rankingInfo.text}
                    </span>
                </td>
                <td>${nomeFornecedor} ${item.imagem_url ? '<i class="bi bi-paperclip ms-1 text-info" title="Possui anexo"></i>' : ''}</td>
                <td>${valorFormatado}</td>
                <td>${item.prazo_entrega} dias</td>
                <td>${tipoFrete}</td>
                <td>${pagamentoDias}</td>
                <td><i class="bi ${statusInfo.icon} text-${statusInfo.class} me-2"></i>${statusInfo.text}</td>
                <td class="text-end">${acoesHTML}</td>
            </tr>`;
    }

    window.atualizarStatusProposta = async function(propostaId, novoStatus) {
        const acao = novoStatus === 'aprovada' ? 'aprovar' : 'rejeitar';
        const ask = async ()=>{
            if(window.confirmDialog){
                return await window.confirmDialog({
                    title: `${acao.charAt(0).toUpperCase()+acao.slice(1)} proposta`,
                    message: `Tem a certeza que deseja ${acao} esta proposta?`,
                    variant: novoStatus==='aprovada'?'primary':'danger',
                    confirmText: acao==='aprovar'?'Aprovar':'Rejeitar',
                    cancelText: 'Cancelar'
                });
            }
            return window.confirm(`Tem a certeza que deseja ${acao} esta proposta?`);
        };
        const ok = await Promise.resolve(ask());
        if(!ok) return;
        const body = new URLSearchParams({
            id: String(propostaId),
            status: novoStatus,
            _method: 'PUT'
        });
        try {
            const response = await fetch(pageConfig.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: body.toString()
            });
            const raw = await response.text();
            let data = null;
            if(raw){ try { data = JSON.parse(raw); } catch(_) { data = null; } }
            if(!response.ok || !data){
                const message = data?.erro || data?.error || (raw ? raw.replace(/<[^>]+>/g,'').slice(0,180) : '');
                throw new Error(message || `Erro ao atualizar proposta (HTTP ${response.status})`);
            }
            if (data.success) {
                showToast(`Proposta ${novoStatus === 'aprovada' ? 'aprovada' : 'rejeitada'} com sucesso!`, 'success');
                carregarPropostas(cotacaoSelect.value);
            } else {
                throw new Error(data.erro || 'Erro ao atualizar proposta.');
            }
        } catch (err) {
            showToast(err.message || 'Erro ao atualizar proposta.', 'danger');
        }
    }
    
    // FUNÇÃO NOVA: gera HTML amigável do anexo
    function buildAttachmentHtml(url, originalName) {
        if (!url) {
            return `
            <div class="anexo-card no-attachment">
                <div class="anexo-card-preview">
                    <i class="bi bi-paperclip anexo-card-icon"></i>
                </div>
                <div class="anexo-card-info">
                    <div class="anexo-card-filename">Nenhum anexo</div>
                    <div class="anexo-card-meta">Esta proposta não possui ficheiros anexados</div>
                </div>
            </div>`;
        }
        // Deriva nome real; prioriza originalName vindo da API
        let nome = originalName || '';
        if(!nome){
            try {
                const clean = url.split('?')[0];
                nome = decodeURIComponent(clean.split('/').pop());
            } catch(e){ nome = 'anexo'; }
        }
        const ext = (nome.split('.').pop() || '').toUpperCase();
        const isImage = /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(nome);
        if (isImage) {
            return `
            <div class="anexo-card">
                <div class="anexo-card-header">
                    <div class="anexo-card-type">
                        <i class="bi bi-image"></i>
                        <span>Imagem</span>
                    </div>
                    <div class="anexo-card-actions">
                        <a href="${url}" target="_blank" class="anexo-card-btn" title="Abrir em nova aba">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                        <a href="${url}" download="${nome}" class="anexo-card-btn" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                </div>
                <div class="anexo-card-preview">
                    <img src="${url}" alt="Pré-visualização">
                </div>
                <div class="anexo-card-info">
                    <div class="anexo-card-filename">${nome}</div>
                    <div class="anexo-card-meta">Clique para visualizar ou baixar</div>
                    <span class="anexo-card-size">${ext}</span>
                </div>
            </div>`;
        }
        
        // Para outros tipos de ficheiro
        const iconMap = {
            'PDF': 'bi-file-earmark-pdf',
            'DOC': 'bi-file-earmark-word', 'DOCX': 'bi-file-earmark-word',
            'XLS': 'bi-file-earmark-excel', 'XLSX': 'bi-file-earmark-excel',
            'PPT': 'bi-file-earmark-ppt', 'PPTX': 'bi-file-earmark-ppt',
            'TXT': 'bi-file-earmark-text',
            'ZIP': 'bi-file-earmark-zip', 'RAR': 'bi-file-earmark-zip',
        };
        const fileIcon = iconMap[ext] || 'bi-file-earmark';
        
        return `
        <div class="anexo-card">
            <div class="anexo-card-header">
                <div class="anexo-card-type">
                    <i class="bi ${fileIcon}"></i>
                    <span>Documento</span>
                </div>
                <div class="anexo-card-actions">
                    <a href="${url}" target="_blank" class="anexo-card-btn" title="Abrir em nova aba">
                        <i class="bi bi-box-arrow-up-right"></i>
                    </a>
                    <a href="${url}" download="${nome}" class="anexo-card-btn" title="Download">
                        <i class="bi bi-download"></i>
                    </a>
                </div>
            </div>
            <div class="anexo-card-preview">
                <i class="bi ${fileIcon} anexo-card-icon"></i>
            </div>
            <div class="anexo-card-info">
                <div class="anexo-card-filename">${nome}</div>
                <div class="anexo-card-meta">Clique para abrir ou baixar</div>
                <span class="anexo-card-size">${ext}</span>
            </div>
        </div>`;
    }

    // Novo helper: carrega anexo privado (proposta) e atualiza placeholder
    async function carregarAnexoProposta(propostaId){
        const dd = document.querySelector('#modal-detalhes-itens .dd-anexo');
        if(!dd) return;
        dd.innerHTML = `<div class="anexo-card no-attachment"><div class="anexo-card-preview"><div class="spinner-border spinner-border-sm text-secondary"></div></div><div class="anexo-card-info"><div class="anexo-card-filename">A carregar anexo...</div><div class="anexo-card-meta">Aguarde um momento</div></div></div>`;
        try {
            const r = await fetch(`../api/anexos_list.php?tipo_ref=proposta&ref_id=${propostaId}`);
            const j = await r.json();
            if(!j.success || !Array.isArray(j.anexos) || j.anexos.length===0){
                dd.innerHTML = buildAttachmentHtml(null);
                return;
            }
            const a = j.anexos.sort((x,y)=> y.id - x.id)[0];
            const downloadUrl = `../api/anexos_download.php?id=${a.id}`;
            dd.innerHTML = buildAttachmentHtml(downloadUrl, a.nome_original || 'anexo');
        } catch(e){
            dd.innerHTML = buildAttachmentHtml(null);
        }
    }

    // --- EVENT LISTENERS ---

    cotacaoSelect.addEventListener('change', function() {
        carregarPropostas(this.value);
    });

    tableBody.addEventListener('click', function(event) {
        const target = event.target.closest('button.btn-action-details');
        if (!target) return;
        
        const itemData = JSON.parse(target.dataset.item.replace(/&quot;/g, '"'));
        
        modalDetalhesItensLabel.textContent = `Detalhes da Proposta #${itemData.id}`;
        detalhesPropostaInfo.innerHTML = `
            <dl class="row">
                <dt class="col-sm-4">Fornecedor ID:</dt><dd class="col-sm-8">${itemData.fornecedor_id}</dd>
                <dt class="col-sm-4">Valor Total:</dt><dd class="col-sm-8">${parseFloat(itemData.valor_total).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</dd>
                <dt class="col-sm-4">Prazo:</dt><dd class="col-sm-8">${itemData.prazo_entrega} dias</dd>
                <dt class="col-sm-4">Pagamento:</dt><dd class="col-sm-8">${(itemData.pagamento_dias !== null && itemData.pagamento_dias !== undefined) ? (itemData.pagamento_dias + ' dias') : '-'}</dd>
                <dt class="col-12 dt-anexo">Anexo:</dt>
                <dd class="col-12 dd-anexo">${buildAttachmentHtml(null)}</dd>
            </dl>`;
        // Substitui placeholder com anexo real (fetch separado)
        carregarAnexoProposta(itemData.id);
        detalhesPropostaObs.innerHTML = `<p class="text-secondary mb-1">Observações:</p><p class="border rounded p-2" style="background-color: var(--matrix-surface);">${itemData.observacoes || 'Nenhuma.'}</p>`;
        tabelaItensPropostaBody.innerHTML = `<tr><td colspan="4" class="text-center">A carregar itens...</td></tr>`;

        fetch(`../api/proposta_itens.php?proposta_id=${itemData.id}`)
            .then(res => res.json())
            .then(itens => {
                tabelaItensPropostaBody.innerHTML = '';
                if(itens.length === 0) {
                    tabelaItensPropostaBody.innerHTML = '<tr><td colspan="4" class="text-center text-secondary">Não foram detalhados itens para esta proposta.</td></tr>';
                    return;
                }
                itens.forEach(item => {
                    const preco = parseFloat(item.preco_unitario);
                    const qtd = parseFloat(item.quantidade);
                    const subtotal = preco * qtd;
                    tabelaItensPropostaBody.innerHTML += `
                        <tr>
                            <td>${item.nome}</td>
                            <td class="text-center">${qtd} ${item.unidade}</td>
                            <td class="text-end">${preco.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</td>
                            <td class="text-end">${subtotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}</td>
                        </tr>`;
                });
            });

        modalDetalhesItens.show();
    });

    const modalExportarPropostas = document.getElementById('modal-exportar-propostas');
    const formExportarPropostas = document.getElementById('form-exportar-propostas');
    const expPropInfo = document.getElementById('exp-prop-info');

    modalExportarPropostas.addEventListener('show.bs.modal', () => {
        expPropInfo.textContent = propostasDaCotacaoAtual.length
            ? `${propostasDaCotacaoAtual.length} proposta(s) carregada(s) para a cotação atual.`
            : 'Nenhuma proposta carregada ainda.';
    });

    formExportarPropostas.addEventListener('submit', function(e){
        e.preventDefault();
        const formato = this.formato.value;
        const intervalo = this.intervalo.value;
        if (intervalo === 'cotacao_atual') {
            const ids = propostasDaCotacaoAtual.map(p => p.id);
            if (!ids.length) {
                showToast('Nenhuma proposta para exportar.', 'warning');
                return;
            }
            gerarPostExportacao({formato, ids, intervalo: 'filtrados'});
        } else {
            // todas: não enviar ids -> exportador pega tudo
            gerarPostExportacao({formato, ids: [], intervalo: 'todos'});
        }
        bootstrap.Modal.getInstance(modalExportarPropostas).hide();
    });

    window.exportarPropostaUnica = function(id) {
        gerarPostExportacao({
            formato: obterFormatoPreferido(),
            ids: [id],
            intervalo: 'filtrados' // força uso dos IDs fornecidos
        });
    };

    function obterFormatoPreferido() {
        // tenta ler ultimo selecionado no modal, senão csv
        const sel = document.querySelector('#form-exportar-propostas input[name="formato"]:checked');
        return sel ? sel.value : 'csv';
    }

    function gerarPostExportacao({formato, ids = [], intervalo}) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'exportador.php';
        form.target = '_blank';
        form.innerHTML = `
            <input type="hidden" name="modulo" value="propostas">
            <input type="hidden" name="formato" value="${formato}">
            <input type="hidden" name="intervalo" value="${intervalo}">
            <input type="hidden" name="ids" value="${ids.join(',')}">
        `;
        document.body.appendChild(form);
        form.submit();
        form.remove();
        showToast('A gerar ficheiro ' + formato.toUpperCase() + '...', 'success');
    }

    // Carga inicial
    carregarDadosIniciais();
    carregarPropostas(''); // Inicia com a tabela vazia
});

function showToast(message, type = 'info') {
    const toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) return;
    const toastId = 'toast-' + Date.now();
    const toastHtml = `<div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button></div></div>`;
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    const bsToast = new bootstrap.Toast(toastEl, { delay: 3000 });
    bsToast.show();
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
}
</script><?php
// ...existing code...
?>