<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
header('Cache-Control: no-store');
// Add basic Content Security Policy for API responses
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");

// CORS support for InfinityFree (blocks PUT/DELETE unless preflight succeeds)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin = '';
if ($origin) {
    $serverHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $originHost = parse_url($origin, PHP_URL_HOST);
    if ($originHost && $serverHost && $originHost === $serverHost) {
        $allowedOrigin = $origin;
    }
}
if (!$allowedOrigin) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $allowedOrigin = $scheme . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-HTTP-Method-Override, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/email.php'; // envio de emails
require_once __DIR__ . '/../includes/rate_limit.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$db = get_db_connection();

function resolve_http_method(): string {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'POST') return $method;
    $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
    if (!$override && isset($_GET['_method'])) {
        $override = $_GET['_method'];
    }
    if ($override) {
        $override = strtoupper($override);
        // Limitar apenas aos métodos utilizados pela API
        if (in_array($override, ['DELETE','PUT','PATCH'], true)) {
            return $override;
        }
    }
    return $method;
}

function resolve_effective_method(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $method = resolve_http_method();
    if (($method === 'POST') && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
        $actionOverride = $_POST['_action'] ?? $_REQUEST['_action'] ?? null;
        if ($actionOverride) {
            $actionMap = [
                'update' => 'PUT',
                'editar' => 'PUT',
                'delete' => 'DELETE',
                'excluir' => 'DELETE'
            ];
            $key = strtolower(trim((string)$actionOverride));
            if (isset($actionMap[$key])) {
                $method = $actionMap[$key];
            }
        }
    }
    return $cached = $method;
}

$method = resolve_effective_method();

function read_json_body(){
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    // fallback para x-www-form-urlencoded
    parse_str($raw, $out);
    return is_array($out) ? $out : [];
}

function only_digits($v){ return preg_replace('/\D/','', (string)$v); }
function normalize_cnpj($c){ return only_digits($c); }
function format_cnpj($c){ $d = only_digits($c); if(strlen($d)!==14) return $c; return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/','$1.$2.$3/$4-$5',$d); }
function format_phone($f){ $d = only_digits($f); if(strlen($d)==11) return preg_replace('/^(\d{2})(\d)(\d{4})(\d{4})$/','($1) $2 $3-$4',$d); if(strlen($d)==10) return preg_replace('/^(\d{2})(\d{4})(\d{4})$/','($1) $2-$3',$d); return $f; }
function cnpj_exists(PDO $db, string $cnpjNorm, ?int $ignoreId=null): bool {
    if (!$cnpjNorm) return false;
    $sql = "SELECT id FROM fornecedores WHERE REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/',''),' ','') = ?";
    $params = [$cnpjNorm];
    if ($ignoreId) { $sql .= " AND id <> ?"; $params[] = $ignoreId; }
    $st = $db->prepare($sql.' LIMIT 1');
    $st->execute($params);
    return (bool)$st->fetchColumn();
}
// Validação de CNPJ com dígitos verificadores
function is_valid_cnpj_checksum(string $cnpj): bool {
    $cnpj = only_digits($cnpj);
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/^(\d)\1{13}$/', $cnpj)) return false; // rejeita repetidos
    $calc = function($base, $length) {
        $sum = 0; $pos = 0; $weights = [5,4,3,2,9,8,7,6,5,4,3,2];
        if ($length === 13) { // para primeiro DV usa pesos a partir de 5 (12 posições)
            for ($i=0; $i<12; $i++) { $sum += (int)$base[$i] * $weights[$i]; }
        } else { // segundo DV: 13 posições, pesos iniciando em 6
            $weights2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];
            for ($i=0; $i<13; $i++) { $sum += (int)$base[$i] * $weights2[$i]; }
        }
        $rest = $sum % 11;
        return ($rest < 2) ? 0 : (11 - $rest);
    };
    $dv1 = $calc($cnpj, 13);
    $dv2 = $calc($cnpj, 14);
    return ($cnpj[12] == (string)$dv1) && ($cnpj[13] == (string)$dv2);
}

// Helpers de validação
function is_valid_email(?string $email): bool {
    if ($email === null || $email === '') return true; // campo opcional
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}
function normalize_status(?string $status): ?string {
    if ($status === null) return null;
    $s = strtolower(trim($status));
    $allowed = ['ativo','inativo'];
    return in_array($s, $allowed, true) ? $s : null;
}
function sanitize_str($v, int $maxLen = 255): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '') return null;
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

function fornecedores_status_options(PDO $db): array {
    try {
        $rows = $db->query("SELECT DISTINCT status FROM fornecedores WHERE status IS NOT NULL AND status<>'' ORDER BY status")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach($rows as $status){
            $label = ucwords(str_replace('_',' ', (string)$status));
            $out[] = ['value'=>$status,'label'=>$label];
        }
        return $out;
    } catch(Throwable $e){
        return [];
    }
}

function fornecedores_build_filters($buscaRaw, $statusRaw, array &$params): array {
    $where = [];
    $busca = trim((string)($buscaRaw ?? ''));
    if($busca !== ''){
        $buscaLower = function_exists('mb_strtolower') ? mb_strtolower($busca,'UTF-8') : strtolower($busca);
        $like = '%'.$buscaLower.'%';
        $or = [
            'LOWER(razao_social) LIKE ?',
            'LOWER(nome_fantasia) LIKE ?',
            'LOWER(email) LIKE ?'
        ];
        $params[] = $like; $params[] = $like; $params[] = $like;
        $cnpjDigits = only_digits($busca);
        if($cnpjDigits !== ''){
            $or[] = "REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/',''),' ','') LIKE ?";
            $params[] = '%'.$cnpjDigits.'%';
        }
        $where[] = '('.implode(' OR ', $or).')';
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

// Rate limit por método
try {
    $rota = 'api/fornecedores.php:' . $method;
    if ($method === 'GET') rate_limit_enforce($db, $rota, 120, 60, true);
    else rate_limit_enforce($db, $rota, 40, 60, true);
} catch (Throwable $e) { /* fail-open */ }

try {
    switch ($method) {
        case 'GET':
            $mode = isset($_GET['mode']) ? strtolower(trim((string)$_GET['mode'])) : null;
            if ($mode === 'ids') {
                $params = [];
                $filters = fornecedores_build_filters($_GET['busca'] ?? null, $_GET['status'] ?? null, $params);
                $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
                $sql = 'SELECT id FROM fornecedores' . $whereSql . ' ORDER BY id DESC';
                $st = $db->prepare($sql);
                $st->execute($params);
                $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                echo json_encode(['ids' => $ids, 'total' => count($ids)]);
                break;
            }
            // Busca direta por ID, se fornecida
            if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
                $id = (int)$_GET['id'];
                $st = $db->prepare('SELECT * FROM fornecedores WHERE id=? LIMIT 1');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                echo json_encode($row ?: null);
                break;
            }
            $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
            if (strlen($q) > 100) { $q = substr($q, 0, 100); }
            // Optional status filters: accept only allowed values
            $statusFilters = [];
            if (isset($_GET['status'])) {
                $rawStatus = $_GET['status'];
                $arr = is_array($rawStatus) ? $rawStatus : preg_split('/[\s,]+/', trim((string)$rawStatus));
                foreach ($arr as $s) {
                    $norm = normalize_status($s);
                    if ($norm !== null) { $statusFilters[$norm] = true; }
                }
                $statusFilters = array_keys($statusFilters);
            }
            // Ordenação com whitelist
            $sortBy = isset($_GET['sort_by']) ? strtolower(trim((string)$_GET['sort_by'])) : '';
            $sortDir = isset($_GET['sort_dir']) ? strtolower(trim((string)$_GET['sort_dir'])) : '';
            $allowedSorts = [
                'razao_social' => 'razao_social',
                'id' => 'id',
                'status' => 'status',
                'cnpj' => 'cnpj'
            ];
            $dir = ($sortDir === 'desc') ? 'DESC' : 'ASC';
            // defaults diferentes para busca vs lista
            $orderSearch = isset($allowedSorts[$sortBy]) ? ($allowedSorts[$sortBy] . ' ' . $dir) : 'razao_social ASC';
            $orderList = isset($allowedSorts[$sortBy]) ? ($allowedSorts[$sortBy] . ' ' . $dir) : 'id DESC';

            if ($q !== '') {
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
                if ($limit < 1) $limit = 1; if ($limit > 50) $limit = 50;
                $like = '%' . $q . '%';
                $cnpjDigits = only_digits($q);
                $params = [];
                $wheres = [];
                // Monta condições de busca evitando CNPJ LIKE '%%' (que retornaria tudo)
                $parts = [];
                // Nome/Razão sempre que $q não vazio
                $parts[] = "(razao_social LIKE ? OR nome_fantasia LIKE ?)";
                $params[] = $like; $params[] = $like;
                // CNPJ apenas se houver ao menos 1 dígito na busca
                if ($cnpjDigits !== '') {
                    $parts[] = "REPLACE(REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/',''),' ','') LIKE ?";
                    $params[] = '%' . $cnpjDigits . '%';
                }
                if (!empty($parts)) { $wheres[] = '(' . implode(' OR ', $parts) . ')'; }
                if (!empty($statusFilters)) {
                    $wheres[] = 'status IN (' . implode(',', array_fill(0, count($statusFilters), '?')) . ')';
                    foreach ($statusFilters as $sf) { $params[] = $sf; }
                }
                $sql = "SELECT id, razao_social, nome_fantasia, cnpj, email, status FROM fornecedores";
                if (!empty($wheres)) $sql .= ' WHERE ' . implode(' AND ', $wheres);
                $sql .= " ORDER BY $orderSearch LIMIT $limit";
                $st = $db->prepare($sql);
                $st->execute($params);
                echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
                break;
            }
            $buscaParam = $_GET['busca'] ?? null;
            $statusParam = $_GET['status'] ?? null;
            $hasPagination = isset($_GET['page']) || isset($_GET['per_page']) || $buscaParam !== null || $statusParam !== null;
            if ($hasPagination) {
                $params = [];
                $filters = fornecedores_build_filters($buscaParam, $statusParam, $params);
                $whereSql = $filters ? (' WHERE ' . implode(' AND ', $filters)) : '';
                $page = max(1, (int)($_GET['page'] ?? 1));
                $perPage = min(200, max(5, (int)($_GET['per_page'] ?? 20)));
                $offset = ($page - 1) * $perPage;

                $dataSql = "SELECT id, usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status
                            FROM fornecedores
                            $whereSql
                            ORDER BY id DESC
                            LIMIT $perPage OFFSET $offset";
                $stData = $db->prepare($dataSql);
                $stData->execute($params);
                $rows = $stData->fetchAll(PDO::FETCH_ASSOC);

                $countSql = 'SELECT COUNT(*) FROM fornecedores' . $whereSql;
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
                        'status_options' => fornecedores_status_options($db)
                    ]
                ]);
                break;
            }
            // fallback: lista (opcionalmente filtrada por status). Limit opcional para não quebrar compatibilidade
            $params = [];
            $wheres = [];
            if (!empty($statusFilters)) {
                $wheres[] = 'status IN (' . implode(',', array_fill(0, count($statusFilters), '?')) . ')';
                foreach ($statusFilters as $sf) { $params[] = $sf; }
            }
            $sql = 'SELECT * FROM fornecedores';
            if (!empty($wheres)) $sql .= ' WHERE ' . implode(' AND ', $wheres);
            $sql .= " ORDER BY $orderList";
            $usePagination = isset($_GET['page']) || isset($_GET['per_page']);
            if (isset($_GET['limit'])) {
                $limitAll = (int)$_GET['limit'];
                if ($limitAll < 1) $limitAll = 1; if ($limitAll > 1000) $limitAll = 1000; // hard cap
                $sql .= " LIMIT $limitAll";
            } elseif ($usePagination) {
                $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
                $perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
                if ($perPage < 1) $perPage = 1; if ($perPage > 200) $perPage = 200;
                $offset = ($page - 1) * $perPage;
                $sql .= " LIMIT $perPage OFFSET $offset";
            }
            $st = $db->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll();

            if ($usePagination && !isset($_GET['limit'])) {
                $sqlCount = 'SELECT COUNT(*) FROM fornecedores';
                if (!empty($wheres)) $sqlCount .= ' WHERE ' . implode(' AND ', $wheres);
                $stc = $db->prepare($sqlCount);
                $stc->execute($params);
                $total = (int)$stc->fetchColumn();
                echo json_encode(['data'=>$rows,'page'=>$page,'per_page'=>$perPage,'total'=>$total]);
            } else {
                echo json_encode($rows);
            }
            break;
        case 'POST':
            $data = read_json_body();
            unset($data['_action']);
            // ================= NOVO: fluxo de criação de usuário (onboarding fornecedor) =================
            $createdUsuarioId = null; $senhaInicial = null; $erroEmail = null; $emailEnviadoCadastro = false; $emailSenhaEnviado = false;
            // Sanitização básica de campos já existentes
            $data['razao_social'] = sanitize_str($data['razao_social'] ?? null, 255);
            $data['nome_fantasia'] = sanitize_str($data['nome_fantasia'] ?? null, 255);
            $data['ie'] = sanitize_str($data['ie'] ?? null, 40);
            $data['endereco'] = sanitize_str($data['endereco'] ?? null, 255);
            $data['telefone'] = sanitize_str($data['telefone'] ?? null, 32);
            $data['email'] = sanitize_str($data['email'] ?? null, 255);

            // Campos de usuário para acesso ao portal (opcionais, mas se fornecidos criam usuário)
            $usuarioId = (int)($data['usuario_id'] ?? 0);
            $usuarioNome  = sanitize_str($data['usuario_nome'] ?? ($data['nome_usuario'] ?? null), 255);
            $usuarioEmail = sanitize_str($data['usuario_email'] ?? ($data['email_usuario'] ?? null), 255);
            $senhaInformada = trim($data['senha_inicial'] ?? $data['senha'] ?? '');

            if (empty($data['razao_social']) || empty($data['cnpj'])) {
                http_response_code(422);
                echo json_encode(['success'=>false,'erro'=>'Campos obrigatórios ausentes (razao_social, cnpj).']);
                break;
            }
            if (!empty($data['email']) && !is_valid_email($data['email'])) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'E-mail inválido.']); break; }
            if ($usuarioId <= 0 && ($usuarioNome || $usuarioEmail)) {
                // Se um dos campos foi informado, exigimos ambos
                if (!$usuarioNome || !$usuarioEmail) {
                    http_response_code(422); echo json_encode(['success'=>false,'erro'=>'Informe usuario_nome e usuario_email ou omita ambos.']); break; }
                if(!is_valid_email($usuarioEmail)) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'E-mail de usuário inválido.']); break; }
                // Unicidade do e-mail do usuário
                $stChk = $db->prepare('SELECT id FROM usuarios WHERE email=? LIMIT 1');
                $stChk->execute([$usuarioEmail]);
                if($stChk->fetch()) { http_response_code(409); echo json_encode(['success'=>false,'erro'=>'Email de usuário já cadastrado.']); break; }
            }

            $statusNorm = normalize_status($data['status'] ?? null) ?? 'ativo';
            $cnpjNorm = normalize_cnpj($data['cnpj']);
            if (strlen($cnpjNorm) !== 14 || !is_valid_cnpj_checksum($cnpjNorm)) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'CNPJ inválido.']); break; }
            if (cnpj_exists($db, $cnpjNorm)) { http_response_code(409); echo json_encode(['success'=>false,'erro'=>'CNPJ já cadastrado.']); break; }
            $cnpjFmt = format_cnpj($cnpjNorm);
            $telFmt = isset($data['telefone']) ? format_phone($data['telefone']) : null;

            try {
                $db->beginTransaction();
                // Criação opcional de usuário vinculado (tipo fornecedor)
                if($usuarioId <= 0 && $usuarioNome && $usuarioEmail){
                    if($senhaInformada === '' || strlen($senhaInformada) < 3){
                        $senhaInicial = bin2hex(random_bytes(5));
                    } else { $senhaInicial = $senhaInformada; }
                    $hash = password_hash($senhaInicial, PASSWORD_DEFAULT);
                    $insU = $db->prepare('INSERT INTO usuarios (nome,email,senha,tipo,ativo) VALUES (?,?,?,?,1)');
                    $insU->execute([$usuarioNome,$usuarioEmail,$hash,'fornecedor']);
                    $usuarioId = (int)$db->lastInsertId();
                    $createdUsuarioId = $usuarioId;
                }

                $stmt = $db->prepare('INSERT INTO fornecedores (usuario_id, razao_social, nome_fantasia, cnpj, ie, endereco, telefone, email, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $ok = $stmt->execute([
                    $usuarioId > 0 ? $usuarioId : null,
                    $data['razao_social'],
                    $data['nome_fantasia'] ?? null,
                    $cnpjFmt,
                    $data['ie'] ?? null,
                    $data['endereco'] ?? null,
                    $telFmt,
                    $data['email'] ?? ($usuarioEmail ?? null),
                    $statusNorm
                ]);
                $fornecedorId = $ok ? (int)$db->lastInsertId() : 0;

                if(!$ok || !$fornecedorId){
                    $db->rollBack();
                    http_response_code(500); echo json_encode(['success'=>false,'erro'=>'Falha ao criar fornecedor.']); break; }

                // Vincula fornecedor_id ao usuário recém-criado (se houve)
                if($createdUsuarioId){
                    try { $db->prepare('UPDATE usuarios SET fornecedor_id=? WHERE id=?')->execute([$fornecedorId,$createdUsuarioId]); } catch(Throwable $e) {/* ignorar */}
                }
                $db->commit();

                // ================= E-mails pós commit =================
                if(!empty($data['email']) || !empty($usuarioEmail)){
                    try {
                        $dest = !empty($data['email']) ? $data['email'] : $usuarioEmail;
                        $html = email_render_template('generic', [
                            'titulo' => 'Cadastro de Fornecedor',
                            'mensagem' => 'Seu cadastro como fornecedor ('.htmlspecialchars($data['razao_social']).') foi efetuado com sucesso.',
                            'acao_html' => ''
                        ]) ?? '<p>Cadastro de fornecedor realizado.</p>';
                        $ids = email_queue($dest, 'Fornecedor cadastrado', $html, [ 'tipo_evento'=>'fornecedor_cadastrado','fornecedor_id'=>$fornecedorId ]);
                        $emailEnviadoCadastro = !empty($ids);
                    } catch(Throwable $e){ $erroEmail = $e->getMessage(); }
                }
                if($createdUsuarioId && $usuarioEmail){
                    try {
                        $loginLink = (defined('APP_URL')?APP_URL:'').'/pages/login.php';
                        $htmlSenha = email_render_template('generic', [
                            'titulo' => 'Acesso ao Portal do Fornecedor',
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

                if ($ok) { http_response_code(201); header('Location: /api/fornecedores.php?id='.$fornecedorId); }
                echo json_encode([
                    'success'=>$ok,
                    'id'=>$fornecedorId,
                    'item'=>$ok? [
                        'id'=>$fornecedorId,
                        'usuario_id'=>$usuarioId ?: null,
                        'razao_social'=>$data['razao_social'],
                        'nome_fantasia'=>$data['nome_fantasia'] ?? null,
                        'cnpj'=>$cnpjFmt,
                        'ie'=>$data['ie'] ?? null,
                        'endereco'=>$data['endereco'] ?? null,
                        'telefone'=>$telFmt,
                        'email'=>$data['email'] ?? ($usuarioEmail ?? null),
                        'status'=>$statusNorm
                    ] : null,
                    'usuario_criado' => (bool)$createdUsuarioId,
                    'usuario_id' => $usuarioId ?: null,
                    'email_notificacao_enviado'=>$emailEnviadoCadastro,
                    'email_senha_enviado'=>$emailSenhaEnviado,
                    'erro_email'=>$erroEmail
                ]);
            } catch(Throwable $e){
                if($db->inTransaction()) $db->rollBack();
                http_response_code(500);
                echo json_encode(['success'=>false,'erro'=>'Falha ao processar cadastro de fornecedor']);
            }
            break;
        case 'PUT':
            $data = read_json_body();
            unset($data['_action']);
            $data['razao_social'] = sanitize_str($data['razao_social'] ?? null, 255);
            $data['nome_fantasia'] = sanitize_str($data['nome_fantasia'] ?? null, 255);
            $data['ie'] = sanitize_str($data['ie'] ?? null, 40);
            $data['endereco'] = sanitize_str($data['endereco'] ?? null, 255);
            $data['telefone'] = sanitize_str($data['telefone'] ?? null, 32);
            $data['email'] = sanitize_str($data['email'] ?? null, 255);
            if (empty($data['id'])) { http_response_code(400); echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
            if (!is_valid_email($data['email'] ?? null)) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'E-mail inválido.']); break; }
            $statusNorm = normalize_status($data['status'] ?? null) ?? 'ativo';
            $cnpjNorm = isset($data['cnpj']) ? normalize_cnpj($data['cnpj']) : '';
            if ($cnpjNorm && (strlen($cnpjNorm)!==14 || !is_valid_cnpj_checksum($cnpjNorm))) { http_response_code(422); echo json_encode(['success'=>false,'erro'=>'CNPJ inválido.']); break; }
            if ($cnpjNorm && cnpj_exists($db, $cnpjNorm, (int)$data['id'])) { http_response_code(409); echo json_encode(['success'=>false,'erro'=>'CNPJ já cadastrado.']); break; }
            $cnpjValue = $cnpjNorm ? format_cnpj($cnpjNorm) : ($data['cnpj'] ?? null);
            $telFmt = isset($data['telefone']) ? format_phone($data['telefone']) : null;
            $stmt = $db->prepare('UPDATE fornecedores SET razao_social=?, nome_fantasia=?, cnpj=?, ie=?, endereco=?, telefone=?, email=?, status=? WHERE id=?');
            $ok = $stmt->execute([$data['razao_social'] ?? null,$data['nome_fantasia'] ?? null,$cnpjValue,$data['ie'] ?? null,$data['endereco'] ?? null,$telFmt,$data['email'] ?? null,$statusNorm,$data['id']]);
            echo json_encode(['success'=>$ok]);
            break;
        case 'DELETE':
            $data = read_json_body();
            unset($data['_action']);
            if (empty($data['id'])) { http_response_code(400); echo json_encode(['success'=>false,'erro'=>'ID ausente']); break; }
            $stmt = $db->prepare('DELETE FROM fornecedores WHERE id=?');
            $ok = $stmt->execute([$data['id']]);
            echo json_encode(['success'=>$ok]);
            break;
        default:
            http_response_code(405);
            header('Allow: GET, POST, PUT, DELETE');
            echo json_encode(['erro'=>'Método não permitido']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    $msg = $e->getMessage();
    $methodEffective = resolve_effective_method();
    $isDuplicate = stripos($msg,'duplicate') !== false;
    $isIntegrity = stripos($msg,'integrity') !== false || stripos($msg,'foreign key') !== false;

    if ($methodEffective === 'DELETE' && $isIntegrity) {
        echo json_encode(['success'=>false,'erro'=>'Não é possível excluir o fornecedor porque existem registros vinculados a ele.']);
    } elseif ($isDuplicate || $isIntegrity) {
        echo json_encode(['success'=>false,'erro'=>'CNPJ já cadastrado.']);
    } else {
        echo json_encode(['success'=>false,'erro'=>'Erro BD: ' . substr($msg,0,120)]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'erro'=>'Erro: '.substr($e->getMessage(),0,120)]);
}
?>