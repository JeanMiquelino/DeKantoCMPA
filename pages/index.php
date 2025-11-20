<?php
session_start();
require_once __DIR__ . '/../includes/branding.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ./login.php');
    exit;
}
$__u = auth_usuario();
if ($__u) {
    $tipo = $__u['tipo'] ?? null;
    if ($tipo === 'cliente') { header('Location: cliente/index.php'); exit; }
    if ($tipo === 'fornecedor') { header('Location: fornecedor/index.php'); exit; }
}

$logo = $branding['logo_url'] ?? null;
$app_name = $branding['app_name'] ?? 'Dekanto';
$db = get_db_connection();

// --- KPIs Principais ---
$kpis = [
    'fornecedores' => (int)$db->query('SELECT COUNT(*) FROM fornecedores')->fetchColumn(),
    'clientes' => (int)$db->query('SELECT COUNT(*) FROM clientes')->fetchColumn(),
    'produtos' => (int)$db->query('SELECT COUNT(*) FROM produtos')->fetchColumn(),
    'requisicoes_abertas' => (int)$db->query("SELECT COUNT(*) FROM requisicoes WHERE status='aberta'")->fetchColumn(),
    'pedidos_emitidos' => (int)$db->query("SELECT COUNT(*) FROM pedidos WHERE status='emitido'")->fetchColumn(),
    'valor_pedidos_mes' => (float)($db->query("SELECT SUM(pr.valor_total) FROM pedidos p JOIN propostas pr ON p.proposta_id=pr.id WHERE MONTH(p.criado_em)=MONTH(NOW()) AND YEAR(p.criado_em)=YEAR(NOW())")->fetchColumn() ?: 0),
];
// KPIs adicionais
$kpis['valor_pedidos_mes_anterior'] = (float)($db->query("SELECT SUM(pr.valor_total) FROM pedidos p JOIN propostas pr ON p.proposta_id=pr.id WHERE p.criado_em >= DATE_SUB(DATE_FORMAT(NOW(), '%Y-%m-01'), INTERVAL 1 MONTH) AND p.criado_em < DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn() ?: 0);
$kpis['pedidos_mes_qtd'] = (int)$db->query("SELECT COUNT(*) FROM pedidos p WHERE MONTH(p.criado_em)=MONTH(NOW()) AND YEAR(p.criado_em)=YEAR(NOW())")->fetchColumn();
$kpis['ticket_medio_mes'] = $kpis['pedidos_mes_qtd'] ? ($kpis['valor_pedidos_mes'] / $kpis['pedidos_mes_qtd']) : 0;
$kpis['variacao_pedidos_mes'] = $kpis['valor_pedidos_mes_anterior'] > 0 ? (($kpis['valor_pedidos_mes'] - $kpis['valor_pedidos_mes_anterior']) / $kpis['valor_pedidos_mes_anterior']) * 100 : 0;

// --- Distribuição de status de pedidos ---
$pedidoStatusStmt = $db->query("SELECT status, COUNT(*) qtd FROM pedidos GROUP BY status");
$pedidoStatus = [];
while ($row = $pedidoStatusStmt->fetch(PDO::FETCH_ASSOC)) {
    $pedidoStatus[$row['status']] = (int)$row['qtd'];
}

// --- Distribuição de status de requisicoes ---
$requisicaoStatusStmt = $db->query("SELECT status, COUNT(*) qtd FROM requisicoes GROUP BY status");
$requisicaoStatus = [];
while ($row = $requisicaoStatusStmt->fetch(PDO::FETCH_ASSOC)) {
    $requisicaoStatus[$row['status']] = (int)$row['qtd'];
}

// --- Últimos Pedidos (limit 6) ---
$ultimosPedidos = $db->query("SELECT p.id, pr.fornecedor_id, f.nome_fantasia, f.razao_social, pr.valor_total, p.status, p.criado_em FROM pedidos p JOIN propostas pr ON p.proposta_id = pr.id LEFT JOIN fornecedores f ON pr.fornecedor_id = f.id ORDER BY p.id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

// --- Timeline mista (requisicoes + pedidos) últimos 8 eventos ---
$timeline = $db->query("(
    SELECT id, criado_em, 'Requisição' tipo, status, NULL valor FROM requisicoes ORDER BY criado_em DESC LIMIT 8
) UNION ALL (
    SELECT p.id, p.criado_em, 'Pedido' tipo, p.status, pr.valor_total valor FROM pedidos p JOIN propostas pr ON p.proposta_id=pr.id ORDER BY p.criado_em DESC LIMIT 8
) ORDER BY criado_em DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// --- Top Fornecedores (por valor de pedidos) ---
$topFornecedores = $db->query("SELECT f.id, COALESCE(f.nome_fantasia, f.razao_social) nome, COUNT(p.id) pedidos, SUM(pr.valor_total) total
    FROM pedidos p
    JOIN propostas pr ON p.proposta_id=pr.id
    LEFT JOIN fornecedores f ON pr.fornecedor_id=f.id
    GROUP BY f.id
    ORDER BY total DESC
    LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// --- Total pedidos últimos 6 meses (para gráfico) ---
$mesesRaw = $db->query("SELECT DATE_FORMAT(p.criado_em,'%Y-%m') ym, DATE_FORMAT(p.criado_em,'%m/%Y') label, SUM(pr.valor_total) total
    FROM pedidos p
    JOIN propostas pr ON p.proposta_id=pr.id
    GROUP BY ym,label
    ORDER BY ym DESC
    LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$meses = array_reverse($mesesRaw); // ordem cronológica
$chartLabels = array_map(fn($r) => $r['label'], $meses);
$chartTotals = array_map(fn($r) => (float)$r['total'], $meses);

// Preparar dados JSON para JS
$jsPedidosStatus = json_encode($pedidoStatus, JSON_UNESCAPED_UNICODE);
$jsRequisicaoStatus = json_encode($requisicaoStatus, JSON_UNESCAPED_UNICODE);
$jsChartLabels = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$jsChartTotals = json_encode($chartTotals, JSON_UNESCAPED_UNICODE);
$jsTopFornecedores = json_encode($topFornecedores, JSON_UNESCAPED_UNICODE);
$jsTimeline = json_encode($timeline, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="pt-br" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo htmlspecialchars($app_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/nexus.css">
    <style>
        .kpi-grid {display:grid; gap:1.1rem; grid-template-columns:repeat(auto-fill,minmax(160px,1fr));}
        .kpi-card {position:relative; overflow:hidden; min-height:155px; display:flex; flex-direction:column; justify-content:space-between;}
        .kpi-card .d-flex.justify-content-between.align-items-start{flex-grow:1;}
        .kpi-icon-bg {position:absolute; right:-18px; top:-18px; font-size:5.2rem; opacity:.06;}
        .kpi-trend {font-size:.7rem; letter-spacing:.5px; margin-top:.35rem;}
        .chart-wrapper {position:relative; height:240px;}
        .mini-chart {height:180px;}
        .timeline {list-style:none; margin:0; padding:0;}
        .timeline-item {display:flex; gap:.9rem; position:relative; padding:.65rem .2rem .65rem .2rem;}
        .timeline-item:not(:last-child)::after {content:""; position:absolute; left:14px; top:38px; width:2px; height:calc(100% - 38px); background:var(--matrix-border,#333);} 
        /* .timeline-bullet styles now come from nexus.css (tertiary brand) */
        .progress-bar {transition:width .6s ease;}
        .quick-actions a {text-decoration:none;}
        .quick-action {display:flex; flex-direction:column; align-items:center; justify-content:center; padding:1rem .75rem; border:1px solid var(--matrix-border); border-radius:.8rem; background:rgba(255,255,255,0.02); transition:.25s; position:relative; overflow:hidden;}
        .quick-action:hover {background:rgba(255,255,255,0.07); transform:translateY(-3px);} 
        .quick-action i {font-size:1.7rem; margin-bottom:.35rem;}
        .quick-action span {font-size:.8rem; font-weight:500; letter-spacing:.5px; text-transform:uppercase;}
        .table-mini td, .table-mini th {padding:.45rem .65rem; font-size:.75rem;}
        /* .top-supplier-rank now inherits light theme from nexus.css */
        .card-section-title {font-size:.85rem; letter-spacing:1px; text-transform:uppercase; font-weight:600; opacity:.8;}
        .value-positive {color:#4ade80;} .value-negative {color:#f87171;}
        .legend-dot {display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:4px;}
        .status-distribution {display:grid; gap:.5rem; grid-template-columns:repeat(auto-fill,minmax(140px,1fr));}
        @media (max-width: 992px){ .chart-wrapper {height:200px;} }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container py-4" id="page-container">
    <header class="page-header-gestao d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="page-title mb-0">Dashboard</h1>
            <p class="text-secondary mb-0">Visão geral inteligente do ecossistema de Compras & Suprimentos.</p>
        </div>
        <div class="d-none d-md-flex align-items-center gap-3">
            <div class="text-end small">
                <div class="text-secondary">Usuário</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($_SESSION['usuario_nome'] ?? 'Usuário'); ?></div>
            </div>
            <?php if ($logo): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="Logo" style="height:48px;" class="rounded shadow-sm">
            <?php endif; ?>
        </div>
    </header>

    <!-- KPIs -->
    <div class="kpi-grid mb-4">
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-currency-dollar"></i></div>
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="text-secondary small">Valor Pedidos (Mês)</div>
                    <div class="fs-4 fw-bold kpi-value" data-format="currency" data-value="<?php echo $kpis['valor_pedidos_mes']; ?>">R$ 0,00</div>
                    <div class="kpi-trend text-success"><i class="bi bi-graph-up-arrow me-1"></i><span id="kpi-trend-pedidos"></span></div>
                </div>
                <!-- Removido o badge do header flex para evitar deslocamento -->
            </div>
            <!-- Badge de data posicionado no canto inferior direito do card -->
            <span class="badge text-bg-primary-subtle text-primary-emphasis badge-bottom-right"><?php echo date('m/Y'); ?></span>
        </div>
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-people"></i></div>
            <div class="text-secondary small">Clientes</div>
            <div class="display-6 kpi-value" data-value="<?php echo $kpis['clientes']; ?>">0</div>
            <div class="kpi-trend text-info"><i class="bi bi-person-plus me-1"></i>Ativos</div>
        </div>
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-truck"></i></div>
            <div class="text-secondary small">Fornecedores</div>
            <div class="display-6 kpi-value" data-value="<?php echo $kpis['fornecedores']; ?>">0</div>
            <div class="kpi-trend text-warning"><i class="bi bi-arrow-repeat me-1"></i>Cadastrados</div>
        </div>
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-box-seam"></i></div>
            <div class="text-secondary small">Produtos</div>
            <div class="display-6 kpi-value" data-value="<?php echo $kpis['produtos']; ?>">0</div>
            <div class="kpi-trend text-secondary"><i class="bi bi-collection me-1"></i>Catálogo</div>
        </div>
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-cart-plus"></i></div>
            <div class="text-secondary small">Req. Abertas</div>
            <div class="display-6 kpi-value" data-value="<?php echo $kpis['requisicoes_abertas']; ?>">0</div>
            <div class="kpi-trend text-danger"><i class="bi bi-hourglass-split me-1"></i>Pendentes</div>
        </div>
        <div class="card-matrix kpi-card p-3">
            <div class="kpi-icon-bg"><i class="bi bi-file-earmark-text"></i></div>
            <div class="text-secondary small">Pedidos Emitidos</div>
            <div class="display-6 kpi-value" data-value="<?php echo $kpis['pedidos_emitidos']; ?>">0</div>
            <div class="kpi-trend text-success"><i class="bi bi-check2-circle me-1"></i>Totais</div>
        </div>
    </div>

    <!-- Linha principal de conteúdo -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-lg-8 d-flex flex-column gap-4">
            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-line me-2"></i>Evolução de Valor (Últimos 6 Meses)</span>
                    <div class="small text-secondary">Pedidos Emitidos</div>
                </div>
                <div class="p-3 pt-4">
                    <div class="chart-wrapper">
                        <canvas id="chartPedidosMes"></canvas>
                    </div>
                </div>
            </div>

            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-activity me-2"></i>Timeline de Atividades</span>
                    <div class="small text-secondary">Eventos recentes</div>
                </div>
                <div class="p-3 pt-2">
                    <ul class="timeline" id="timeline-list"></ul>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4 d-flex flex-column gap-4">
            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-lightning-charge me-2"></i>Ações Rápidas</span>
                </div>
                <div class="p-3">
                    <div class="quick-actions d-grid" style="grid-template-columns:repeat(auto-fill,minmax(110px,1fr)); gap:.85rem;">
                        <a href="requisicoes.php" class="quick-action text-primary"><i class="bi bi-cart-plus"></i><span>Requisições</span></a>
                        <a href="cotacoes.php" class="quick-action text-warning"><i class="bi bi-cash-coin"></i><span>Cotações</span></a>
                        <a href="propostas.php" class="quick-action text-info"><i class="bi bi-bar-chart-line"></i><span>Propostas</span></a>
                        <a href="pedidos.php" class="quick-action text-success"><i class="bi bi-file-earmark-text"></i><span>Pedidos</span></a>
                        <a href="fornecedores.php" class="quick-action text-danger"><i class="bi bi-truck"></i><span>Fornec.</span></a>
                        <a href="clientes.php" class="quick-action text-secondary"><i class="bi bi-people"></i><span>Clientes</span></a>
                        <a href="produtos.php" class="quick-action text-info"><i class="bi bi-box-seam"></i><span>Produtos</span></a>
                        <a href="configuracoes.php" class="quick-action text-warning"><i class="bi bi-gear"></i><span>Config</span></a>
                    </div>
                </div>
            </div>

            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-diagram-3 me-2"></i>Status dos Pedidos</span>
                </div>
                <div class="p-3">
                    <div id="status-pedidos" class="status-distribution"></div>
                </div>
            </div>

            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-segmented-nav me-2"></i>Status Requisições</span>
                </div>
                <div class="p-3">
                    <div id="status-requisicoes" class="status-distribution"></div>
                </div>
            </div>

            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-trophy me-2"></i>Top Fornecedores</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-mini mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fornecedor</th>
                                <th class="text-end">Pedidos</th>
                                <th class="text-end">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$topFornecedores): ?>
                            <tr><td colspan="4" class="text-center text-secondary">Sem dados.</td></tr>
                        <?php else: foreach ($topFornecedores as $i => $f): ?>
                            <tr>
                                <td><div class="top-supplier-rank"><?php echo $i+1; ?></div></td>
                                <td><?php echo htmlspecialchars($f['nome']); ?></td>
                                <td class="text-end"><span class="badge text-bg-secondary"><?php echo (int)$f['pedidos']; ?></span></td>
                                <td class="text-end">R$ <?php echo number_format($f['total'],2,',','.'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- KPIs Operacionais (auto-refresh) -->
            <div class="card-matrix">
                <div class="card-header-matrix d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-speedometer2 me-2"></i>KPIs Operacionais</span>
                    <small class="text-secondary">Atualiza a cada 60s</small>
                </div>
                <div class="p-3">
                    <div class="status-distribution">
                        <div class="status-pill">
                            <small>Req → 1º Convite (médio)</small>
                            <strong><span id="kpi-req-convite">—</span> h</strong>
                        </div>
                        <div class="status-pill">
                            <small>Taxa resposta fornecedores</small>
                            <strong><span id="kpi-taxa-resp">—</span>%</strong>
                        </div>
                        <div class="status-pill">
                            <small>Delta aceite cliente (médio)</small>
                            <strong><span id="kpi-aceite">—</span> h</strong>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Últimos Pedidos Detalhados -->
    <div class="card-matrix table-container-gestao mb-5">
        <div class="card-header-matrix d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i>Últimos Pedidos Gerados</span>
            <a href="pedidos.php" class="btn btn-sm btn-matrix-secondary-outline">Ver Todos <i class="bi bi-arrow-right"></i></a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Pedido ID</th>
                        <th>Fornecedor</th>
                        <th>Data</th>
                        <th>Valor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ultimosPedidos)): ?>
                        <tr><td colspan="5" class="text-center text-secondary py-4">Nenhum pedido recente.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ultimosPedidos as $p):
                            $statusMap = [
                                'emitido' => ['class' => 'info', 'text' => 'Emitido'],
                                'pendente' => ['class' => 'primary', 'text' => 'Pendente'],
                                'em_producao' => ['class' => 'warning', 'text' => 'Em Produção'],
                                'enviado' => ['class' => 'info', 'text' => 'Enviado'],
                                'entregue' => ['class' => 'success', 'text' => 'Entregue'],
                                'cancelado' => ['class' => 'danger', 'text' => 'Cancelado']
                            ];
                            $statusInfo = $statusMap[$p['status']] ?? ['class' => 'secondary', 'text' => $p['status']];
                        ?>
                        <tr>
                            <td><span class="font-monospace">#<?php echo htmlspecialchars($p['id']); ?></span></td>
                            <td><?php echo htmlspecialchars($p['nome_fantasia'] ?: $p['razao_social']); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($p['criado_em'])); ?></td>
                            <td><?php echo 'R$ ' . number_format($p['valor_total'], 2, ',', '.'); ?></td>
                            <td><span class="badge text-bg-<?php echo $statusInfo['class']; ?>"><?php echo $statusInfo['text']; ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Produtos Rápido -->
<div class="modal fade" id="modalProdutos" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark border-secondary">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Catálogo de Produtos</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-dark-subtle">
              <tr>
                <th style="width:70px;">ID</th>
                <th>Nome</th>
                <th style="width:120px;">NCM</th>
                <th style="width:90px;">Unid.</th>
                <th style="width:140px;" class="text-end">Preço Base</th>
              </tr>
            </thead>
            <tbody id="tabelaProdutosModalBody">
              <tr><td colspan="5" class="text-center text-secondary py-4">Carregando...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <small class="text-secondary">Fonte: /api/produtos.php</small>
        <button class="btn btn-sm btn-matrix-secondary-outline" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const pedidosStatusData = <?php echo $jsPedidosStatus; ?>;
const requisicaoStatusData = <?php echo $jsRequisicaoStatus; ?>;
const chartLabels = <?php echo $jsChartLabels; ?>;
const chartTotals = <?php echo $jsChartTotals; ?>;
const topFornecedoresData = <?php echo $jsTopFornecedores; ?>;
const timelineData = <?php echo $jsTimeline; ?>;

// Padronização de rótulos legíveis
const STATUS_LABELS_PEDIDOS = {
    emitido: 'Emitido',
    pendente: 'Pendente',
    em_producao: 'Em produção',
    enviado: 'Enviado',
    entregue: 'Entregue',
    cancelado: 'Cancelado'
};
const STATUS_LABELS_REQUISICOES = {
    pendente_aprovacao: 'Pendente de aprovação',
    em_analise: 'Em análise',
    aberta: 'Aberta',
    fechada: 'Fechada',
    aprovada: 'Aprovada',
    rejeitada: 'Rejeitada'
};
function humanizeStatus(v){ if(!v) return '-'; return (v+'').replace(/_/g,' ').replace(/\b\w/g, c=> c.toUpperCase()); }
function statusLabelPedido(v){ v=(v||'').toLowerCase(); return STATUS_LABELS_PEDIDOS[v] || humanizeStatus(v); }
function statusLabelRequisicao(v){ v=(v||'').toLowerCase(); return STATUS_LABELS_REQUISICOES[v] || humanizeStatus(v); }

// --- KPI Animation ---
function animateValue(element, start, end, duration, formatCurrency = false) {
    let startTimestamp = null;
    const step = (timestamp) => {
        if (!startTimestamp) startTimestamp = timestamp;
        const progress = Math.min((timestamp - startTimestamp) / duration, 1);
        const currentValue = start + (end - start) * progress;
        element.textContent = formatCurrency
            ? currentValue.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})
            : Math.floor(currentValue).toLocaleString('pt-BR');
        if (progress < 1) window.requestAnimationFrame(step);
    }
    window.requestAnimationFrame(step);
}

function loadKpisOperacionais(){
  fetch('../api/kpis_basico.php', {cache:'no-store'})
    .then(r=> r.ok ? r.json() : Promise.reject())
    .then(j=>{
      const k = (j && j.kpis) ? j.kpis : {};
      const f = (n)=> (n==null || isNaN(n)) ? '—' : Number(n).toFixed(1);
      const f2= (n)=> (n==null || isNaN(n)) ? '—' : Number(n).toFixed(0);
      const el1=document.getElementById('kpi-req-convite'); if(el1) el1.textContent = f(k.tempo_requisicao_para_convites_horas);
      const el2=document.getElementById('kpi-taxa-resp'); if(el2) el2.textContent = f2(k.taxa_resposta_fornecedores_pct);
      const el3=document.getElementById('kpi-aceite'); if(el3) el3.textContent = f(k.delta_aceite_cliente_horas);
    })
    .catch(()=>{
      // mantém valores atuais
    });
}

let KPIS_TIMER=null;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.kpi-value').forEach(el => {
        const value = parseFloat(el.dataset.value) || 0;
        const isCurrency = el.dataset.format === 'currency' || el.textContent.includes('R$');
        animateValue(el, 0, value, 1300, isCurrency);
    });

    // Status Pills
    function renderStatus(containerId, dataObj, labeler) {
        const container = document.getElementById(containerId);
        if (!container) return;
        const total = Object.values(dataObj).reduce((a,b)=>a+b,0) || 1;
        container.innerHTML = Object.entries(dataObj).map(([status,qtd]) => {
            const pct = ((qtd/total)*100).toFixed(1);
            const label = typeof labeler==='function' ? labeler(status) : humanizeStatus(status);
            return `<div class="status-pill"><small>${label}</small><strong>${qtd}</strong><div class="progress mt-1" style="height:4px;"><div class="progress-bar" style="width:${pct}%"></div></div><span class="text-secondary small">${pct}%</span></div>`;
        }).join('') || '<div class="text-secondary small">Sem dados.</div>';
    }
    renderStatus('status-pedidos', pedidosStatusData, statusLabelPedido);
    renderStatus('status-requisicoes', requisicaoStatusData, statusLabelRequisicao);

    // Timeline
    const timelineList = document.getElementById('timeline-list');
    if (timelineList) {
        if (!timelineData.length) {
            timelineList.innerHTML = '<li class="text-secondary small">Sem eventos recentes.</li>';
        } else {
            timelineList.innerHTML = timelineData.map(ev => {
                const date = new Date(ev.criado_em);
                const dataFmt = date.toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'})+' '+date.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
                const icon = ev.tipo === 'Pedido' ? 'bi-file-earmark-text' : 'bi-cart-plus';
                let statusBadgeClass = 'secondary';
                if (/aprov/i.test(ev.status)) statusBadgeClass = 'success';
                else if (/emit/i.test(ev.status)) statusBadgeClass = 'info';
                else if (/cancel/i.test(ev.status)) statusBadgeClass = 'danger';
                else if (/abert/i.test(ev.status)) statusBadgeClass = 'warning';
                const statusLegivel = ev.tipo === 'Pedido' ? statusLabelPedido(ev.status) : statusLabelRequisicao(ev.status);
                return `<li class="timeline-item">
                    <div class="timeline-bullet"><i class="bi ${icon}"></i></div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between flex-wrap gap-2">
                            <div><strong>${ev.tipo} #${ev.id}</strong> <span class="badge rounded-pill text-bg-${statusBadgeClass}">${statusLegivel}</span></div>
                            <div class="text-secondary small">${dataFmt}</div>
                        </div>
                        ${ev.valor ? `<div class="small text-success mt-1">Valor: ${parseFloat(ev.valor).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}</div>`:''}
                    </div>
                </li>`;
            }).join('');
        }
    }

    // Chart.js - Pedidos por mês
    const ctx = document.getElementById('chartPedidosMes');
    if (ctx) {
        // Use brand tertiary color from CSS vars instead of hardcoded purple
        const css = getComputedStyle(document.documentElement);
        const tertiary = (css.getPropertyValue('--matrix-tertiary') || '#4DABB5').trim();
        function hexToRgb(hex){
          const h = hex.replace('#','');
          const b = h.length===3 ? h.split('').map(c=>c+c).join('') : h;
          const n = parseInt(b,16); return { r:(n>>16)&255, g:(n>>8)&255, b:n&255 };
        }
        const t = /^#/.test(tertiary) ? hexToRgb(tertiary) : { r:77, g:171, b:181 };
        const tFill = `rgba(${t.r},${t.g},${t.b},0.35)`;
        const tBorder = `rgba(${t.r},${t.g},${t.b},0.9)`;
        const gridColor = 'rgba(0,0,0,0.08)';

        new Chart(ctx, {
            type:'bar',
            data:{
                labels: chartLabels,
                datasets:[{
                    label:'Valor Total',
                    data: chartTotals,
                    borderRadius:6,
                    backgroundColor: chartTotals.map(()=> tFill),
                    borderColor: tBorder,
                    borderWidth:1.2,
                }]
            },
            options:{
                responsive:true,
                maintainAspectRatio:false,
                plugins:{
                    legend:{display:false},
                    tooltip:{callbacks:{label:(ctx)=> ctx.parsed.y.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}}
                },
                scales:{
                    y:{
                        ticks:{callback:(v)=> v.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})},
                        grid:{color: gridColor}
                    },
                    x:{grid:{display:false}}
                }
            }
        });
    }

    // KPIs operacionais (auto-refresh)
    loadKpisOperacionais();
    KPIS_TIMER = setInterval(loadKpisOperacionais, 60000);
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

function carregarProdutosModal(){
  const tbody = document.getElementById('tabelaProdutosModalBody');
  if(!tbody) return;
  tbody.innerHTML = '<tr><td colspan="5" class="text-center text-secondary py-4">Carregando...</td></tr>';
  fetch('../api/produtos.php')
    .then(r=> r.ok ? r.json() : Promise.reject())
    .then(lista => {
        if(!lista.length){
           tbody.innerHTML = '<tr><td colspan="5" class="text-center text-secondary py-4">Nenhum produto cadastrado.</td></tr>';
           return;
        }
        tbody.innerHTML = lista.map(p => `
          <tr>
            <td class="font-monospace">#${p.id}</td>
            <td>${p.nome ? escapeHtml(p.nome) : ''}</td>
            <td>${p.ncm || ''}</td>
            <td>${p.unidade || ''}</td>
            <td class="text-end">${Number(p.preco_base||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'})}</td>
          </tr>`).join('');
    })
    .catch(()=>{
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Erro ao carregar.</td></tr>';
    });
}

function escapeHtml(str){return str.replace(/[&<>"']/g, m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));}

// Adiciona botão flutuante para abrir modal (caso queira remover, comentar este bloco)
(function(){
  if(document.getElementById('btnProdutosModalFloat')) return;
  const btn = document.createElement('button');
  btn.id='btnProdutosModalFloat';
  btn.className='btn btn-matrix-primary position-fixed';
  btn.style.cssText='bottom:90px; right:18px; z-index:1030; box-shadow:0 4px 18px rgba(0,0,0,.4);';
  btn.innerHTML='<i class="bi bi-box-seam"></i>';
  btn.title='Catálogo de Produtos';
  btn.addEventListener('click', ()=>{ const m = new bootstrap.Modal('#modalProdutos'); m.show(); carregarProdutosModal(); });
  document.body.appendChild(btn);
})();
</script>
</body>
</html>