<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

function run_check(PDO $pdo, string $name, string $sql): array
{
    try {
        $stmt = $pdo->query($sql);
        $rows = [];
        if ($stmt instanceof PDOStatement) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['ok' => true, 'name' => $name, 'rows' => $rows];
    } catch (Throwable $e) {
        return ['ok' => false, 'name' => $name, 'error' => $e->getMessage()];
    }
}

try {
    $webConfigPath = __DIR__ . '/webconfig.php';
    $defaultConfigPath = __DIR__ . '/config.php';
    $configPath = is_file($webConfigPath) ? $webConfigPath : $defaultConfigPath;
    $config = require $configPath;

    require_once __DIR__ . '/lib/db.php';
    $pdo = db_connect((array)($config['db'] ?? []));

    $checks = [];
    $checks[] = run_check($pdo, 'tables', "SHOW TABLES");
    $checks[] = run_check($pdo, 'system_users_count', "SELECT COUNT(*) AS c FROM system_users");
    $checks[] = run_check($pdo, 'workflow_records_count', "SELECT COUNT(*) AS c FROM workflow_records");
    $checks[] = run_check($pdo, 'event_ledger_count', "SELECT COUNT(*) AS c FROM event_ledger");
    $checks[] = run_check($pdo, 'notification_logs_count', "SELECT COUNT(*) AS c FROM notification_logs");
    $checks[] = run_check($pdo, 'master_branch_count', "SELECT COUNT(*) AS c FROM master_branch");
    $checks[] = run_check($pdo, 'master_product_count', "SELECT COUNT(*) AS c FROM master_product");
    $checks[] = run_check($pdo, 'master_contract_count', "SELECT COUNT(*) AS c FROM master_contract");
    $checks[] = run_check(
        $pdo,
        'master_contract_json_test',
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN JSON_VALID(data_json) THEN 1 ELSE 0 END) AS json_valid_total
         FROM master_contract
         WHERE is_latest = 1 AND is_deleted = 0"
    );
    $checks[] = run_check(
        $pdo,
        'dashboard_query_test',
        "SELECT
            COUNT(DISTINCT customer_code) AS borrower_total,
            COUNT(*) AS contract_total,
            COALESCE(SUM(JSON_LENGTH(JSON_EXTRACT(data_json, '$.payment_history'))), 0) AS installment_total,
            SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.dpd_bucket')) <> 'CURRENT' THEN 1 ELSE 0 END) AS delinquency_total,
            SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.current_status')) = 'NPL' THEN 1 ELSE 0 END) AS npl_total
         FROM master_contract
         WHERE is_latest = 1 AND is_deleted = 0"
    );

    echo json_encode(['ok' => true, 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

