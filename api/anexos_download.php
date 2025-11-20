<?php
// Download de anexo autenticado ou publico se marcado como publico e via token tracking
// GET: /api/anexos_download.php?id=123  (auth) 
// GET: /api/anexos_download.php?token=XYZ&anexo_id=123 (publico via token da requisicao se anexo publico)

// Security headers (public-capable endpoint)
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/timeline.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$id = (int)($_GET['id'] ?? ($_GET['anexo_id'] ?? 0));
$token = trim($_GET['token'] ?? '');

if($id<=0){ http_response_code(400); echo 'ID invalido'; exit; }

try {
    $db = get_db_connection();
    $tab = $db->query("SHOW TABLES LIKE 'anexos'")->fetch();
    if(!$tab){ http_response_code(404); header('Cache-Control: public, max-age=3600'); echo 'Nao encontrado'; exit; }

    $st = $db->prepare('SELECT * FROM anexos WHERE id=?');
    $st->execute([$id]);
    $ax = $st->fetch(PDO::FETCH_ASSOC);
    if(!$ax){ http_response_code(404); header('Cache-Control: public, max-age=3600'); echo 'Nao encontrado'; exit; }

    $requisicao_id = (int)$ax['requisicao_id'];

    $publicAccess = false;
    if($token !== ''){
        // Rate limit publico por IP para rota de token + bucket parametrizado
        $tHash = hash('sha256', $token);
        rate_limit_enforce($db, 'public.anexos_download', 100, 300, false);
        rate_limit_enforce($db, 'public.anexos_download:' . $tHash, 60, 300, false);
        // Validar token de requisicao e expiração
        $cols = $db->query("SHOW COLUMNS FROM requisicoes LIKE 'tracking_token'")->fetch();
        if(!$cols){ http_response_code(404); header('Cache-Control: public, max-age=3600'); echo 'Nao encontrado'; exit; }
        $stT = $db->prepare('SELECT id FROM requisicoes WHERE id=? AND tracking_token=? AND (tracking_token_expira IS NULL OR tracking_token_expira > NOW())');
        $stT->execute([$requisicao_id,$token]);
        $reqOk = $stT->fetch();
        if($reqOk && (int)$ax['publico']===1){ $publicAccess = true; }
        else {
            // Log seguro de falha de token
            try { seg_log_token_fail($db, 'public.anexos_download', $tHash, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null, $requisicao_id ?: null); } catch(Throwable $e){}
            http_response_code(404); header('Cache-Control: public, max-age=3600'); echo 'Nao encontrado'; exit; }
    }

    if(!$publicAccess){
        $u = auth_usuario();
        if(!$u){ http_response_code(401); echo 'Nao autenticado'; exit; }
        // Rate limit leve por usuário em downloads autenticados
        try { rate_limit_enforce($db, 'api.anexos_download:' . $id, 300, 300, false); } catch(Throwable $e){}
    }

    $filePath = realpath(__DIR__ . '/../' . $ax['caminho']);
    if(!$filePath || !is_file($filePath)){
        http_response_code(410); echo 'Arquivo indisponivel'; exit; }

    // Registro de timeline de download (apenas interno para auditoria; evitar spam => sample)
    if(!$publicAccess){
        try { log_requisicao_event($db,$requisicao_id,'anexo_download','Download anexo: '.$ax['nome_original'],null,['anexo_id'=>$ax['id']]); } catch(Throwable $e){}
    }

    $mime = $ax['mime'] ?: 'application/octet-stream';
    $fname = basename($ax['nome_original'] ?: ('anexo_'.$ax['id']));
    // Sanitiza para header (remove aspas duplas)
    $fname = str_replace(['"','\r','\n'], ['','',''], $fname);

    // Lista de tipos que podem ser exibidos inline em aba (evita forçar download para PDF/imagens/textos simples)
    $previewable = false;
    if(preg_match('~^(image/|text/plain)~i',$mime)) $previewable = true;
    if(strcasecmp($mime,'application/pdf')===0) $previewable = true;
    if(strcasecmp($mime,'text/csv')===0) $previewable = true;

    // Permite override explicito via query ?inline=1 para debugging interno (apenas autenticado)
    if(!$previewable && isset($_GET['inline']) && !$publicAccess){
        $previewable = true; // risco controlado pois exige auth
    }

    header('Content-Type: '.$mime);
    if($previewable){
        header('Content-Disposition: inline; filename="'.$fname.'"');
    } else {
        header('Content-Disposition: attachment; filename="'.$fname.'"');
    }
    header('Content-Length: '.filesize($filePath));
    // Evita caching agressivo para conteudo potencialmente confidencial
    header('Cache-Control: private, max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    readfile($filePath);
} catch(Throwable $e){
    http_response_code(500); echo 'Erro';
}
