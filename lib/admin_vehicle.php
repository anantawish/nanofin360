<?php
declare(strict_types=1);

/**
 * @return array<int, array<string,string>>
 */
function admin_default_car_catalog(): array
{
    return [
        ['brand_name' => 'Toyota', 'model_name' => 'Yaris'],
        ['brand_name' => 'Toyota', 'model_name' => 'Yaris Ativ'],
        ['brand_name' => 'Toyota', 'model_name' => 'Vios'],
        ['brand_name' => 'Toyota', 'model_name' => 'Corolla Altis'],
        ['brand_name' => 'Toyota', 'model_name' => 'Camry'],
        ['brand_name' => 'Toyota', 'model_name' => 'Hilux Revo'],
        ['brand_name' => 'Toyota', 'model_name' => 'Fortuner'],
        ['brand_name' => 'Honda', 'model_name' => 'City'],
        ['brand_name' => 'Honda', 'model_name' => 'Civic'],
        ['brand_name' => 'Honda', 'model_name' => 'Accord'],
        ['brand_name' => 'Honda', 'model_name' => 'HR-V'],
        ['brand_name' => 'Honda', 'model_name' => 'CR-V'],
        ['brand_name' => 'Honda', 'model_name' => 'BR-V'],
        ['brand_name' => 'Isuzu', 'model_name' => 'D-Max'],
        ['brand_name' => 'Isuzu', 'model_name' => 'MU-X'],
        ['brand_name' => 'Mitsubishi', 'model_name' => 'Mirage'],
        ['brand_name' => 'Mitsubishi', 'model_name' => 'Attrage'],
        ['brand_name' => 'Mitsubishi', 'model_name' => 'Triton'],
        ['brand_name' => 'Mitsubishi', 'model_name' => 'Pajero Sport'],
        ['brand_name' => 'Nissan', 'model_name' => 'Almera'],
        ['brand_name' => 'Nissan', 'model_name' => 'Kicks'],
        ['brand_name' => 'Nissan', 'model_name' => 'Navara'],
        ['brand_name' => 'Mazda', 'model_name' => 'Mazda2'],
        ['brand_name' => 'Mazda', 'model_name' => 'Mazda3'],
        ['brand_name' => 'Mazda', 'model_name' => 'CX-3'],
        ['brand_name' => 'Mazda', 'model_name' => 'CX-5'],
        ['brand_name' => 'Ford', 'model_name' => 'Ranger'],
        ['brand_name' => 'Ford', 'model_name' => 'Everest'],
        ['brand_name' => 'MG', 'model_name' => 'MG5'],
        ['brand_name' => 'MG', 'model_name' => 'ZS'],
        ['brand_name' => 'MG', 'model_name' => 'HS'],
        ['brand_name' => 'BYD', 'model_name' => 'Dolphin'],
        ['brand_name' => 'BYD', 'model_name' => 'Atto 3'],
        ['brand_name' => 'BYD', 'model_name' => 'Seal'],
        ['brand_name' => 'GWM', 'model_name' => 'Haval H6'],
        ['brand_name' => 'GWM', 'model_name' => 'Ora Good Cat'],
    ];
}

function admin_ensure_master_car_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS master_car_model (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            record_uid VARCHAR(80) NOT NULL,
            version_no INT UNSIGNED NOT NULL DEFAULT 1,
            is_latest TINYINT(1) NOT NULL DEFAULT 1,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            brand_name VARCHAR(120) NOT NULL,
            model_name VARCHAR(160) NOT NULL,
            data_json LONGTEXT NULL,
            created_by VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by VARCHAR(100) DEFAULT '',
            updated_at DATETIME NULL,
            deleted_by VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME NULL,
            UNIQUE KEY uniq_master_car_model_version (record_uid, version_no),
            KEY idx_master_car_model_latest (is_latest, is_deleted, brand_name, model_name),
            KEY idx_master_car_model_brand (brand_name, model_name)
        )"
    );
}

function admin_seed_default_car_models(PDO $pdo, string $actor = 'system_seed'): int
{
    $existing = (int)$pdo->query("SELECT COUNT(*) FROM master_car_model WHERE is_latest = 1")->fetchColumn();
    if ($existing > 0) {
        return 0;
    }

    $catalog = admin_default_car_catalog();
    $stmt = $pdo->prepare(
        "INSERT INTO master_car_model (
            record_uid, version_no, is_latest, is_deleted, brand_name, model_name, data_json,
            created_by, created_at, updated_by, updated_at, deleted_by, deleted_at
        ) VALUES (
            :record_uid, 1, 1, 0, :brand_name, :model_name, :data_json,
            :created_by, :created_at, :updated_by, :updated_at, NULL, NULL
        )"
    );

    $now = now_dt();
    $inserted = 0;
    foreach ($catalog as $index => $item) {
        $stmt->execute([
            ':record_uid' => sprintf('MCM-SEED-%04d', $index + 1),
            ':brand_name' => trim((string)$item['brand_name']),
            ':model_name' => trim((string)$item['model_name']),
            ':data_json' => json_encode(['seed_source' => 'default_car_catalog'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => $actor,
            ':created_at' => $now,
            ':updated_by' => $actor,
            ':updated_at' => $now,
        ]);
        $inserted++;
    }

    return $inserted;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,string>
 */
function admin_validate_car_model_payload(array $input): array
{
    $brandName = trim((string)($input['brand_name'] ?? ''));
    $modelName = trim((string)($input['model_name'] ?? ''));
    $noteText = trim((string)($input['note_text'] ?? ''));

    if ($brandName === '') {
        throw new RuntimeException('กรุณากรอกยี่ห้อรถ');
    }
    if (mb_strlen($brandName) > 120) {
        throw new RuntimeException('ยี่ห้อรถยาวเกิน 120 ตัวอักษร');
    }
    if ($modelName === '') {
        throw new RuntimeException('กรุณากรอกรุ่นรถ');
    }
    if (mb_strlen($modelName) > 160) {
        throw new RuntimeException('รุ่นรถยาวเกิน 160 ตัวอักษร');
    }
    if (mb_strlen($noteText) > 500) {
        throw new RuntimeException('หมายเหตุยาวเกิน 500 ตัวอักษร');
    }

    return [
        'brand_name' => $brandName,
        'model_name' => $modelName,
        'note_text' => $noteText,
    ];
}

/**
 * @return array<int, array<string,mixed>>
 */
function admin_fetch_car_model_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT
            id, record_uid, version_no, is_deleted, brand_name, model_name, data_json,
            created_by, created_at, updated_by, updated_at
         FROM master_car_model
         WHERE is_latest = 1
         ORDER BY is_deleted ASC, brand_name ASC, model_name ASC"
    );

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $data = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($data)) {
            $data = [];
        }
        $row['note_text'] = trim((string)($data['note_text'] ?? ''));
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return array<string, string[]>
 */
function admin_active_car_brand_model_map(PDO $pdo): array
{
    $rows = admin_fetch_car_model_rows($pdo);
    $map = [];
    foreach ($rows as $row) {
        if ((int)($row['is_deleted'] ?? 0) === 1) {
            continue;
        }

        $brand = trim((string)($row['brand_name'] ?? ''));
        $model = trim((string)($row['model_name'] ?? ''));
        if ($brand === '' || $model === '') {
            continue;
        }

        if (!isset($map[$brand])) {
            $map[$brand] = [];
        }
        $map[$brand][$model] = $model;
    }

    foreach ($map as $brand => $models) {
        $list = array_values($models);
        sort($list, SORT_NATURAL | SORT_FLAG_CASE);
        $map[$brand] = $list;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);

    return $map;
}

/**
 * @return string[]
 */
function admin_active_car_brand_names(PDO $pdo): array
{
    $map = admin_active_car_brand_model_map($pdo);
    return array_keys($map);
}
