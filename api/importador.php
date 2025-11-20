<?php
// Carrega o autoloader do Composer para usar a biblioteca PhpSpreadsheet
require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'erro' => 'Acesso negado.']);
    exit;
}

// Função para formatar automaticamente CPF (###.###.###-##) e CNPJ (##.###.###/####-##)
function formatCpfCnpj(string $v): string {
    $d = preg_replace('/\D/', '', $v);
    if (strlen($d) === 11) {
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d);
    }
    if (strlen($d) === 14) {
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d);
    }
    return $v; // retorna original se tamanho inesperado
}

// --- CONFIGURAÇÃO DOS MÓDULOS ---
// Adicione aqui as configurações para cada novo módulo que você criar
$modulos_config = [
    'clientes' => [
        'tabela' => 'clientes',
        // As colunas DEVEM estar na mesma ordem do ficheiro de exemplo
        'colunas' => ['razao_social', 'nome_fantasia', 'cnpj', 'ie', 'endereco', 'email', 'telefone', 'status'],
        'usa_usuario_id' => true,
    ],
    'fornecedores' => [
        'tabela' => 'fornecedores',
        'colunas' => ['razao_social', 'nome_fantasia', 'cnpj', 'ie', 'endereco', 'email', 'telefone', 'status'],
        'usa_usuario_id' => true,
    ],
    'produtos' => [ // novo módulo ativo
        'tabela' => 'produtos',
        'colunas' => ['nome', 'descricao', 'ncm', 'unidade', 'preco_base'],
        'usa_usuario_id' => false,
    ],
    'requisicoes' => [
        'tabela'  => 'requisicoes',
        // Apenas colunas que o utilizador fornece no ficheiro (sem id / usuario_id / timestamps)
        'colunas' => ['titulo','cliente_id','status'], // Se a tabela ainda não tem 'titulo', criar via ALTER TABLE ou remover aqui.
        'usa_usuario_id' => false,
    ],
];

$modulo = $_POST['modulo'] ?? '';
if (!isset($modulos_config[$modulo])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => 'Modulo de importacao invalido: ' . htmlspecialchars($modulo)]);
    exit;
}

if (!isset($_FILES['arquivo_importacao']) || $_FILES['arquivo_importacao']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => 'Nenhum ficheiro foi enviado ou ocorreu um erro no upload.']);
    exit;
}

$config = $modulos_config[$modulo];
$db = get_db_connection();
$file = $_FILES['arquivo_importacao']['tmp_name'];

// NOVO: detectar colunas reais da tabela e ajustar config / uso de usuario_id
try {
    $colsReal = array_column($db->query("SHOW COLUMNS FROM {$config['tabela']}")->fetchAll(PDO::FETCH_ASSOC), 'Field');
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'erro'=>'Falha ao inspecionar estrutura da tabela: '.$e->getMessage()]);
    exit;
}
$colunasExistentes = array_values(array_intersect($config['colunas'], $colsReal));
if (empty($colunasExistentes)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'erro'=>'Nenhuma coluna válida encontrada para importação.']);
    exit;
}
$temUsuarioIdTabela = in_array('usuario_id', $colsReal, true);
$usarUsuarioId = ($config['usa_usuario_id'] ?? false) && $temUsuarioIdTabela;

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $data = $sheet->toArray(null, true, true, true);

    if (count($data) < 2) {
        throw new Exception("O ficheiro está vazio ou contém apenas o cabeçalho.");
    }

    $headersFromFile = array_map('trim', array_shift($data));
    // NOVO: usar somente colunas existentes
    $colunas_esperadas = $colunasExistentes;

    // Validação (todas esperadas precisam estar presentes)
    if (count(array_diff($colunas_esperadas, $headersFromFile)) > 0) {
        $esperados_str = implode(', ', $colunas_esperadas);
        throw new Exception("Os cabeçalhos do ficheiro não correspondem ao esperado. Esperado: $esperados_str");
    }

    $db->beginTransaction();

    // Montagem inicial do SQL (sem tentar adivinhar)
    $sql_cols = implode(', ', $colunas_esperadas) . ($usarUsuarioId ? ', usuario_id' : '');
    $sql_placeholders = ':' . implode(', :', $colunas_esperadas) . ($usarUsuarioId ? ', :usuario_id' : '');
    $sql = "INSERT INTO {$config['tabela']} ($sql_cols) VALUES ($sql_placeholders)";
    $stmt = $db->prepare($sql);

    $linhas_importadas = 0;
    $duplicados = [];
    $precisouRetrySemUsuario = false;
    $fkErros = []; // NOVO: armazena erros de FK

    // NOVO: cache de FKs válidos (ex: clientes) para evitar exceção
    $clienteIdsValidos = [];
    if ($modulo === 'requisicoes') {
        $clienteIdsValidos = $db->query('SELECT id FROM clientes')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $clienteIdsValidos = array_flip($clienteIdsValidos); // chave rápida
    }

    $linhaIndice = 1; // inicia em 1 (após cabeçalho)
    foreach ($data as $linha) {
        $linhaIndice++;
        $rowData = array_combine($headersFromFile, $linha);
        $params = [];
        foreach($colunas_esperadas as $col) {
            $val = $rowData[$col] ?? null;
            if ($col === 'cnpj' && $val) {
                $val = formatCpfCnpj($val);
            }
            if ($modulo === 'produtos' && $col === 'preco_base' && $val !== null && $val !== '') {
                $val = str_replace('.', '', $val);
                $val = str_replace(',', '.', $val);
                if (is_numeric($val)) {
                    $val = number_format((float)$val, 2, '.', '');
                }
            }
            $params[":$col"] = $val;
        }

        // NOVO: validação prévia de FK para requisicoes
        if ($modulo === 'requisicoes') {
            $cid = $rowData['cliente_id'] ?? null;
            if ($cid === null || !isset($clienteIdsValidos[(int)$cid])) {
                $fkErros[] = [
                    'linha' => $linhaIndice,
                    'motivo' => 'cliente_id inexistente',
                    'valor_cliente_id' => $cid
                ];
                continue; // ignora esta linha
            }
        }

        if ($usarUsuarioId) {
            $params[':usuario_id'] = $_SESSION['usuario_id'];
        }

        try {
            $stmt->execute($params);
            $linhas_importadas++;
        } catch (PDOException $e) {
            // Retry automático se erroneamente incluiu usuario_id
            if ($usarUsuarioId && strpos($e->getMessage(), 'usuario_id') !== false) {
                $precisouRetrySemUsuario = true;
                $usarUsuarioId = false;
                $sql_cols = implode(', ', $colunas_esperadas);
                $sql_placeholders = ':' . implode(', :', $colunas_esperadas);
                $stmt = $db->prepare("INSERT INTO {$config['tabela']} ($sql_cols) VALUES ($sql_placeholders)");
                unset($params[':usuario_id']);
                try {
                    $stmt->execute($params);
                    $linhas_importadas++;
                } catch (PDOException $e2) {
                    // Se ainda falhar com FK, registra e segue
                    if ($e2->getCode() === '23000' && strpos($e2->getMessage(), 'foreign key constraint fails') !== false) {
                        $fkErros[] = [
                            'linha' => $linhaIndice,
                            'motivo' => 'violação de chave estrangeira (retry)',
                        ];
                        continue;
                    }
                    throw $e2;
                }
                continue;
            }

            // Duplicados
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $duplicados[] = $rowData['cnpj'] ?? '(sem chave)';
                continue;
            }

            // FK violation
            if ($e->getCode() === '23000' && strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
                $fkErros[] = [
                    'linha' => $linhaIndice,
                    'motivo' => 'violação de chave estrangeira',
                ];
                continue;
            }

            // Outros erros -> aborta
            throw $e;
        }
    }

    $db->commit();

    if ($precisouRetrySemUsuario) {
        $extraInfo = ' (detetado que a tabela não possui usuario_id; import continuou sem esta coluna)';
    } else {
        $extraInfo = '';
    }

    $msgPartes = [];
    $msgPartes[] = "$linhas_importadas registos importados";
    if (count($duplicados) > 0) {
        $msgPartes[] = count($duplicados) . " duplicados ignorados";
    }
    if (count($fkErros) > 0) {
        $msgPartes[] = count($fkErros) . " com erro de chave estrangeira ignorados";
    }
    $mensagem = implode(', ', $msgPartes) . " para $modulo!" . $extraInfo;

    echo json_encode([
        'success' => true,
        'message' => $mensagem,
        'importados' => $linhas_importadas,
        'duplicados' => count($duplicados),
        'duplicados_lista' => $duplicados,
        'fk_erros' => count($fkErros),
        'fk_erros_lista' => $fkErros
    ]);

} catch (Exception $e) {
    if($db->inTransaction()){
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'erro' => "Erro ao processar o ficheiro: " . $e->getMessage()]);
}

exit;