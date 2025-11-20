<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fpdf.php';
require_once __DIR__ . '/../includes/branding.php';

$proposta_id = $_GET['id'] ?? null;
if (!$proposta_id) { die('ID da proposta não informado.'); }

$db = get_db_connection();

// Verificar dinamicamente se a coluna tipo_frete existe (para evitar erro se migration não aplicada)
$hasTipoFreteCol = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM cotacoes LIKE 'tipo_frete'");
    if ($colCheck && $colCheck->rowCount() > 0) { $hasTipoFreteCol = true; }
} catch (Exception $e) { /* silencioso */ }

// Buscar proposta + dados fornecedor (incluindo tipo_frete se existir)
$sql = 'SELECT p.*, c.requisicao_id' . ($hasTipoFreteCol ? ', c.tipo_frete' : '') . ', f.razao_social, f.nome_fantasia, f.cnpj, f.ie, f.endereco, f.telefone, f.email FROM propostas p JOIN cotacoes c ON p.cotacao_id = c.id JOIN fornecedores f ON f.id = p.fornecedor_id WHERE p.id = ?';
$stmt = $db->prepare($sql);
$stmt->execute([$proposta_id]);
$proposta = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$proposta) { die('Proposta não encontrada.'); }
if (!$hasTipoFreteCol) { $proposta['tipo_frete'] = null; }

// Buscar itens (agora incluindo NCM)
$stmt = $db->prepare('SELECT pi.*, pr.nome, pr.unidade, pr.ncm FROM proposta_itens pi JOIN produtos pr ON pi.produto_id = pr.id WHERE pi.proposta_id = ? ORDER BY pr.nome');
$stmt->execute([$proposta_id]);
$itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Carregar dados do cliente via requisicao
$cliente = null;
if ($proposta) {
    $stCli = $db->prepare('SELECT cli.* FROM requisicoes r JOIN clientes cli ON cli.id = r.cliente_id WHERE r.id = ? LIMIT 1');
    $stCli->execute([$proposta['requisicao_id']]);
    $cliente = $stCli->fetch(PDO::FETCH_ASSOC);
}

// Carregar configurações comerciais (pagamento / frete / impostos)
$cond_pag = $frete_modalidade = $impostos_info = null;
try {
    $cfgKeys = ['cond_pagamento','frete_modalidade','impostos_info'];
    $in = str_repeat('?,', count($cfgKeys)-1) . '?';
    $stCfg = $db->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ($in)");
    $stCfg->execute($cfgKeys);
    $cfg = [];
    foreach($stCfg->fetchAll(PDO::FETCH_ASSOC) as $row){ $cfg[$row['chave']] = trim($row['valor']); }
    $cond_pag = $cfg['cond_pagamento'] ?? null;
    $frete_modalidade = $cfg['frete_modalidade'] ?? null;
    $impostos_info = $cfg['impostos_info'] ?? null;
} catch(Exception $e) { /* silencioso */ }

class PDFPedido extends FPDF {
    public $titulo;
    // Branding
    public $brandColor = [181,168,134]; // Dekanto #B5A886
    public $brandTextOnBrand = [0,0,0];
    public $logoPath = null;

    function Header() {
        // Logo (reduzida novamente)
        if ($this->logoPath && file_exists($this->logoPath)) {
            $logoW = 9; // antes 12
            $this->Image($this->logoPath, 10, 8, $logoW);
        }
        // Título
        $this->SetFont('Arial','B',14);
        $this->SetTextColor(45,45,45);
        $this->Cell(0,6, utf8_decode($this->titulo),0,1,'R');
        $this->SetFont('Arial','',9);
        $this->SetTextColor(80,80,80);
        $this->Cell(0,5, utf8_decode('Emitido em: ').date('d/m/Y H:i'),0,1,'R');
        $this->Ln(2);
        $this->SetDrawColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetLineWidth(0.3);
        $this->Line(10, $this->GetY(), $this->GetPageWidth()-10, $this->GetY());
        $this->Ln(4);
        $this->SetTextColor(0,0,0);
    }
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetDrawColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetLineWidth(0.2);
        $this->Line(10, $this->GetY()-2, $this->GetPageWidth()-10, $this->GetY()-2);
        $this->SetTextColor(90,90,90);
        $this->Cell(0,8, utf8_decode('Página ').$this->PageNo().'/{nb}',0,0,'C');
    }
    // NOVO: função para duas colunas com quebra automática
    function Row2Cols($leftText, $rightText, $wLeft=95, $wRight=95, $lineH=5) {
        $linesL = $this->NbLines($wLeft, utf8_decode($leftText));
        $linesR = $this->NbLines($wRight, utf8_decode($rightText));
        $h = max($linesL, $linesR) * $lineH;
        $this->CheckPageBreak($h);
        $x = $this->GetX(); $y = $this->GetY();
        $this->MultiCell($wLeft, $lineH, utf8_decode($leftText), 0, 'L');
        $this->SetXY($x + $wLeft, $y);
        $this->MultiCell($wRight, $lineH, utf8_decode($rightText), 0, 'L');
        $this->SetXY($x, $y + $h);
    }
    function FancyTable($header, $data, $widths) {
        // Header with brand color
        $this->SetFillColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetTextColor($this->brandTextOnBrand[0],$this->brandTextOnBrand[1],$this->brandTextOnBrand[2]);
        $this->SetDrawColor(120,120,120);
        $this->SetLineWidth(.3);
        $this->SetFont('Arial','B',9);
        foreach ($header as $i=>$col) {
            $this->Cell($widths[$i],7,utf8_decode($col),1,0,'C',true);
        }
        $this->Ln();
        // Corpo
        $this->SetFillColor(248,246,240);
        $this->SetTextColor(0);
        $this->SetFont('Arial','',9);
        $fill=false;
        foreach ($data as $row) {
            $nome = utf8_decode($row['nome']);
            $linesProduto = $this->NbLines($widths[1], $nome);
            $h = max($linesProduto * 5, 5);
            $this->CheckPageBreak($h);
            $xStart = $this->GetX();
            $yStart = $this->GetY();
            // Código
            $this->Cell($widths[0], $h, $row['produto_id'], 'LR', 0, 'C', $fill);
            // Produto (MultiCell)
            $this->SetXY($xStart + $widths[0], $yStart);
            $this->MultiCell($widths[1],5,$nome,'LR','L',$fill);
            $yAfterNome = $this->GetY();
            $rowHeight = $yAfterNome - $yStart;
            if ($rowHeight < $h) {
                // preenche espaço restante na coluna de produto
                $this->SetXY($xStart + $widths[0], $yStart + $h);
            }
            // NCM
            $this->SetXY($xStart + $widths[0] + $widths[1], $yStart);
            $this->Cell($widths[2], $h, utf8_decode($row['ncm'] ?: '-'), 'LR', 0, 'C', $fill);
            // Quantidade
            $this->Cell($widths[3], $h, number_format($row['quantidade'],2,',','.'), 'LR', 0, 'C', $fill);
            // Unidade
            $this->Cell($widths[4], $h, utf8_decode($row['unidade']), 'LR', 0, 'C', $fill);
            // Preço Unit
            $this->Cell($widths[5], $h, 'R$ '.number_format($row['preco_unitario'],2,',','.'), 'LR', 0, 'R', $fill);
            // Subtotal
            $subtotal = $row['preco_unitario'] * $row['quantidade'];
            $this->Cell($widths[6], $h, 'R$ '.number_format($subtotal,2,',','.'), 'LR', 0, 'R', $fill);
            $this->Ln();
            $fill = !$fill;
        }
        $this->Cell(array_sum($widths),0,'','T');
        $this->Ln(2);
    }
    function CheckPageBreak($h) {
        if($this->GetY()+$h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }
    function NbLines($w,$txt) {
        $cw = $this->CurrentFont['cw'];
        if($w==0)
            $w = $this->w-$this->rMargin-$this->x;
        $wmax = ($w-2*$this->cMargin)*1000/$this->FontSize;
        $s = str_replace("\r",'',(string)$txt);
        $nb = strlen($s);
        if($nb>0 && $s[$nb-1]=="\n")
            $nb--;
        $sep = -1;
        $i = 0; $j = 0; $l = 0; $nl = 1;
        while($i<$nb){
            $c = $s[$i];
            if($c=="\n"){
                $i++; $sep=-1; $j=$i; $l=0; $nl++; continue;
            }
            if($c==' ')
                $sep=$i;
            $l += $cw[$c] ?? 0;
            if($l>$wmax){
                if($sep==-1){
                    if($i==$j) $i++;
                } else {
                    $i=$sep+1;
                }
                $sep=-1; $j=$i; $l=0; $nl++;
            } else { $i++; }
        }
        return $nl;
    }
}

// Helpers branding
$brandHex = $branding['primary_color'] ?? '#B5A886';
$hex = ltrim((string)$brandHex,'#');
if(strlen($hex)===3){ $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
$int = hexdec(preg_replace('/[^0-9a-fA-F]/','',$hex));
$brandRGB = [($int>>16)&255, ($int>>8)&255, $int&255];
$luminance = 0.2126*$brandRGB[0] + 0.7152*$brandRGB[1] + 0.0722*$brandRGB[2];
$brandTextOnBrand = ($luminance > 150) ? [0,0,0] : [255,255,255];
// Resolve logo
$logoCandidates = [];
if (!empty($branding['logo'])) {
    $logoCandidates[] = __DIR__ . '/../' . ltrim($branding['logo'],'/');
}
$logoCandidates[] = __DIR__ . '/../assets/images/logo_dekanto.png';
$logoCandidates[] = __DIR__ . '/../assets/images/logo_site.jpg';
$resolvedLogo = null;
foreach ($logoCandidates as $cand) { if (is_string($cand) && file_exists($cand)) { $resolvedLogo = $cand; break; } }

$pdf = new PDFPedido();
$pdf->AliasNbPages();
$pdf->titulo = 'Pedido de Compra - ' . ($branding['app_name'] ?? 'Dekanto');
$pdf->brandColor = $brandRGB;
$pdf->brandTextOnBrand = $brandTextOnBrand;
$pdf->logoPath = $resolvedLogo;
$pdf->AddPage();

// Cabeçalho de informações gerais
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,6,utf8_decode('Dados da Proposta / Pedido'),0,1,'L');
$pdf->SetFont('Arial','',9);
$linha1 = [
    'Proposta ID: '.$proposta_id,
    'Requisição: '.$proposta['requisicao_id'],
    'Fornecedor ID: '.$proposta['fornecedor_id'],
    'Status: '.utf8_decode($proposta['status'])
];
foreach ($linha1 as $txt) { $pdf->Cell(48,5,utf8_decode($txt),0,0,'L'); }
$pdf->Ln();
$pdf->Cell(48,5,utf8_decode('Prazo Entrega: '.($proposta['prazo_entrega']??' - ').' dias'),0,0,'L');
$pdf->Cell(48,5,utf8_decode('Pagamento: '.(isset($proposta['pagamento_dias']) && $proposta['pagamento_dias']!==null ? $proposta['pagamento_dias'].' dias' : '-')),0,0,'L'); // novo campo
$pdf->Cell(48,5,utf8_decode('Valor Total: R$ '.number_format($proposta['valor_total'],2,',','.')),0,0,'L');
$pdf->Cell(96,5,utf8_decode('Emitido: '.date('d/m/Y H:i')),0,1,'L');
$pdf->Ln(3);

// Dados do fornecedor
$pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Fornecedor'),0,1,'L');
$pdf->SetFont('Arial','',9);
// ALTERADO: usar duas colunas com quebra automática
$pdf->Row2Cols('Razão Social: '.($proposta['razao_social'] ?: '-'), 'Fantasia: '.($proposta['nome_fantasia'] ?: '-'));
$pdf->Cell(95,5,utf8_decode('CNPJ: '.($proposta['cnpj'] ?: '-')),0,0,'L');
$pdf->Cell(95,5,utf8_decode('IE: '.($proposta['ie'] ?: '-')),0,1,'L');
$pdf->MultiCell(0,5,utf8_decode('Endereço: '.($proposta['endereco'] ?: '-')));
$pdf->MultiCell(0,5,utf8_decode('Contacto: '.($proposta['telefone'] ?: '-').'  /  '.($proposta['email'] ?: '-')));
$pdf->Ln(2);

// Dados do cliente
if($cliente){
    $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Cliente (Faturamento)'),0,1,'L');
    $pdf->SetFont('Arial','',9);
    // ALTERADO: usar duas colunas com quebra automática
    $pdf->Row2Cols('Razão Social: '.($cliente['razao_social'] ?: '-'), 'Fantasia: '.($cliente['nome_fantasia'] ?: '-'));
    $pdf->Cell(95,5,utf8_decode('CNPJ: '.($cliente['cnpj'] ?: '-')),0,0,'L');
    $pdf->Cell(95,5,utf8_decode('IE: '.($cliente['ie'] ?: '-')),0,1,'L');
    $pdf->MultiCell(0,5,utf8_decode('Endereço: '.($cliente['endereco'] ?: '-')));
    $pdf->MultiCell(0,5,utf8_decode('Contacto: '.(($cliente['telefone'] ?: '-').' / '.($cliente['email'] ?: '-'))));
    $pdf->Ln(2);
}

// Seção: Condições Comerciais (pagamento / frete / impostos)
if($cond_pag || $frete_modalidade || $impostos_info || !empty($proposta['tipo_frete'])) {
    $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Condições Comerciais'),0,1,'L');
    $pdf->SetFont('Arial','',9);
    if($cond_pag) { $pdf->MultiCell(0,5,utf8_decode('Pagamento: '.$cond_pag)); }
    // Prioriza tipo_frete da cotação se existir
    if(!empty($proposta['tipo_frete'])) {
        $pdf->MultiCell(0,5,utf8_decode('Frete (Cotação): '.$proposta['tipo_frete']));
        // Se houver configuração global diferente, mostra também
        if($frete_modalidade && strtoupper(trim($frete_modalidade)) !== strtoupper(trim($proposta['tipo_frete']))) {
            $pdf->MultiCell(0,5,utf8_decode('Frete (Padrão): '.$frete_modalidade));
        }
    } elseif($frete_modalidade) { $pdf->MultiCell(0,5,utf8_decode('Frete: '.$frete_modalidade)); }
    if($impostos_info) { $pdf->MultiCell(0,5,utf8_decode('Impostos: '.$impostos_info)); }
    $pdf->Ln(2);
}

// Tabela de Itens
$pdf->SetFont('Arial','B',11);
$pdf->Cell(0,6,utf8_decode('Itens do Pedido'),0,1,'L');
$pdf->Ln(1);
$pageWidth = $pdf->GetPageWidth();
$leftX = $pdf->GetX();
$margin = $leftX; 
$larguraTotal = $pageWidth - ($margin * 2);
// Novas larguras: Código, Produto, NCM, Qtd, Un, Preço, Subtotal
$widths = [18,60,20,18,15,28,31];
if (abs(array_sum($widths) - $larguraTotal) > 0.5) {
    $factor = $larguraTotal / array_sum($widths);
    $widths = array_map(function($w) use ($factor){ return round($w*$factor,2); }, $widths);
    $diff = $larguraTotal - array_sum($widths);
    if(abs($diff) >= 0.01) { $widths[1] += $diff; }
}
$header = ['Código','Produto','NCM','Qtd','Un','Preço Unit.','Subtotal'];
$pdf->FancyTable($header,$itens,$widths);

// Total
$total = 0; foreach($itens as $i){ $total += $i['preco_unitario']*$i['quantidade']; }
$pdf->SetFont('Arial','B',11);
$pdf->Cell($widths[0]+$widths[1]+$widths[2]+$widths[3]+$widths[4]+$widths[5],8,utf8_decode('Total Geral'),0,0,'R');
$pdf->Cell($widths[6],8,'R$ '.number_format($total,2,',','.'),0,1,'R');
$pdf->Ln(5);

// Observações
if(!empty($proposta['observacoes'])) {
    $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Observações'),0,1,'L');
    $pdf->SetFont('Arial','',9);
    $pdf->MultiCell(0,5,utf8_decode($proposta['observacoes']));
    $pdf->Ln(3);
}

// Área de assinatura
$pdf->Ln(10);
$pdf->SetFont('Arial','',9);
$pdf->Cell(95,8,utf8_decode('__________________________________'),0,0,'C');
$pdf->Cell(0,8,utf8_decode('__________________________________'),0,1,'C');
$pdf->Cell(95,5,utf8_decode('Emitente'),0,0,'C');
$pdf->Cell(0,5,utf8_decode('Fornecedor'),0,1,'C');

// Branding setup
$brandHex = $branding['primary_color'] ?? '#B5A886';
$hex = ltrim((string)$brandHex,'#');
if(strlen($hex)===3){ $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
$int = hexdec(preg_replace('/[^0-9a-fA-F]/','',$hex));
$brandRGB = [($int>>16)&255, ($int>>8)&255, $int&255];
$luminance = 0.2126*$brandRGB[0] + 0.7152*$brandRGB[1] + 0.0722*$brandRGB[2];
$brandTextOnBrand = ($luminance > 150) ? [0,0,0] : [255,255,255];

$pdf->Output('D','pedido_'.$proposta_id.'.pdf');
exit;