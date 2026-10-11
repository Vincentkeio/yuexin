<?php

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . '/config.php';

        if (!is_file($file)) {
            throw new RuntimeException('配置文件不存在：' . $file);
        }

        $config = require $file;

        if (!is_array($config)) {
            throw new RuntimeException('配置文件必须 return array');
        }
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();

    if (!isset($config['db']) || !is_array($config['db'])) {
        throw new RuntimeException('配置错误：config.php 缺少 db 配置');
    }

    $db = $config['db'];

    $host = $db['host'] ?? '127.0.0.1';
    $port = $db['port'] ?? 3306;
    $database = $db['database'] ?? '';
    $username = $db['username'] ?? '';
    $password = $db['password'] ?? '';
    $charset = $db['charset'] ?? 'utf8mb4';

    if ($database === '' || $username === '') {
        throw new RuntimeException('配置错误：数据库名或用户名为空');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
