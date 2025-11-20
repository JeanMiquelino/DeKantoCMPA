<?php
session_start();
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/auth.php';
require_once __DIR__.'/../../includes/ranking.php';
$u = auth_usuario();
if(!$u || ($u['tipo']??'')!=='fornecedor'){ http_response_code(403); echo 'Acesso negado'; exit; }
$db = get_db_connection();
$id = (int)($_GET['id'] ?? 0); $formato = strtolower($_GET['formato'] ?? 'xlsx');
if($id<=0){ http_response_code(400); echo 'id inválido'; exit; }
// Fallback para fornecedores legados sem fornecedor_id em usuarios
$fornecedorId = (int)($u['fornecedor_id'] ?? 0);
if($fornecedorId<=0){
    try {
        $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
        $stF->execute([$u['id']]);
        $fid = (int)$stF->fetchColumn();
        if($fid>0){ $fornecedorId = $fid; $u['fornecedor_id']=$fid; }
    } catch(Throwable $e){ /* ignore */ }
}
$prop = null;
if($fornecedorId>0){
    $st = $db->prepare('SELECT * FROM propostas WHERE id=? AND fornecedor_id=?');
    $st->execute([$id,$fornecedorId]);
    $prop = $st->fetch(PDO::FETCH_ASSOC);
}
// Fallback adicional: caso ainda não encontrado, tenta via join pelo usuario_id (legado extremo)
if(!$prop){
    try {
        $st2 = $db->prepare('SELECT p.* FROM propostas p JOIN fornecedores f ON f.id=p.fornecedor_id WHERE p.id=? AND f.usuario_id=? LIMIT 1');
        $st2->execute([$id,$u['id']]);
        $prop = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch(Throwable $e){ /* ignore */ }
}
if(!$prop){ http_response_code(404); echo 'Proposta não encontrada'; exit; }
// Ranking banda atual
$ordered = ranking_compute_for_cotacao($db,(int)$prop['cotacao_id']);
$pos = ranking_position_for_fornecedor($ordered,(int)$prop['fornecedor_id']);
$banda = $frase = null; $range=null;
if($pos!==null){ [$banda,$frase,$range] = ranking_band_from_position($pos); }
if($formato==='csv'){ header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="proposta_'.$id.'.csv"'); $out=fopen('php://output','w'); fputcsv($out,['ID','Cotacao','Valor Total','Prazo Entrega','Pagamento Dias','Status','Banda','Frase']); fputcsv($out,[$prop['id'],$prop['cotacao_id'],$prop['valor_total'],$prop['prazo_entrega'],$prop['pagamento_dias'],$prop['status'],$banda,$frase]); fclose($out); exit; }
// XLSX via PhpSpreadsheet
require_once __DIR__.'/../../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet; use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
$ss = new Spreadsheet(); $ws=$ss->getActiveSheet(); $ws->setTitle('Proposta');
$ws->fromArray([['ID','Cotacao','Valor Total','Prazo Entrega (dias)','Pagamento (dias)','Status','Banda','Frase']],null,'A1');
$ws->fromArray([[ $prop['id'],$prop['cotacao_id'],$prop['valor_total'],$prop['prazo_entrega'],$prop['pagamento_dias'],$prop['status'],$banda,$frase ]],null,'A2');
foreach(range('A','H') as $col){ $ws->getColumnDimension($col)->setAutoSize(true); }
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="proposta_'.$id.'.xlsx"');
$writer=new Xlsx($ss); $writer->save('php://output');
exit;
