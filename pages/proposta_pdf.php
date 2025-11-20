<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/fpdf.php';
require_once __DIR__ . '/../includes/branding.php';

$proposta_id = $_GET['id'] ?? null;
if (!$proposta_id) { die('ID da proposta não informado.'); }

$db = get_db_connection();
// Proposta + fornecedor + cotacao (inclui tipo_frete) + requisicao + cliente
$stmt = $db->prepare('SELECT p.*, c.requisicao_id, c.tipo_frete, f.razao_social AS forn_razao, f.nome_fantasia AS forn_fantasia, f.cnpj AS forn_cnpj, f.ie AS forn_ie, f.endereco AS forn_endereco, f.telefone AS forn_tel, f.email AS forn_email FROM propostas p JOIN cotacoes c ON p.cotacao_id = c.id JOIN fornecedores f ON f.id = p.fornecedor_id WHERE p.id = ?');
$stmt->execute([$proposta_id]);
$prop = $stmt->fetch(PDO::FETCH_ASSOC);
if(!$prop){ die('Proposta não encontrada.'); }

// Itens
$stItens = $db->prepare('SELECT pi.*, pr.nome, pr.unidade, pr.ncm FROM proposta_itens pi JOIN produtos pr ON pi.produto_id = pr.id WHERE pi.proposta_id = ? ORDER BY pr.nome');
$stItens->execute([$proposta_id]);
$itens = $stItens->fetchAll(PDO::FETCH_ASSOC);

// Configurações comerciais
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
} catch(Exception $e) { }

// Dados da consultoria (carregados das configurações gerais via branding.php)
$consultoria = [
    'razao_social' => trim((string)($branding['consultoria.razao_social'] ?? '')),
    'nome_fantasia' => trim((string)($branding['consultoria.nome_fantasia'] ?? '')),
    'cnpj' => trim((string)($branding['consultoria.cnpj'] ?? '')),
    'ie' => trim((string)($branding['consultoria.ie'] ?? '')),
    'endereco' => trim((string)($branding['consultoria.endereco'] ?? '')),
    'telefone' => trim((string)($branding['consultoria.telefone'] ?? '')),
    'email' => trim((string)($branding['consultoria.email'] ?? '')),
];
if($consultoria['razao_social'] === '') { $consultoria['razao_social'] = $branding['app_name'] ?? 'Consultoria de Compras'; }
if($consultoria['nome_fantasia'] === '') { $consultoria['nome_fantasia'] = $branding['app_name'] ?? 'Consultoria'; }
if($consultoria['cnpj'] === '') { $consultoria['cnpj'] = '-'; }
if($consultoria['ie'] === '') { $consultoria['ie'] = '-'; }
if($consultoria['endereco'] === '') { $consultoria['endereco'] = 'Não informado'; }
if($consultoria['telefone'] === '') { $consultoria['telefone'] = '-'; }
if($consultoria['email'] === '') {
    $consultoria['email'] = trim((string)($branding['mail.from'] ?? (defined('SMTP_FROM') ? SMTP_FROM : 'contato@consultoria.com')));
}

class PDFProposta extends FPDF {
    public $titulo;
    // Branding
    public $brandColor = [181,168,134]; // Dekanto default #B5A886
    public $brandTextOnBrand = [0,0,0]; // black on light brand
    public $logoPath = null;

    function Header(){
        if($this->logoPath && file_exists($this->logoPath)) { 
            $logoW = 9; // antes 12
            $this->Image($this->logoPath,10,8,$logoW); 
        }
        // Title
        $this->SetFont('Arial','B',14);
        $this->SetTextColor(45,45,45);
        $this->Cell(0,6,utf8_decode($this->titulo),0,1,'R');
        $this->SetFont('Arial','',9);
        $this->SetTextColor(80,80,80);
        $this->Cell(0,5,utf8_decode('Emitido em: ').date('d/m/Y H:i'),0,1,'R');
        $this->Ln(2);
        // Brand divider
        $this->SetDrawColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetLineWidth(0.3);
        $this->Line(10,$this->GetY(),$this->GetPageWidth()-10,$this->GetY());
        $this->Ln(4);
        // Reset text color
        $this->SetTextColor(0,0,0);
    }
    function Footer(){
        $this->SetY(-15);
        $this->SetFont('Arial','I',8);
        $this->SetDrawColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetLineWidth(0.2);
        $this->Line(10,$this->GetY()-2,$this->GetPageWidth()-10,$this->GetY()-2);
        $this->SetTextColor(90,90,90);
        $this->Cell(0,8,utf8_decode('Página ').$this->PageNo().'/{nb}',0,0,'C');
    }
    function CheckPageBreak($h){ if($this->GetY()+$h > $this->PageBreakTrigger) $this->AddPage($this->CurOrientation); }
    function NbLines($w,$txt){
        $cw=$this->CurrentFont['cw']; if($w==0)$w=$this->w-$this->rMargin-$this->x; $wmax=($w-2*$this->cMargin)*1000/$this->FontSize; $s=str_replace("\r",'',(string)$txt); $nb=strlen($s); if($nb>0 && $s[$nb-1]=="\n")$nb--; $sep=-1; $i=0; $j=0; $l=0; $nl=1; while($i<$nb){ $c=$s[$i]; if($c=="\n"){ $i++; $sep=-1; $j=$i; $l=0; $nl++; continue; } if($c==' ')$sep=$i; $l+=$cw[$c]??0; if($l>$wmax){ if($sep==-1){ if($i==$j)$i++; } else { $i=$sep+1; } $sep=-1;$j=$i;$l=0;$nl++; } else { $i++; } } return $nl; }
    // NOVO: imprime duas colunas lado a lado com quebra automática
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
    function ItensTable($header,$data,$widths){
        // Header with brand color
        $this->SetFillColor($this->brandColor[0],$this->brandColor[1],$this->brandColor[2]);
        $this->SetTextColor($this->brandTextOnBrand[0],$this->brandTextOnBrand[1],$this->brandTextOnBrand[2]);
        $this->SetDrawColor(120,120,120);
        $this->SetLineWidth(.3);
        $this->SetFont('Arial','B',9);
        foreach($header as $i=>$col){ $this->Cell($widths[$i],7,utf8_decode($col),1,0,'C',true);} $this->Ln();
        // Body
        $this->SetFillColor(248,246,240); // light warm background for zebra rows
        $this->SetTextColor(0);
        $this->SetFont('Arial','',9); $fill=false;
        foreach($data as $row){
            $nome=utf8_decode($row['nome']); $lines=$this->NbLines($widths[1],$nome); $h=max($lines*5,5); $this->CheckPageBreak($h); $x=$this->GetX(); $y=$this->GetY();
            $this->Cell($widths[0],$h,$row['produto_id'],'LR',0,'C',$fill); // Código
            $this->SetXY($x+$widths[0],$y); $this->MultiCell($widths[1],5,$nome,'LR','L',$fill); $yAfter=$this->GetY(); $rowH=$yAfter-$y; if($rowH < $h){ $this->SetXY($x+$widths[0],$y+$h); }
            $this->SetXY($x+$widths[0]+$widths[1],$y); $this->Cell($widths[2],$h,utf8_decode($row['ncm']?:'-'),'LR',0,'C',$fill);
            $this->Cell($widths[3],$h,number_format($row['quantidade'],2,',','.'),'LR',0,'C',$fill);
            $this->Cell($widths[4],$h,utf8_decode($row['unidade']),'LR',0,'C',$fill);
            $this->Cell($widths[5],$h,'R$ '.number_format($row['preco_unitario'],2,',','.'),'LR',0,'R',$fill);
            $subtotal=$row['preco_unitario']*$row['quantidade'];
            $this->Cell($widths[6],$h,'R$ '.number_format($subtotal,2,',','.'),'LR',0,'R',$fill);
            $this->Ln(); $fill=!$fill; }
        $this->Cell(array_sum($widths),0,'','T'); $this->Ln(2);
    }
}

// Helpers para branding
$brandHex = $branding['primary_color'] ?? '#B5A886';
$hex = ltrim((string)$brandHex,'#');
if(strlen($hex)===3){ $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
$int = hexdec(preg_replace('/[^0-9a-fA-F]/','',$hex));
$brandRGB = [($int>>16)&255, ($int>>8)&255, $int&255];
// Contraste simples: se cor clara, usa texto preto; senão, branco
$luminance = 0.2126*$brandRGB[0] + 0.7152*$brandRGB[1] + 0.0722*$brandRGB[2];
$brandTextOnBrand = ($luminance > 150) ? [0,0,0] : [255,255,255];
// Resolver logo
$logoCandidates = [];
if (!empty($branding['logo'])) {
    $logoCandidates[] = __DIR__ . '/../' . ltrim($branding['logo'],'/');
}
$logoCandidates[] = __DIR__ . '/../assets/images/logo_dekanto.png';
$logoCandidates[] = __DIR__ . '/../assets/images/logo_site.jpg';
$resolvedLogo = null;
foreach ($logoCandidates as $cand) { if (is_string($cand) && file_exists($cand)) { $resolvedLogo = $cand; break; } }

$pdf = new PDFProposta();
$pdf->AliasNbPages();
$pdf->titulo = 'Proposta Comercial - ' . ($branding['app_name'] ?? 'Dekanto');
$pdf->brandColor = $brandRGB;
$pdf->brandTextOnBrand = $brandTextOnBrand;
$pdf->logoPath = $resolvedLogo;
$pdf->AddPage();

// Cabeçalho Proposta
$pdf->SetFont('Arial','B',11); $pdf->Cell(0,6,utf8_decode('Dados da Proposta'),0,1,'L');
$pdf->SetFont('Arial','',9);
$pdf->Cell(48,5,utf8_decode('Proposta ID: '.$proposta_id),0,0,'L');
$pdf->Cell(48,5,utf8_decode('Cotação ID: '.$prop['cotacao_id']),0,0,'L');
$pdf->Cell(48,5,utf8_decode('Requisição: '.$prop['requisicao_id']),0,0,'L');
$pdf->Cell(48,5,utf8_decode('Status: '.$prop['status']),0,1,'L');
$pdf->Cell(48,5,utf8_decode('Prazo (dias): '.($prop['prazo_entrega']?:'-')),0,0,'L');
$pdf->Cell(48,5,utf8_decode('Pagamento: '.(isset($prop['pagamento_dias']) && $prop['pagamento_dias']!==null ? $prop['pagamento_dias'].' dias' : '-')),0,0,'L'); // novo campo
$pdf->Cell(48,5,utf8_decode('Valor Total: R$ '.number_format($prop['valor_total'],2,',','.')),0,0,'L');
$pdf->Cell(96,5,utf8_decode('Emitido: '.date('d/m/Y H:i')),0,1,'L');
$pdf->Ln(3);

// Fornecedor
$pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Fornecedor'),0,1,'L');
$pdf->SetFont('Arial','',9);
// ALTERADO: usar colunas com quebra
$pdf->Row2Cols('Razão Social: '.($prop['forn_razao']?:'-'), 'Fantasia: '.($prop['forn_fantasia']?:'-'));
$pdf->Cell(95,5,utf8_decode('CNPJ: '.($prop['forn_cnpj']?:'-')),0,0,'L');
$pdf->Cell(95,5,utf8_decode('IE: '.($prop['forn_ie']?:'-')),0,1,'L');
$pdf->MultiCell(0,5,utf8_decode('Endereço: '.($prop['forn_endereco']?:'-')));
$pdf->MultiCell(0,5,utf8_decode('Contato: '.(($prop['forn_tel']?:'-').' / '.($prop['forn_email']?:'-'))));
$pdf->Ln(2);

// Consultoria / empresa compradora
$pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Consultoria de Compras'),0,1,'L');
$pdf->SetFont('Arial','',9);
$pdf->Row2Cols('Razão Social: '.$consultoria['razao_social'], 'Fantasia: '.$consultoria['nome_fantasia']);
$pdf->Cell(95,5,utf8_decode('CNPJ: '.$consultoria['cnpj']),0,0,'L');
$pdf->Cell(95,5,utf8_decode('IE: '.$consultoria['ie']),0,1,'L');
$pdf->MultiCell(0,5,utf8_decode('Endereço: '.$consultoria['endereco']));
$pdf->MultiCell(0,5,utf8_decode('Contacto: '.($consultoria['telefone'].' / '.$consultoria['email'])));
$pdf->Ln(1);
$pdf->SetFont('Arial','I',8);
$pdf->SetTextColor(120,120,120);
$pdf->MultiCell(0,4,utf8_decode('Os dados completos do cliente serão disponibilizados apenas no Pedido de Compra correspondente.'));
$pdf->SetTextColor(0,0,0);
$pdf->Ln(3);

// Condicoes comerciais
if($cond_pag || $frete_modalidade || $impostos_info || !empty($prop['tipo_frete'])){
    $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Condições Comerciais'),0,1,'L');
    $pdf->SetFont('Arial','',9);
    if($cond_pag) $pdf->MultiCell(0,5,utf8_decode('Pagamento: '.$cond_pag));
    if(!empty($prop['tipo_frete'])) {
        $pdf->MultiCell(0,5,utf8_decode('Frete (Cotação): '.$prop['tipo_frete']));
        if($frete_modalidade && strtoupper(trim($frete_modalidade)) !== strtoupper(trim($prop['tipo_frete']))) {
            $pdf->MultiCell(0,5,utf8_decode('Frete (Padrão): '.$frete_modalidade));
        }
    } elseif($frete_modalidade) { $pdf->MultiCell(0,5,utf8_decode('Frete: '.$frete_modalidade)); }
    if($impostos_info) $pdf->MultiCell(0,5,utf8_decode('Impostos: '.$impostos_info));
    $pdf->Ln(2);
}

// Itens da proposta
$pdf->SetFont('Arial','B',11); $pdf->Cell(0,6,utf8_decode('Itens da Proposta'),0,1,'L'); $pdf->Ln(1);
$pageWidth = $pdf->GetPageWidth(); $leftX = $pdf->GetX(); $margin=$leftX; $larguraTotal=$pageWidth-($margin*2);
$widths=[18,60,20,18,15,28,31];
if(abs(array_sum($widths)-$larguraTotal)>0.5){ $factor=$larguraTotal/array_sum($widths); $widths=array_map(function($w)use($factor){return round($w*$factor,2);},$widths); $diff=$larguraTotal-array_sum($widths); if(abs($diff)>=0.01){ $widths[1]+=$diff; }}
$header=['Código','Produto','NCM','Qtd','Un','Preço Unit.','Subtotal'];
$pdf->ItensTable($header,$itens,$widths);

// Totais
$total=0; foreach($itens as $i){ $total += $i['preco_unitario']*$i['quantidade']; }
$pdf->SetFont('Arial','B',11); $pdf->Cell($widths[0]+$widths[1]+$widths[2]+$widths[3]+$widths[4]+$widths[5],8,utf8_decode('Total Geral'),0,0,'R'); $pdf->Cell($widths[6],8,'R$ '.number_format($total,2,',','.'),0,1,'R'); $pdf->Ln(5);

// Observações da proposta
if(!empty($prop['observacoes'])){ $pdf->SetFont('Arial','B',10); $pdf->Cell(0,6,utf8_decode('Observações'),0,1,'L'); $pdf->SetFont('Arial','',9); $pdf->MultiCell(0,5,utf8_decode($prop['observacoes'])); $pdf->Ln(3); }

// Assinaturas
$pdf->Ln(10); $pdf->SetFont('Arial','',9); $pdf->Cell(95,8,utf8_decode('__________________________________'),0,0,'C'); $pdf->Cell(0,8,utf8_decode('__________________________________'),0,1,'C'); $pdf->Cell(95,5,utf8_decode('Fornecedor'),0,0,'C'); $pdf->Cell(0,5,utf8_decode('Comprador'),0,1,'C');

$pdf->Output('D','proposta_'.$proposta_id.'.pdf');
exit;
