<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/admin_occupation.php';
require_once __DIR__ . '/lib/admin_vehicle.php';

if (PHP_SAPI !== 'cli' && strtolower(trim(current_role_name())) !== 'admin') {
    add_flash('danger', 'Access denied: admin role required.');
    redirect_to(app_base_url('index.php'));
}

/**
 * @return array<string, string>
 */
function admin_fetch_active_branches(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT branch_code, branch_name FROM master_branch WHERE is_latest = 1 AND is_deleted = 0 ORDER BY branch_code");
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(string)$row['branch_code']] = (string)$row['branch_name'];
    }
    return $result;
}

$pdo = db();
$roleOptions = all_role_options();
$occupationTypeOptions = admin_employment_type_options();
$thaiProvinces = admin_thai_provinces();
$provinceMap = array_fill_keys($thaiProvinces, true);

admin_ensure_master_occupation_table($pdo);
admin_seed_default_occupations($pdo, current_user_name());
admin_ensure_master_car_table($pdo);
admin_seed_default_car_models($pdo, current_user_name());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));
        $action = trim((string)($_POST['admin_action'] ?? ''));
        $actor = current_user_name();
        $now = now_dt();

        if ($action === 'branch_create') {
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $branchName = trim((string)($_POST['branch_name'] ?? ''));
            $regionName = trim((string)($_POST['region_name'] ?? ''));
            if ($branchCode === '' || $branchName === '') {
                throw new RuntimeException('Please enter the branch code and branch name.');
            }
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM master_branch WHERE is_latest = 1 AND is_deleted = 0 AND branch_code = :branch_code");
            $stmtDup->execute([':branch_code' => $branchCode]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('This branch code already exists.');
            }
            $recordUid = sprintf('MBR-%s-%04d', date('YmdHis'), random_int(1000, 9999));
            $stmtInsert = $pdo->prepare("INSERT INTO master_branch (record_uid, version_no, is_latest, is_deleted, branch_code, branch_name, region_name, data_json, created_by, created_at, updated_by, updated_at, deleted_by, deleted_at) VALUES (:record_uid, 1, 1, 0, :branch_code, :branch_name, :region_name, :data_json, :created_by, :created_at, :updated_by, :updated_at, NULL, NULL)");
            $stmtInsert->execute([
                ':record_uid' => $recordUid,
                ':branch_code' => $branchCode,
                ':branch_name' => $branchName,
                ':region_name' => $regionName,
                ':data_json' => json_encode(['source' => 'admin_page'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $actor,
                ':created_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
            ]);
            add_flash('success', 'Branch created successfully.');
        } elseif ($action === 'branch_update') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            $branchName = trim((string)($_POST['branch_name'] ?? ''));
            $regionName = trim((string)($_POST['region_name'] ?? ''));
            if ($sourceId <= 0) {
                throw new RuntimeException('The branch list that needs to be edited was not found.');
            }
            if ($branchCode === '' || $branchName === '') {
                throw new RuntimeException('Please enter the branch code and branch name.');
            }
            $stmtSource = $pdo->prepare("SELECT * FROM master_branch WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Can\'t find latest branch information.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('Deleted branches cannot be edited.');
            }
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM master_branch WHERE is_latest = 1 AND is_deleted = 0 AND branch_code = :branch_code AND record_uid <> :record_uid");
            $stmtDup->execute([':branch_code' => $branchCode, ':record_uid' => (string)$source['record_uid']]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('This branch code is the same as another one.');
            }
            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_branch SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);
                $stmtInsert = $pdo->prepare("INSERT INTO master_branch (record_uid, version_no, is_latest, is_deleted, branch_code, branch_name, region_name, data_json, created_by, created_at, updated_by, updated_at, deleted_by, deleted_at) VALUES (:record_uid, :version_no, 1, 0, :branch_code, :branch_name, :region_name, :data_json, :created_by, :created_at, :updated_by, :updated_at, NULL, NULL)");
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':branch_code' => $branchCode,
                    ':branch_name' => $branchName,
                    ':region_name' => $regionName,
                    ':data_json' => (string)($source['data_json'] ?? ''),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('success', 'Branch updated successfully.');
        } elseif ($action === 'branch_delete') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('The branch entry that you want to delete was not found.');
            }
            $stmtSource = $pdo->prepare("SELECT * FROM master_branch WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Can\'t find latest branch information.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('This branch has been deleted.');
            }
            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_branch SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);
                $stmtInsert = $pdo->prepare("INSERT INTO master_branch (record_uid, version_no, is_latest, is_deleted, branch_code, branch_name, region_name, data_json, created_by, created_at, updated_by, updated_at, deleted_by, deleted_at) VALUES (:record_uid, :version_no, 1, 1, :branch_code, :branch_name, :region_name, :data_json, :created_by, :created_at, :updated_by, :updated_at, :deleted_by, :deleted_at)");
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':branch_code' => (string)$source['branch_code'],
                    ':branch_name' => (string)$source['branch_name'],
                    ':region_name' => (string)$source['region_name'],
                    ':data_json' => (string)($source['data_json'] ?? ''),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':deleted_by' => $actor,
                    ':deleted_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('warning', 'Branch soft-deleted successfully.');
        } elseif ($action === 'user_create') {
            $userName = strtolower(trim((string)($_POST['user_name'] ?? '')));
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            if (!preg_match('/^[a-z0-9_.-]{3,50}$/', $userName)) {
                throw new RuntimeException('Username must be a-z, 0-9, period, underscore, or dash. 3-50 characters long.');
            }
            if ($displayName === '') {
                throw new RuntimeException('Please enter a display name.');
            }
            if (!array_key_exists($roleName, $roleOptions)) {
                throw new RuntimeException('Invalid user role.');
            }
            $activeBranchMap = admin_fetch_active_branches($pdo);
            if (!isset($activeBranchMap[$branchCode])) {
                throw new RuntimeException('Please select a valid branch.');
            }
            $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM system_users WHERE user_name = :user_name");
            $stmtDup->execute([':user_name' => $userName]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('This username already exists.');
            }
            $stmtInsert = $pdo->prepare("INSERT INTO system_users (user_name, display_name, role_name, is_latest, is_deleted, created_by, created_at, updated_by, updated_at, deleted_by, deleted_at, profile_json) VALUES (:user_name, :display_name, :role_name, 1, 0, :created_by, :created_at, :updated_by, :updated_at, NULL, NULL, :profile_json)");
            $stmtInsert->execute([
                ':user_name' => $userName,
                ':display_name' => $displayName,
                ':role_name' => $roleName,
                ':created_by' => $actor,
                ':created_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':profile_json' => json_encode(['branch_code' => $branchCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            add_flash('success', 'User created successfully.');
        } elseif ($action === 'user_update') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $roleName = trim((string)($_POST['role_name'] ?? ''));
            $branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
            if ($sourceId <= 0) {
                throw new RuntimeException('The user entry to be edited was not found.');
            }
            if ($displayName === '') {
                throw new RuntimeException('Please enter a display name.');
            }
            if (!array_key_exists($roleName, $roleOptions)) {
                throw new RuntimeException('Invalid user role.');
            }
            $activeBranchMap = admin_fetch_active_branches($pdo);
            if (!isset($activeBranchMap[$branchCode])) {
                throw new RuntimeException('Please select a valid branch.');
            }
            $stmtSource = $pdo->prepare("SELECT * FROM system_users WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('No user information found.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('Deleted users cannot be edited.');
            }
            $stmtUpdate = $pdo->prepare("UPDATE system_users SET display_name = :display_name, role_name = :role_name, profile_json = :profile_json, updated_by = :updated_by, updated_at = :updated_at WHERE id = :id AND is_latest = 1");
            $stmtUpdate->execute([
                ':display_name' => $displayName,
                ':role_name' => $roleName,
                ':profile_json' => json_encode(['branch_code' => $branchCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':updated_by' => $actor,
                ':updated_at' => $now,
                ':id' => $sourceId,
            ]);
            add_flash('success', 'User updated successfully.');
        } elseif ($action === 'user_delete') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('The user entry to be deleted was not found.');
            }
            $stmtSource = $pdo->prepare("SELECT * FROM system_users WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('No user information found.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('This user has been deleted.');
            }
            $stmtDelete = $pdo->prepare("UPDATE system_users SET is_deleted = 1, deleted_by = :deleted_by, deleted_at = :deleted_at, updated_by = :updated_by, updated_at = :updated_at WHERE id = :id AND is_latest = 1");
            $stmtDelete->execute([':deleted_by' => $actor, ':deleted_at' => $now, ':updated_by' => $actor, ':updated_at' => $now, ':id' => $sourceId]);
            add_flash('warning', 'User soft-deleted successfully.');
        } elseif ($action === 'car_master_create') {
            $payload = admin_validate_car_model_payload($_POST);
            $stmtDup = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM master_car_model
                 WHERE is_latest = 1
                   AND is_deleted = 0
                   AND brand_name = :brand_name
                   AND model_name = :model_name"
            );
            $stmtDup->execute([
                ':brand_name' => $payload['brand_name'],
                ':model_name' => $payload['model_name'],
            ]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('This car make and model already exists.');
            }

            $recordUid = sprintf('MCM-%s-%04d', date('YmdHis'), random_int(1000, 9999));
            $stmtInsert = $pdo->prepare(
                "INSERT INTO master_car_model (
                    record_uid, version_no, is_latest, is_deleted, brand_name, model_name, data_json,
                    created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                ) VALUES (
                    :record_uid, 1, 1, 0, :brand_name, :model_name, :data_json,
                    :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
                )"
            );
            $stmtInsert->execute([
                ':record_uid' => $recordUid,
                ':brand_name' => $payload['brand_name'],
                ':model_name' => $payload['model_name'],
                ':data_json' => json_encode([
                    'note_text' => $payload['note_text'],
                    'created_scope' => 'admin_car_master',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $actor,
                ':created_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
            ]);
            add_flash('success', 'Vehicle brand/model created successfully.');
        } elseif ($action === 'car_master_update') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('Could not find the car brand/model that you want to edit.');
            }
            $payload = admin_validate_car_model_payload($_POST);

            $stmtSource = $pdo->prepare("SELECT * FROM master_car_model WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Couldn\'t find the latest car brand/model information.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('Deleted items cannot be edited.');
            }

            $stmtDup = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM master_car_model
                 WHERE is_latest = 1
                   AND is_deleted = 0
                   AND brand_name = :brand_name
                   AND model_name = :model_name
                   AND record_uid <> :record_uid"
            );
            $stmtDup->execute([
                ':brand_name' => $payload['brand_name'],
                ':model_name' => $payload['model_name'],
                ':record_uid' => (string)$source['record_uid'],
            ]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('The make and model of this car is the same as the others.');
            }

            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_car_model SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);

                $stmtInsert = $pdo->prepare(
                    "INSERT INTO master_car_model (
                        record_uid, version_no, is_latest, is_deleted, brand_name, model_name, data_json,
                        created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                    ) VALUES (
                        :record_uid, :version_no, 1, 0, :brand_name, :model_name, :data_json,
                        :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
                    )"
                );
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':brand_name' => $payload['brand_name'],
                    ':model_name' => $payload['model_name'],
                    ':data_json' => json_encode([
                        'note_text' => $payload['note_text'],
                        'updated_scope' => 'admin_car_master',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('success', 'Vehicle brand/model updated successfully.');
        } elseif ($action === 'car_master_delete') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('Could not find the car brand/model that you want to delete.');
            }

            $stmtSource = $pdo->prepare("SELECT * FROM master_car_model WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Couldn\'t find the latest car brand/model information.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('This item has been deleted.');
            }

            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_car_model SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);

                $stmtInsert = $pdo->prepare(
                    "INSERT INTO master_car_model (
                        record_uid, version_no, is_latest, is_deleted, brand_name, model_name, data_json,
                        created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                    ) VALUES (
                        :record_uid, :version_no, 1, 1, :brand_name, :model_name, :data_json,
                        :created_by, :created_at, :updated_by, :updated_at, :deleted_by, :deleted_at
                    )"
                );
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':brand_name' => (string)$source['brand_name'],
                    ':model_name' => (string)$source['model_name'],
                    ':data_json' => (string)($source['data_json'] ?? ''),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':deleted_by' => $actor,
                    ':deleted_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('warning', 'Vehicle brand/model soft-deleted successfully.');
        } elseif ($action === 'occupation_create') {
            $payload = admin_validate_occupation_payload($_POST, $provinceMap);
            $stmtDup = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM master_occupation
                 WHERE is_latest = 1
                   AND is_deleted = 0
                   AND occupation_code = :occupation_code
                   AND province_name = :province_name"
            );
            $stmtDup->execute([
                ':occupation_code' => $payload['occupation_code'],
                ':province_name' => $payload['province_name'],
            ]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('Occupation code already exists in this province.');
            }

            $recordUid = sprintf('MOC-%s-%04d', date('YmdHis'), random_int(1000, 9999));
            $stmtInsert = $pdo->prepare(
                "INSERT INTO master_occupation (
                    record_uid, version_no, is_latest, is_deleted, occupation_code, occupation_name, employment_type, province_name,
                    avg_income_min, avg_income_max, avg_income_default, agriculture_detail, data_json,
                    created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                ) VALUES (
                    :record_uid, 1, 1, 0, :occupation_code, :occupation_name, :employment_type, :province_name,
                    :avg_income_min, :avg_income_max, :avg_income_default, :agriculture_detail, :data_json,
                    :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
                )"
            );
            $stmtInsert->execute([
                ':record_uid' => $recordUid,
                ':occupation_code' => $payload['occupation_code'],
                ':occupation_name' => $payload['occupation_name'],
                ':employment_type' => $payload['employment_type'],
                ':province_name' => $payload['province_name'],
                ':avg_income_min' => $payload['avg_income_min'],
                ':avg_income_max' => $payload['avg_income_max'],
                ':avg_income_default' => $payload['avg_income_default'],
                ':agriculture_detail' => $payload['agriculture_detail'],
                ':data_json' => json_encode([
                    'note_text' => $payload['note_text'],
                    'created_scope' => 'admin_occupation',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_by' => $actor,
                ':created_at' => $now,
                ':updated_by' => $actor,
                ':updated_at' => $now,
            ]);
            add_flash('success', 'Occupation created successfully.');
        } elseif ($action === 'occupation_update') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('Missing occupation source ID for update.');
            }
            $payload = admin_validate_occupation_payload($_POST, $provinceMap);

            $stmtSource = $pdo->prepare("SELECT * FROM master_occupation WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Occupation record not found.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('Cannot update: occupation record is already deleted.');
            }

            $stmtDup = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM master_occupation
                 WHERE is_latest = 1
                   AND is_deleted = 0
                   AND occupation_code = :occupation_code
                   AND province_name = :province_name
                   AND record_uid <> :record_uid"
            );
            $stmtDup->execute([
                ':occupation_code' => $payload['occupation_code'],
                ':province_name' => $payload['province_name'],
                ':record_uid' => (string)$source['record_uid'],
            ]);
            if ((int)$stmtDup->fetchColumn() > 0) {
                throw new RuntimeException('Occupation code already exists in this province.');
            }

            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_occupation SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);

                $stmtInsert = $pdo->prepare(
                    "INSERT INTO master_occupation (
                        record_uid, version_no, is_latest, is_deleted, occupation_code, occupation_name, employment_type, province_name,
                        avg_income_min, avg_income_max, avg_income_default, agriculture_detail, data_json,
                        created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                    ) VALUES (
                        :record_uid, :version_no, 1, 0, :occupation_code, :occupation_name, :employment_type, :province_name,
                        :avg_income_min, :avg_income_max, :avg_income_default, :agriculture_detail, :data_json,
                        :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
                    )"
                );
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':occupation_code' => $payload['occupation_code'],
                    ':occupation_name' => $payload['occupation_name'],
                    ':employment_type' => $payload['employment_type'],
                    ':province_name' => $payload['province_name'],
                    ':avg_income_min' => $payload['avg_income_min'],
                    ':avg_income_max' => $payload['avg_income_max'],
                    ':avg_income_default' => $payload['avg_income_default'],
                    ':agriculture_detail' => $payload['agriculture_detail'],
                    ':data_json' => json_encode([
                        'note_text' => $payload['note_text'],
                        'updated_scope' => 'admin_occupation',
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('success', 'Occupation updated successfully.');
        } elseif ($action === 'occupation_delete') {
            $sourceId = (int)($_POST['source_id'] ?? 0);
            if ($sourceId <= 0) {
                throw new RuntimeException('Missing occupation source ID for delete.');
            }

            $stmtSource = $pdo->prepare("SELECT * FROM master_occupation WHERE id = :id AND is_latest = 1 LIMIT 1");
            $stmtSource->execute([':id' => $sourceId]);
            $source = $stmtSource->fetch();
            if (!$source) {
                throw new RuntimeException('Occupation record not found.');
            }
            if ((int)$source['is_deleted'] === 1) {
                throw new RuntimeException('Occupation record is already deleted.');
            }

            $pdo->beginTransaction();
            try {
                $stmtFlag = $pdo->prepare("UPDATE master_occupation SET is_latest = 0 WHERE record_uid = :record_uid AND is_latest = 1");
                $stmtFlag->execute([':record_uid' => (string)$source['record_uid']]);

                $stmtInsert = $pdo->prepare(
                    "INSERT INTO master_occupation (
                        record_uid, version_no, is_latest, is_deleted, occupation_code, occupation_name, employment_type, province_name,
                        avg_income_min, avg_income_max, avg_income_default, agriculture_detail, data_json,
                        created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
                    ) VALUES (
                        :record_uid, :version_no, 1, 1, :occupation_code, :occupation_name, :employment_type, :province_name,
                        :avg_income_min, :avg_income_max, :avg_income_default, :agriculture_detail, :data_json,
                        :created_by, :created_at, :updated_by, :updated_at, :deleted_by, :deleted_at
                    )"
                );
                $stmtInsert->execute([
                    ':record_uid' => (string)$source['record_uid'],
                    ':version_no' => ((int)$source['version_no']) + 1,
                    ':occupation_code' => (string)$source['occupation_code'],
                    ':occupation_name' => (string)$source['occupation_name'],
                    ':employment_type' => (string)$source['employment_type'],
                    ':province_name' => (string)$source['province_name'],
                    ':avg_income_min' => (float)$source['avg_income_min'],
                    ':avg_income_max' => (float)$source['avg_income_max'],
                    ':avg_income_default' => (float)$source['avg_income_default'],
                    ':agriculture_detail' => (string)($source['agriculture_detail'] ?? ''),
                    ':data_json' => (string)($source['data_json'] ?? ''),
                    ':created_by' => (string)$source['created_by'],
                    ':created_at' => (string)$source['created_at'],
                    ':updated_by' => $actor,
                    ':updated_at' => $now,
                    ':deleted_by' => $actor,
                    ':deleted_at' => $now,
                ]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            add_flash('warning', 'Occupation soft-deleted successfully.');
        } else {
            throw new RuntimeException('The sent command is not recognized.');
        }
    } catch (Throwable $e) {
        add_flash('danger', 'Unable to save data: ' . $e->getMessage());
    }
    redirect_to(app_base_url('admin.php'));
}

$branchRows = $pdo->query("SELECT id, record_uid, version_no, branch_code, branch_name, region_name, is_deleted, created_by, created_at, updated_by, updated_at FROM master_branch WHERE is_latest = 1 ORDER BY is_deleted ASC, branch_code ASC")->fetchAll();
$activeBranchMap = admin_fetch_active_branches($pdo);
$activeBranches = [];
foreach ($activeBranchMap as $code => $name) {
    $activeBranches[] = ['branch_code' => $code, 'branch_name' => $name];
}
$userRows = $pdo->query("SELECT id, user_name, display_name, role_name, is_deleted, created_by, created_at, updated_by, updated_at, profile_json FROM system_users WHERE is_latest = 1 ORDER BY is_deleted ASC, user_name ASC")->fetchAll();
foreach ($userRows as &$userRow) {
    $branchCode = '';
    $decoded = json_decode((string)($userRow['profile_json'] ?? ''), true);
    if (is_array($decoded) && isset($decoded['branch_code'])) {
        $branchCode = strtoupper(trim((string)$decoded['branch_code']));
    }
    $userRow['branch_code'] = $branchCode;
    $userRow['branch_name'] = $activeBranchMap[$branchCode] ?? '';
}
unset($userRow);
$activeBranchCount = count($activeBranches);
$activeUserCount = 0;
foreach ($userRows as $row) {
    if ((int)$row['is_deleted'] === 0) {
        $activeUserCount++;
    }
}

$occupationRows = admin_fetch_occupation_rows($pdo);
$activeOccupationCount = 0;
$agricultureOccupationCount = 0;
foreach ($occupationRows as $row) {
    if ((int)$row['is_deleted'] === 0) {
        $activeOccupationCount++;
        if ((string)$row['employment_type'] === 'AGRICULTURE') {
            $agricultureOccupationCount++;
        }
    }
}
$provinceCount = count($thaiProvinces);
$carMasterRows = admin_fetch_car_model_rows($pdo);
$activeCarBrandSet = [];
$activeCarModelCount = 0;
foreach ($carMasterRows as $row) {
    if ((int)($row['is_deleted'] ?? 0) === 1) {
        continue;
    }
    $brandName = trim((string)($row['brand_name'] ?? ''));
    if ($brandName !== '') {
        $activeCarBrandSet[$brandName] = true;
    }
    $activeCarModelCount++;
}
$activeCarBrandCount = count($activeCarBrandSet);

$pageTitle = 'Admin: Branch and User Management';
$currentModule = 'admin';

include __DIR__ . '/partials/head.php';
include __DIR__ . '/partials/menu.php';
?>
<section class="card shadow-sm border-0 mb-4 module-hero">
    <div class="card-body">
        <h1 class="h5 mb-1">Admin Menu</h1>
        <p class="text-muted mb-0">Manage branch and user records with soft delete, without affecting historical transactions.</p>
    </div>
</section>

<?php include __DIR__ . '/partials/admin_occupation_block.php'; ?>
<?php include __DIR__ . '/partials/admin_vehicle_block.php'; ?>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1">Branch Management</h2>
                <div class="text-muted small">Create, view, edit, and soft-delete branches.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#branchCreateModal">Add Branch</button>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#branchListModal">View Branch List</button>
            </div>
        </div>        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Active Branches</span>
                    <strong><?php echo number_format($activeBranchCount); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>All branches (latest)</span>
                    <strong><?php echo number_format(count($branchRows)); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Note</span>
                    <strong class="fs-6">Editing/deleting branches does not revert to old transaction data.</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h2 class="h6 mb-1">User Management</h2>
                <div class="text-muted small">Create, view, edit, and soft-delete users.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#userCreateModal">Add User</button>
                <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#userListModal">View User List</button>
            </div>
        </div>
        <?php if ($activeBranchCount === 0): ?>
            <div class="alert alert-warning mb-3">There are no active branches yet. Please add a branch before creating a user.</div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="stat-card">
                    <span>Active users</span>
                    <strong><?php echo number_format($activeUserCount); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>All users (latest)</span>
                    <strong><?php echo number_format(count($userRows)); ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <span>User Roles</span>
                    <strong class="fs-6">user / supervisor / admin</strong>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade" id="branchCreateModal" tabindex="-1" aria-labelledby="branchCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="branch_create">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="branchCreateModalLabel">Add Branch</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Branch code *</label>
                            <input class="form-control" name="branch_code" maxlength="60" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Branch name *</label>
                            <input class="form-control" name="branch_name" maxlength="200" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Region/Zone</label>
                            <input class="form-control" name="region_name" maxlength="120">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Branch</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="branchListModal" tabindex="-1" aria-labelledby="branchListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="branchListModalLabel">Branch List</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0 js-admin-datatable">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Branch Code</th>
                            <th>Branch Name</th>
                            <th>Region</th>
                            <th>Version</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($branchRows as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><code><?php echo h((string)$row['branch_code']); ?></code></td>
                                <td><?php echo h((string)$row['branch_name']); ?></td>
                                <td><?php echo h((string)$row['region_name']); ?></td>
                                <td><?php echo (int)$row['version_no']; ?></td>
                                <td>
                                    <?php if ((int)$row['is_deleted'] === 1): ?>
                                        <span class="badge text-bg-secondary">Deleted</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ((int)$row['is_deleted'] === 0): ?>
                                            <button
                                                class="btn btn-sm btn-outline-primary js-edit-branch-btn"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#branchEditModal"
                                                data-source-id="<?php echo (int)$row['id']; ?>"
                                                data-branch-code="<?php echo h((string)$row['branch_code']); ?>"
                                                data-branch-name="<?php echo h((string)$row['branch_name']); ?>"
                                                data-region-name="<?php echo h((string)$row['region_name']); ?>"
                                            >Edit</button>
                                            <form method="post" class="needs-confirm-delete">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_action" value="branch_delete">
                                                <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="branchEditModal" tabindex="-1" aria-labelledby="branchEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="branch_update">
                <input type="hidden" name="source_id" id="branch_edit_source_id">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="branchEditModalLabel">Edit Branch</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Branch code *</label>
                            <input class="form-control" name="branch_code" id="branch_edit_code" maxlength="60" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Branch name *</label>
                            <input class="form-control" name="branch_name" id="branch_edit_name" maxlength="200" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Region/Zone</label>
                            <input class="form-control" name="region_name" id="branch_edit_region" maxlength="120">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Changes</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="userCreateModal" tabindex="-1" aria-labelledby="userCreateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="user_create">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="userCreateModalLabel">Add User</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Username *</label>
                            <input class="form-control" name="user_name" maxlength="100" pattern="[a-z0-9_.-]{3,50}" required>
                            <div class="form-text">Use a-z, 0-9, period, underscore, dash.</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Display name *</label>
                            <input class="form-control" name="display_name" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">User Role *</label>
                            <select class="form-select" name="role_name" required>
                                <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                                    <option value="<?php echo h($roleKey); ?>"><?php echo h($roleLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Assigned Branch *</label>
                            <select class="form-select" name="branch_code" required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($activeBranches as $branch): ?>
                                    <option value="<?php echo h((string)$branch['branch_code']); ?>">
                                        <?php echo h((string)$branch['branch_code'] . ' - ' . (string)$branch['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save User</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="userListModal" tabindex="-1" aria-labelledby="userListModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h3 class="h6 mb-0" id="userListModalLabel">User List</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0 js-admin-datatable">
                        <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Display name</th>
                            <th>Role</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($userRows as $row): ?>
                            <tr>
                                <td><?php echo (int)$row['id']; ?></td>
                                <td><code><?php echo h((string)$row['user_name']); ?></code></td>
                                <td><?php echo h((string)$row['display_name']); ?></td>
                                <td><?php echo h($roleOptions[(string)$row['role_name']] ?? (string)$row['role_name']); ?></td>
                                <td><?php echo h((string)$row['branch_code']); ?> <?php echo $row['branch_name'] !== '' ? ('- ' . h((string)$row['branch_name'])) : ''; ?></td>
                                <td>
                                    <?php if ((int)$row['is_deleted'] === 1): ?>
                                        <span class="badge text-bg-secondary">Deleted</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo h((string)($row['updated_at'] ?: $row['created_at'])); ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ((int)$row['is_deleted'] === 0): ?>
                                            <button
                                                class="btn btn-sm btn-outline-primary js-edit-user-btn"
                                                type="button"
                                                data-bs-toggle="modal"
                                                data-bs-target="#userEditModal"
                                                data-source-id="<?php echo (int)$row['id']; ?>"
                                                data-user-name="<?php echo h((string)$row['user_name']); ?>"
                                                data-display-name="<?php echo h((string)$row['display_name']); ?>"
                                                data-role-name="<?php echo h((string)$row['role_name']); ?>"
                                                data-branch-code="<?php echo h((string)$row['branch_code']); ?>"
                                            >Edit</button>
                                            <form method="post" class="needs-confirm-delete">
                                                <?php echo csrf_input(); ?>
                                                <input type="hidden" name="admin_action" value="user_delete">
                                                <input type="hidden" name="source_id" value="<?php echo (int)$row['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="userEditModal" tabindex="-1" aria-labelledby="userEditModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-0 shadow">
            <form method="post" class="validate-form" novalidate>
                <?php echo csrf_input(); ?>
                <input type="hidden" name="admin_action" value="user_update">
                <input type="hidden" name="source_id" id="user_edit_source_id">
                <div class="modal-header">
                    <h3 class="h6 mb-0" id="userEditModalLabel">Edit user</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Username</label>
                            <input class="form-control" id="user_edit_user_name" readonly>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Display name *</label>
                            <input class="form-control" name="display_name" id="user_edit_display_name" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">User Role *</label>
                            <select class="form-select" name="role_name" id="user_edit_role_name" required>
                                <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                                    <option value="<?php echo h($roleKey); ?>"><?php echo h($roleLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Assigned Branch *</label>
                            <select class="form-select" name="branch_code" id="user_edit_branch_code" required>
                                <option value="">-- Select branch --</option>
                                <?php foreach ($activeBranches as $branch): ?>
                                    <option value="<?php echo h((string)$branch['branch_code']); ?>">
                                        <?php echo h((string)$branch['branch_code'] . ' - ' . (string)$branch['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-brand" type="submit">Save Changes</button>
                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
