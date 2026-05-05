<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

try {
    $webConfigPath = __DIR__ . '/webconfig.php';
    $defaultConfigPath = __DIR__ . '/config.php';
    $configPath = is_file($webConfigPath) ? $webConfigPath : $defaultConfigPath;
    $config = require $configPath;

    require_once __DIR__ . '/lib/db.php';
    $pdo = db_connect((array)($config['db'] ?? []));

    $existsStmt = $pdo->query("SHOW TABLES LIKE 'system_users'");
    if (!$existsStmt || !$existsStmt->fetchColumn()) {
        throw new RuntimeException("Missing table 'system_users'. Please import schema first.");
    }

    $branchCode = 'HQ';
    $branchStmt = $pdo->query(
        "SELECT branch_code
         FROM master_branch
         WHERE is_latest = 1 AND is_deleted = 0
         ORDER BY id ASC
         LIMIT 1"
    );
    if ($branchStmt) {
        $firstBranch = (string)$branchStmt->fetchColumn();
        if ($firstBranch !== '') {
            $branchCode = strtoupper(trim($firstBranch));
        }
    }

    $userName = 'admin';
    $displayName = 'System Admin';
    $roleName = 'admin';
    $plainPassword = 'Smart@1234';
    $now = date('Y-m-d H:i:s');
    $passwordHash = password_hash($plainPassword, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare(
        "SELECT id, profile_json
         FROM system_users
         WHERE user_name = :user_name AND is_latest = 1 AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':user_name' => $userName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $profile = json_decode((string)($row['profile_json'] ?? ''), true);
        if (!is_array($profile)) {
            $profile = [];
        }
        $profile['branch_code'] = $profile['branch_code'] ?? $branchCode;
        $profile['password_hash'] = $passwordHash;
        unset($profile['password']);

        $upd = $pdo->prepare(
            "UPDATE system_users
             SET display_name = :display_name,
                 role_name = :role_name,
                 profile_json = :profile_json,
                 updated_by = :updated_by,
                 updated_at = :updated_at
             WHERE id = :id AND is_latest = 1"
        );
        $upd->execute([
            ':display_name' => $displayName,
            ':role_name' => $roleName,
            ':profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_by' => 'ops_fix_login_admin',
            ':updated_at' => $now,
            ':id' => (int)$row['id'],
        ]);
        $result = 'updated_existing_admin';
    } else {
        $profile = [
            'branch_code' => $branchCode,
            'password_hash' => $passwordHash,
        ];
        $ins = $pdo->prepare(
            "INSERT INTO system_users (
                user_name, display_name, role_name,
                is_latest, is_deleted,
                created_by, created_at,
                updated_by, updated_at,
                deleted_by, deleted_at,
                profile_json
             ) VALUES (
                :user_name, :display_name, :role_name,
                1, 0,
                :created_by, :created_at,
                :updated_by, :updated_at,
                NULL, NULL,
                :profile_json
             )"
        );
        $ins->execute([
            ':user_name' => $userName,
            ':display_name' => $displayName,
            ':role_name' => $roleName,
            ':created_by' => 'ops_fix_login_admin',
            ':created_at' => $now,
            ':updated_by' => 'ops_fix_login_admin',
            ':updated_at' => $now,
            ':profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $result = 'created_admin';
    }

    echo json_encode([
        'ok' => true,
        'result' => $result,
        'login' => [
            'username' => $userName,
            'password' => $plainPassword,
        ],
        'branch_code' => $branchCode,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

