<?php
require_once __DIR__ . '/includes/db.php';

// Debug opcional: adicionar ?debug=1 na URL para ver erros na tela
if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
    header('Content-Type: text/plain; charset=utf-8');
}

try {
    $db = get_db_connection();
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro de conexão com o banco: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

try {
    $db->beginTransaction();

    // Desativa checagem de FKs para permitir limpeza segura em qualquer ordem
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    
    // Cria tabelas necessárias para o sistema de roles/permissões se não existirem
    $db->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(50) NOT NULL UNIQUE,
        descricao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS permissoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(100) NOT NULL UNIQUE,
        descricao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS role_permissao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        role_id INT NOT NULL,
        permissao_id INT NOT NULL,
        UNIQUE KEY unique_role_permissao (role_id, permissao_id),
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS usuario_role (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        role_id INT NOT NULL,
        UNIQUE KEY unique_usuario_role (usuario_id, role_id),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
        FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    )");

    // Limpa tabelas em ordem de dependência
    $tables = [
        'requisicao_itens',
        'proposta_itens',
        'propostas',
        'cotacoes',
        'pedidos',
        'notificacoes_inscricoes',
        'notificacoes',
        'anexos',
        'requisicoes_timeline',
        'logs',
        'usuario_role',
        'role_permissao',
        'api_tokens',
        'sessoes',
        'login_fail',
        'followup_logs',
        'rate_limit_hits',
        'seg_token_fail',
        'requisicoes',
        'clientes',
        'fornecedores',
        'produtos',
        'usuarios',
        'permissoes',
        'roles'
    ];
    foreach ($tables as $t) {
        try { $db->exec("DELETE FROM `$t`"); } catch (Throwable $ignore) {}
    }

    // Primeiro, cria/verifica se existe a role 'admin' e 'comprador'
    $db->exec("INSERT IGNORE INTO roles (nome, descricao) VALUES
        ('admin', 'Administrador do sistema'),
        ('comprador', 'Comprador / gestor de compras')");
    $adminRoleId = $db->query("SELECT id FROM roles WHERE nome = 'admin'")->fetchColumn();
    $compradorRoleId = $db->query("SELECT id FROM roles WHERE nome = 'comprador'")->fetchColumn();
    
    // Cria permissões básicas necessárias se não existirem
    $permissoes = [
        ['config.ver', 'Ver configurações'],
        ['config.editar', 'Editar configurações'],
        ['usuarios.ver', 'Ver usuários'],
        ['usuarios.criar', 'Criar usuários'],
        ['usuarios.editar', 'Editar usuários'],
        ['usuarios.excluir', 'Excluir usuários'],
        ['config.ver', 'Ver configurações'],
        ['config.editar', 'Editar configurações'],
        ['cotacoes.ver_token', 'Ver token de cotações'],
        ['cotacoes.listar', 'Listar cotações'],
        ['cotacoes.criar', 'Criar cotações'],
        ['cotacoes.atualizar', 'Atualizar cotações'],
        ['cotacoes.excluir', 'Excluir cotações'],
        ['requisicoes.ver', 'Ver requisições'],
        ['requisicoes.editar', 'Editar requisições'],
        ['requisicoes.aprovar', 'Aprovar requisições'],
        ['requisicoes.rejeitar', 'Rejeitar requisições'],
        ['propostas.ver', 'Ver propostas'],
        ['propostas.editar', 'Editar propostas']
    ];
    
    foreach ($permissoes as $perm) {
        $db->prepare("INSERT IGNORE INTO permissoes (codigo, descricao) VALUES (?, ?)")
           ->execute($perm);
    }
    
    // Associa todas as permissões à role admin
    $db->exec("INSERT IGNORE INTO role_permissao (role_id, permissao_id) 
               SELECT '$adminRoleId', id FROM permissoes");
    if($compradorRoleId){
        $db->exec("INSERT IGNORE INTO role_permissao (role_id, permissao_id)
                   SELECT '$compradorRoleId', id FROM permissoes WHERE codigo IN (
                        'cotacoes.ver_token','cotacoes.listar','cotacoes.criar','cotacoes.atualizar','cotacoes.excluir',
                        'requisicoes.ver','requisicoes.editar',
                        'propostas.ver','propostas.editar'
                   )");
    }

    // Cria usuário admin e obtém o ID gerado
    $stmt = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?,?,?,?,1)");
    $stmt->execute([
        'Administrador',
        'admin@dekanto.com.br',
        password_hash('admin123', PASSWORD_DEFAULT),
        'interno'
    ]);
    $adminId = (int) $db->lastInsertId();
    
    // Associa o usuário admin à role admin
    $db->prepare("INSERT IGNORE INTO usuario_role (usuario_id, role_id) VALUES (?, ?)")
       ->execute([$adminId, $adminRoleId]);

    // Cria usuário comprador padrão
    if($compradorRoleId){
        $stmt = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?,?,?,?,1)");
        $stmt->execute([
            'Comprador Padrão',
            'comprador@dekanto.com.br',
            password_hash('comprador123', PASSWORD_DEFAULT),
            'interno'
        ]);
        $compradorId = (int)$db->lastInsertId();
        $db->prepare("INSERT IGNORE INTO usuario_role (usuario_id, role_id) VALUES (?, ?)")
           ->execute([$compradorId, $compradorRoleId]);
    }

    // Cria um usuário regular de exemplo (sem privilégios admin)
    $stmt = $db->prepare("INSERT INTO usuarios (nome, email, senha, tipo, ativo) VALUES (?,?,?,?,1)");
    $stmt->execute([
        'Usuário Exemplo',
        'usuario@dekanto.com.br',
        password_hash('user123', PASSWORD_DEFAULT),
        'usuario'
    ]);
    $usuarioId = (int) $db->lastInsertId();

    // Fornecedor exemplo
    $stmt = $db->prepare("INSERT INTO fornecedores (usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status) VALUES (?,?,?,?,?,?,?,?, 'ativo')");
    $stmt->execute([
        $adminId,
        'Fornecedor Exemplo Ltda',
        'Fornecedor Exemplo',
        '12.345.678/0001-99',
        '123456789',
        'Rua Exemplo, 100',
        '(11) 99999-0000',
        'fornecedor@dekanto.com.br'
    ]);

    // Cliente exemplo
    $stmt = $db->prepare("INSERT INTO clientes (usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status) VALUES (?,?,?,?,?,?,?,?, 'ativo')");
    $stmt->execute([
        $adminId,
        'Cliente Exemplo Ltda',
        'Cliente Exemplo',
        '98.765.432/0001-11',
        '987654321',
        'Av. Cliente, 200',
        '(11) 98888-0000',
        'cliente@dekanto.com.br'
    ]);

    // Produtos exemplo
    $db->exec("INSERT INTO produtos (nome, descricao, ncm, unidade, preco_base) VALUES
        ('Produto A', 'Descrição do Produto A', '12345678', 'UN', 10.50),
        ('Produto B', 'Descrição do Produto B', '87654321', 'KG', 25.00)");

    // Requisições exemplo (se a coluna 'titulo' existir)
    $hasTitulo = false;
    try {
        $hasTitulo = (bool) $db->query("SHOW COLUMNS FROM requisicoes LIKE 'titulo'")->fetch();
    } catch (Throwable $e) {
        // silencioso se tabela não existir
    }

    if ($hasTitulo) {
        $db->exec("INSERT INTO requisicoes (titulo, cliente_id, status) VALUES
            ('Compra inicial de materiais', (SELECT id FROM clientes LIMIT 1), 'aberta'),
            ('Reposição de estoque de ferramentas', (SELECT id FROM clientes LIMIT 1), 'aberta')");
    } else {
        $db->exec("INSERT INTO requisicoes (cliente_id, status) VALUES
            ((SELECT id FROM clientes LIMIT 1), 'aberta')");
    }

    // Reativa FKs e finaliza
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
    $db->commit();

    echo "<h2>Seed concluído com sucesso!</h2>";
    echo "<div style='font-family: Arial, sans-serif; max-width: 600px;'>";
    echo "<h3>Usuários criados:</h3>";
    echo "<p><strong>Administrador:</strong><br>";
    echo "Email: <b>admin@dekanto.com.br</b><br>";
    echo "Senha: <b>admin123</b><br>";
    echo "Tipo: Administrador com acesso total</p>";
    if(isset($compradorId)){
        echo "<p><strong>Comprador:</strong><br>";
        echo "Email: <b>comprador@dekanto.com.br</b><br>";
        echo "Senha: <b>comprador123</b><br>";
        echo "Tipo: Comprador com acesso ao painel de compras</p>";
    }
    echo "<p><strong>Usuário Regular:</strong><br>";
    echo "Email: <b>usuario@dekanto.com.br</b><br>";
    echo "Senha: <b>user123</b><br>";
    echo "Tipo: Usuário padrão</p>";
    echo "</div>";
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    http_response_code(500);
    echo "<h2>Erro ao executar seed:</h2><pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
}
