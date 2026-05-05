<?php
declare(strict_types=1);

$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
$httpHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalHost = in_array($serverName, ['localhost', '127.0.0.1'], true)
    || in_array($httpHost, ['localhost', '127.0.0.1', 'localhost:888', '127.0.0.1:888'], true)
    || PHP_SAPI === 'cli';

$dbHost = getenv('NANFIN_DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('NANFIN_DB_PORT') ?: '3306';
$dbName = getenv('NANFIN_DB_NAME') ?: ($isLocalHost ? 'nanfinance' : '');
$dbUser = getenv('NANFIN_DB_USER') ?: ($isLocalHost ? 'root' : '');
$dbPassEnv = getenv('NANFIN_DB_PASS');
$dbPass = $dbPassEnv !== false ? $dbPassEnv : ($isLocalHost ? '' : '');

return [
    'app_name' => 'Smart Finance 360',
    'timezone' => 'Asia/Bangkok',
    'db' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'name' => $dbName,
        'user' => $dbUser,
        'pass' => $dbPass,
        'charset' => 'utf8mb4',
    ],
    'session_name' => 'nanfinance_session',
    'max_rows_per_module' => 50,
];
