<?php
session_start();
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/branding.php';
require_once __DIR__.'/../../includes/auth.php';
$u = auth_usuario();
if(!$u){ header('Location: ../login.php'); exit; }
if(($u['tipo'] ?? null) !== 'cliente'){ header('Location: ../index.php'); exit; }
$db = get_db_connection();
$clienteId = (int)($u['cliente_id'] ?? 0);
$app_name = $branding['app_name'] ?? 'Dekanto';
$logo = $branding['logo'] ?? null;
// KPIs do cliente
$st = $db->prepare("SELECT COUNT(*) FROM requisicoes WHERE cliente_id=?"); $st->execute([$clienteId]); $qtdReq = (int)$st->fetchColumn();
$st = $db->prepare("SELECT COUNT(*) FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id JOIN requisicoes r ON r.id=c.requisicao_id WHERE r.cliente_id=?"); $st->execute([$clienteId]); $qtdPedidos = (int)$st->fetchColumn();
$st = $db->prepare("SELECT COUNT(*) FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id JOIN requisicoes r ON r.id=c.requisicao_id WHERE r.cliente_id=? AND p.cliente_aceite_status='pendente'"); $st->execute([$clienteId]); $aceitesPend = (int)$st->fetchColumn();
$st = $db->prepare("SELECT COALESCE(SUM(pr.valor_total),0) FROM pedidos p JOIN propostas pr ON p.proposta_id=pr.id JOIN cotacoes c ON c.id=pr.cotacao_id JOIN requisicoes r ON r.id=c.requisicao_id WHERE r.cliente_id=?"); $st->execute([$clienteId]); $valorPedidos = (float)$st->fetchColumn();
// Pedidos recentes
$st = $db->prepare("SELECT p.id, p.cliente_aceite_status, p.cliente_aceite_em, p.criado_em, r.id AS requisicao_id, COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id)) AS requisicao_titulo, pr.valor_total, pr.prazo_entrega AS prazo_dias
                    FROM pedidos p
                    JOIN propostas pr ON pr.id=p.proposta_id
                    JOIN cotacoes c ON c.id=pr.cotacao_id
                    JOIN requisicoes r ON r.id=c.requisicao_id
                    WHERE r.cliente_id=?
                    ORDER BY p.id DESC
                    LIMIT 5");
$st->execute([$clienteId]);
$pedidosRecentes = $st->fetchAll(PDO::FETCH_ASSOC);
// Cotações recentes com agregados (tracking)
$st = $db->prepare("SELECT c.id, c.requisicao_id, c.status, c.criado_em,
                           COUNT(pr.id) AS propostas,
                           MIN(pr.valor_total) AS menor_valor,
                           MIN(pr.prazo_entrega) AS melhor_prazo,
                           MAX(pr.pagamento_dias) AS melhor_pagamento
                    FROM cotacoes c
                    JOIN requisicoes r ON r.id=c.requisicao_id
                    LEFT JOIN propostas pr ON pr.cotacao_id=c.id
                    WHERE r.cliente_id=?
                    GROUP BY c.id
                    ORDER BY c.id DESC
                    LIMIT 5");
$st->execute([$clienteId]);
$cotacoesRecentes = $st->fetchAll(PDO::FETCH_ASSOC);
// Atividades recentes (timeline) — segura caso tabela não exista
$atividadesRecentes = [];
try {
  $hasTimeline = (bool)$db->query("SHOW TABLES LIKE 'requisicoes_timeline'")->fetch();
  if($hasTimeline){
    $sql = "SELECT t.requisicao_id, t.tipo_evento, t.descricao, t.criado_em,
                   COALESCE(NULLIF(r.titulo,''), CONCAT('Requisição #', r.id)) AS requisicao_titulo
            FROM requisicoes_timeline t
            JOIN requisicoes r ON r.id=t.requisicao_id
            WHERE r.cliente_id=?
            ORDER BY t.id DESC
            LIMIT 8";
    $st = $db->prepare($sql); $st->execute([$clienteId]);
    $atividadesRecentes = $st->fetchAll(PDO::FETCH_ASSOC);
  }
} catch(Throwable $e) { $atividadesRecentes = []; }
// Mapas de rótulos legíveis
$STATUS_LABELS = [
  'pendente_aprovacao' => 'Pendente de aprovação',
  'em_analise' => 'Em análise',
  'aberta' => 'Aberta',
  'fechada' => 'Fechada',
  'aprovada' => 'Aprovada',
  'rejeitada' => 'Rejeitada',
];
$COTACAO_STATUS = [ 'aberta' => 'Aberta', 'encerrada' => 'Encerrada', 'fechada' => 'Encerrada' ];
$ACEITE_LABELS = [
  'pendente' => 'Pendente',
  'aceito' => 'Aceito',
  'rejeitado' => 'Rejeitado',
];
?>
<!DOCTYPE html>
<?php
$theme = isset($_GET['theme']) ? ($_GET['theme']==='dark'?'dark':'light') : ($_COOKIE['theme'] ?? 'light');
if(isset($_GET['theme'])) setcookie('theme',$theme,time()+3600*24*30,'/');
?>
<html lang="pt-br" data-bs-theme="<?=$theme?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Portal do Cliente - <?=htmlspecialchars($app_name)?> </title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="../../assets/css/nexus.css">
  <style>
    .kpi{border:1px solid var(--matrix-border);border-radius:.8rem;padding:1rem;background:rgba(255,255,255,.02)}
    /* Ajuste: ícones mais claros (fundo translúcido) usando cor terciária apenas no ícone */
    .kpi .icon{width:36px;height:36px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;margin-right:.6rem;
      background:linear-gradient(145deg,rgba(255,255,255,.08),rgba(255,255,255,.02));
      border:1px solid var(--matrix-border);
      color:var(--matrix-tertiary,#b5a886);
      box-shadow:0 2px 4px -2px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.05) inset;
    }
    .shortcut-card{border:1px dashed var(--matrix-border);background:rgba(255,255,255,.03);border-radius:12px;padding:1rem}
  .btn-client-approve,.btn-client-reject{border-radius:999px;display:inline-flex;align-items:center;gap:.35rem;font-weight:600;padding:.32rem .95rem;font-size:.85rem;transition:transform .18s ease,background .2s ease,color .2s ease;border:1px solid transparent;letter-spacing:.01em}
  .btn-client-approve{background:linear-gradient(120deg,#d3f9d8,#7dd3a7);color:#064e3b;border-color:rgba(34,197,94,.35)}
  .btn-client-approve:hover,.btn-client-approve:focus-visible{transform:translateY(-1px);color:#022c22}
  .btn-client-reject{background:linear-gradient(145deg,rgba(127,29,29,.85),rgba(244,63,94,.65));color:#fee2e2;border-color:rgba(220,38,38,.85)}
  .btn-client-reject:hover,.btn-client-reject:focus-visible{background:linear-gradient(145deg,rgba(88,28,28,.95),rgba(220,38,38,.85));color:#fff;transform:translateY(-1px)}
    .btn-client-approve i,.btn-client-reject i{font-size:1rem;line-height:1}
  </style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<div class="container py-4">
  <h2 class="mb-3">Bem-vindo, <?=htmlspecialchars($u['nome']??'Cliente')?>!</h2>
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="kpi d-flex align-items-center"><div class="icon"><i class="bi bi-journal-text"></i></div><div><div class="text-secondary small">Minhas Requisições</div><div class="h3 m-0"><?=$qtdReq?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi d-flex align-items-center"><div class="icon"><i class="bi bi-cart-check"></i></div><div><div class="text-secondary small">Pedidos</div><div class="h3 m-0"><?=$qtdPedidos?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi d-flex align-items-center"><div class="icon"><i class="bi bi-hourglass-split"></i></div><div><div class="text-secondary small">Aceites Pendentes</div><div class="h3 m-0"><?=$aceitesPend?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="kpi d-flex align-items-center"><div class="icon"><i class="bi bi-cash-coin"></i></div><div><div class="text-secondary small">Valor Total</div><div class="h5 m-0">R$ <?=number_format($valorPedidos,2,',','.')?></div></div></div></div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-8">
      <div class="shortcut-card d-flex align-items-center justify-content-between">
        <div>
          <div class="fw-semibold mb-1">Atalhos</div>
          <div class="text-secondary small">Crie uma nova requisição ou acesse seus pedidos.</div>
        </div>
        <div class="text-end">
          <button class="btn btn-matrix-primary me-2" data-bs-toggle="modal" data-bs-target="#novaReqModal"><i class="bi bi-plus-lg me-1"></i>Nova Requisição</button>
          <a class="btn btn-outline-light me-2" href="requisicoes.php"><i class="bi bi-journal-arrow-down me-1"></i>Requisições</a>
          <a class="btn btn-outline-light" href="pedidos.php"><i class="bi bi-cart-check me-1"></i>Pedidos</a>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <?php if($aceitesPend>0): ?>
      <div class="alert alert-warning mb-0" role="alert">
        <div class="d-flex align-items-center justify-content-between">
          <div><i class="bi bi-exclamation-triangle me-2"></i>Você tem <strong><?=$aceitesPend?></strong> pedido(s) aguardando aceite.</div>
          <a href="pedidos.php" class="btn btn-sm btn-outline-dark">Ver Pedidos</a>
        </div>
      </div>
      <?php else: ?>
      <div class="alert bg-success-subtle text-success-emphasis border border-success-subtle mb-0" role="alert">
        <i class="bi bi-check-circle me-2"></i> Nenhum aceite pendente no momento.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-matrix mb-4">
  <div class="card-header-matrix d-flex justify-content-between align-items-center"><span><i class="bi bi-journal-text me-2"></i>Minhas Requisições</span><a href="requisicoes.php" class="btn btn-sm btn-client-action"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver todas</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a></div>
    <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>Título</th><th>Status</th><th>Criado em</th><th>Ações</th></tr></thead><tbody>
<?php
$st = $db->prepare("SELECT id, COALESCE(NULLIF(titulo,''), CONCAT('Requisição #',id)) titulo, status, criado_em FROM requisicoes WHERE cliente_id=? ORDER BY id DESC LIMIT 10");
$st->execute([$clienteId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if(!$rows){ echo '<tr><td colspan="5" class="text-center text-secondary">Sem requisições.</td></tr>'; }
else foreach($rows as $r){
    $status = (string)($r['status'] ?? '');
    $badge = 'secondary';
    if($status==='aberta') $badge='info';
    elseif($status==='em_analise' || $status==='pendente_aprovacao') $badge='warning text-dark';
    elseif($status==='fechada' || $status==='aprovada') $badge='success';
    elseif($status==='rejeitada') $badge='danger';
    $label = $STATUS_LABELS[$status] ?? ucwords(str_replace('_',' ', $status));
    echo '<tr><td>'.(int)$r['id'].'</td>';
    // título sem link
    echo '<td>'.htmlspecialchars($r['titulo']).'</td>';
    echo '<td><span class="badge bg-'.$badge.'">'.htmlspecialchars($label).'</span></td>';
    echo '<td>'.htmlspecialchars($r['criado_em']).'</td>';
  echo '<td><a class="btn btn-sm btn-client-action" href="requisicao.php?id='.(int)$r['id'].'"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver detalhes</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>';
  echo ' <a class="btn btn-sm btn-client-action ms-1" href="acompanhar_requisicao.php?id='.(int)$r['id'].'"><span class="btn-icon"><i class="bi bi-geo-alt"></i></span><span>Acompanhar</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a></td></tr>';
}
?>
    </tbody></table></div>
  </div>

  <div class="card-matrix mb-4">
  <div class="card-header-matrix d-flex justify-content-between align-items-center"><span><i class="bi bi-graph-up me-2"></i>Cotações Recentes</span><a href="requisicoes.php" class="btn btn-sm btn-client-action"><span class="btn-icon"><i class="bi bi-graph-up"></i></span><span>Ver requisições</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a></div>
    <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>Requisição</th><th>Propostas</th><th>Menor Valor</th><th>Melhor Prazo</th><th>Status</th><th>Ações</th></tr></thead><tbody>
<?php
if(!$cotacoesRecentes){ echo '<tr><td colspan="7" class="text-center text-secondary">Sem cotações recentes.</td></tr>'; }
else foreach($cotacoesRecentes as $c){
  $stC = strtolower((string)($c['status'] ?? ''));
  $badge = ($stC==='aberta') ? 'info' : 'secondary';
  if($stC==='fechada' || $stC==='encerrada') $badge='secondary';
  $label = $COTACAO_STATUS[$stC] ?? ucwords(str_replace('_',' ', $stC));
  $menor = $c['menor_valor']!==null ? ('R$ '.number_format((float)$c['menor_valor'],2,',','.')) : '—';
  $melhorPrazo = $c['melhor_prazo']!==null ? ((int)$c['melhor_prazo'].' dias') : '—';
  echo '<tr>';
  echo '<td>#'.(int)$c['id'].'</td>';
  // Requisição sem link
  echo '<td>Requisição #'.(int)$c['requisicao_id'].'</td>';
  echo '<td>'.(int)$c['propostas'].'</td>';
  echo '<td>'.$menor.'</td>';
  echo '<td>'.$melhorPrazo.'</td>';
  echo '<td><span class="badge bg-'.$badge.'">'.htmlspecialchars($label).'</span></td>';
  echo '<td><a class="btn btn-sm btn-client-action" href="requisicao.php?id='.(int)$c['requisicao_id'].'"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver detalhes</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>';
  echo ' <a class="btn btn-sm btn-client-action ms-1" href="acompanhar_requisicao.php?id='.(int)$c['requisicao_id'].'"><span class="btn-icon"><i class="bi bi-geo-alt"></i></span><span>Acompanhar</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a></td>';
  echo '</tr>';
}
?>
    </tbody></table></div>
  </div>

  <div class="card-matrix">
  <div class="card-header-matrix d-flex justify-content-between align-items-center"><span><i class="bi bi-receipt me-2"></i>Pedidos Recentes</span><a href="pedidos.php" class="btn btn-sm btn-client-action"><span class="btn-icon"><i class="bi bi-receipt"></i></span><span>Ver todos</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a></div>
    <div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>ID</th><th>Requisição</th><th>Valor</th><th>Prazo</th><th>Aceite</th><th>Ações</th></tr></thead><tbody>
<?php
if(!$pedidosRecentes){ echo '<tr><td colspan="6" class="text-center text-secondary">Sem pedidos recentes.</td></tr>'; }
else foreach($pedidosRecentes as $p){
  // Badge e rótulo amigável para aceite do cliente
  $statusAceite = (string)($p['cliente_aceite_status'] ?? '');
  $badge = ($statusAceite === 'aceito') ? 'success' : (($statusAceite === 'rejeitado') ? 'danger' : 'warning text-dark');
  $aceiteLabel = $ACEITE_LABELS[$statusAceite] ?? ucwords(str_replace('_',' ', $statusAceite));
  echo '<tr>';
  echo '<td>'.(int)$p['id'].'</td>';
  // Requisição sem link
  echo '<td>'.htmlspecialchars($p['requisicao_titulo']).'</td>';
  echo '<td>R$ '.number_format((float)$p['valor_total'],2,',','.').'</td>';
  echo '<td>'.htmlspecialchars($p['prazo_dias'] ?? '').' dias</td>';
  echo '<td><span class="badge bg-'.$badge.'">'.htmlspecialchars($aceiteLabel).'</span></td>';
  echo '<td>';
  // Botão Ver e Acompanhar
  echo '<a class="btn btn-sm btn-client-action me-1" href="requisicao.php?id='.(int)$p['requisicao_id'].'"><span class="btn-icon"><i class="bi bi-eye"></i></span><span>Ver detalhes</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>';
  echo '<a class="btn btn-sm btn-client-action" href="acompanhar_requisicao.php?id='.(int)$p['requisicao_id'].'"><span class="btn-icon"><i class="bi bi-geo-alt"></i></span><span>Acompanhar</span><span class="chevron-icon"><i class="bi bi-arrow-right-short"></i></span></a>';
  // Botões de aceite (se pendente)
  if($statusAceite === 'pendente'){
    echo ' <form method="post" action="pedidos.php" class="d-inline ms-2" data-bs-theme="light"><input type="hidden" name="pedido_id" value="'.(int)$p['id'].'"><button name="acao" value="aceitar" class="btn btn-client-approve"><i class="bi bi-check2-circle"></i><span>Aprovar</span></button></form>';
    echo ' <form method="post" action="pedidos.php" class="d-inline ms-1" data-bs-theme="light"><input type="hidden" name="pedido_id" value="'.(int)$p['id'].'"><button name="acao" value="rejeitar" class="btn btn-client-reject"><i class="bi bi-x-circle"></i><span>Rejeitar</span></button></form>';
  }
  echo '</td>';
  echo '</tr>';
}
?>
    </tbody></table></div>
  </div>
</div>

<!-- Modal: Nova Requisição -->
<div class="modal fade" id="novaReqModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="requisicoes.php">
        <div class="modal-header">
          <h5 class="modal-title">Nova Requisição</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <label class="form-label">Título (opcional)</label>
          <input type="text" name="titulo" class="form-control" placeholder="Ex: Compra de insumos">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-matrix-primary" type="submit">Criar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="position-fixed" style="right:1rem;bottom:1rem;z-index:1030">
  <button class="btn btn-sm btn-outline-secondary" id="toggleThemeBtn"><i class="bi bi-brightness-high"></i></button>
</div>
<script>
  document.getElementById('toggleThemeBtn')?.addEventListener('click',()=>{
    const cur = document.documentElement.getAttribute('data-bs-theme')==='dark'?'dark':'light';
    const next = cur==='dark'?'light':'dark';
    const url = new URL(window.location.href);
    url.searchParams.set('theme', next);
    window.location.href = url.toString();
  });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
