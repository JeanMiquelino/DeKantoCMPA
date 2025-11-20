<?php
// API de Usuários (listagem e operações CRUD básicas via fetch JSON)
// Permissões atuais utilizadas: usuarios.ver, usuarios.editar, usuarios.reset_senha
header('Content-Type: application/json; charset=utf-8');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/db.php';
require_once __DIR__.'/../includes/email.php';
require_once __DIR__.'/../includes/rate_limit.php';
$u = auth_requer_login();
$db = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

$adminRoleId = null;
$compradorRoleId = null;
// === GARANTIR ROLES PRINCIPAIS ===
try {
    $adminRoleId = (int)$db->query("SELECT id FROM roles WHERE nome='admin'")->fetchColumn();
    if(!$adminRoleId){
        $db->exec("INSERT IGNORE INTO roles (nome,descricao) VALUES ('admin','Administrador do sistema')");
        $adminRoleId = (int)$db->query("SELECT id FROM roles WHERE nome='admin'")->fetchColumn();
    }
    $compradorRoleId = (int)$db->query("SELECT id FROM roles WHERE nome='comprador'")->fetchColumn();
    if(!$compradorRoleId){
        $db->exec("INSERT IGNORE INTO roles (nome,descricao) VALUES ('comprador','Comprador / gestor de compras')");
        $compradorRoleId = (int)$db->query("SELECT id FROM roles WHERE nome='comprador'")->fetchColumn();
    }
} catch(Throwable $e){ /* silencioso */ }

// === NOVO: Endpoint de estatísticas (GET ?stats=1) ===
if($method === 'GET' && isset($_GET['stats'])){
    if(!auth_can('usuarios.ver')) json_out(['error'=>'Sem acesso'],403);
    try {
        $total = (int)$db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
        $ativos = (int)$db->query('SELECT COUNT(*) FROM usuarios WHERE ativo=1')->fetchColumn();
        $inativos = $total - $ativos;
        $pctAtivos = $total>0 ? round(($ativos/$total)*100,1) : 0.0;
        $novos7d = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE criado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $rolesRows = $db->query("SELECT r.nome, COUNT(DISTINCT ur.usuario_id) qtd FROM roles r LEFT JOIN usuario_role ur ON ur.role_id=r.id GROUP BY r.id ORDER BY qtd DESC")->fetchAll(PDO::FETCH_ASSOC);
        $topRoles = array_map(function($r){ return ['nome'=>$r['nome'],'qtd'=>(int)$r['qtd']]; }, $rolesRows);
        json_out([
            'ok'=>true,
            'total'=>$total,
            'ativos'=>$ativos,
            'inativos'=>$inativos,
            'pct_ativos'=>$pctAtivos,
            'novos_7d'=>$novos7d,
            'roles'=>$topRoles,
            'timestamp'=>date('c')
        ]);
    } catch(Throwable $e){ json_out(['error'=>'Falha stats','detalhe'=>$e->getMessage()],500); }
}

function json_out($data, $code=200){ http_response_code($code); echo json_encode($data); exit; }

function sanitize_role_ids(PDO $db, array $rawIds): array {
    $ids = [];
    foreach ($rawIds as $value) {
        $value = (int)$value;
        if ($value > 0) {
            $ids[$value] = true;
        }
    }
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id FROM roles WHERE id IN ($placeholders)");
    $st->execute(array_keys($ids));
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function assign_roles(PDO $db, int $usuarioId, array $roleIds): void {
    $db->prepare('DELETE FROM usuario_role WHERE usuario_id=?')->execute([$usuarioId]);
    if(!$roleIds) return;
    $ins = $db->prepare('INSERT IGNORE INTO usuario_role (usuario_id, role_id) VALUES (?, ?)');
    foreach ($roleIds as $roleId) {
        $ins->execute([$usuarioId, (int)$roleId]);
    }
}

// Função auxiliar para limpar referências de usuário de forma segura
function limpar_referencias_usuario($db, $usuarioId) {
    $tabelas_limpeza = [
        'usuario_role' => 'DELETE FROM usuario_role WHERE usuario_id=?',
        'notificacoes_inscricoes' => 'DELETE FROM notificacoes_inscricoes WHERE usuario_id=?',
        'usuarios_recuperacao' => 'DELETE FROM usuarios_recuperacao WHERE usuario_id=?',
        'auditoria' => 'UPDATE auditoria SET usuario_id=NULL WHERE usuario_id=?',
        'requisicoes_timeline' => 'UPDATE requisicoes_timeline SET usuario_id=NULL WHERE usuario_id=?',
        'clientes' => 'UPDATE clientes SET usuario_id=NULL WHERE usuario_id=?',
        'fornecedores' => 'UPDATE fornecedores SET usuario_id=NULL WHERE usuario_id=?'
    ];
    
    foreach ($tabelas_limpeza as $tabela => $sql) {
        try {
            // Verifica se a tabela existe
            $checkTable = $db->query("SHOW TABLES LIKE '$tabela'")->fetch();
            if ($checkTable) {
                $db->prepare($sql)->execute([$usuarioId]);
            }
        } catch (PDOException $e) {
            // Ignora erros de tabela não encontrada ou coluna inexistente
            if (strpos($e->getMessage(), "doesn't exist") === false && 
                strpos($e->getMessage(), "Unknown column") === false) {
                throw $e; // Re-lança apenas erros não relacionados a estrutura
            }
        }
    }
}

if($method === 'GET'){
    // Rate limit leve para listagem
    rate_limit_enforce($db, 'api/usuarios_get', 120, 300, true);
    if(!auth_can('usuarios.ver')) json_out(['error'=>'Sem acesso'],403);
    $q = trim($_GET['q'] ?? '');
    $role = (int)($_GET['role'] ?? 0);
    $status = $_GET['status'] ?? ''; // 'ativo' | 'inativo' | ''
    $page = max(1,(int)($_GET['page'] ?? 1));
    $perPage = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
    $off = ($page-1)*$perPage;
    $w = [];$p=[];
    // NEW: filtro direto por ID para deep-link (ex.: usuarios.php?open_id=123)
    $id = (int)($_GET['id'] ?? 0);
    if($id > 0){ $w[] = 'u.id = ?'; $p[] = $id; $page = 1; $perPage = 1; $off = 0; }
    if($q!==''){ $w[] = '(u.nome LIKE ? OR u.email LIKE ?)'; $p[]="%$q%"; $p[]="%$q%"; }
    if($role>0){ $w[] = 'EXISTS (SELECT 1 FROM usuario_role ur2 WHERE ur2.usuario_id=u.id AND ur2.role_id=?)'; $p[]=$role; }
    if($status==='ativo'){ $w[]='u.ativo=1'; }
    if($status==='inativo'){ $w[]='u.ativo=0'; }
    $where = $w? ('WHERE '.implode(' AND ',$w)) : '';
    $sql = "SELECT SQL_CALC_FOUND_ROWS u.id,u.nome,u.email,u.ativo,u.criado_em,
        GROUP_CONCAT(DISTINCT r.nome ORDER BY r.nome SEPARATOR ',') roles,
        GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') roles_ids
            FROM usuarios u
            LEFT JOIN usuario_role ur ON ur.usuario_id=u.id
            LEFT JOIN roles r ON r.id=ur.role_id
            $where
            GROUP BY u.id
            ORDER BY u.id DESC
            LIMIT $perPage OFFSET $off";
    $st = $db->prepare($sql); $st->execute($p); $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)$db->query('SELECT FOUND_ROWS()')->fetchColumn();
    foreach($rows as &$r){
        $r['roles_array'] = $r['roles']? array_filter(array_map('trim', explode(',',$r['roles']))) : [];
        $r['roles_ids'] = $r['roles_ids']? array_map('intval', array_filter(array_map('trim', explode(',',$r['roles_ids'])))) : [];
    }
    json_out(['data'=>$rows,'page'=>$page,'per_page'=>$perPage,'total'=>$total]);
}

// Demais ações via POST JSON / form-urlencode
if($method === 'POST'){
    // Rate limit para mutações
    rate_limit_enforce($db, 'api/usuarios_post', 60, 300, true);
    $action = $_POST['action'] ?? ($_GET['action'] ?? '');
    if(!$action){
        $input = json_decode(file_get_contents('php://input'), true);
        if(is_array($input)) $action = $input['action'] ?? '';
        $_POST = $input ?: $_POST;
    }

    switch($action){
        case 'create':
            if(!auth_can('usuarios.editar')) json_out(['error'=>'Sem acesso'],403);
            $nome = trim($_POST['nome']??'');
            $email = trim($_POST['email']??'');
            $senha = $_POST['senha']??'';
            $senhaConf = $_POST['senha_conf']??'';
            $rolesInput = $_POST['roles'] ?? [];
            if(!is_array($rolesInput)) { $rolesInput = [$rolesInput]; }
            $rolesSel = sanitize_role_ids($db, $rolesInput);
            if(!$rolesSel && !empty($compradorRoleId)) { $rolesSel = [$compradorRoleId]; }
            if(!$rolesSel) json_out(['error'=>'Selecione pelo menos um perfil'],422);
            if($nome===''||$email===''||$senha==='') json_out(['error'=>'Campos obrigatórios'],422);
            if($senhaConf===''||$senhaConf!==$senha) json_out(['error'=>'Confirmação de senha não confere'],422);
            $st=$db->prepare('SELECT id FROM usuarios WHERE email=?'); $st->execute([$email]); if($st->fetch()) json_out(['error'=>'Email já usado'],409);
            $hash=password_hash($senha,PASSWORD_DEFAULT);
            $db->prepare('INSERT INTO usuarios (nome,email,senha,tipo,ativo) VALUES (?,?,?,?,1)')->execute([$nome,$email,$hash,'interno']);
            $id=(int)$db->lastInsertId();
            assign_roles($db, $id, $rolesSel);
            auditoria_log($u['id'],'usuario_create','usuarios',$id,['email'=>$email]);
            // Enviar emails (imediato): confirmação e boas-vindas
            try { email_send_confirmacao($id, false); } catch (Throwable $e) { /* ignora */ }
            try { email_send_usuario_boas_vindas($id, false); } catch (Throwable $e) { /* ignora */ }
            // Email boas-vindas + senha (customizado)
            try {
                $loginLink = (defined('APP_URL')?APP_URL:'').'/pages/login.php';
                $html = email_render_template('generic', [
                    'titulo' => 'Bem-vindo(a), '.htmlspecialchars($nome).'! 🎉',
                    'mensagem' => 'Sua conta foi criada com sucesso em <strong>'.htmlspecialchars(APP_NAME).'</strong>.<br><br>'
                        .'Use o email <strong>'.htmlspecialchars($email).'</strong> e a senha inicial abaixo para entrar:<br>'
                        .'<div style="margin:14px 0;padding:14px 18px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;letter-spacing:.5px;color:#1e293b;display:inline-block">'
                        .htmlspecialchars($senha)
                        .'</div><br>'
                        .'Por segurança, altere essa senha após o primeiro acesso (Menu Conta > Alterar Senha).',
                    'acao_html' => '<a class="btn" href="'.$loginLink.'" target="_blank">Acessar agora</a>',
                    'extra_css' => email_force_light_css()
                ]) ?? ('<p>Bem-vindo(a) '.htmlspecialchars($nome).'</p><p>Senha inicial: '.htmlspecialchars($senha).'</p><p><a href="'.$loginLink.'">Acessar</a></p>');
                $idsSenha = email_queue($email, 'Bem-vindo(a) - Acesse com sua senha inicial', $html, [
                    'tipo_evento' => 'usuario_senha_inicial',
                    'usuario_id'  => $id
                ]);
                if(!$idsSenha){ $errSenha = $GLOBALS['EMAIL_LAST_ERROR'] ?? 'Falha ao enviar senha'; }
            } catch (Throwable $e) { $errSenha = $e->getMessage(); }
            json_out(['ok'=>true,'id'=>$id,'senha_mail_erro'=> $errSenha ?? null]);
        case 'update':
            if(!auth_can('usuarios.editar')) json_out(['error'=>'Sem acesso'],403);
            $id=(int)($_POST['id']??0);
            if($id<=0) json_out(['error'=>'ID inválido'],422);
            $nome=trim($_POST['nome']??''); $email=trim($_POST['email']??'');
            $rolesInput = $_POST['roles'] ?? [];
            if(!is_array($rolesInput)) { $rolesInput = [$rolesInput]; }
            $rolesSel = sanitize_role_ids($db, $rolesInput);
            if(!$rolesSel && !empty($compradorRoleId)) { $rolesSel = [$compradorRoleId]; }
            if(!$rolesSel) json_out(['error'=>'Selecione pelo menos um perfil'],422);
            if($id === (int)$u['id'] && $adminRoleId && !in_array($adminRoleId,$rolesSel,true)){
                json_out(['error'=>'Não é possível remover seu próprio acesso de administrador'],422);
            }
            $senhaNova=$_POST['senha_nova']??''; $senhaNovaConf=$_POST['senha_nova_conf']??'';
            if($nome===''||$email==='') json_out(['error'=>'Campos obrigatórios'],422);
            if($senhaNova!==''){ if($senhaNovaConf===''||$senhaNovaConf!==$senhaNova) json_out(['error'=>'Confirmação de nova senha não confere'],422); }
            $st=$db->prepare('SELECT id FROM usuarios WHERE email=? AND id<>?'); $st->execute([$email,$id]); if($st->fetch()) json_out(['error'=>'Email já usado'],409);
            $db->prepare('UPDATE usuarios SET nome=?, email=? WHERE id=?')->execute([$nome,$email,$id]);
            if($senhaNova!==''){ $hash=password_hash($senhaNova,PASSWORD_DEFAULT); $db->prepare('UPDATE usuarios SET senha=? WHERE id=?')->execute([$hash,$id]); auditoria_log($u['id'],'usuario_reset_senha','usuarios',$id,null); }
            assign_roles($db, $id, $rolesSel);
            auditoria_log($u['id'],'usuario_update','usuarios',$id,['email'=>$email]);
            json_out(['ok'=>true]);
        case 'roles':
            if(!auth_can('usuarios.editar')) json_out(['error'=>'Sem acesso'],403);
            $id=(int)($_POST['id']??0); if($id<=0) json_out(['error'=>'ID inválido'],422);
            $rolesInput = $_POST['roles'] ?? [];
            if(!is_array($rolesInput)) { $rolesInput = [$rolesInput]; }
            $rolesSel = sanitize_role_ids($db, $rolesInput);
            if(!$rolesSel && !empty($compradorRoleId)) { $rolesSel = [$compradorRoleId]; }
            if(!$rolesSel) json_out(['error'=>'Selecione pelo menos um perfil'],422);
            if($id === (int)$u['id'] && $adminRoleId && !in_array($adminRoleId,$rolesSel,true)){
                json_out(['error'=>'Não é possível remover seu próprio acesso de administrador'],422);
            }
            assign_roles($db, $id, $rolesSel);
            auditoria_log($u['id'],'usuario_roles_update','usuarios',$id,['roles'=>$rolesSel]);
            json_out(['ok'=>true]);
        case 'delete':
            if(!auth_can('usuarios.editar')) json_out(['error'=>'Sem acesso'],403);
            $id=(int)($_POST['id']??0);
            if($id<=0) json_out(['error'=>'ID inválido'],422);
            if($id===(int)$u['id']) json_out(['error'=>'Não pode excluir a si mesmo'],422);
            
            try {
                $db->beginTransaction();
                
                // Usar função auxiliar para limpar todas as referências de forma segura
                limpar_referencias_usuario($db, $id);
                
                // Agora pode excluir o usuário com segurança
                $db->prepare('DELETE FROM usuarios WHERE id=?')->execute([$id]);
                
                $db->commit();
                auditoria_log($u['id'],'usuario_delete','usuarios',$id,null);
                json_out(['ok'=>true, 'message'=>'Usuário excluído com sucesso.']);
                
            } catch(Throwable $e){
                if($db->inTransaction()) $db->rollBack();
                json_out(['error'=>'Falha ao excluir: '.$e->getMessage()],500);
            }
            break;
        case 'reset_password':
            if(!auth_can('usuarios.reset_senha')) json_out(['error'=>'Sem acesso'],403);
            $id = (int)($_POST['id'] ?? 0);
            $nova = trim($_POST['nova'] ?? '');
            if($id<=0) json_out(['error'=>'ID inválido'],422);
            if($nova==='') json_out(['error'=>'Informe a nova senha'],422);
            $hash = password_hash($nova, PASSWORD_DEFAULT);
            $db->prepare('UPDATE usuarios SET senha=? WHERE id=?')->execute([$hash,$id]);
            auditoria_log($u['id'],'usuario_reset_senha_manual','usuarios',$id,null);
            json_out(['ok'=>true]);
        break;
        default:
            json_out(['error'=>'Ação inválida'],400);
    }
}

json_out(['error'=>'Método não suportado'],405);
