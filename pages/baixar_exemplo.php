<?php
ob_start(); // captura saída acidental
if (headers_sent($f,$l)) {
    http_response_code(500);
    echo "Falha: saída prematura em $f:$l";
    exit;
}
session_start();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('Acesso negado.');
}

require_once __DIR__ . '/../includes/db.php';
require __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$modulo  = strtolower($_GET['modulo']  ?? '');
$formato = strtolower($_GET['formato'] ?? 'csv');

$exemplos = [
    'clientes' => [
        'headers' => ['razao_social','nome_fantasia','cnpj','ie','endereco','email','telefone','status'],
        'rows' => [
            ['Empresa Alpha Ltda','Alpha','12.345.678/0001-90','123456789','Rua A, 100','contato@alpha.com','(11) 90000-0000','ativo'],
            ['Empresa Beta SA','Beta','98.765.432/0001-10','987654321','Av. B, 200','vendas@beta.com','(21) 98888-7777','inativo'],
        ],
    ],
    'fornecedores' => [
        'headers' => ['razao_social','nome_fantasia','cnpj','ie','endereco','email','telefone','status'],
        'rows' => [
            ['Fornecedor Exemplo Ltda','FornEx','11.222.333/0001-44','1122334455','Rua Central, 10','comercial@fornex.com','(31) 95555-4444','ativo'],
        ],
    ],
    'produtos' => [
        'headers' => ['nome','descricao','ncm','unidade','preco_base'],
        'rows' => [
            ['Parafuso Sextavado M12','Parafuso aço zincado','7318.15.00','UN','1.25'],
            ['Arruela Lisa 1/2','Arruela aço','7318.21.00','UN','0.15'],
        ],
    ],
    'requisicoes' => [
        // Items são importados separadamente (API de itens), aqui só a requisição
        'headers' => ['titulo','cliente_id','status'],
        'rows' => [
            ['Compra material escritório', '1', 'aberta'],
            ['Reposição ferramentas', '2', 'aberta'],
        ],
    ],
];

if (!isset($exemplos[$modulo])) {
    http_response_code(400);
    exit('Modulo invalido.');
}
if (!in_array($formato, ['csv','xlsx'], true)) {
    http_response_code(400);
    exit('Formato invalido.');
}

$headers = $exemplos[$modulo]['headers'];
$rows    = $exemplos[$modulo]['rows'];
$filename = "exemplo_{$modulo}." . $formato;

if ($formato === 'csv') {
    while (ob_get_level()>1) @ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    $out = fopen('php://output','w');
    fputcsv($out, $headers, ',');
    foreach ($rows as $r) fputcsv($out, $r, ',');
    fclose($out);
    exit;
}

// XLSX
while (ob_get_level()>1) @ob_end_clean();
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(ucfirst($modulo));
$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col.'1', $h);
    $sheet->getColumnDimension($col)->setAutoSize(true);
    $col++;
}
$sheet->getStyle('A1:' . chr(ord('A')+count($headers)-1).'1')->getFont()->setBold(true);
$rowNum = 2;
foreach ($rows as $r) {
    $col = 'A';
    foreach ($r as $val) {
        $sheet->setCellValue($col.$rowNum, $val);
        $col++;
    }
    $rowNum++;
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;