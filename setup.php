<?php
session_start();
require_once __DIR__ . '/includes/branding.php';
require_once __DIR__ . '/config.php';

$state = [
    'db_exists' => false,
    'migrations_table' => false,
    'errors' => [],
    'notices' => [],
    'outputs' => []
];

function safe_collation_for_charset($charset) {
    // Fallback simples
    if (stripos($charset, 'utf8mb4') !== false) return 'utf8mb4_general_ci';
    if (stripos($charset, 'utf8') !== false) return 'utf8_general_ci';
    return $charset . '_general_ci';
}

// Helper: atualiza (ou insere) defines no config.php com segurança
function setup_write_config_php(array $consts, string $filePath): array {
    $result = ['ok' => false, 'msg' => ''];
    try {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return ['ok'=>false,'msg'=>'config.php não encontrado ou sem permissão de leitura'];
        }
        $src = file_get_contents($filePath);
        if ($src === false) return ['ok'=>false,'msg'=>'Falha ao ler config.php'];
        $original = $src;
        $appendBuf = '';
        $changed = false;
        $phpQuote = function($v){
            $v = (string)$v;
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $v) . "'";
        };
        foreach ($consts as $name => $val) {
            // Trata null como string vazia
            if ($val === null) $val = '';
            $q = $phpQuote($val);
            $pattern = "/define\\(\\s*'" . preg_quote($name,'/') . "'\\s*,\\s*([^)]*)\\)\\s*;\\s*/i";
            $replacement = "define('{$name}', {$q});";
            if (preg_match($pattern, $src)) {
                $src = preg_replace($pattern, $replacement, $src, 1);
                $changed = true;
            } else {
                $appendBuf .= "\n" . $replacement;
            }
        }
        if ($appendBuf !== '') {
            // Garante tag PHP aberta
            if ($src === '' || strpos($src, '<?php') === false) {
                $src = "<?php\n" . $src;
            }
            $src .= "\n" . $appendBuf . "\n";
            $changed = true;
        }
        if (!$changed) return ['ok'=>true,'msg'=>'Nenhuma alteração necessária em config.php'];
        // Backup simples
        $bak = $filePath . '.bak-' . date('YmdHis');
        @copy($filePath, $bak);
        // Escreve
        $ok = file_put_contents($filePath, $src);
        if ($ok === false) return ['ok'=>false,'msg'=>'Falha ao escrever no config.php'];
        return ['ok'=>true,'msg'=>'config.php atualizado com sucesso'];
    } catch (Throwable $e) {
        return ['ok'=>false,'msg'=>'Erro ao atualizar config.php: '.$e->getMessage()];
    }
}

// Detecta estado atual do banco
try {
    if (DB_TYPE === 'mysql') {
        // Conecta ao servidor (sem selecionar DB) para poder criar o banco
        $dsnServer = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
        $pdoServer = new PDO($dsnServer, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        // Verifica existência do DB
        $st = $pdoServer->query("SHOW DATABASES LIKE " . $pdoServer->quote(DB_NAME));
        $state['db_exists'] = (bool) $st->fetchColumn();

        if ($state['db_exists']) {
            // Verifica tabela de migrações
            $dsnDb = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET . ';port=' . DB_PORT;
            $pdoDb = new PDO($dsnDb, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            try {
                $st2 = $pdoDb->query("SHOW TABLES LIKE 'migrations_log'");
                $state['migrations_table'] = (bool) $st2->fetchColumn();
            } catch (Throwable $e) { /* ignora */ }
        }
    } elseif (DB_TYPE === 'sqlite') {
        $state['db_exists'] = file_exists(SQLITE_PATH);
        if ($state['db_exists']) {
            try {
                $pdoDb = new PDO('sqlite:' . SQLITE_PATH);
                $pdoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $st2 = $pdoDb->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations_log'");
                $state['migrations_table'] = (bool) $st2->fetchColumn();
            } catch (Throwable $e) { /* ignora */ }
        }
    } else {
        $state['errors'][] = 'Tipo de banco não suportado em setup.';
    }
} catch (Throwable $e) {
    $state['errors'][] = 'Falha ao verificar estado do banco: ' . $e->getMessage();
}

// --- SUPORTE AJAX: respostas JSON para wizard na landing ---
if (isset($_GET['ajax']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $resp = ['ok'=>true,'state'=>$state,'errors'=>[],'notices'=>[],'outputs'=>[]];
    try {
        if ($action === 'check_state') {
            $resp['db'] = [
                'type' => DB_TYPE,
                'host' => defined('DB_HOST')? DB_HOST : null,
                'port' => defined('DB_PORT')? DB_PORT : null,
                'name' => defined('DB_NAME')? DB_NAME : null,
                'charset' => defined('DB_CHARSET')? DB_CHARSET : null,
                'sqlite_path' => defined('SQLITE_PATH')? SQLITE_PATH : null,
            ];
        } elseif ($action === 'run_setup') {
            // Executa criação e migrações (similar ao formulário)
            if (DB_TYPE === 'mysql') {
                $dsnServer = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
                $pdoServer = new PDO($dsnServer, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                if (!$state['db_exists']) {
                    $collation = safe_collation_for_charset(DB_CHARSET);
                    $dbNameEsc = str_replace('`', '``', DB_NAME);
                    $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET " . DB_CHARSET . " COLLATE {$collation}");
                    $resp['notices'][] = 'Banco de dados criado: ' . htmlspecialchars(DB_NAME);
                    $state['db_exists'] = true;
                } else {
                    $resp['notices'][] = 'Banco já existia: ' . htmlspecialchars(DB_NAME);
                }
            } elseif (DB_TYPE === 'sqlite') {
                if (!$state['db_exists']) {
                    if (!is_dir(dirname(SQLITE_PATH))) { @mkdir(dirname(SQLITE_PATH), 0775, true); }
                    @touch(SQLITE_PATH);
                    $resp['notices'][] = 'Arquivo SQLite criado: ' . htmlspecialchars(SQLITE_PATH);
                    $state['db_exists'] = true;
                } else {
                    $resp['notices'][] = 'Arquivo SQLite já existia.';
                }
            }
            // Migrações
            ob_start();
            include __DIR__ . '/migrate.php';
            $migrateOutput = ob_get_clean();
            $resp['outputs'][] = [ 'type'=>'migrations', 'text'=>$migrateOutput ];
            $state['migrations_table'] = true;
            // Seed opcional
            if (!empty($_POST['run_seed'])) {
                ob_start();
                include __DIR__ . '/seed.php';
                $seedOutput = ob_get_clean();
                $resp['outputs'][] = [ 'type'=>'seed', 'text'=>$seedOutput ];
            }
            $resp['notices'][] = 'Setup concluído.';
            $resp['state'] = $state;
        } elseif ($action === 'save_config') {
            require_once __DIR__ . '/includes/db.php';
            $db = get_db_connection();
            $db->exec("CREATE TABLE IF NOT EXISTS configuracoes (id INT PRIMARY KEY AUTO_INCREMENT, chave VARCHAR(50) NOT NULL UNIQUE, valor TEXT)");
            $db->beginTransaction();
            $pairs = [
                'app_name'       => trim($_POST['app_name'] ?? ''),
                'app_url'        => trim($_POST['app_url'] ?? ''),
                'smtp_host'      => trim($_POST['smtp_host'] ?? ''),
                'smtp_port'      => trim($_POST['smtp_port'] ?? ''),
                'smtp_user'      => trim($_POST['smtp_user'] ?? ''),
                'smtp_pass'      => trim($_POST['smtp_pass'] ?? ''),
                'smtp_secure'    => trim($_POST['smtp_secure'] ?? ''),
                'smtp_from'      => trim($_POST['smtp_from'] ?? ''),
                'smtp_from_name' => trim($_POST['smtp_from_name'] ?? ''),
            ];
            foreach ($pairs as $k=>$v) {
                $st = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)');
                $st->execute([$k,$v]);
            }
            $db->commit();
            $resp['notices'][] = 'Configurações salvas com sucesso.';
            // Opcional: escrever em config.php
            if (!empty($_POST['write_config'])) {
                $constMap = [
                    'SMTP_HOST'      => $pairs['smtp_host'],
                    'SMTP_PORT'      => $pairs['smtp_port'],
                    'SMTP_USER'      => $pairs['smtp_user'],
                    'SMTP_PASS'      => $pairs['smtp_pass'],
                    'SMTP_SECURE'    => $pairs['smtp_secure'],
                    'SMTP_FROM'      => $pairs['smtp_from'],
                    'SMTP_FROM_NAME' => $pairs['smtp_from_name'],
                    'APP_NAME'       => $pairs['app_name'],
                    'APP_URL'        => $pairs['app_url'],
                ];
                $res = setup_write_config_php($constMap, __DIR__ . '/config.php');
                if ($res['ok']) { $resp['notices'][] = $res['msg']; }
                else { $resp['errors'][] = $res['msg']; $resp['ok'] = false; }
            }
        } else {
            $resp['ok'] = false; $resp['errors'][] = 'Ação inválida.';
        }
    } catch (Throwable $e) {
        $resp['ok'] = false; $resp['errors'][] = $e->getMessage();
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

// Ações de POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_setup'])) {
    try {
        if (DB_TYPE === 'mysql') {
            // Conecta no servidor e cria o banco, se necessário
            $dsnServer = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET;
            $pdoServer = new PDO($dsnServer, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (!$state['db_exists']) {
                $collation = safe_collation_for_charset(DB_CHARSET);
                $dbNameEsc = str_replace('`', '``', DB_NAME);
                $pdoServer->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET " . DB_CHARSET . " COLLATE {$collation}");
                $state['notices'][] = 'Banco de dados criado: ' . htmlspecialchars(DB_NAME);
                $state['db_exists'] = true;
            } else {
                $state['notices'][] = 'Banco já existia: ' . htmlspecialchars(DB_NAME);
            }
        } elseif (DB_TYPE === 'sqlite') {
            if (!$state['db_exists']) {
                // Apenas tocar o arquivo; PDO criará na primeira conexão
                if (!is_dir(dirname(SQLITE_PATH))) { @mkdir(dirname(SQLITE_PATH), 0775, true); }
                @touch(SQLITE_PATH);
                $state['notices'][] = 'Arquivo SQLite criado: ' . htmlspecialchars(SQLITE_PATH);
                $state['db_exists'] = true;
            } else {
                $state['notices'][] = 'Arquivo SQLite já existia.';
            }
        }

        // Executa migrações
        ob_start();
        include __DIR__ . '/migrate.php';
        $migrateOutput = ob_get_clean();
        $state['outputs'][] = "[MIGRATIONS]\n" . $migrateOutput;
        $state['migrations_table'] = true; // depois de rodar, espera-se que exista

        // Seed opcional
        if (!empty($_POST['run_seed'])) {
            ob_start();
            include __DIR__ . '/seed.php';
            $seedOutput = ob_get_clean();
            $state['outputs'][] = "[SEED]\n" . $seedOutput;
        }

        $state['notices'][] = 'Setup concluído.';
    } catch (Throwable $e) {
        $state['errors'][] = 'Falha na execução do setup: ' . $e->getMessage();
    }
}

// Carrega configuracoes atuais (se tabela existir)
$currentCfg = [];
try {
    $db = get_db_connection();
    $db->exec("CREATE TABLE IF NOT EXISTS configuracoes (id INT PRIMARY KEY AUTO_INCREMENT, chave VARCHAR(50) NOT NULL UNIQUE, valor TEXT)");
    $rs = $db->query('SELECT chave, valor FROM configuracoes');
    foreach ($rs->fetchAll() as $row) { $currentCfg[$row['chave']] = $row['valor']; }
} catch (Throwable $e) { /* tabela pode não existir antes do migrate */ }

// Ações de POST adicionais: salvar configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    try {
        $db = get_db_connection();
        $db->beginTransaction();
        $pairs = [
            'app_name'       => trim($_POST['app_name'] ?? ''),
            'app_url'        => trim($_POST['app_url'] ?? ''),
            'smtp_host'      => trim($_POST['smtp_host'] ?? ''),
            'smtp_port'      => trim($_POST['smtp_port'] ?? ''),
            'smtp_user'      => trim($_POST['smtp_user'] ?? ''),
            'smtp_pass'      => trim($_POST['smtp_pass'] ?? ''),
            'smtp_secure'    => trim($_POST['smtp_secure'] ?? ''),
            'smtp_from'      => trim($_POST['smtp_from'] ?? ''),
            'smtp_from_name' => trim($_POST['smtp_from_name'] ?? ''),
        ];
        foreach ($pairs as $k=>$v) {
            $st = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)');
            $st->execute([$k,$v]);
            $currentCfg[$k] = $v; // update cache local
        }
        // Upload de logo (opcional)
        if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','gif','svg'])) {
                $destDir = __DIR__ . '/assets/images';
                if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
                $dest = $destDir . '/logo.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                    $url = 'assets/images/' . 'logo.' . $ext;
                    $st = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)');
                    $st->execute(['logo', $url]);
                    $currentCfg['logo'] = $url;
                }
            }
        }
        $db->commit();
        $state['notices'][] = 'Configurações salvas com sucesso.';

        // Opcional: escrever em config.php
        if (!empty($_POST['write_config'])) {
            $constMap = [
                'SMTP_HOST'      => $pairs['smtp_host'],
                'SMTP_PORT'      => $pairs['smtp_port'],
                'SMTP_USER'      => $pairs['smtp_user'],
                'SMTP_PASS'      => $pairs['smtp_pass'],
                'SMTP_SECURE'    => $pairs['smtp_secure'],
                'SMTP_FROM'      => $pairs['smtp_from'],
                'SMTP_FROM_NAME' => $pairs['smtp_from_name'],
                'APP_NAME'       => $pairs['app_name'],
                'APP_URL'        => $pairs['app_url'],
            ];
            $res = setup_write_config_php($constMap, __DIR__ . '/config.php');
            if ($res['ok']) { $state['notices'][] = $res['msg']; }
            else { $state['errors'][] = $res['msg']; }
        }
    } catch (Throwable $e) {
        if (!empty($db) && $db->inTransaction()) $db->rollBack();
        $state['errors'][] = 'Falha ao salvar configurações: ' . $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="pt-br" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Configuração Inicial - <?php echo htmlspecialchars($branding['app_name'] ?? 'Dekanto'); ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link rel="stylesheet" href="assets/css/nexus.css">
  <style>
    :root{
      --neo-primary: var(--matrix-primary, #B5A886);
      --neo-secondary: var(--matrix-tertiary, #931621);
      /* paleta clara */
      --neo-bg: #f7f8fc;
      --neo-surface: color-mix(in oklab, #ffffff 92%, #f2f4f8 8%);
      --neo-text: var(--matrix-text-primary, #1c1f2e);
      --neo-muted: var(--matrix-text-secondary, #5c6273);
      --neo-border: var(--matrix-border, #e5e7ef);
      --shadow-soft: 0 8px 20px rgba(16,24,40,.08);
      --shadow-softer: 0 2px 8px rgba(16,24,40,.06);
    }
    html, body { background: var(--neo-bg); }
    .page-title h1 { font-weight: 800; letter-spacing: .2px; }
    .page-title p { color: var(--neo-muted); }

    /* Cards claros com vidro suave */
    .card.card-matrix { background: color-mix(in oklab, var(--neo-surface) 88%, transparent 12%); border: 1px solid var(--neo-border); box-shadow: var(--shadow-softer); }
    .card-header.card-header-matrix { background: transparent; border-bottom: 1px solid var(--neo-border); font-weight: 700; }

    /* Botões matrix refinados */
    .btn.btn-matrix-primary{ background: linear-gradient(135deg, var(--neo-primary), color-mix(in oklab, var(--neo-secondary), var(--neo-primary) 35%)); color:#111; border: none; box-shadow: var(--shadow-soft); font-weight: 700; }
    .btn.btn-matrix-primary:hover{ transform: translateY(-1px); filter: brightness(1.02); }
    .btn.btn-matrix-secondary{ border:1px solid var(--neo-border); background: transparent; color: var(--neo-text); }
    .btn.btn-matrix-secondary:hover{ background: rgba(0,0,0,.04); }

    /* Inputs */
    .form-control, .form-select { background: color-mix(in oklab, var(--neo-surface) 92%, transparent 8%); border:1px solid var(--neo-border); color: var(--neo-text); }
    .form-floating > label { color: var(--neo-muted); }

    /* Badges e alerts suaves */
    .badge.bg-secondary { background-color: rgba(0,0,0,.04) !important; border:1px solid var(--neo-border); color: var(--neo-text); }

    /* Log output claro */
    pre.bg-dark.text-light { background: color-mix(in oklab, var(--neo-surface) 92%, transparent 8%) !important; color: var(--neo-text) !important; border:1px solid var(--neo-border); }
  </style>
</head>
<body>
<div class="container py-5">
  <div class="mb-4 text-center page-title">
    <h1 class="mb-1">Configuração Inicial</h1>
    <p class="mb-0">Este assistente cria o banco de dados e aplica todas as migrações.</p>
  </div>

  <?php if ($state['errors']): ?>
    <div class="alert alert-danger">
      <strong>Erros detectados:</strong>
      <ul class="mb-0">
        <?php foreach ($state['errors'] as $e): ?>
          <li><?php echo htmlspecialchars($e); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($state['notices']): ?>
    <div class="alert alert-info">
      <ul class="mb-0">
        <?php foreach ($state['notices'] as $n): ?>
          <li><?php echo htmlspecialchars($n); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card card-matrix mb-4">
    <div class="card-header card-header-matrix d-flex justify-content-between align-items-center">
      <span><i class="bi bi-gear me-2"></i>Parâmetros de Banco (config.php)</span>
      <span class="badge bg-secondary text-uppercase"><?php echo htmlspecialchars(DB_TYPE); ?></span>
    </div>
    <div class="card-body">
      <?php if (DB_TYPE === 'mysql'): ?>
        <div class="row g-3">
          <div class="col-md-6">
            <div class="form-floating">
              <input type="text" readonly class="form-control" id="db_host" value="<?php echo htmlspecialchars(DB_HOST); ?>">
              <label for="db_host">Host</label>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-floating">
              <input type="text" readonly class="form-control" id="db_port" value="<?php echo htmlspecialchars(DB_PORT); ?>">
              <label for="db_port">Porta</label>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-floating">
              <input type="text" readonly class="form-control" id="db_name" value="<?php echo htmlspecialchars(DB_NAME); ?>">
              <label for="db_name">Banco</label>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-floating">
              <input type="text" readonly class="form-control" id="db_user" value="<?php echo htmlspecialchars(DB_USER); ?>">
              <label for="db_user">Usuário</label>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-floating">
              <input type="password" readonly class="form-control" id="db_pass" value="<?php echo htmlspecialchars(DB_PASS); ?>">
              <label for="db_pass">Senha</label>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="form-floating">
          <input type="text" readonly class="form-control" id="sqlite_path" value="<?php echo htmlspecialchars(SQLITE_PATH); ?>">
          <label for="sqlite_path">Caminho do arquivo SQLite</label>
        </div>
      <?php endif; ?>

      <div class="row mt-4">
        <div class="col-md-4">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-database-check fs-4 text-success"></i>
                <strong>Status do Banco</strong>
              </div>
              <p class="mb-1">Banco existente: <span class="badge bg-<?php echo $state['db_exists'] ? 'success' : 'secondary'; ?>"><?php echo $state['db_exists'] ? 'Sim' : 'Não'; ?></span></p>
              <p class="mb-0">Tabela de migrações: <span class="badge bg-<?php echo $state['migrations_table'] ? 'success' : 'secondary'; ?>"><?php echo $state['migrations_table'] ? 'Sim' : 'Não'; ?></span></p>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <form method="post" class="h-100 d-flex flex-column justify-content-between">
            <div>
              <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="run_seed" name="run_seed">
                <label class="form-check-label" for="run_seed">Rodar seed inicial (opcional)</label>
              </div>
              <div class="alert alert-warning small">
                Atenção: este processo irá criar o banco (se necessário) e aplicar todas as migrações. Recomendado executar apenas uma vez por ambiente.
              </div>
            </div>
            <div>
              <button type="submit" name="run_setup" value="1" class="btn btn-matrix-primary">
                <i class="bi bi-play-circle me-1"></i> Criar Banco e Rodar Migrações
              </button>
              <a href="index.php" class="btn btn-matrix-secondary ms-2">Voltar</a>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

  <?php if ($state['outputs']): ?>
    <div class="card card-matrix mb-4">
      <div class="card-header card-header-matrix"><i class="bi bi-terminal me-2"></i>Saída</div>
      <div class="card-body">
        <?php foreach ($state['outputs'] as $out): ?>
          <pre class="bg-dark text-light p-3 rounded" style="white-space:pre-wrap;">&lt;?php echo htmlspecialchars($out); ?&gt;</pre>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card card-matrix mb-4">
    <div class="card-header card-header-matrix"><i class="bi bi-sliders me-2"></i>Configurações da Aplicação e SMTP</div>
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <div class="row g-3">
          <div class="col-md-6">
            <div class="form-floating">
              <input type="text" class="form-control" id="app_name" name="app_name" placeholder="Nome da aplicação" value="<?php echo htmlspecialchars($currentCfg['app_name'] ?? ($branding['app_name'] ?? '')); ?>">
              <label for="app_name">Nome da aplicação</label>
            </div>
          </div>
          <div class="col-md-6">
            <div class="form-floating">
              <input type="text" class="form-control" id="app_url" name="app_url" placeholder="http://localhost/atlas" value="<?php echo htmlspecialchars($currentCfg['app_url'] ?? (defined('APP_URL')? APP_URL : '')); ?>">
              <label for="app_url">URL base (APP_URL)</label>
            </div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-4">
            <div class="form-floating">
              <input type="text" class="form-control" id="smtp_host" name="smtp_host" placeholder="smtp.exemplo.com" value="<?php echo htmlspecialchars($currentCfg['smtp_host'] ?? (defined('SMTP_HOST')? SMTP_HOST : '')); ?>">
              <label for="smtp_host">SMTP Host</label>
            </div>
          </div>
          <div class="col-md-2">
            <div class="form-floating">
              <input type="number" class="form-control" id="smtp_port" name="smtp_port" placeholder="587" value="<?php echo htmlspecialchars($currentCfg['smtp_port'] ?? (defined('SMTP_PORT')? SMTP_PORT : 587)); ?>">
              <label for="smtp_port">SMTP Porta</label>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-floating">
              <input type="text" class="form-control" id="smtp_user" name="smtp_user" placeholder="usuario" value="<?php echo htmlspecialchars($currentCfg['smtp_user'] ?? (defined('SMTP_USER')? SMTP_USER : '')); ?>">
              <label for="smtp_user">SMTP Usuário</label>
            </div>
          </div>
          <div class="col-md-3">
            <div class="form-floating">
              <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" placeholder="senha" value="<?php echo htmlspecialchars($currentCfg['smtp_pass'] ?? (defined('SMTP_PASS')? SMTP_PASS : '')); ?>">
              <label for="smtp_pass">SMTP Senha</label>
            </div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-3">
            <div class="form-floating">
              <select class="form-select" id="smtp_secure" name="smtp_secure">
                <?php $sec = $currentCfg['smtp_secure'] ?? (defined('SMTP_SECURE')? SMTP_SECURE : 'tls'); ?>
                <option value="tls" <?php echo ($sec==='tls')?'selected':''; ?>>TLS</option>
                <option value="ssl" <?php echo ($sec==='ssl')?'selected':''; ?>>SSL</option>
                <option value="" <?php echo ($sec==='' )?'selected':''; ?>>Nenhum</option>
              </select>
              <label for="smtp_secure">Segurança</label>
            </div>
          </div>
          <div class="col-md-4">
            <div class="form-floating">
              <input type="email" class="form-control" id="smtp_from" name="smtp_from" placeholder="no-reply@dominio" value="<?php echo htmlspecialchars($currentCfg['smtp_from'] ?? (defined('SMTP_FROM')? SMTP_FROM : '')); ?>">
              <label for="smtp_from">SMTP From</label>
            </div>
          </div>
          <div class="col-md-5">
            <div class="form-floating">
              <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" placeholder="Nome do remetente" value="<?php echo htmlspecialchars($currentCfg['smtp_from_name'] ?? (defined('SMTP_FROM_NAME')? SMTP_FROM_NAME : ($branding['app_name'] ?? ''))); ?>">
              <label for="smtp_from_name">Nome do remetente</label>
            </div>
          </div>
        </div>
        <div class="row g-3 mt-1">
          <div class="col-md-8">
            <div class="form-floating">
              <input type="file" class="form-control" id="logo" name="logo" accept=".png,.jpg,.jpeg,.gif,.svg">
              <label for="logo">Logo (opcional)</label>
            </div>
            <?php if (!empty($currentCfg['logo'])): ?>
            <div class="form-text mt-1">Logo atual: <a href="<?php echo htmlspecialchars($currentCfg['logo']); ?>" target="_blank">ver</a></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" value="1" id="write_config" name="write_config">
          <label class="form-check-label" for="write_config">Atualizar config.php com estas definições (opcional)</label>
        </div>
        <div class="mt-3">
          <button type="submit" name="save_config" value="1" class="btn btn-matrix-primary">
            <i class="bi bi-save me-1"></i> Salvar Configurações
          </button>
        </div>
      </form>
    </div>
  </div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
