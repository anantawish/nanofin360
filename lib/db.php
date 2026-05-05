<?php
declare(strict_types=1);

function db_connect(array $dbConfig): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $charset = (string)($dbConfig['charset'] ?? 'utf8mb4');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        (string)$dbConfig['host'],
        (string)$dbConfig['port'],
        (string)$dbConfig['name'],
        $charset
    );

    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if (strtolower($charset) === 'utf8mb4') {
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET character_set_client = utf8mb4, character_set_connection = utf8mb4, character_set_results = utf8mb4");
    }

    return $pdo;
}
