<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/legacy_anexos.php';

$u = auth_usuario();
if(!$u){
    http_response_code(401);
    echo json_encode(['erro' => 'Nao autenticado']);
    exit;
}

try {
    $db = get_db_connection();
    rate_limit_enforce($db, 'api/anexos_delete', 60, 300, true);

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if($id <= 0){
        http_response_code(400);
        echo json_encode(['erro' => 'ID inválido']);
        exit;
    }

    $st = $db->prepare('SELECT * FROM anexos WHERE id=? LIMIT 1');
    $st->execute([$id]);
    $anexo = $st->fetch(PDO::FETCH_ASSOC);
    if(!$anexo){
        http_response_code(404);
        echo json_encode(['erro' => 'Anexo não encontrado']);
        exit;
    }

    $ehFornecedor = (($u['tipo'] ?? '') === 'fornecedor');
    $fornecedorId = (int)($u['fornecedor_id'] ?? 0);
    if($ehFornecedor){
        if($fornecedorId <= 0){
            try {
                $stF = $db->prepare('SELECT id FROM fornecedores WHERE usuario_id=? LIMIT 1');
                $stF->execute([$u['id']]);
                $fornecedorId = (int)($stF->fetchColumn() ?: 0);
            } catch(Throwable $e){ }
        }
        if($fornecedorId <= 0){
            http_response_code(403);
            echo json_encode(['erro' => 'Fornecedor não configurado']);
            exit;
        }
        if(($anexo['tipo_ref'] ?? '') !== 'proposta' || (int)($anexo['ref_id'] ?? 0) <= 0){
            http_response_code(403);
            echo json_encode(['erro' => 'Sem permissão para remover']);
            exit;
        }
        $stProp = $db->prepare('SELECT fornecedor_id, imagem_url FROM propostas WHERE id=? LIMIT 1');
        $stProp->execute([(int)$anexo['ref_id']]);
        $prop = $stProp->fetch(PDO::FETCH_ASSOC);
        if(!$prop || (int)$prop['fornecedor_id'] !== $fornecedorId){
            http_response_code(403);
            echo json_encode(['erro' => 'Sem permissão para remover']);
            exit;
        }
        if((int)($anexo['publico'] ?? 0) === 1){
            http_response_code(403);
            echo json_encode(['erro' => 'Anexo público não pode ser removido']);
            exit;
        }
    } else {
        $prop = null;
    }

    $db->beginTransaction();
    $stDel = $db->prepare('DELETE FROM anexos WHERE id=?');
    $stDel->execute([$id]);
    $db->commit();

    $fileRemoved = false;
    if(!empty($anexo['caminho'])){
        $realBase = realpath(__DIR__ . '/../');
        $realFile = realpath(__DIR__ . '/../' . $anexo['caminho']);
        if($realBase && $realFile && strpos($realFile, $realBase) === 0 && is_file($realFile)){
            $fileRemoved = @unlink($realFile);
        }
    }

    if(isset($prop) && !empty($prop['imagem_url'])){
        $propPath = legacy_normalize_anexo_path($prop['imagem_url']);
        $anexoPath = legacy_normalize_anexo_path($anexo['caminho'] ?? '');
        if($propPath && $anexoPath && $propPath === $anexoPath){
            try {
                $stUpd = $db->prepare('UPDATE propostas SET imagem_url=NULL WHERE id=?');
                $stUpd->execute([(int)$anexo['ref_id']]);
            } catch(Throwable $e){ }
        }
    }

    echo json_encode(['success' => true, 'arquivo' => $fileRemoved ? 'removido' : 'indisponivel']);
} catch(Throwable $e){
    if(isset($db) && $db->inTransaction()){
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['erro' => 'Falha ao remover anexo', 'detalhe' => $e->getMessage()]);
}
