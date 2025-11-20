<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/timeline.php';
require_once __DIR__.'/../includes/email.php';

if (!isset($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['erro'=>'Não autenticado']); exit; }

$db = get_db_connection();
$agora = new DateTimeImmutable();
$resumo = ['requisicoes'=>0,'pedidos'=>0,'convites_lembretes'=>0];

// Config SLA (fallback default)
$cfg = [];
try { $cfg = $db->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'sla_%' OR chave LIKE 'lembrete_%'")->fetchAll(PDO::FETCH_KEY_PAIR); } catch(Throwable $e){}
$slaAnalise = isset($cfg['sla_analise_dias']) ? (int)$cfg['sla_analise_dias'] : 2;
$slaProducao = isset($cfg['sla_producao_dias']) ? (int)$cfg['sla_producao_dias'] : 5;
$slaTransito = isset($cfg['sla_transito_dias']) ? (int)$cfg['sla_transito_dias'] : 3;
$lembreteConviteHoras = isset($cfg['lembrete_convite_horas']) ? (int)$cfg['lembrete_convite_horas'] : 48;

// Helper: evita duplicados por janela (por dia) na followup_logs
$alreadyLogged = function(string $entidade, int $entidade_id, string $tipo) use ($db): bool {
    try {
        $st = $db->prepare("SELECT 1 FROM followup_logs WHERE entidade=? AND entidade_id=? AND tipo=? AND DATE(criado_em)=CURDATE() LIMIT 1");
        $st->execute([$entidade,$entidade_id,$tipo]);
        return (bool)$st->fetchColumn();
    } catch(Throwable $e){ return false; }
};

// Follow-up Requisicoes em analise sem update
try {
    $sql = "SELECT r.id, r.status, r.criado_em FROM requisicoes r WHERE r.status='em_analise' AND TIMESTAMPDIFF(DAY, r.criado_em, NOW()) > ?";
    $st = $db->prepare($sql); $st->execute([$slaAnalise]);
    foreach($st as $r){
        if($alreadyLogged('requisicao',(int)$r['id'],'analise_atraso')){ continue; }
        $det = ['motivo'=>'requisicao_em_analise_sem_update','dias'=>$slaAnalise];
        $ins = $db->prepare("INSERT INTO followup_logs (entidade, entidade_id, tipo, detalhe) VALUES ('requisicao',?,?,?)");
        try { $ins->execute([$r['id'],'analise_atraso', json_encode($det,JSON_UNESCAPED_UNICODE)]); $resumo['requisicoes']++; } catch(Throwable $e){}
        log_requisicao_event($db,(int)$r['id'],'followup_alerta','Follow-up análise atraso',null,$det);
    }
} catch(Throwable $e){}

// Follow-up Pedidos em producao
try {
    $sql = "SELECT p.id, p.status, p.criado_em, c.requisicao_id FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id WHERE p.status='em_producao' AND TIMESTAMPDIFF(DAY,p.criado_em,NOW())>?";
    $st=$db->prepare($sql); $st->execute([$slaProducao]);
    foreach($st as $p){
        if($alreadyLogged('pedido',(int)$p['id'],'producao_atraso')){ continue; }
        $det = ['motivo'=>'pedido_producao_sem_update','dias'=>$slaProducao];
        $ins = $db->prepare("INSERT INTO followup_logs (entidade, entidade_id, tipo, detalhe) VALUES ('pedido',?,?,?)");
        try { $ins->execute([$p['id'],'producao_atraso', json_encode($det,JSON_UNESCAPED_UNICODE)]); $resumo['pedidos']++; } catch(Throwable $e){}
        log_requisicao_event($db,(int)$p['requisicao_id'],'followup_alerta','Follow-up produção atraso',null,$det);
    }
} catch(Throwable $e){}

// Follow-up Pedidos em transito
try {
    $sql = "SELECT p.id, p.status, p.criado_em, c.requisicao_id FROM pedidos p JOIN propostas pr ON pr.id=p.proposta_id JOIN cotacoes c ON c.id=pr.cotacao_id WHERE p.status='em_transito' AND TIMESTAMPDIFF(DAY,p.criado_em,NOW())>?";
    $st=$db->prepare($sql); $st->execute([$slaTransito]);
    foreach($st as $p){
        if($alreadyLogged('pedido',(int)$p['id'],'transito_atraso')){ continue; }
        $det = ['motivo'=>'pedido_transito_sem_update','dias'=>$slaTransito];
        $ins = $db->prepare("INSERT INTO followup_logs (entidade, entidade_id, tipo, detalhe) VALUES ('pedido',?,?,?)");
        try { $ins->execute([$p['id'],'transito_atraso', json_encode($det,JSON_UNESCAPED_UNICODE)]); $resumo['pedidos']++; } catch(Throwable $e){}
        log_requisicao_event($db,(int)$p['requisicao_id'],'followup_alerta','Follow-up trânsito atraso',null,$det);
    }
} catch(Throwable $e){}

// Lembrete: convites de cotação prestes a expirar (status enviado)
try {
    $sql = "SELECT cc.id, cc.requisicao_id, cc.fornecedor_id, cc.expira_em, cc.status FROM cotacao_convites cc WHERE cc.status='enviado' AND cc.expira_em BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)";
    $st=$db->prepare($sql); $st->execute([$lembreteConviteHoras]);
    foreach($st as $cc){
        $conviteId = (int)$cc['id'];
        if($alreadyLogged('cotacao_convite',$conviteId,'lembrete_expiracao')){ continue; }
        // Envia email de lembrete (buscar email do fornecedor)
        $email = null;
        try { $stE=$db->prepare('SELECT email FROM fornecedores WHERE id=?'); $stE->execute([(int)$cc['fornecedor_id']]); $email=$stE->fetchColumn() ?: null; } catch(Throwable $e){}
        if($email){
            try {
                email_send_cotacao_convite(
                    $email,
                    (int)$cc['requisicao_id'],
                    null,
                    true,
                    [
                        'convite_id' => (int)$cc['id'],
                        'fornecedor_id' => (int)$cc['fornecedor_id'],
                        'expira_em' => $cc['expira_em'] ?? null
                    ]
                );
            } catch(Throwable $e){}
        }
        $det=['motivo'=>'cotacao_convite_prestes_expirar','horas'=>$lembreteConviteHoras,'fornecedor_id'=>(int)$cc['fornecedor_id']];
        $ins = $db->prepare("INSERT INTO followup_logs (entidade, entidade_id, tipo, detalhe) VALUES ('cotacao_convite',?,?,?)");
        try { $ins->execute([$conviteId,'lembrete_expiracao', json_encode($det,JSON_UNESCAPED_UNICODE)]); $resumo['convites_lembretes']++; } catch(Throwable $e){}
        log_requisicao_event($db,(int)$cc['requisicao_id'],'followup_alerta','Lembrete: convite de cotação prestes a expirar',null,$det);
    }
} catch(Throwable $e){}

echo json_encode(['executado_em'=>$agora->format('c'),'resumo'=>$resumo]);
