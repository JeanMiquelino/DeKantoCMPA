-- Script idempotente para sincronizar a role "comprador" e usuário padrão.
-- Execute via: mysql -u user -p dbname < setup_comprador.sql

START TRANSACTION;

-- 0) Remover roles legadas que não devem ser exibidas
DELETE ur
FROM usuario_role ur
JOIN roles r ON r.id = ur.role_id
WHERE r.nome IN ('gestor','leitor');

DELETE rp
FROM role_permissao rp
JOIN roles r ON r.id = rp.role_id
WHERE r.nome IN ('gestor','leitor');

DELETE FROM roles WHERE nome IN ('gestor','leitor');

-- 1) Garantir permissões necessárias
INSERT INTO permissoes (codigo, descricao)
SELECT t.codigo, t.descricao
FROM (
    SELECT 'usuarios.ver' AS codigo, 'Listar usuários' AS descricao UNION ALL
    SELECT 'usuarios.editar', 'Criar/editar usuários' UNION ALL
    SELECT 'usuarios.reset_senha', 'Resetar senha' UNION ALL
    SELECT 'config.ver', 'Ver configurações' UNION ALL
    SELECT 'config.editar', 'Editar configurações' UNION ALL
    SELECT 'cotacoes.ver_token', 'Ver token de cotações' UNION ALL
    SELECT 'cotacoes.listar', 'Listar cotações' UNION ALL
    SELECT 'cotacoes.criar', 'Criar cotações' UNION ALL
    SELECT 'cotacoes.atualizar', 'Atualizar cotações' UNION ALL
    SELECT 'cotacoes.excluir', 'Excluir cotações' UNION ALL
    SELECT 'requisicoes.ver', 'Ver requisições' UNION ALL
    SELECT 'requisicoes.editar', 'Editar requisições' UNION ALL
    SELECT 'requisicoes.aprovar', 'Aprovar requisições' UNION ALL
    SELECT 'requisicoes.rejeitar', 'Rejeitar requisições' UNION ALL
    SELECT 'propostas.ver', 'Ver propostas' UNION ALL
    SELECT 'propostas.editar', 'Editar propostas'
) AS t
LEFT JOIN permissoes p ON p.codigo = t.codigo
WHERE p.id IS NULL;

UPDATE permissoes SET descricao = 'Listar usuários' WHERE codigo = 'usuarios.ver';
UPDATE permissoes SET descricao = 'Criar/editar usuários' WHERE codigo = 'usuarios.editar';
UPDATE permissoes SET descricao = 'Resetar senha' WHERE codigo = 'usuarios.reset_senha';
UPDATE permissoes SET descricao = 'Ver configurações' WHERE codigo = 'config.ver';
UPDATE permissoes SET descricao = 'Editar configurações' WHERE codigo = 'config.editar';
UPDATE permissoes SET descricao = 'Ver token de cotações' WHERE codigo = 'cotacoes.ver_token';
UPDATE permissoes SET descricao = 'Listar cotações' WHERE codigo = 'cotacoes.listar';
UPDATE permissoes SET descricao = 'Criar cotações' WHERE codigo = 'cotacoes.criar';
UPDATE permissoes SET descricao = 'Atualizar cotações' WHERE codigo = 'cotacoes.atualizar';
UPDATE permissoes SET descricao = 'Excluir cotações' WHERE codigo = 'cotacoes.excluir';
UPDATE permissoes SET descricao = 'Ver requisições' WHERE codigo = 'requisicoes.ver';
UPDATE permissoes SET descricao = 'Editar requisições' WHERE codigo = 'requisicoes.editar';
UPDATE permissoes SET descricao = 'Aprovar requisições' WHERE codigo = 'requisicoes.aprovar';
UPDATE permissoes SET descricao = 'Rejeitar requisições' WHERE codigo = 'requisicoes.rejeitar';
UPDATE permissoes SET descricao = 'Ver propostas' WHERE codigo = 'propostas.ver';
UPDATE permissoes SET descricao = 'Editar propostas' WHERE codigo = 'propostas.editar';

-- 2) Garantir roles admin e comprador
INSERT INTO roles (nome, descricao)
SELECT t.nome, t.descricao
FROM (
    SELECT 'admin' AS nome, 'Administrador do sistema' AS descricao UNION ALL
    SELECT 'comprador', 'Comprador / gestor de compras'
) AS t
LEFT JOIN roles r ON r.nome = t.nome
WHERE r.id IS NULL;

UPDATE roles SET descricao = 'Administrador do sistema' WHERE nome = 'admin';
UPDATE roles SET descricao = 'Comprador / gestor de compras' WHERE nome = 'comprador';

-- 3) Vincular permissões ao admin (todas)
INSERT INTO role_permissao (role_id, permissao_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissoes p
LEFT JOIN role_permissao rp ON rp.role_id = r.id AND rp.permissao_id = p.id
WHERE r.nome = 'admin' AND rp.role_id IS NULL;

-- 4) Vincular permissões operacionais ao comprador
INSERT INTO role_permissao (role_id, permissao_id)
SELECT r.id, p.id
FROM roles r
JOIN permissoes p ON p.codigo IN (
    'cotacoes.ver_token','cotacoes.listar','cotacoes.criar','cotacoes.atualizar','cotacoes.excluir',
    'requisicoes.ver','requisicoes.editar',
    'propostas.ver','propostas.editar'
)
LEFT JOIN role_permissao rp ON rp.role_id = r.id AND rp.permissao_id = p.id
WHERE r.nome = 'comprador' AND rp.role_id IS NULL;

-- 5) Criar/atualizar usuário comprador padrão
SET @comprador_email = 'comprador@dekanto.com.br';
SET @comprador_nome  = 'Comprador Padrão';
SET @comprador_senha = '$2y$10$2uWB7HkE4pO1Y7Hx9aJtMeGm6pTgHjVj6z6sOQ9Sikb1f7Q0cR5qS';

INSERT INTO usuarios (nome, email, senha, tipo, ativo, criado_em)
SELECT @comprador_nome, @comprador_email, @comprador_senha, 'interno', 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM usuarios WHERE email = @comprador_email);

UPDATE usuarios SET nome = @comprador_nome, ativo = 1 WHERE email = @comprador_email;

-- 6) Garantir associação usuário-role
INSERT INTO usuario_role (usuario_id, role_id)
SELECT u.id, r.id
FROM usuarios u
JOIN roles r ON r.nome = 'comprador'
LEFT JOIN usuario_role ur ON ur.usuario_id = u.id AND ur.role_id = r.id
WHERE u.email = @comprador_email AND ur.usuario_id IS NULL;

COMMIT;
