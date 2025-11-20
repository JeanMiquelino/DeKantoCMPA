<?php
session_start();
// Security headers for authenticated download endpoint
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
// Generally do not cache export responses (except attachments streaming below)
header('Cache-Control: no-store');
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/timeline.php'; // para normalize_event_type

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment; // added for wrap and vertical alignment

// Only POST
$__method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($__method !== 'POST') { http_response_code(405); header('Allow: POST'); echo 'Método não suportado'; exit; }

$modulo  = $_POST['modulo'] ?? '';
$formato = strtolower($_POST['formato'] ?? 'csv');
$intervalo = $_POST['intervalo'] ?? 'todos';
$idsRaw = trim($_POST['ids'] ?? '');

$modulos = [
    'clientes' => [ 'tabela' => 'clientes', 'colunas' => ['id','razao_social','nome_fantasia','cnpj','ie','endereco','email','telefone','status','created_at'] ],
    'fornecedores' => [ 'tabela' => 'fornecedores', 'colunas' => ['id','razao_social','nome_fantasia','cnpj','ie','endereco','email','telefone','status','created_at'] ],
    'produtos' => [ 'tabela' => 'produtos', 'colunas' => ['id','nome','descricao','ncm','unidade','preco_base','created_at'] ],
    'cotacoes' => [ 'tabela' => 'cotacoes', 'colunas' => ['id','requisicao_id','rodada','status','tipo_frete','token','token_expira_em','criado_em'] ],
    // Novo: export de timeline (histórico) por requisicao
    // Uso: modulo=timeline, requisicao_id=123
    'timeline' => [ 'especial' => true ]
];

$formatosPermitidos = ['csv','xlsx','pdf'];
if (!isset($modulos[$modulo])) { http_response_code(400); echo "Módulo inválido."; exit; }
if (!in_array($formato, $formatosPermitidos, true)) { http_response_code(400); echo "Formato inválido."; exit; }

$db = get_db_connection();
$registos = null;

if ($modulo === 'timeline') {
    $requisicaoId = (int)($_POST['requisicao_id'] ?? 0);
    if(!$requisicaoId){ http_response_code(400); echo "requisicao_id obrigatório para timeline."; exit; }
    // Verificar se tabela existe
    $hasTl = $db->query("SHOW TABLES LIKE 'requisicoes_timeline'")->fetch();
    if(!$hasTl){ http_response_code(501); echo "Timeline indisponível."; exit; }
    $st = $db->prepare('SELECT id, requisicao_id, tipo_evento, descricao, dados_antes, dados_depois, usuario_id, ip_origem, criado_em FROM requisicoes_timeline WHERE requisicao_id=? ORDER BY id ASC');
    $st->execute([$requisicaoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Montar registros exportáveis com tipo canônico
    $registos = array_map(function($r){
        return [
            'id' => (int)$r['id'],
            'requisicao_id' => (int)$r['requisicao_id'],
            'tipo' => normalize_event_type((string)$r['tipo_evento']),
            'descricao' => (string)$r['descricao'],
            'dados_antes' => $r['dados_antes'],
            'dados_depois' => $r['dados_depois'],
            'usuario_id' => $r['usuario_id'],
            'ip_origem' => $r['ip_origem'],
            'criado_em' => $r['criado_em']
        ];
    }, $rows);
} else {
    $cfg = $modulos[$modulo];
    $params=[]; $where='';
    if ($idsRaw !== '') {
        $ids = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $idsRaw))), fn($v)=>$v>0));
        if(!$ids){ http_response_code(400); echo "Nenhum ID válido fornecido."; exit; }
        $place = implode(',', array_fill(0,count($ids),'?'));
        $where = "WHERE id IN ($place)"; $params=$ids;
    }
    $sql = "SELECT ".implode(',', $cfg['colunas'])." FROM {$cfg['tabela']} $where ORDER BY id ASC";
    $stmt=$db->prepare($sql); $stmt->execute($params); $registos=$stmt->fetchAll(PDO::FETCH_ASSOC);
}

if(!$registos){ http_response_code(204); echo "Sem dados para exportar."; exit; }

$timestamp=date('Ymd_His'); $filenameBase="export_{$modulo}_{$timestamp}"; $filename=$filenameBase.'.'.$formato;

if($formato==='csv'){
    header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"$filename\""); $out=fopen('php://output','w'); fputcsv($out,array_keys($registos[0])); foreach($registos as $linha){ fputcsv($out,$linha);} fclose($out); exit;
}

$spreadsheet=new Spreadsheet(); $sheet=$spreadsheet->getActiveSheet(); $sheet->setTitle(ucfirst($modulo));
// Cabeçalhos
$headers = array_keys($registos[0]);
$colIndex=1; foreach($headers as $header){ $col=Coordinate::stringFromColumnIndex($colIndex); $sheet->setCellValue($col.'1',$header); $sheet->getStyle($col.'1')->getFont()->setBold(true); $colIndex++; }
// Dados
$rowNumber=2; foreach($registos as $linha){ $colIndex=1; foreach($linha as $valor){ $col=Coordinate::stringFromColumnIndex($colIndex); $sheet->setCellValue($col.$rowNumber,$valor); $colIndex++; } $rowNumber++; }

// Ajustes de layout para evitar sobreposição de texto em PDF
$highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
$lastDataRow = $rowNumber - 1; // última linha preenchida

// Habilitar quebra de linha e alinhamento superior por padrão nas células da área usada
$usedRange = 'A1:' . Coordinate::stringFromColumnIndex($highestColIndex) . $lastDataRow;
$sheet->getStyle($usedRange)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
// Garantir altura de linha automática para suportar múltiplas linhas
$sheet->getDefaultRowDimension()->setRowHeight(-1);

// Definir largura controlada para colunas que costumam ter textos longos (empresa/nome/descrição)
$provavelTextoLongo = ['razao_social','nome_fantasia','cliente','fornecedor','empresa','nome','descricao','endereco','produto','proposta','pedido','titulo'];
$larguraTextoLongo = 50; // em caracteres aproximadamente

// Primeiro, auto largura para uma base geral
for($i=1;$i<=$highestColIndex;$i++){ $col=Coordinate::stringFromColumnIndex($i); $sheet->getColumnDimension($col)->setAutoSize(true); }

// Em seguida, sobrescrever colunas de texto longo para largura fixa com wrap
foreach ($headers as $idx => $h) {
    if (in_array(strtolower($h), $provavelTextoLongo, true)) {
        $col = Coordinate::stringFromColumnIndex($idx + 1);
        $sheet->getColumnDimension($col)->setAutoSize(false);
        $sheet->getColumnDimension($col)->setWidth($larguraTextoLongo);
        // Wrap já aplicado em $usedRange; reforçar nesta coluna
        $sheet->getStyle($col.'1:'.$col.$lastDataRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }
}

if($formato==='xlsx'){ header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header("Content-Disposition: attachment; filename=\"$filename\""); $writer=new Xlsx($spreadsheet); $writer->save('php://output'); exit; }
if($formato==='pdf'){
    if(!class_exists(\Mpdf\Mpdf::class)){ http_response_code(501); echo "Exportação PDF indisponível (dependência mpdf não instalada)."; exit; }
    IOFactory::registerWriter('Pdf', \PhpOffice\PhpSpreadsheet\Writer\Pdf\Mpdf::class); header('Content-Type: application/pdf'); header("Content-Disposition: attachment; filename=\"$filename\""); $pdfWriter=IOFactory::createWriter($spreadsheet,'Pdf'); $pdfWriter->save('php://output'); exit; }
http_response_code(400); echo "Formato não suportado.";
