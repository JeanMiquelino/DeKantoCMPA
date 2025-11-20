<?php
/**
 * Script de sincronização da role "comprador" e usuário padrão.
 * Uso:
 *   php cli/setup_comprador_role.php [--email=comprador@empresa.com] [--senha=Senha123] [--nome="Comprador"] [--skip-user]
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    echo "Este script deve ser executado via CLI (php cli/setup_comprador_role.php)" . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';

function println(string $msg = ''): void {
    echo $msg . PHP_EOL;
}

function ensure_permission(PDO $db, string $codigo, string $descricao): int {
    $stmt = $db->prepare('SELECT id FROM permissoes WHERE codigo = ?');
    $stmt->execute([$codigo]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $db->prepare('UPDATE permissoes SET descricao = COALESCE(?, descricao) WHERE id = ?')
           ->execute([$descricao, $id]);
        return $id;
    }
    $db->prepare('INSERT INTO permissoes (codigo, descricao) VALUES (?, ?)')
       ->execute([$codigo, $descricao]);
    return (int)$db->lastInsertId();
}

function ensure_role(PDO $db, string $nome, string $descricao): int {
    $stmt = $db->prepare('SELECT id FROM roles WHERE nome = ?');
    $stmt->execute([$nome]);
    $id = (int)$stmt->fetchColumn();
    if ($id > 0) {
        $db->prepare('UPDATE roles SET descricao = COALESCE(?, descricao) WHERE id = ?')
           ->execute([$descricao, $id]);
        return $id;
    }
    $db->prepare('INSERT INTO roles (nome, descricao) VALUES (?, ?)')
       ->execute([$nome, $descricao]);
    return (int)$db->lastInsertId();
}

function attach_role_permissions(PDO $db, int $roleId, array $permIds): void {
    if (!$permIds) return;
    $stmt = $db->prepare('INSERT IGNORE INTO role_permissao (role_id, permissao_id) VALUES (?, ?)');
    foreach ($permIds as $permId) {
        $stmt->execute([$roleId, (int)$permId]);
    }
}

function assign_user_role(PDO $db, int $usuarioId, int $roleId): void {
    $db->prepare('DELETE FROM usuario_role WHERE usuario_id = ? AND role_id = ?')
       ->execute([$usuarioId, $roleId]);
    $db->prepare('INSERT IGNORE INTO usuario_role (usuario_id, role_id) VALUES (?, ?)')
       ->execute([$usuarioId, $roleId]);
}

function random_password(int $length = 12): string {
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

$options = getopt('', ['email::', 'senha::', 'nome::', 'skip-user']);
$email = $options['email'] ?? 'comprador@dekanto.com.br';
$senha = $options['senha'] ?? random_password();
$nome  = $options['nome']  ?? 'Comprador Padrão';
$skipUser = array_key_exists('skip-user', $options);

try {
    $db = get_db_connection();
    $db->beginTransaction();

    println('> Sincronizando permissões...');
    $permsCatalog = [
        'usuarios.ver'        => 'Listar usuários',
        'usuarios.editar'     => 'Criar/editar usuários',
        'usuarios.reset_senha'=> 'Resetar senha',
        'config.ver'          => 'Ver configurações',
        'config.editar'       => 'Editar configurações',
        'cotacoes.ver_token'  => 'Ver token de cotações',
        'cotacoes.listar'     => 'Listar cotações',
        'cotacoes.criar'      => 'Criar cotações',
        'cotacoes.atualizar'  => 'Atualizar cotações',
        'cotacoes.excluir'    => 'Excluir cotações',
        'requisicoes.ver'     => 'Ver requisições',
        'requisicoes.editar'  => 'Editar requisições',
        'requisicoes.aprovar' => 'Aprovar requisições',
        'requisicoes.rejeitar'=> 'Rejeitar requisições',
        'propostas.ver'       => 'Ver propostas',
        'propostas.editar'    => 'Editar propostas'
    ];

    $permIds = [];
    foreach ($permsCatalog as $codigo => $descricao) {
        $permIds[$codigo] = ensure_permission($db, $codigo, $descricao);
    }

    println('> Garantindo roles admin e comprador...');
    $adminRoleId     = ensure_role($db, 'admin', 'Administrador do sistema');
    $compradorRoleId = ensure_role($db, 'comprador', 'Comprador / gestor de compras');

    println('> Vinculando permissões ao admin (todas) e comprador (operacionais)...');
    attach_role_permissions($db, $adminRoleId, array_values($permIds));
    $compradorPerms = [
        'cotacoes.ver_token','cotacoes.listar','cotacoes.criar','cotacoes.atualizar','cotacoes.excluir',
        'requisicoes.ver','requisicoes.editar',
        'propostas.ver','propostas.editar'
    ];
    $compradorPermIds = array_values(array_intersect_key($permIds, array_flip($compradorPerms)));
    attach_role_permissions($db, $compradorRoleId, $compradorPermIds);

    if (!$skipUser) {
        println('> Criando/atualizando usuário comprador...');
        $stmt = $db->prepare('SELECT id FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $userId = (int)$stmt->fetchColumn();

        if ($userId > 0) {
            $db->prepare('UPDATE usuarios SET nome = ?, ativo = 1 WHERE id = ?')
               ->execute([$nome, $userId]);
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $db->prepare('INSERT INTO usuarios (nome, email, senha, tipo, ativo, criado_em) VALUES (?,?,?,?,1,NOW())')
               ->execute([$nome, $email, $hash, 'interno']);
            $userId = (int)$db->lastInsertId();
        }

        assign_user_role($db, $userId, $compradorRoleId);

        println("Usuário comprador sincronizado:");
        println("  Nome : $nome");
        println("  Email: $email");
        println("  Senha: $senha");
    } else {
        println('> --skip-user informado: pulando criação de usuário.');
    }

    $db->commit();
    println('✅ Processo concluído com sucesso.');

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
