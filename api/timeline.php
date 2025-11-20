<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$db = get_db_connection();
// Rate limit leve para GET timeline (por IP)
try { rate_limit_enforce($db, 'api.timeline:get', 600, 300, true); } catch(Throwable $e){}

$entidade = $_GET['entidade'] ?? 'requisicao';
$id = (int)($_GET['id'] ?? 0);
if(!$id){ http_response_code(400); echo json_encode(['erro'=>'id obrigatório']); exit; }

// Verificação de ownership quando usuário é cliente
try {
    $u = auth_usuario();
    if(($u['tipo'] ?? null) === 'cliente' && $entidade === 'requisicao'){
        $stChk = $db->prepare('SELECT 1 FROM requisicoes WHERE id=? AND cliente_id=?');
        $stChk->execute([$id, (int)$u['cliente_id']]);
        if(!$stChk->fetch()){ http_response_code(404); echo json_encode(['erro'=>'Não encontrado']); exit; }
    }
} catch(Throwable $e){ /* fallback: sem bloqueio adicional */ }

$out = [];
try {
    if($entidade === 'requisicao') {
        // Eventos primários da timeline de requisicoes
        $st = $db->prepare('SELECT id, requisicao_id AS entidade_id, tipo_evento AS tipo, descricao, dados_antes, dados_depois, usuario_id, ip_origem, criado_em FROM requisicoes_timeline WHERE requisicao_id=? ORDER BY id ASC');
        $st->execute([$id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach($rows as $r){
            $tipoCanon = normalize_event_type($r['tipo']);
            $out[] = [
                'fonte'=>'requisicoes_timeline',
                'entidade'=>'requisicao',
                'entidade_id'=>$r['entidade_id'],
                'tipo'=>$tipoCanon,
                'descricao'=>$r['descricao'],
                'meta'=>['antes'=> json_decode($r['dados_antes']??'null',true),'depois'=> json_decode($r['dados_depois']??'null',true)],
                'usuario_id'=>$r['usuario_id'],
                'criado_em'=>$r['criado_em']
            ];
        }
        // Followups (usar tipo canônico consolidado)
        if($db->query("SHOW TABLES LIKE 'followup_logs'")->fetch()){
            $st = $db->prepare("SELECT id, tipo, detalhe, usuario_id, criado_em FROM followup_logs WHERE entidade='requisicao' AND entidade_id=? ORDER BY id ASC");
            $st->execute([$id]);
            foreach($st as $f){
                $out[] = [
                    'fonte'=>'followup_logs',
                    'entidade'=>'requisicao',
                    'entidade_id'=>$id,
                    'tipo'=>'followup_alerta',
                    'descricao'=>'Follow-up: '.$f['tipo'],
                    'meta'=> json_decode($f['detalhe']??'null',true),
                    'usuario_id'=>$f['usuario_id'],
                    'criado_em'=>$f['criado_em']
                ];
            }
        }
        // Pedidos relacionados -> aceite cliente
        if($db->query("SHOW TABLES LIKE 'pedidos'")->fetch()){
            $sqlPedidos = "SELECT p.id, p.cliente_aceite_status, p.cliente_aceite_em, p.criado_em FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id WHERE c.requisicao_id=?";
            $st = $db->prepare($sqlPedidos); $st->execute([$id]);
            foreach($st as $p){
                if($p['cliente_aceite_status']){
                    $out[] = [
                        'fonte'=>'pedidos',
                        'entidade'=>'pedido',
                        'entidade_id'=>$p['id'],
                        'tipo'=>'pedido_aceite_status',
                        'descricao'=>'Pedido aceite status: '.$p['cliente_aceite_status'],
                        'meta'=>['status'=>$p['cliente_aceite_status'],'aceite_em'=>$p['cliente_aceite_em']],
                        'usuario_id'=>null,
                        'criado_em'=>$p['criado_em']
                    ];
                }
            }
        }
        // Resumo de convites (destaque)
        if($db->query("SHOW TABLES LIKE 'cotacao_convites'")->fetch()){
            $sqlCv = "SELECT COUNT(*) total,
                              SUM(CASE WHEN status='respondido' THEN 1 ELSE 0 END) responded,
                              SUM(CASE WHEN status='enviado' THEN 1 ELSE 0 END) enviados,
                              SUM(CASE WHEN status='expirado' THEN 1 ELSE 0 END) expirados,
                              SUM(CASE WHEN status='cancelado' THEN 1 ELSE 0 END) cancelados,
                              MIN(enviado_em) AS primeiro_envio,
                              MAX(respondido_em) AS ultima_resposta
                       FROM cotacao_convites WHERE requisicao_id=?";
            $stCv = $db->prepare($sqlCv); $stCv->execute([$id]);
            $cv = $stCv->fetch(PDO::FETCH_ASSOC);
            if($cv && (int)$cv['total']>0){
                $out[] = [
                    'fonte'=>'cotacao_convites',
                    'entidade'=>'requisicao',
                    'entidade_id'=>$id,
                    'tipo'=>'cotacao_convites_resumo',
                    'descricao'=>'Convites enviados: '.((int)$cv['enviados']).' • Respondidos: '.((int)$cv['responded']).' • Expirados: '.((int)$cv['expirados']).' • Cancelados: '.((int)$cv['cancelados']),
                    'meta'=>$cv,
                    'usuario_id'=>null,
                    'criado_em'=>$cv['primeiro_envio'] ?: date('Y-m-d H:i:s')
                ];
            }
        }
        // Resumo de propostas (destaque)
        $hasPropostas = $db->query("SHOW TABLES LIKE 'propostas'")->fetch();
        if($hasPropostas){
            $sqlPr = "SELECT COUNT(*) total,
                              SUM(CASE WHEN status='enviada' THEN 1 ELSE 0 END) enviadas,
                              SUM(CASE WHEN status='aprovada' THEN 1 ELSE 0 END) aprovadas,
                              SUM(CASE WHEN status='rejeitada' THEN 1 ELSE 0 END) rejeitadas,
                              MIN(p.id) AS primeira_id,
                              MAX(p.id) AS ultima_id
                       FROM propostas p JOIN cotacoes c ON c.id=p.cotacao_id WHERE c.requisicao_id=?";
            $stPr = $db->prepare($sqlPr); $stPr->execute([$id]);
            $pr = $stPr->fetch(PDO::FETCH_ASSOC);
            if($pr && (int)$pr['total']>0){
                $out[] = [
                    'fonte'=>'propostas',
                    'entidade'=>'requisicao',
                    'entidade_id'=>$id,
                    'tipo'=>'propostas_resumo',
                    'descricao'=>'Propostas recebidas: '.((int)$pr['total']).' • Aprovadas: '.((int)$pr['aprovadas']).' • Rejeitadas: '.((int)$pr['rejeitadas']),
                    'meta'=>$pr,
                    'usuario_id'=>null,
                    'criado_em'=>date('Y-m-d H:i:s')
                ];
            }
        }
    } else {
        http_response_code(400); echo json_encode(['erro'=>'entidade não suportada']); exit;
    }
    usort($out,function($a,$b){ return strcmp($a['criado_em'],$b['criado_em']); });
    echo json_encode($out);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['erro'=>'Falha timeline']); }
