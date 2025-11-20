<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers (authenticated endpoint)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Only GET
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') { http_response_code(405); header('Allow: GET'); echo json_encode(['erro'=>'Método não suportado']); exit; }

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$db = get_db_connection();
$metrics = [
    'tempo_requisicao_para_convites_horas' => null,
    'taxa_resposta_fornecedores_pct' => null,
    'delta_aceite_cliente_horas' => null,
];

// Janela de tempo (padrão 90 dias)
$dias = isset($_GET['dias']) ? (int)$_GET['dias'] : 90;
if ($dias < 7) $dias = 7; if ($dias > 365) $dias = 365;
$cutoff = (new DateTimeImmutable('-'.$dias.' days'))->format('Y-m-d H:i:s');

// Helpers
$hasConvites = false; $hasTimeline = false; $hasPedidosClienteAceite = false;
try { $hasConvites = (bool)$db->query("SHOW TABLES LIKE 'cotacao_convites'")->fetch(); } catch(Throwable $e){ $hasConvites=false; }
try { $hasTimeline = (bool)$db->query("SHOW TABLES LIKE 'requisicoes_timeline'")->fetch(); } catch(Throwable $e){ $hasTimeline=false; }
try { $col = $db->query("SHOW COLUMNS FROM pedidos LIKE 'cliente_aceite_em'")->fetch(); $hasPedidosClienteAceite = (bool)$col; } catch(Throwable $e){ $hasPedidosClienteAceite=false; }

// 1) Tempo médio: requisição -> primeiro convite enviado (fallback: primeira cotação criada)
try {
    if ($hasConvites) {
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, r.criado_em, t.first_env)) as avg_h
                FROM requisicoes r
                JOIN (
                    SELECT requisicao_id, MIN(enviado_em) AS first_env
                    FROM cotacao_convites
                    WHERE enviado_em IS NOT NULL AND enviado_em >= :cut1
                    GROUP BY requisicao_id
                ) t ON t.requisicao_id=r.id
                WHERE r.criado_em >= :cut2 AND t.first_env >= r.criado_em";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff, ':cut2'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['avg_h'] !== null){ $metrics['tempo_requisicao_para_convites_horas'] = (float)$row['avg_h']; }
    }
    if ($metrics['tempo_requisicao_para_convites_horas'] === null) {
        // Fallback: primeira cotação criada vinculada à requisição
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, r.criado_em, c.first_cot)) AS avg_h
                FROM requisicoes r
                JOIN (
                  SELECT requisicao_id, MIN(criado_em) AS first_cot
                  FROM cotacoes
                  WHERE criado_em >= :cut1
                  GROUP BY requisicao_id
                ) c ON c.requisicao_id=r.id
                WHERE r.criado_em >= :cut2 AND c.first_cot >= r.criado_em";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff, ':cut2'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['avg_h'] !== null){ $metrics['tempo_requisicao_para_convites_horas'] = (float)$row['avg_h']; }
    }
} catch(Throwable $e){ /* ignore */ }

// 2) Taxa de resposta fornecedores (fallback: % de cotações com alguma proposta)
try {
    if ($hasConvites) {
        $sql = "SELECT (SUM(CASE WHEN status='respondido' THEN 1 ELSE 0 END) / NULLIF(COUNT(*),0)) * 100 AS pct
                FROM cotacao_convites
                WHERE enviado_em IS NOT NULL AND enviado_em >= :cut1";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['pct'] !== null){ $metrics['taxa_resposta_fornecedores_pct'] = (float)$row['pct']; }
    }
    if ($metrics['taxa_resposta_fornecedores_pct'] === null) {
        // Fallback aproximado: % de cotações que receberam ao menos uma proposta
        $sql = "SELECT (COUNT(DISTINCT p.cotacao_id) / NULLIF(COUNT(DISTINCT c.id),0)) * 100 AS pct
                FROM cotacoes c
                LEFT JOIN propostas p ON p.cotacao_id = c.id
                WHERE c.criado_em >= :cut1";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['pct'] !== null){ $metrics['taxa_resposta_fornecedores_pct'] = (float)$row['pct']; }
    }
} catch(Throwable $e){ /* ignore */ }

// 3) Delta aceite cliente: pedido_enviado_cliente -> (pedido_aceito|pedido_rejeitado)
try {
    if ($hasTimeline) {
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, t1.min_enviado, t2.min_decisao)) AS avg_h
                FROM (
                    SELECT requisicao_id, MIN(criado_em) AS min_enviado
                    FROM requisicoes_timeline
                    WHERE tipo_evento='pedido_enviado_cliente' AND criado_em >= :cut1
                    GROUP BY requisicao_id
                ) t1
                JOIN (
                    SELECT requisicao_id, MIN(criado_em) AS min_decisao
                    FROM requisicoes_timeline
                    WHERE tipo_evento IN ('pedido_aceito','pedido_rejeitado') AND criado_em >= :cut2
                    GROUP BY requisicao_id
                ) t2 ON t1.requisicao_id = t2.requisicao_id
                WHERE t2.min_decisao >= t1.min_enviado";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff, ':cut2'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['avg_h'] !== null){ $metrics['delta_aceite_cliente_horas'] = (float)$row['avg_h']; }
    }
    if ($metrics['delta_aceite_cliente_horas'] === null && $hasPedidosClienteAceite) {
        // Fallback: usar timestamps do pedido (criado_em -> cliente_aceite_em)
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, p.criado_em, p.cliente_aceite_em)) AS avg_h
                FROM pedidos p
                WHERE p.cliente_aceite_em IS NOT NULL AND p.cliente_aceite_em >= :cut1";
        $st = $db->prepare($sql);
        $st->execute([':cut1'=>$cutoff]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if($row && $row['avg_h'] !== null){ $metrics['delta_aceite_cliente_horas'] = (float)$row['avg_h']; }
    }
} catch(Throwable $e){ /* ignore */ }

// Defaults para quando não houver dados suficientes
foreach ($metrics as $k=>$v) {
    if ($v === null || !is_numeric($v)) $metrics[$k] = 0.0;
}

// Resposta
$resp = ['kpis'=>$metrics, 'janela_dias'=>$dias];
echo json_encode($resp, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
