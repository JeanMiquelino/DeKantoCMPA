<?php
// Camada de autenticação / autorização básica
require_once __DIR__.'/db.php';

function auth_usuario(): ?array {
    if (!isset($_SESSION)) session_start();
    if (!isset($_SESSION['usuario_id'])) return null;
    // Cache global (permite reset explícito)
    if (array_key_exists('__AUTH_CACHE',$GLOBALS) && $GLOBALS['__AUTH_CACHE']!==null) return $GLOBALS['__AUTH_CACHE'];
    $db = get_db_connection();
    $st = $db->prepare('SELECT * FROM usuarios WHERE id=? AND ativo=1');
    $st->execute([$_SESSION['usuario_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    // Carrega todas as roles do usuário
    $st = $db->prepare('SELECT r.id, r.nome FROM usuario_role ur JOIN roles r ON r.id = ur.role_id WHERE ur.usuario_id = ?');
    $st->execute([$u['id']]);
    $rolesRows = $st->fetchAll(PDO::FETCH_ASSOC);
    $u['roles'] = array_values(array_unique(array_column($rolesRows, 'nome')));
    $roleIds = array_map('intval', array_column($rolesRows, 'id'));

    // Permissões: admin recebe todas, demais buscam conforme role_permissao
    if (in_array('admin', $u['roles'], true)) {
        $st = $db->query('SELECT codigo FROM permissoes');
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($roleIds) {
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $st = $db->prepare("SELECT DISTINCT p.codigo FROM permissoes p JOIN role_permissao rp ON rp.permissao_id = p.id WHERE rp.role_id IN ($placeholders)");
        $st->execute($roleIds);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $perms = [];
    }
    $u['permissoes'] = array_values(array_unique($perms));
    $GLOBALS['__AUTH_CACHE'] = $u;
    return $u;
}

function auth_clear_cache(): void { unset($GLOBALS['__AUTH_CACHE']); }

function auth_requer_login(): array {
    $u = auth_usuario();
    if (!$u) {
        http_response_code(401);
        echo 'Nao autenticado';
        exit;
    }
    return $u;
}

function auth_can(string $perm): bool {
    $u = auth_usuario();
    if (!$u) return false;
    if (in_array('admin',$u['roles'],true)) return true; // super
    return in_array($perm,$u['permissoes'],true);
}

function auth_requer_permissao(string $perm): void {
    if (!auth_can($perm)) {
        http_response_code(403);
        echo 'Permissao negada';
        exit;
    }
}

function auditoria_log(?int $usuarioId,string $acao,?string $tabela,?int $alvoId,$payload=null): void {
    try {
        $db = get_db_connection();
        $st = $db->prepare('INSERT INTO auditoria (usuario_id,acao,alvo_tabela,alvo_id,payload_json,ip,user_agent) VALUES (?,?,?,?,?,?,?)');
        $json = $payload===null? null: (is_string($payload)? $payload: json_encode($payload,JSON_UNESCAPED_UNICODE));
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,250);
        $st->execute([$usuarioId,$acao,$tabela,$alvoId,$json,$ip,$ua]);
    } catch(Throwable $e) { /* silenciar */ }
}
