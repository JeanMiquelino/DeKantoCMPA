<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/email.php'; // envio emails
require_once __DIR__ . '/../includes/auth.php'; // auditoria / auth util
require_once __DIR__ . '/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();

function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') {
        return $method;
    }
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }
    if ($override) {
        $override = strtoupper(trim((string)$override));
        if (in_array($override, ['DELETE', 'PUT', 'PATCH'], true)) {
            return $override;
        }
    }
    return $method;
}

function resolve_effective_method(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $method = resolve_http_method();
    if ($method === 'POST' && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
        $actionOverride = $_POST['_action'] ?? $_REQUEST['_action'] ?? null;
        if ($actionOverride) {
            $map = [
                'update' => 'PUT',
                'editar' => 'PUT',
                'delete' => 'DELETE',
                'excluir' => 'DELETE'
            ];
            $key = strtolower(trim((string)$actionOverride));
            if (isset($map[$key])) {
                $method = $map[$key];
            }
        }
    }
    return $cached = $method;
}

function read_request_payload(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        $raw = '';
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $tmp = [];
        parse_str($raw, $tmp);
        if (is_array($tmp) && $tmp) {
            $data = $tmp;
        }
    }
    if (!is_array($data) || !$data) {
        $data = $_POST ?: [];
    }
    if (isset($data['_method'])) unset($data['_method']);
    if (isset($data['_action'])) unset($data['_action']);
    $cached = $data;
    return $cached;
}

function only_digits($value): string {
    return preg_replace('/\D/', '', (string)$value);
}

function normalize_status(?string $status): ?string {
    if ($status === null) return null;
    $s = strtolower(trim($status));
    $allowed = ['ativo','inativo'];
    return in_array($s, $allowed, true) ? $s : null;
}

function clientes_status_options(PDO $db): array {
    try {
        $rows = $db->query("SELECT DISTINCT status FROM clientes WHERE status IS NOT NULL AND status<>'' ORDER BY status")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach($rows as $status){
            $out[] = [
                'value' => $status,
                'label' => ucwords(str_replace('_',' ', (string)$status))
            ];
        }
        return $out;
    } catch(Throwable $e){
        return [];
    }
}

function clientes_build_filters($buscaRaw, $statusRaw, array &$params): array {
    $where = [];
    $busca = trim((string)($buscaRaw ?? ''));
    if($busca !== ''){
        $buscaLower = function_exists('mb_strtolower') ? mb_strtolower($busca,'UTF-8') : strtolower($busca);
        $like = '%'.$buscaLower.'%';
        $where[] = '(LOWER(razao_social) LIKE ? OR LOWER(nome_fantasia) LIKE ? OR LOWER(email) LIKE ?)';
        $params[] = $like; $params[] = $like; $params[] = $like;
        $digits = only_digits($busca);
        if($digits !== ''){
            $where[] = "REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/',''),' ','') LIKE ?";
            $params[] = '%'.$digits.'%';
        }
    }

    if(is_array($statusRaw)){
        $valid = [];
        foreach($statusRaw as $statusItem){
            $norm = normalize_status($statusItem);
            if($norm !== null){ $valid[$norm] = true; }
        }
        if($valid){
            $placeholders = implode(',', array_fill(0, count($valid), '?'));
            $where[] = 'status IN ('.$placeholders.')';
            foreach(array_keys($valid) as $val){ $params[] = $val; }
        }
    } else {
        $statusNorm = normalize_status($statusRaw);
        if($statusNorm !== null){
            $where[] = 'status = ?';
            $params[] = $statusNorm;
        }
    }
    return $where;
}

$method = resolve_effective_method();

switch ($method) {
    case 'GET':
        rate_limit_enforce($db, 'api/clientes_get', 120, 300, true);
        $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
        if ($mode === 'ids') {
            $params = [];
            $filters = clientes_build_filters($_GET['busca'] ?? null, $_GET['status'] ?? null, $params);
            $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
            $sql = 'SELECT id FROM clientes' . $whereSql . ' ORDER BY id DESC';
            $st = $db->prepare($sql);
            $st->execute($params);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            echo json_encode(['ids' => $ids, 'total' => count($ids)]);
            break;
        }

        if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
            $id = (int)$_GET['id'];
            $st = $db->prepare('SELECT * FROM clientes WHERE id=? LIMIT 1');
            $st->execute([$id]);
            echo json_encode($st->fetch(PDO::FETCH_ASSOC) ?: null);
            break;
        }

        $buscaParam = $_GET['busca'] ?? null;
        $statusParam = $_GET['status'] ?? null;
        $hasPagination = isset($_GET['page']) || isset($_GET['per_page']) || $buscaParam !== null || $statusParam !== null;

        if ($hasPagination) {
            $params = [];
            $filters = clientes_build_filters($buscaParam, $statusParam, $params);
            $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(200, max(5, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            $dataSql = "SELECT id, usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status
                        FROM clientes
                        $whereSql
                        ORDER BY id DESC
                        LIMIT $perPage OFFSET $offset";
            $stData = $db->prepare($dataSql);
            $stData->execute($params);
            $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

            $countSql = 'SELECT COUNT(*) FROM clientes' . $whereSql;
            $stCount = $db->prepare($countSql);
            $stCount->execute($params);
            $total = (int)$stCount->fetchColumn();

            echo json_encode([
                'data' => $rows,
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => max(1, (int)ceil($total / $perPage)),
                    'status_options' => clientes_status_options($db)
                ]
            ]);
            break;
        }

        $stmt = $db->query('SELECT * FROM clientes ORDER BY id DESC');
        echo json_encode($stmt->fetchAll());
        break;
    case 'POST':
        rate_limit_enforce($db, 'api/clientes_post', 60, 300, true);
        $data = read_request_payload();
        // Fluxo estendido: se usuario_id não vier, criar usuario (tipo=cliente) e vincular
        $createdUsuarioId = null; $senhaInicial = null; $erroEmail = null; $emailEnviado = false; $emailSenhaEnviado = false;
        
        try {
            $db->beginTransaction();
            // Normaliza campos de usuario/cliente
            $usuarioId = (int)($data['usuario_id'] ?? 0);
            $usuarioNome  = trim($data['usuario_nome'] ?? ($data['nome_usuario'] ?? ($data['contato_nome'] ?? ($data['nome'] ?? ''))));
            $usuarioEmail = trim($data['usuario_email'] ?? ($data['email_usuario'] ?? ($data['contato_email'] ?? ($data['email'] ?? ''))));
            $senhaInformada = trim($data['senha_inicial'] ?? $data['senha'] ?? '');

            if($usuarioId <= 0){
                // Criar usuario se tivermos dados mínimos
                if($usuarioNome === '' || $usuarioEmail === ''){
                    $db->rollBack();
                    http_response_code(422);
                    echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios ausentes. Informe usuario_nome e usuario_email ou usuario_id.']);
                    break;
                }
                // Unicidade de email
                $st = $db->prepare('SELECT id FROM usuarios WHERE email=?');
                $st->execute([$usuarioEmail]);
                if($st->fetch()){
                    $db->rollBack();
                    http_response_code(409);
                    echo json_encode(['success'=>false,'erro'=>'Email de usuário já cadastrado']);
                    break;
                }
                // Gera/usa senha
                if($senhaInformada === '' || strlen($senhaInformada) < 3){
                    $senhaInicial = bin2hex(random_bytes(5)); // 10 chars hex
                } else {
                    $senhaInicial = $senhaInformada;
                }
                $hash = password_hash($senhaInicial, PASSWORD_DEFAULT);
                $insU = $db->prepare('INSERT INTO usuarios (nome,email,senha,tipo,ativo) VALUES (?,?,?,?,1)');
                $insU->execute([$usuarioNome,$usuarioEmail,$hash,'cliente']);
                $usuarioId = (int)$db->lastInsertId();
                $createdUsuarioId = $usuarioId;
            }

            // Criar cliente
            if(empty($data['razao_social'])){
                $db->rollBack();
                http_response_code(422);
                echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios ausentes (razao_social).']);
                break;
            }
            $stmt = $db->prepare('INSERT INTO clientes (usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $ok = $stmt->execute([
                $usuarioId,
                $data['razao_social'],
                $data['nome_fantasia'] ?? null,
                $data['cnpj'] ?? null,
                $data['ie'] ?? null,
                $data['endereco'] ?? null,
                $data['telefone'] ?? null,
                // usa email do payload; fallback para email do usuario criado
                ($data['email'] ?? null) ?: ($usuarioEmail ?? null),
                $data['status'] ?? 'ativo'
            ]);
            $clienteId = $ok? (int)$db->lastInsertId() : null;

            if(!$ok || !$clienteId){
                $db->rollBack();
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Falha ao criar cliente']);
                break;
            }

            // Vincula usuario -> cliente (tipo cliente)
            $upU = $db->prepare('UPDATE usuarios SET cliente_id=?, tipo="cliente" WHERE id=?');
            $upU->execute([$clienteId, $usuarioId]);

            $db->commit();

            // Auditoria pós-commit
            $uAuth = auth_usuario();
            auditoria_log($uAuth['id'] ?? null, 'cliente_create', 'clientes', $clienteId, ['usuario_id'=>$usuarioId]);

            // Notificações: cliente cadastrado
            if(!empty($data['email']) || !empty($usuarioEmail)){
                try {
                    $dest = !empty($data['email']) ? $data['email'] : $usuarioEmail;
                    $html = email_render_template('generic', [
                        'titulo' => 'Cadastro de Cliente',
                        'mensagem' => 'Seu cadastro como cliente ('.htmlspecialchars($data['razao_social']).') foi efetuado com sucesso.',
                        'acao_html' => ''
                    ]) ?? '<p>Cadastro de cliente realizado.</p>';
                    $ids = email_queue($dest, 'Cliente cadastrado', $html, [ 'tipo_evento'=>'cliente_cadastrado','cliente_id'=>$clienteId ]);
                    $emailEnviado = !empty($ids);
                } catch(Throwable $e){ $erroEmail = $e->getMessage(); }
            }

            // Se criamos um novo usuario, envia email de boas-vindas com senha inicial
            if($createdUsuarioId && $usuarioEmail){
                try {
                    $loginLink = (defined('APP_URL')?APP_URL:'').'/pages/login.php';
                    $htmlSenha = email_render_template('generic', [
                        'titulo' => 'Acesso ao Portal do Cliente',
                        'mensagem' => 'Sua conta foi criada. Use o email <strong>'.htmlspecialchars($usuarioEmail).'</strong> e a senha abaixo para entrar:<br>'
                            .'<div style="margin:14px 0;padding:14px 18px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;font-weight:600;letter-spacing:.5px;color:#1e293b;display:inline-block">'
                            .htmlspecialchars($senhaInicial)
                            .'</div><br>Por segurança, altere a senha após o primeiro acesso.',
                        'acao_html' => '<a class="btn" href="'.$loginLink.'" target="_blank">Acessar agora</a>',
                        'extra_css' => email_force_light_css()
                    ]) ?? ('<p>Bem-vindo(a)</p><p>Senha inicial: '.htmlspecialchars($senhaInicial).'</p>');
                    $idsSenha = email_queue($usuarioEmail, 'Acesso ao Portal - Senha inicial', $htmlSenha, [
                        'tipo_evento' => 'usuario_senha_inicial',
                        'usuario_id'  => $createdUsuarioId
                    ]);
                    $emailSenhaEnviado = !empty($idsSenha);
                } catch(Throwable $e){ /* ignora */ }
            }

            echo json_encode([
                'success' => true,
                'id' => $clienteId,
                'cliente_id' => $clienteId,
                'usuario_id' => $usuarioId,
                'email_notificacao_enviado' => $emailEnviado,
                'email_senha_enviado' => $emailSenhaEnviado,
                'erro_email' => $erroEmail
            ]);
        } catch(Throwable $e){
            if($db->inTransaction()) $db->rollBack();
            http_response_code(500);
            echo json_encode(['success'=>false,'erro'=>'Falha ao processar cadastro de cliente']);
        }
        break;
    case 'PUT':
        rate_limit_enforce($db, 'api/clientes_put', 60, 300, true);
        $data = read_request_payload();
        $stmt = $db->prepare('UPDATE clientes SET razao_social=?, nome_fantasia=?, cnpj=?, ie=?, endereco=?, telefone=?, email=?, status=? WHERE id=?');
        $ok = $stmt->execute([
            $data['razao_social'], $data['nome_fantasia'], $data['cnpj'], $data['ie'], $data['endereco'], $data['telefone'], $data['email'], $data['status'], $data['id']
        ]);
        echo json_encode(['success' => $ok]);
        break;
    case 'DELETE':
        rate_limit_enforce($db, 'api/clientes_delete', 60, 300, true);
        $data = read_request_payload();
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success'=>false,'erro'=>'ID inválido']);
            break;
        }
        try {
            $db->beginTransaction();
            // Captura dados para auditoria / decisões (exclusão usuário, etc.)
            $stSel = $db->prepare('SELECT id, usuario_id FROM clientes WHERE id=?');
            $stSel->execute([$id]);
            $row = $stSel->fetch();
            if(!$row){
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['success'=>false,'erro'=>'Registro não encontrado']);
                break;
            }
            // Tentativa de exclusão
            $stmt = $db->prepare('DELETE FROM clientes WHERE id=?');
            $ok = $stmt->execute([$id]);
            if(!$ok){
                throw new RuntimeException('Falha ao remover');
            }
            // Opcional: desativar usuário vinculado (evita órfão, se regra de negócio desejar)
            if(!empty($row['usuario_id'])){
                try {
                    $db->prepare('UPDATE usuarios SET cliente_id=NULL, ativo=0 WHERE id=?')->execute([$row['usuario_id']]);
                } catch(Throwable $e){ /* ignora falha secundária */ }
            }
            $db->commit();
            // Auditoria
            $uAuth = auth_usuario();
            auditoria_log($uAuth['id'] ?? null, 'cliente_delete', 'clientes', $id, ['usuario_id'=>$row['usuario_id'] ?? null]);
            echo json_encode(['success'=>true]);
        } catch(Throwable $e){
            if($db->inTransaction()) $db->rollBack();
            $msg = $e->getMessage();
            if(stripos($msg,'foreign key') !== false || stripos($msg,'constraint') !== false){
                http_response_code(409);
                echo json_encode(['success'=>false,'erro'=>'Não é possível remover: existem registros vinculados a este cliente.']);
            } else {
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Erro ao remover cliente']);
            }
        }
        break;
    default:
        http_response_code(405);
        echo json_encode(['erro' => 'Método não permitido']);
}