<?php
require_once __DIR__ . '/../config.php';

function get_db_connection() {
    static $pdo = null;
    if ($pdo) return $pdo;

    if (DB_TYPE === 'mysql') {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET . ';port=' . DB_PORT;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ];
        // Ativa buffering para permitir múltiplos statements preparados na migration
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } elseif (DB_TYPE === 'sqlite') {
        $dsn = 'sqlite:' . SQLITE_PATH;
        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } else {
        die('Tipo de banco não suportado.');
    }
    return $pdo;
}