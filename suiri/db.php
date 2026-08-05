<?php
declare(strict_types=1);

// オンライン対戦（room_*.php）が共通で使うPDO接続ヘルパー。

function suiri_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = require __DIR__ . '/db_config.php';
    if (($config['db_name'] ?? '') === 'CHANGE_THIS_DB_NAME') {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'database_not_configured']);
        exit;
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_name']
    );

    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function suiri_table(string $name): string
{
    $config = require __DIR__ . '/db_config.php';
    return ($config['table_prefix'] ?? 'suiri_') . $name;
}
