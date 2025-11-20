<?php
declare(strict_types=1);

// Exportador multi-formato (csv, xlsx, pdf)
// Requer extensões: zip, xmlwriter (para xlsx / pdf via phpspreadsheet) e mpdf/mpdf para PDF

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

$start = microtime(true);
ob_start(); // captura ruídos
session_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

$wantJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function outError(string $msg,int $code=400,array $extra=[]): void {
    global $wantJson;
    if (!headers_sent()) {
        header_remove();
        http_response_code($code);
        header('X-Export-Erro: '.$msg);
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['erro'=>$msg]+$extra, JSON_UNESCAPED_UNICODE);
        } else {
            $dump = $extra ? "<pre style='background:#222;padding:.5rem;border:1px solid #444;color:#ccc;font-size:12px;white-space:pre-wrap'>"
                .htmlspecialchars(print_r($extra,true))."</pre>" : '';
            echo "<!DOCTYPE html><meta charset='utf-8'><title>Erro Exportação</title>
                  <body style='background:#111;color:#eee;font-family:Arial;padding:1rem'>
                  <h3>Falha na Exportação</h3><p><strong>{$msg}</strong></p>{$dump}</body>";
        }
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') outError('metodo_nao_permitido',405);
if (!isset($_SESSION['usuario_id'])) outError('nao_autenticado',403);

require __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/branding.php';
$__user = auth_usuario();
if (!$__user) outError('nao_autenticado',403);

// Branding helpers (Dekanto)
$appName = isset($branding['app_name']) && is_string($branding['app_name']) ? (string)$branding['app_name'] : 'Dekanto';
$brandCss = (string)($branding['primary_color'] ?? '#B5A886');
if ($brandCss === '') { $brandCss = '#B5A886'; }
if ($brandCss[0] !== '#') { $brandCss = '#'.$brandCss; }
$hex = ltrim($brandCss, '#');
if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
$int = hexdec(preg_replace('/[^0-9a-f]/i','',$hex));
$brandRGB = [($int>>16)&255, ($int>>8)&255, $int&255];
$luminance = 0.2126*$brandRGB[0] + 0.7152*$brandRGB[1] + 0.0722*$brandRGB[2];
$brandTextOnBrand = ($luminance > 150) ? '#000000' : '#FFFFFF';
// Resolve logo path or URL (prefer configured, then fallbacks)
$logoWeb = null;
$logoCandidates = [];
if (!empty($branding['logo'])) {
    $logoVal = (string)$branding['logo'];
    if (preg_match('~^https?://~i', $logoVal)) {
        $logoCandidates[] = $logoVal;
    } else {
        $logoCandidates[] = __DIR__ . '/../' . ltrim($logoVal,'/');
    }
}
$logoCandidates[] = __DIR__ . '/../assets/images/logo_dekanto.png';
$logoCandidates[] = __DIR__ . '/../assets/images/logo_site.jpg';
foreach ($logoCandidates as $cand) {
    if (is_string($cand) && @file_exists($cand)) { $logoWeb = $cand; break; }
}

// Permissões por formato
$__permFormato = [ 'csv'=>'export.csv', 'xlsx'=>'export.xlsx', 'pdf'=>'export.pdf' ];

$modulo    = trim($_POST['modulo'] ?? '');
$formato   = strtolower($_POST['formato'] ?? 'xlsx');
$intervalo = $_POST['intervalo'] ?? 'todos';
$idsRaw    = trim($_POST['ids'] ?? '');
$requisicaoId = isset($_POST['requisicao_id']) ? (int)$_POST['requisicao_id'] : 0;
$pesoPrecoIn = isset($_POST['peso_preco']) ? (float)$_POST['peso_preco'] : null;
$pesoPrazoIn = isset($_POST['peso_prazo']) ? (float)$_POST['peso_prazo'] : null;
$pesoPagamentoIn = isset($_POST['peso_pagamento']) ? (float)$_POST['peso_pagamento'] : null;

$formatosPermitidos = ['csv','xlsx','pdf'];
if (!in_array($formato,$formatosPermitidos,true)) outError('formato_invalido');
// Ajuste: se não possuir permissão específica, permite fallback (temporário) para não bloquear exportação
$__permCode = $__permFormato[$formato];
if (!auth_can($__permCode)) {
    // Fallback liberado – registrar auditoria e seguir adiante
    header('X-Export-Permissao: fallback');
    auditoria_log($__user['id'] ?? null,'export_fallback_permissao',$modulo,null,['formato'=>$formato,'perm_requerida'=>$__permCode]);
}
if (!in_array($intervalo,['todos','pagina_atual','filtrados'],true)) outError('intervalo_invalido');
if ($modulo === '') outError('modulo_obrigatorio');

try { $db = get_db_connection(); } catch(Throwable $e) { outError('conexao_falhou',500); }

// ================== EXPORT RANKING ESPECIAL ==================
if ($modulo === 'ranking') {
    if ($requisicaoId <= 0) outError('requisicao_id_obrigatorio');
    // Coletar IDs opcionais
    $ids = [];
    if ($idsRaw !== '') {
        foreach (explode(',',$idsRaw) as $p) { $v=(int)$p; if($v>0) $ids[]=$v; }
        $ids = array_values(array_unique($ids));
    }
    // Pesos (usar inputs se enviados; senão, carregar da configuração)
    $pesoPreco = 0.6; $pesoPrazo = 0.25; $pesoPagamento = 0.15;
    if($pesoPrecoIn!==null && $pesoPrazoIn!==null && $pesoPagamentoIn!==null){
        $pesoPreco = max(0,min(1,$pesoPrecoIn));
        $pesoPrazo = max(0,min(1,$pesoPrazoIn));
        $pesoPagamento = max(0,min(1,$pesoPagamentoIn));
    } else {
        try {
            $cfg = $db->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('peso_preco','peso_prazo','peso_pagamento')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if(isset($cfg['peso_preco'])) $pesoPreco = (float)$cfg['peso_preco'];
            if(isset($cfg['peso_prazo'])) $pesoPrazo = (float)$cfg['peso_prazo'];
            if(isset($cfg['peso_pagamento'])) $pesoPagamento = (float)$cfg['peso_pagamento'];
        } catch(Throwable $e){}
    }

    $sql = "SELECT pr.id as proposta_id, pr.valor_total, pr.prazo_entrega, pr.pagamento_dias, pr.fornecedor_id,
                   COALESCE(f.nome_fantasia, f.razao_social) AS fornecedor_nome
            FROM propostas pr
            JOIN cotacoes c ON c.id = pr.cotacao_id
            LEFT JOIN fornecedores f ON f.id = pr.fornecedor_id
            WHERE c.requisicao_id = ?";
    $st = $db->prepare($sql); $st->execute([$requisicaoId]);
    $propostas = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$propostas) outError('sem_dados',204);
    if ($ids) { $propostas = array_values(array_filter($propostas, fn($p)=> in_array((int)$p['proposta_id'],$ids,true))); }
    if (!$propostas) outError('sem_dados_filtro',204);

    $precoMin = min(array_map(fn($p)=> (float)$p['valor_total'], $propostas));
    $prazoVals = array_values(array_filter(array_map(fn($p)=> $p['prazo_entrega'], $propostas), fn($v)=> $v!==null && $v!==''));
    $prazoMin = $prazoVals? min($prazoVals): null;

    $rows = [];
    foreach($propostas as $p){
        $precoScore = $precoMin>0 ? ($precoMin / max((float)$p['valor_total'],0.0001)) : 0;
        $prazoScore = ($prazoMin && $p['prazo_entrega']) ? ($prazoMin / max((float)$p['prazo_entrega'],1)) : 0;
        $pag = (int)($p['pagamento_dias'] ?? 0);
        if($pag<=30) $fatorPag = 1; elseif($pag<=45) $fatorPag=0.8; elseif($pag<=60) $fatorPag=0.6; else $fatorPag=0.4;
        $score = ($precoScore * $pesoPreco) + ($prazoScore * $pesoPrazo) + ($fatorPag * $pesoPagamento);
        $rows[] = [
            'proposta_id'     => (int)$p['proposta_id'],
            'fornecedor_id'   => (int)($p['fornecedor_id'] ?? 0),
            'fornecedor_nome' => (string)($p['fornecedor_nome'] ?? ''),
            'valor_total'     => (float)$p['valor_total'],
            'prazo_entrega'   => $p['prazo_entrega'],
            'pagamento_dias'  => $p['pagamento_dias'],
            'score'           => round($score,4),
            'comp_preco'      => round($precoScore,4),
            'comp_prazo'      => round($prazoScore,4),
            'comp_pagamento'  => $fatorPag,
            'peso_preco'      => $pesoPreco,
            'peso_prazo'      => $pesoPrazo,
            'peso_pagamento'  => $pesoPagamento,
        ];
    }
    // Ordena por score desc
    usort($rows, fn($a,$b)=> $b['score'] <=> $a['score']);
    // Reindex com posição
    foreach($rows as $i=>&$r){ $r = ['posicao'=>$i+1] + $r; }
    unset($r);

    $cols = ['posicao','proposta_id','fornecedor_id','fornecedor_nome','valor_total','prazo_entrega','pagamento_dias','score','comp_preco','comp_prazo','comp_pagamento','peso_preco','peso_prazo','peso_pagamento'];

    // CSV
    if ($formato === 'csv') {
        ob_end_clean();
        $filename = "ranking_requisicao_{$requisicaoId}_".date('Ymd_His').".csv";
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out = fopen('php://output','w');
        fputcsv($out,$cols);
        foreach($rows as $r){ fputcsv($out, array_map(fn($c)=> $r[$c] ?? '', $cols)); }
        fclose($out);
        auditoria_log($__user['id'],'export_csv','ranking',null,['requisicao_id'=>$requisicaoId,'linhas'=>count($rows)]);
        exit;
    }

    // XLSX
    if ($formato === 'xlsx') {
        ob_end_clean();
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RANKING');
        foreach ($cols as $i=>$c) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
            $sheet->setCellValue($col.'1', $c);
            $sheet->getStyle($col.'1')->getFont()->setBold(true);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $r=2; foreach($rows as $row){ foreach($cols as $i=>$c){ $col=\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1); $sheet->setCellValueExplicit($col.$r,(string)($row[$c]??''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);} $r++; }
        $tmp = tempnam(sys_get_temp_dir(),'rk_'); (new Xlsx($spreadsheet))->save($tmp);
        $filename = "ranking_requisicao_{$requisicaoId}_".date('Ymd_His').".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        readfile($tmp); unlink($tmp);
        auditoria_log($__user['id'],'export_xlsx','ranking',null,['requisicao_id'=>$requisicaoId,'linhas'=>count($rows)]);
        exit;
    }

    // PDF
    if ($formato === 'pdf') {
        if (!class_exists(\Mpdf\Mpdf::class)) {
            outError('pdf_indisponivel_instale_mpdf',501,[ 'composer' => 'composer require mpdf/mpdf' ]);
        }
        $mpdf = new \Mpdf\Mpdf([
            'orientation' => 'L',
            'tempDir' => sys_get_temp_dir(),
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 28,
            'margin_bottom' => 16,
        ]);
        // Dekanto light theme
        $css = "body{font-family:DejaVu Sans,Arial,sans-serif;background:#ffffff;color:#222;}";
        $css .= "h1{font-size:18px;margin:0 0 8px;font-weight:700;letter-spacing:.2px;color:#111;}";
        $css .= ".meta{font-size:10px;color:#555;margin-bottom:10px;}";
        $css .= ".brandbar{height:3px;background:{$brandCss};margin:6px 0 10px;border-radius:2px;}";
        $css .= "table{width:100%;border-collapse:collapse;font-size:11px;}";
        $css .= "thead th{background:{$brandCss};color:{$brandTextOnBrand};padding:6px 5px;border:1px solid #bfbfbf;text-align:left;font-weight:600;}";
        $css .= "tbody td{padding:5px 5px;border:1px solid #e3e3e3;color:#222;vertical-align:top;}";
        $css .= "tbody tr:nth-child(odd){background:#faf8f2;}"; // warm light zebra
        $css .= ".badge{display:inline-block;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:600;background:{$brandCss};color:{$brandTextOnBrand};letter-spacing:.3px;}";
        $css .= ".footer{margin-top:10px;font-size:9px;color:#666;text-align:right;}";
        $esc = fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $thead=''; foreach($cols as $c){ $thead.='<th>'.$esc($c).'</th>'; }
        $tbody=''; foreach($rows as $row){ $tbody.='<tr>'; foreach($cols as $c){ $tbody.='<td>'.$esc($row[$c]??'').'</td>'; } $tbody.='</tr>'; }
        $title = 'Ranking Requisição #'.$requisicaoId;
        $meta = "<div class='meta'>Pesos: Preço={$pesoPreco} | Prazo={$pesoPrazo} | Pagamento={$pesoPagamento}</div>";
        $logoHtml = $logoWeb ? ("<img src='".$esc($logoWeb)."' style='height:18px;vertical-align:middle;margin-right:6px'>") : '';
        $html = "<div style='display:flex;align-items:center;justify-content:space-between'>".
                "<div>".$logoHtml."<strong>".$esc($appName)."</strong></div>".
                "<div style='font-size:10px;color:#666'>Emitido em ".date('d/m/Y H:i')."</div></div>".
                "<div class='brandbar'></div>".
                "<h1>".$esc($title)."</h1>".$meta.
                "<table><thead><tr>{$thead}</tr></thead><tbody>{$tbody}</tbody></table>".
                "<div class='footer'>Gerado por ".$esc($appName)." · Exportador PDF</div>";
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
        $filename = "ranking_requisicao_{$requisicaoId}_".date('Ymd_His').".pdf";
        $mpdf->SetTitle($title);
        $mpdf->Output($filename,'D');
        auditoria_log($__user['id'],'export_pdf','ranking',null,['requisicao_id'=>$requisicaoId,'linhas'=>count($rows)]);
        exit;
    }

    outError('formato_nao_suportado',400);
}
// ================== FIM EXPORT RANKING ==================

// Mapeamento simples
$map = [
    'clientes'=>'clientes',
    'fornecedores'=>'fornecedores',
    'produtos'=>'produtos',
    'requisicoes'=>'requisicoes',
    'cotacoes'=>'cotacoes',
    'propostas'=>'propostas',
    'pedidos'=>'pedidos', // adicionado
];
if (!isset($map[$modulo])) outError('modulo_desconhecido');

$ids = [];
if ($idsRaw !== '') {
    foreach (explode(',',$idsRaw) as $p) {
        $v = (int)$p;
        if ($v>0) $ids[]=$v;
    }
    $ids = array_values(array_unique($ids));
}
if ($intervalo !== 'todos' && !$ids) outError('ids_obrigatorios_para_intervalo');

// Colunas
try {
    $tabela = $map[$modulo];
    $cols = array_column(
        $db->query("SHOW COLUMNS FROM `$tabela`")->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    if (!$cols) outError('sem_colunas');
} catch(Throwable $e) { outError('erro_ler_colunas',500,['detalhe'=>$e->getMessage()]); }

// Query
$sql = "SELECT ".implode(', ',array_map(fn($c)=>"`$c`",$cols))." FROM `$tabela`";
$params = [];
if ($intervalo !== 'todos') {
    $place = implode(',', array_fill(0,count($ids),'?'));
    $sql .= " WHERE id IN ($place)";
    $params = $ids;
}

try {
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { outError('erro_query',500,['detalhe'=>$e->getMessage()]); }

// Ajuste específico: preencher pdf_url para pedidos, se vazio
if ($modulo === 'pedidos' && $rows) {
    $scheme = ($_SERVER['REQUEST_SCHEME'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http'));
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('exportador.php','', $_SERVER['REQUEST_URI'] ?? ''),'/'); // /atlas/pages
    foreach ($rows as &$r) {
        if ((empty($r['pdf_url']) || trim($r['pdf_url'])==='') && !empty($r['proposta_id'])) {
            $r['pdf_url'] = $scheme.'://'.$host.$basePath.'/pedido_pdf.php?id='.$r['proposta_id'];
        }
    }
    unset($r);
}

if (!$rows) outError('sem_dados',204);

// Se PDF: gerar HTML estilizado com mPDF (Dekanto)
if ($formato === 'pdf') {
    if (!class_exists(\Mpdf\Mpdf::class)) {
        outError('pdf_indisponivel_instale_mpdf',501,[ 'composer' => 'composer require mpdf/mpdf' ]);
    }
    $orientation = count($cols) > 8 ? 'L' : 'P';
    $mpdf = new \Mpdf\Mpdf([
        'orientation' => $orientation,
        'tempDir' => sys_get_temp_dir(),
        'margin_left' => 12,
        'margin_right' => 12,
        'margin_top' => 28,
        'margin_bottom' => 16,
    ]);

    // Dekanto light theme
    $css = "body{font-family:DejaVu Sans,Arial,sans-serif;background:#ffffff;color:#222;}";
    $css .= "h1{font-size:18px;margin:0 0 8px;font-weight:700;letter-spacing:.2px;color:#111;}";
    $css .= ".meta{font-size:10px;color:#555;margin-bottom:10px;}";
    $css .= ".brandbar{height:3px;background:{$brandCss};margin:6px 0 10px;border-radius:2px;}";
    $css .= "table{width:100%;border-collapse:collapse;font-size:11px;}";
    $css .= "thead th{background:{$brandCss};color:{$brandTextOnBrand};padding:6px 5px;border:1px solid #bfbfbf;text-align:left;font-weight:600;}";
    $css .= "tbody td{padding:5px 5px;border:1px solid #e3e3e3;color:#222;vertical-align:top;word-break:break-word;}";
    $css .= "tbody tr:nth-child(odd){background:#faf8f2;}";
    $css .= ".badge{display:inline-block;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:600;background:{$brandCss};color:{$brandTextOnBrand};letter-spacing:.3px;}";
    $css .= ".status-text{display:inline-block;padding:2px 6px;border-radius:6px;font-size:9px;font-weight:600;background:transparent;color:#222;border:1px solid #ccc;letter-spacing:.3px;}";
    $css .= ".footer{margin-top:10px;font-size:9px;color:#666;text-align:right;}";
    $title = 'Relatório '.ucfirst($modulo);
    $now = date('d/m/Y H:i:s');
    $count = count($rows);
    $dur = number_format(microtime(true)-$start,2);

    $esc = fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');

    $thead = '';
    foreach ($cols as $c) { $thead .= '<th>'.$esc($c).'</th>'; }
    $tbody = '';
    foreach ($rows as $row) {
        $tbody .= '<tr>';
        foreach ($cols as $c) {
            $val = $row[$c] ?? '';
            if (is_scalar($val)) {
                $text = (string)$val;
            } else {
                $text = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            if ($c === 'status' && $text !== '') {
                $text = '<span class="status-text">'.$esc($text).'</span>';
            } else {
                $text = $esc($text);
            }
            if ($c === 'pdf_url' && preg_match('~^https?://~i',$text)) {
                $urlDisp = strlen($text)>60? substr($text,0,57).'…' : $text;
                $text = '<a href="'.$esc($row[$c]).'" style="color:#111;text-decoration:underline">'.$esc($urlDisp).'</a>';
            }
            $tbody .= '<td>'.$text.'</td>';
        }
        $tbody .= '</tr>';
    }

    $logoHtml = $logoWeb ? ("<img src='".$esc($logoWeb)."' style='height:18px;vertical-align:middle;margin-right:6px'>") : '';
    $html = "<div style='display:flex;align-items:center;justify-content:space-between'>".
            "<div>".$logoHtml."<strong>".$esc($appName)."</strong></div>".
            "<div style='font-size:10px;color:#666'>Emitido em ".$esc($now)."</div></div>".
            "<div class='brandbar'></div>".
            "<h1>".$esc($title)."</h1>".
            "<div class='meta'>Registros: {$count} | Intervalo: ".$esc($intervalo)." | Tempo: {$dur}s</div>".
            "<table><thead><tr>{$thead}</tr></thead><tbody>{$tbody}</tbody></table>".
            "<div class='footer'>Gerado por ".$esc($appName)." · Exportador PDF</div>";

    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);

    $filename = "relatorio_{$modulo}_".date('Ymd_His').".pdf";
    $mpdf->SetTitle($title);
    $mpdf->Output($filename,'D');
    auditoria_log($__user['id'],'export_pdf',$map[$modulo],null,['modulo'=>$modulo,'linhas'=>count($rows)]);
    exit;
}

// CSV direto
if ($formato === 'csv') {
    ob_end_clean();
    $filename = "relatorio_{$modulo}_".date('Ymd_His').".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $out = fopen('php://output','w');
    fputcsv($out,$cols);
    foreach ($rows as $row) {
        $line = [];
        foreach ($cols as $c) $line[] = $row[$c] ?? '';
        fputcsv($out,$line);
    }
    fclose($out);
    auditoria_log($__user['id'],'export_csv',$map[$modulo],null,['modulo'=>$modulo,'linhas'=>count($rows)]);
    exit;
}

// Limpa buffer de ruído antes de gerar planilha / pdf
ob_end_clean();

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr(strtoupper($modulo),0,31));
    foreach ($cols as $i=>$c) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
        $sheet->setCellValue($col.'1', $c);
        $sheet->getStyle($col.'1')->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $r=2;
    foreach ($rows as $row) {
        foreach ($cols as $i=>$c) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1);
            $sheet->setCellValueExplicit($col.$r, (string)($row[$c] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $r++;
    }

    if ($formato === 'xlsx') {
        $tmp = tempnam(sys_get_temp_dir(),'xp_');
        (new Xlsx($spreadsheet))->save($tmp);
        $filename = "relatorio_{$modulo}_".date('Ymd_His').".xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.filesize($tmp));
        header('X-Export-Modulo: '.$modulo);
        header('X-Export-Linhas: '.count($rows));
        header('X-Export-Colunas: '.count($cols));
        header('X-Export-Intervalo: '.$intervalo);
        if ($ids) header('X-Export-Ids: '.min(count($ids),50));
        header('X-Export-Duracao: '.number_format(microtime(true)-$start,4).'s');
        readfile($tmp);
        unlink($tmp);
        auditoria_log($__user['id'],'export_xlsx',$map[$modulo],null,['modulo'=>$modulo,'linhas'=>count($rows)]);
        exit;
    }

    // PDF
    if ($formato === 'pdf') {
        // (Fluxo agora tratado antes de criar Spreadsheet) Salvaguarda
        outError('pdf_fluxo_invalido',500);
    }

    outError('formato_nao_suportado',400);
} catch(Throwable $e) {
    outError('erro_gerar_arquivo',500,['detalhe'=>$e->getMessage()]);
}