<?php
session_start();
header('Content-Type: application/json');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Rate limit leve para GET de configurações
        rate_limit_enforce($db, 'api/configuracoes_get', 120, 300, true);
        $stmt = $db->query('SELECT chave, valor FROM configuracoes');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['chave']] = $row['valor'];
        }
        echo json_encode($out);
        break;
    case 'POST':
        // Rate limit para alterações
        rate_limit_enforce($db, 'api/configuracoes_post', 30, 300, true);
        $data = $_POST;
        // Upload de logo
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            $allowExt = ['png','jpg','jpeg','gif','svg'];
            if(!in_array($ext,$allowExt,true)){
                http_response_code(422);
                echo json_encode(['erro'=>'Extensão de logo inválida']);
                break;
            }
            $destDir = realpath(__DIR__.'/../assets/images');
            if(!$destDir){ $destDir = __DIR__.'/../assets/images'; if(!is_dir($destDir)) mkdir($destDir,0775,true); }
            $dest = $destDir.'/logo.'.$ext;
            if(!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)){
                http_response_code(500);
                echo json_encode(['erro'=>'Falha ao salvar logo']);
                break;
            }
            $data['logo'] = 'assets/images/logo.'.$ext;
        }
        foreach ($data as $k => $v) {
            $stmt = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor=?');
            $stmt->execute([$k, $v, $v]);
        }
        echo json_encode(['success' => true]);
        break;
    default:
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['erro' => 'Método não permitido']);
}