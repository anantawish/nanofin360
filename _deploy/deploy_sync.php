<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);
ini_set('memory_limit', '1024M');
header('Content-Type: text/plain; charset=UTF-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e) {
        echo "FATAL: " . $e['message'] . " in " . $e['file'] . ":" . $e['line'] . "\n";
    }
});

$key = (string)(getenv('SF360_DEPLOY_KEY') ?: '');
if ($key === '') {
    http_response_code(500);
    echo "Missing SF360_DEPLOY_KEY environment variable\n";
    exit;
}
if (!function_exists('hash_equals') || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$base = __DIR__;
$zipPath = $base . '/smartfin_upload.zip';
$sqlPath = $base . '/smartfin_upload.sql';
if (!is_file($zipPath)) { throw new RuntimeException('Missing zip file'); }
if (!is_file($sqlPath)) { throw new RuntimeException('Missing sql file'); }

function starts_with($s, $prefix) { return substr($s, 0, strlen($prefix)) === $prefix; }
function rrmdir($path) {
    if (!file_exists($path)) return;
    if (is_file($path) || is_link($path)) { @unlink($path); return; }
    $items = scandir($path); if ($items === false) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        rrmdir($path . '/' . $item);
    }
    @rmdir($path);
}

$keep = ['deploy_sync.php'=>true, 'smartfin_upload.zip'=>true, 'smartfin_upload.sql'=>true];
$entries = scandir($base); if ($entries === false) throw new RuntimeException('scan failed');
foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    if (isset($keep[$entry])) continue;
    rrmdir($base . '/' . $entry);
}
echo "[1/4] cleaned target directory\n";

$zip = new ZipArchive();
$openResult = $zip->open($zipPath);
if ($openResult !== true) throw new RuntimeException('Cannot open zip: ' . (string)$openResult);
if (!$zip->extractTo($base)) { $zip->close(); throw new RuntimeException('Zip extract failed'); }
$zip->close();
echo "[2/4] extracted code\n";

$config = require $base . '/webconfig.php';
$db = is_array($config['db'] ?? null) ? $config['db'] : [];
$host = (string)($db['host'] ?? '127.0.0.1');
$port = (int)($db['port'] ?? 3306);
$name = (string)($db['name'] ?? '');
$user = (string)($db['user'] ?? '');
$pass = (string)($db['pass'] ?? '');
if ($name === '' || $user === '') throw new RuntimeException('Invalid DB config');

$dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4';
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$lines = file($sqlPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) throw new RuntimeException('Cannot read sql file');

$filtered = '';
$inBlockComment = false;
foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($inBlockComment) {
        if (strpos($trim, '*/') !== false) $inBlockComment = false;
        continue;
    }
    if ($trim === '') continue;
    if (starts_with($trim, '--')) continue;
    if (starts_with($trim, '/*') && !starts_with($trim, '/*!')) {
        if (strpos($trim, '*/') === false) $inBlockComment = true;
        continue;
    }
    $filtered .= $line . "\n";
}

$len = strlen($filtered);
$statement = '';
$inSingle = false; $inDouble = false; $inBacktick = false; $escape = false; $executed = 0;
for ($i = 0; $i < $len; $i++) {
    $ch = $filtered[$i];
    $statement .= $ch;
    if ($escape) { $escape = false; continue; }
    if ($ch === '\\') { $escape = true; continue; }
    if ($ch === "'" && !$inDouble && !$inBacktick) { $inSingle = !$inSingle; continue; }
    if ($ch === '"' && !$inSingle && !$inBacktick) { $inDouble = !$inDouble; continue; }
    if ($ch === '`' && !$inSingle && !$inDouble) { $inBacktick = !$inBacktick; continue; }
    if ($ch === ';' && !$inSingle && !$inDouble && !$inBacktick) {
        $sql = trim($statement);
        $statement = '';
        if ($sql === '' || $sql === ';') continue;
        $pdo->exec($sql);
        $executed++;
    }
}
$tail = trim($statement);
if ($tail !== '') {
    $pdo->exec($tail);
    $executed++;
}

echo "[3/4] imported database statements={$executed}\n";
@unlink($zipPath);
@unlink($sqlPath);
echo "[4/4] cleanup deploy files done\n";
echo "DEPLOY_OK\n";
