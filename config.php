<?php
// Exemplo de configuração para o DeKanto

// Tipo de banco: 'mysql' ou 'sqlite'
define('DB_TYPE', 'mysql');

// Configuração para MySQL
// Só será usada se DB_TYPE = 'mysql'
define('DB_HOST', 'sql300.infinityfree.com');
// Atualizado para refletir ambiente atual: banco existente 'nexus'
define('DB_NAME', 'bd');
define('DB_USER', 'user');
define('DB_PASS', 'pass');

define('DB_CHARSET', 'utf8mb4');

define('DB_PORT', 3306);

// Configuração para SQLite
// Só será usada se DB_TYPE = 'sqlite'
// Atualizado para refletir a nova marca DeKanto
define('SQLITE_PATH', __DIR__ . '/dekanto.sqlite');

// Configuração de SMTP (Gmail)
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'user');
define('SMTP_PASS', 'pass');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM', 'dekantocmpa@gmail.com');
define('SMTP_FROM_NAME', 'DeKanto CMPA');

// Outras configurações gerais
define('APP_NAME', 'DeKanto CMPA');
// Corrigido para apontar ao diretório real 'atlas'
define('APP_URL', 'https://dekantocmpa.wuaze.com');
