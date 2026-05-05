<?php
declare(strict_types=1);

function set_app_config(array $config): void
{
    $GLOBALS['__app_config'] = $config;
}

function app_config(): array
{
    return $GLOBALS['__app_config'] ?? [];
}

function set_db(PDO $pdo): void
{
    $GLOBALS['__pdo'] = $pdo;
}

function db(): PDO
{
    if (!isset($GLOBALS['__pdo']) || !($GLOBALS['__pdo'] instanceof PDO)) {
        throw new RuntimeException('Database not initialized');
    }
    return $GLOBALS['__pdo'];
}

function ensure_workflow_performance_indexes(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($schema === '') {
            return;
        }

        $indexes = [
            'idx_module_latest_branch_id' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_branch_id (module_key, is_latest, branch_code, id)',
            'idx_module_latest_branch_status' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_branch_status (module_key, is_latest, branch_code, record_status, is_deleted)',
            'idx_module_latest_record_uid' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_record_uid (module_key, is_latest, record_uid, id)',
            'idx_module_latest_primary_ref' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_primary_ref (module_key, is_latest, primary_ref, id)',
            'idx_module_latest_customer_ref' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_customer_ref (module_key, is_latest, customer_ref, id)',
            'idx_module_latest_primary_name' => 'ALTER TABLE workflow_records ADD INDEX idx_module_latest_primary_name (module_key, is_latest, primary_name, id)',
        ];

        $stmtExists = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = :table_schema
               AND table_name = :table_name
               AND index_name = :index_name'
        );

        foreach ($indexes as $indexName => $sql) {
            $stmtExists->execute([
                ':table_schema' => $schema,
                ':table_name' => 'workflow_records',
                ':index_name' => $indexName,
            ]);

            $exists = (int)$stmtExists->fetchColumn() > 0;
            if (!$exists) {
                $pdo->exec($sql);
            }
        }
    } catch (Throwable $e) {
        // Never block app flow if index creation is not allowed in this environment.
    }
}

function ensure_event_ledger_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS event_ledger (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(60) NOT NULL,
                module_key VARCHAR(120) NOT NULL,
                record_uid VARCHAR(191) NOT NULL,
                version_no INT NOT NULL DEFAULT 1,
                event_payload LONGTEXT NULL,
                actor_name VARCHAR(120) NOT NULL DEFAULT '',
                actor_role VARCHAR(80) NOT NULL DEFAULT '',
                ip_address VARCHAR(80) NOT NULL DEFAULT '',
                device_info VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL,
                KEY idx_event_module_created (module_key, created_at),
                KEY idx_event_record_latest (module_key, record_uid, id),
                KEY idx_event_type_created (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Do not block application bootstrap in environments without DDL rights.
    }
}

function nanfin_table_exists(PDO $pdo, string $tableName): bool
{
    static $cache = [];

    $schema = '';
    try {
        $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }

    if ($schema === '' || $tableName === '') {
        return false;
    }

    $cacheKey = $schema . '.' . $tableName;
    if (array_key_exists($cacheKey, $cache)) {
        return (bool)$cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = :table_schema
               AND table_name = :table_name'
        );
        $stmt->execute([
            ':table_schema' => $schema,
            ':table_name' => $tableName,
        ]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $cache[$cacheKey] = $exists;
        return $exists;
    } catch (Throwable $e) {
        $cache[$cacheKey] = false;
        return false;
    }
}

function nanfin_is_valid_utf8(string $value): bool
{
    if ($value === '') {
        return true;
    }

    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($value, 'UTF-8');
    }

    return preg_match('//u', $value) === 1;
}

function nanfin_repair_request_text(string $value): string
{
    if ($value === '') {
        return '';
    }

    $text = str_replace("\0", '', $value);
    if (!nanfin_is_valid_utf8($text)) {
        $converted = null;
        if (function_exists('mb_convert_encoding')) {
            $try = null;
            try {
                $try = mb_convert_encoding($text, 'UTF-8', 'CP874,TIS-620,ISO-8859-1,Windows-1252');
            } catch (Throwable $e) {
                $try = null;
            }
            if (is_string($try) && $try !== '' && nanfin_is_valid_utf8($try)) {
                $converted = $try;
            }
        }

        if (!is_string($converted) && function_exists('iconv')) {
            $try = @iconv('Windows-874', 'UTF-8//IGNORE', $text);
            if (is_string($try) && $try !== '' && nanfin_is_valid_utf8($try)) {
                $converted = $try;
            }
        }

        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    return nanfin_normalize_display_text($text);
}

/**
 * @param mixed $value
 * @return mixed
 */
function nanfin_normalize_request_value($value)
{
    if (is_string($value)) {
        return nanfin_repair_request_text($value);
    }

    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalizedKey = is_string($key) ? nanfin_repair_request_text($key) : $key;
            $normalized[$normalizedKey] = nanfin_normalize_request_value($item);
        }
        return $normalized;
    }

    return $value;
}

function nanfin_normalize_request_encoding(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (isset($_GET) && is_array($_GET)) {
        $_GET = nanfin_normalize_request_value($_GET);
    }
    if (isset($_POST) && is_array($_POST)) {
        $_POST = nanfin_normalize_request_value($_POST);
    }
}

function nanfin_contains_mojibake(string $text): bool
{
    if ($text === '') {
        return false;
    }

    return preg_match(
        '/(?:'
        . 'เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธโฌเน€เธยเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธเธเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธเธเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย'
        . '|\\x{00E0}\\x{00B8}|\\x{00E0}\\x{00B9}|\\x{00E0}\\x{00BA}|\\x{00E0}\\x{00BB}'
        . '|(?:[เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขยเน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย ][\\x{0080}-\\x{00BF}]){2,}'
        . ')/u',
        $text
    ) === 1;
}
function nanfin_mojibake_score(string $text): int
{
    if ($text === '') {
        return 0;
    }

    $bad = preg_match_all(
        '/(?:'
        . 'เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธโฌเน€เธยเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธยเธขยเน€เธยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธเธเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเธขยเน€เธเธเธขยเน€เธโฌเน€เธยเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธยเนยเธเธขย|เน€เธโฌเน€เธยเน€เธยเน€เธโฌเน€เธยเธขย'
        . '|\\x{00E0}\\x{00B8}|\\x{00E0}\\x{00B9}|\\x{00E0}\\x{00BA}|\\x{00E0}\\x{00BB}'
        . '|(?:[เน€เธโฌเน€เธยเน€เธยเน€เธเธเธขยเน€เธโฌเน€เธยเน€เธยเน€เธเธเธขย ][\\x{0080}-\\x{00BF}]){2,}'
        . ')/u',
        $text
    );
    $thai = preg_match_all('/[\\x{0E00}-\\x{0E7F}]/u', $text);
    return ((int)$bad * 4) - (int)$thai;
}
function nanfin_utf8_codepoint(string $char): int
{
    if ($char === '') {
        return -1;
    }

    if (function_exists('mb_ord')) {
        return (int)mb_ord($char, 'UTF-8');
    }

    $ucs4 = @mb_convert_encoding($char, 'UCS-4BE', 'UTF-8');
    if (!is_string($ucs4) || strlen($ucs4) !== 4) {
        return -1;
    }

    $unpacked = unpack('Ncp', $ucs4);
    if (!is_array($unpacked) || !isset($unpacked['cp'])) {
        return -1;
    }

    return (int)$unpacked['cp'];
}

function nanfin_latinish_text_to_bytes(string $text): ?string
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || $chars === []) {
        return '';
    }

    static $cp1252Reverse = [
        0x20AC => 0x80,
        0x201A => 0x82,
        0x0192 => 0x83,
        0x201E => 0x84,
        0x2026 => 0x85,
        0x2020 => 0x86,
        0x2021 => 0x87,
        0x02C6 => 0x88,
        0x2030 => 0x89,
        0x0160 => 0x8A,
        0x2039 => 0x8B,
        0x0152 => 0x8C,
        0x017D => 0x8E,
        0x2018 => 0x91,
        0x2019 => 0x92,
        0x201C => 0x93,
        0x201D => 0x94,
        0x2022 => 0x95,
        0x2013 => 0x96,
        0x2014 => 0x97,
        0x02DC => 0x98,
        0x2122 => 0x99,
        0x0161 => 0x9A,
        0x203A => 0x9B,
        0x0153 => 0x9C,
        0x017E => 0x9E,
        0x0178 => 0x9F,
    ];

    $bytes = '';
    foreach ($chars as $char) {
        $cp = nanfin_utf8_codepoint((string)$char);
        if ($cp < 0) {
            return null;
        }

        if ($cp <= 0xFF) {
            $bytes .= chr($cp);
            continue;
        }

        if (isset($cp1252Reverse[$cp])) {
            $bytes .= chr($cp1252Reverse[$cp]);
            continue;
        }

        return null;
    }

    return $bytes;
}

function nanfin_repair_mojibake_chunk(string $chunk): string
{
    if ($chunk === '' || !nanfin_contains_mojibake($chunk)) {
        return $chunk;
    }

    $variants = [$chunk];
    $current = $chunk;
    // Allow multiple decode passes because some strings are double/triple encoded
    // after repeated edits/deployments.
    for ($i = 0; $i < 6; $i++) {
        $candidateBytes = nanfin_latinish_text_to_bytes($current);
        if (!is_string($candidateBytes) || $candidateBytes === '' || $candidateBytes === $current) {
            break;
        }
        if (preg_match('//u', $candidateBytes) !== 1) {
            break;
        }

        $current = $candidateBytes;
        $variants[] = $current;
        if (!nanfin_contains_mojibake($current)) {
            break;
        }
    }

    $originalScore = nanfin_mojibake_score($chunk);
    $chosen = $chunk;
    $chosenScore = $originalScore;
    $chosenThai = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $chunk);

    foreach ($variants as $variant) {
        $variantScore = nanfin_mojibake_score($variant);
        $variantThai = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $variant);
        if (
            $variantScore < $chosenScore
            || ($variantScore === $chosenScore && (int)$variantThai > (int)$chosenThai)
        ) {
            $chosen = $variant;
            $chosenScore = $variantScore;
            $chosenThai = (int)$variantThai;
        }
    }

    return $chosenScore <= $originalScore ? $chosen : $chunk;
}

function nanfin_normalize_display_text(?string $value): string
{
    $text = (string)$value;
    if ($text === '' || !nanfin_contains_mojibake($text)) {
        return $text;
    }

    $normalized = preg_replace_callback(
        '/[\x{0080}-\x{24FF}]{2,}/u',
        static function (array $m): string {
            return nanfin_repair_mojibake_chunk((string)($m[0] ?? ''));
        },
        $text
    );

    if (!is_string($normalized) || $normalized === '') {
        return $text;
    }

    return $normalized;
}

function nanfin_output_repair_mojibake(string $buffer): string
{
    return nanfin_normalize_display_text($buffer);
}

function h(?string $value): string
{
    return htmlspecialchars(nanfin_normalize_display_text($value), ENT_QUOTES, 'UTF-8');
}

function ui_strip_english_parenthetical(string $text): string
{
    $normalized = trim(nanfin_normalize_display_text($text));
    if ($normalized === '') {
        return '';
    }

    $stripped = preg_replace('/\s*\((?=[^)]*[A-Za-z])[^)]*\)\s*/u', ' ', $normalized);
    if (!is_string($stripped) || trim($stripped) === '') {
        return $normalized;
    }

    $stripped = preg_replace('/\s{2,}/u', ' ', $stripped);
    if (!is_string($stripped)) {
        return $normalized;
    }

    return trim($stripped);
}

function ui_extract_english_parenthetical(string $text): string
{
    $normalized = trim(nanfin_normalize_display_text($text));
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/\(([^)]*[A-Za-z][^)]*)\)/u', $normalized, $matches) !== 1) {
        return '';
    }

    return trim((string)($matches[1] ?? ''));
}

function ui_humanize_key(string $key): string
{
    $normalized = trim((string)$key);
    if ($normalized === '') {
        return '';
    }

    $normalized = str_replace(['-', '.'], '_', $normalized);
    $parts = array_values(array_filter(explode('_', $normalized), static fn(string $part): bool => trim($part) !== ''));
    if ($parts === []) {
        return '';
    }

    $words = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^[A-Z0-9]+$/', $part) === 1) {
            $words[] = $part;
            continue;
        }
        $words[] = ucfirst(strtolower($part));
    }

    return implode(' ', $words);
}

function ui_text(string $text): string
{
    $normalized = trim(nanfin_normalize_display_text($text));
    if ($normalized === '') {
        return '';
    }

    $englishInParen = ui_extract_english_parenthetical($normalized);
    if ($englishInParen !== '') {
        return $englishInParen;
    }

    return $normalized;
}

function ui_module_title(string $title): string
{
    $english = ui_extract_english_parenthetical($title);
    if ($english !== '') {
        return $english;
    }

    return ui_text($title);
}

function ui_field_label(string $fieldName, string $label): string
{
    $normalizedLabel = trim(nanfin_normalize_display_text($label));
    $english = ui_extract_english_parenthetical($normalizedLabel);
    if ($english !== '') {
        return $english;
    }

    if ($normalizedLabel !== '' && preg_match('/^[\x20-\x7E]+$/', $normalizedLabel) === 1) {
        return $normalizedLabel;
    }

    $fromField = ui_humanize_key($fieldName);
    if ($fromField !== '') {
        return $fromField;
    }

    return $normalizedLabel;
}

function ui_localize_module_definition(array $module): array
{
    $localizedTitle = ui_module_title((string)($module['title'] ?? ''));
    if (preg_match('/[ก-๙]/u', $localizedTitle) === 1) {
        $fallbackFromKey = ui_humanize_key((string)($module['key'] ?? ''));
        if ($fallbackFromKey !== '') {
            $localizedTitle = $fallbackFromKey;
        }
    }
    $module['title'] = $localizedTitle;
    $module['description'] = ui_text((string)($module['description'] ?? ''));
    $module['search_placeholder'] = ui_text((string)($module['search_placeholder'] ?? ''));

    $fields = $module['fields'] ?? [];
    if (!is_array($fields)) {
        $fields = [];
    }

    foreach ($fields as $index => $field) {
        if (!is_array($field)) {
            continue;
        }

        $fieldName = (string)($field['name'] ?? '');
        $field['label'] = ui_field_label($fieldName, (string)($field['label'] ?? ''));
        if (isset($field['button_label'])) {
            $field['button_label'] = 'Add Item';
        }

        if (isset($field['list_columns']) && is_array($field['list_columns'])) {
            foreach ($field['list_columns'] as $colIndex => $column) {
                if (!is_array($column)) {
                    continue;
                }
                $colKey = (string)($column['key'] ?? '');
                $column['label'] = ui_field_label($colKey, (string)($column['label'] ?? ''));
                $field['list_columns'][$colIndex] = $column;
            }
        }

        $fields[$index] = $field;
    }

    $module['fields'] = $fields;
    return $module;
}

function current_user_name(): string
{
    return (string)($_SESSION['user_name'] ?? 'finance_admin');
}

function current_role_name(): string
{
    return (string)($_SESSION['role_name'] ?? 'maker');
}




/**
 * @return array<string, string>
 */
function all_role_options(): array
{
    return [
        'user' => thai_role_label('user'),
        'director' => thai_role_label('director'),
        'supervisor' => thai_role_label('supervisor'),
        'branch_manager' => thai_role_label('branch_manager'),
        'region_manager' => thai_role_label('region_manager'),
        'central_manager' => thai_role_label('central_manager'),
        'admin' => thai_role_label('admin'),
        'maker' => thai_role_label('maker'),
        'checker' => thai_role_label('checker'),
        'auditor' => thai_role_label('auditor'),
        'executive' => thai_role_label('executive'),
    ];
}

function role_scope_type(string $role): string
{
    switch ($role) {
        case 'branch_manager':
        case 'user':
        case 'supervisor':
        case 'maker':
            return 'branch';
        case 'region_manager':
            return 'region';
        case 'central_manager':
        case 'director':
        case 'admin':
        case 'checker':
        case 'auditor':
        case 'executive':
            return 'all';
        default:
            return 'all';
    }
}

function role_can_approve(string $role): bool
{
    return in_array($role, [
        'checker',
        'director',
        'executive',
        'admin',
        'central_manager',
        'region_manager',
        'branch_manager',
        'supervisor',
    ], true);
}

/**
 * @return array<int, array<string, string>>
 */
function active_branch_rows(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $stmt = db()->query('
        SELECT branch_code, branch_name, region_name
        FROM master_branch
        WHERE is_latest = 1 AND is_deleted = 0
        ORDER BY branch_code
    ');

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'branch_code' => strtoupper(trim((string)($row['branch_code'] ?? ''))),
            'branch_name' => trim((string)($row['branch_name'] ?? '')),
            'region_name' => trim((string)($row['region_name'] ?? '')),
        ];
    }

    $cached = $rows;
    return $rows;
}

/**
 * @return array<string, array<string, string>>
 */
function active_branch_map(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $map = [];
    foreach (active_branch_rows() as $row) {
        $map[$row['branch_code']] = $row;
    }

    $cached = $map;
    return $map;
}

function branch_region_name(string $branchCode): string
{
    $branchCode = strtoupper(trim($branchCode));
    if ($branchCode === '') {
        return '';
    }

    $map = active_branch_map();
    return (string)($map[$branchCode]['region_name'] ?? '');
}

/**
 * @return array{user_name:string, role_name:string, branch_code:string, region_name:string, profile:array<string,mixed>}
 */
function current_user_profile(): array
{
    static $cached = null;
    $cacheKey = current_user_name() . '|' . current_role_name();

    if (is_array($cached) && ($cached['__cache_key'] ?? '') === $cacheKey) {
        /** @var array{user_name:string, role_name:string, branch_code:string, region_name:string, profile:array<string,mixed>} $result */
        $result = $cached['data'];
        return $result;
    }

    $userName = current_user_name();
    $roleName = current_role_name();
    $profile = $_SESSION['user_profile'] ?? [];
    if (!is_array($profile)) {
        $profile = [];
    }

    try {
        $stmt = db()->prepare(
            'SELECT role_name, profile_json
             FROM system_users
             WHERE user_name = :user_name AND is_latest = 1 AND is_deleted = 0
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':user_name' => $userName]);
        $row = $stmt->fetch();

        if ($row) {
            $dbRole = trim((string)($row['role_name'] ?? ''));
            if ($dbRole !== '') {
                $roleName = $dbRole;
            }

            $decoded = json_decode((string)($row['profile_json'] ?? ''), true);
            if (is_array($decoded)) {
                $profile = $decoded;
            }
        }
    } catch (Throwable $e) {
        // Keep session values when DB lookup fails.
    }

    $branchCode = strtoupper(trim((string)($profile['branch_code'] ?? ($_SESSION['branch_code'] ?? ''))));
    $regionName = trim((string)($profile['region_name'] ?? ($_SESSION['region_name'] ?? '')));
    if ($regionName === '' && $branchCode !== '') {
        $regionName = branch_region_name($branchCode);
    }

    $_SESSION['role_name'] = $roleName;
    $_SESSION['user_profile'] = $profile;
    $_SESSION['branch_code'] = $branchCode;
    $_SESSION['region_name'] = $regionName;

    $result = [
        'user_name' => $userName,
        'role_name' => $roleName,
        'branch_code' => $branchCode,
        'region_name' => $regionName,
        'profile' => $profile,
    ];

    $cached = ['__cache_key' => $cacheKey, 'data' => $result];
    return $result;
}

/**
 * @return array{scope:string, role_name:string, branch_code:string, region_name:string}
 */
function current_access_scope(): array
{
    static $cached = null;
    $profile = current_user_profile();
    $cacheKey = implode('|', [
        $profile['user_name'],
        $profile['role_name'],
        $profile['branch_code'],
        $profile['region_name'],
    ]);

    if (is_array($cached) && ($cached['__cache_key'] ?? '') === $cacheKey) {
        /** @var array{scope:string, role_name:string, branch_code:string, region_name:string} $result */
        $result = $cached['data'];
        return $result;
    }

    $roleName = $profile['role_name'];
    $scope = role_scope_type($roleName);
    $branchCode = strtoupper(trim($profile['branch_code']));
    $regionName = trim($profile['region_name']);

    if ($scope === 'branch' && $branchCode === '') {
        // Backward compatible: old branch-scoped roles without assignment can still work.
        $scope = ($roleName === 'branch_manager') ? 'none' : 'all';
    }

    if ($scope === 'region' && $regionName === '') {
        if ($branchCode !== '') {
            $regionName = branch_region_name($branchCode);
        }
        if ($regionName === '') {
            $scope = 'none';
        }
    }

    $result = [
        'scope' => $scope,
        'role_name' => $roleName,
        'branch_code' => $branchCode,
        'region_name' => $regionName,
    ];
    $cached = ['__cache_key' => $cacheKey, 'data' => $result];
    return $result;
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string}|null $scope
 * @return string[]
 */
function accessible_branch_codes(?array $scope = null): array
{
    $scope = $scope ?? current_access_scope();
    $rows = active_branch_rows();

    if ($scope['scope'] === 'all') {
        return array_values(array_map(
            static fn(array $row): string => (string)$row['branch_code'],
            $rows
        ));
    }

    if ($scope['scope'] === 'none') {
        return [];
    }

    if ($scope['scope'] === 'branch') {
        return $scope['branch_code'] === '' ? [] : [$scope['branch_code']];
    }

    if ($scope['scope'] === 'region') {
        $codes = [];
        foreach ($rows as $row) {
            if ((string)$row['region_name'] === $scope['region_name']) {
                $codes[] = (string)$row['branch_code'];
            }
        }
        return $codes;
    }

    return [];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string}|null $scope
 * @return array{sql:string, params:array<string,string>}
 */
function access_scope_sql_clause(string $branchColumn = 'branch_code', string $paramPrefix = 'scope', ?array $scope = null): array
{
    $scope = $scope ?? current_access_scope();

    if ($scope['scope'] === 'all') {
        return ['sql' => '', 'params' => []];
    }

    if ($scope['scope'] === 'none') {
        return ['sql' => ' AND 1 = 0', 'params' => []];
    }

    if ($scope['scope'] === 'branch') {
        if ($scope['branch_code'] === '') {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        $key = ':' . $paramPrefix . '_branch_code';
        return [
            'sql' => ' AND ' . $branchColumn . ' = ' . $key,
            'params' => [$key => $scope['branch_code']],
        ];
    }

    if ($scope['scope'] === 'region') {
        $codes = accessible_branch_codes($scope);
        if ($codes === []) {
            return ['sql' => ' AND 1 = 0', 'params' => []];
        }

        $placeholders = [];
        $params = [];
        foreach (array_values($codes) as $index => $code) {
            $key = ':' . $paramPrefix . '_branch_' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        return [
            'sql' => ' AND ' . $branchColumn . ' IN (' . implode(', ', $placeholders) . ')',
            'params' => $params,
        ];
    }

    return ['sql' => '', 'params' => []];
}

/**
 * @param array{scope:string, role_name:string, branch_code:string, region_name:string}|null $scope
 */
function is_branch_in_current_scope(string $branchCode, ?array $scope = null): bool
{
    $branchCode = strtoupper(trim($branchCode));
    $scope = $scope ?? current_access_scope();

    if ($scope['scope'] === 'all') {
        return true;
    }

    if ($scope['scope'] === 'none' || $branchCode === '') {
        return false;
    }

    if ($scope['scope'] === 'branch') {
        return $branchCode === $scope['branch_code'];
    }

    if ($scope['scope'] === 'region') {
        return in_array($branchCode, accessible_branch_codes($scope), true);
    }

    return false;
}

function assert_branch_in_current_scope(string $branchCode): void
{
    if (is_branch_in_current_scope($branchCode)) {
        return;
    }

    throw new RuntimeException('You do not have permission to access this branch in your current scope.');
}

function request_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function request_device(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown-device');
    return mb_substr($ua, 0, 120);
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf_token'];
}

function verify_csrf_or_fail(string $token): void
{
    if (!hash_equals((string)($_SESSION['_csrf_token'] ?? ''), $token)) {
        throw new RuntimeException('CSRF token invalid');
    }
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function add_flash(string $type, string $message): void
{
    $_SESSION['_flashes'][] = ['type' => $type, 'message' => ui_text($message)];
}

function consume_flashes(): array
{
    $flashes = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    return is_array($flashes) ? $flashes : [];
}

function redirect_to(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function parse_date_or_null(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $dt = date_create($value);
    return $dt ? $dt->format('Y-m-d') : null;
}

function parse_decimal_or_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function badge_class_for_status(string $status): string
{
    switch ($status) {
        case 'APPROVED':
            return 'success';
        case 'REJECTED':
            return 'danger';
        case 'DELETED':
            return 'secondary';
        case 'PENDING_CHECKER':
            return 'warning';
        default:
            return 'primary';
    }
}

function thai_role_label(string $role): string
{
    switch ($role) {
        case 'branch_manager':
            return 'Branch Manager';
        case 'region_manager':
            return 'Region Manager';
        case 'central_manager':
            return 'Central Manager';
        case 'director':
            return 'Director';
        case 'user':
            return 'User';
        case 'supervisor':
            return 'Supervisor';
        case 'admin':
            return 'Administrator';
        case 'maker':
            return 'Maker';
        case 'checker':
            return 'Checker';
        case 'auditor':
            return 'Auditor';
        case 'executive':
            return 'Executive';
        default:
            return $role;
    }
}

function thai_status_label(string $status): string
{
    $labels = [
        'DRAFT' => 'Draft',
        'PENDING_CHECKER' => 'Pending Checker',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'DELETED' => 'Deleted',
        'ACTIVE' => 'Active',
        'INACTIVE' => 'Inactive',
        'PAID' => 'Paid',
        'UNPAID' => 'Unpaid',
        'PARTIAL' => 'Partial',
        'OVERDUE' => 'Overdue',
    ];

    $key = strtoupper(trim($status));
    return $labels[$key] ?? $status;
}

function thai_action_label(string $action): string
{
    $labels = [
        'CREATE' => 'Create',
        'UPDATE' => 'Update',
        'APPROVE' => 'Approve',
        'SOFT_DELETE' => 'Soft Delete',
    ];

    $key = strtoupper(trim($action));
    return $labels[$key] ?? $action;
}

function thai_level_label(string $level): string
{
    $labels = [
        'INFO' => 'Info',
        'SUCCESS' => 'Success',
        'WARNING' => 'Warning',
        'DANGER' => 'Danger',
    ];

    $key = strtoupper(trim($level));
    return $labels[$key] ?? $level;
}

function thai_option_label(string $value): string
{
    $labels = [
        'GRANTED' => 'Granted',
        'PENDING' => 'Pending',
        'REVOKED' => 'Revoked',
        'NORMAL' => 'Normal',
        'BLACKLIST' => 'Blacklist',
        'WHITELIST' => 'Whitelist',
        'LOW' => 'Low',
        'MEDIUM' => 'Medium',
        'HIGH' => 'High',
        'VERY_HIGH' => 'Very High',
        'CRITICAL' => 'Critical',
        'APPROVE' => 'Approve',
        'REVIEW' => 'Review',
        'REJECT' => 'Reject',
        'CALL' => 'Call',
        'SMS' => 'SMS',
        'LINE' => 'LINE',
        'VISIT' => 'Field Visit',
        'RESTRUCTURE' => 'Restructure',
        'SETTLEMENT' => 'Settlement',
        'WRITE_OFF' => 'Write Off',
        'REPOSSESS' => 'Repossess',
        'LITIGATION' => 'Litigation',
        'DEBT_SALE' => 'Debt Sale',
        'YES' => 'Yes',
        'NO' => 'No',
        'TRUE_POSITIVE' => 'True Positive',
        'FALSE_POSITIVE' => 'False Positive',
        'GL_POSTING' => 'GL Posting',
        'ACCRUAL' => 'Accrual',
        'EIR' => 'EIR',
        'PROVISION' => 'Provision',
        'RECON' => 'Reconcile',
        'MATCHED' => 'Matched',
        'UNMATCHED' => 'Unmatched',
        'OPEN' => 'Open',
        'IN_PROGRESS' => 'In Progress',
        'CLOSED' => 'Closed',
        'READY' => 'Ready',
        'SUCCESS' => 'Success',
        'FAILED' => 'Failed',
        'PAUSED' => 'Paused',
        'PAID' => 'Paid',
        'UNPAID' => 'Unpaid',
        'PARTIAL' => 'Partial',
        '1-7' => '1-7 Days',
        '8-30' => '8-30 Days',
        '31-60' => '31-60 Days',
        '61-90' => '61-90 Days',
        '90+' => '90+ Days',
        'A' => 'Grade A',
        'B' => 'Grade B',
        'C' => 'Grade C',
        'D' => 'Grade D',
        'E' => 'Grade E',
        'GT_10000' => '> 10,000 / month',
        'GT_20000' => '> 20,000 / month',
        'GT_30000' => '> 30,000 / month',
        'GT_40000' => '> 40,000 / month',
        'GT_50000' => '> 50,000 / month',
        'GT_60000' => '> 60,000 / month',
        'GT_70000' => '> 70,000 / month',
        'GT_80000' => '> 80,000 / month',
        'GT_90000' => '> 90,000 / month',
        'GT_100000' => '> 100,000 / month',
        'LE_10000' => '<= 10,000 / month',
        '10000-14999' => '10,000 - 14,999 / month',
        '15000-24999' => '15,000 - 24,999 / month',
        '25000-39999' => '25,000 - 39,999 / month',
        '40000-59999' => '40,000 - 59,999 / month',
        '60000+' => '60,000+ / month',
        'HOUSE' => 'House',
        'LAND' => 'Land',
        'CAR' => 'Car',
        'MOTORCYCLE' => 'Motorcycle',
        'MIXED' => 'Mixed',
        'NONE' => 'No Collateral Required',
        'CONSERVATIVE' => 'Conservative',
        'BALANCED_GROWTH' => 'Balanced Growth',
        'SUBPRIME_CONTROLLED' => 'Subprime Controlled',
        'AGGRESSIVE_EXPANSION' => 'Aggressive Expansion',
        'RECOVERY_FOCUS' => 'Recovery Focus',
        'LOW_RISK_STABLE' => 'Low Risk Stable',
        'MASS_MARKET_BALANCED' => 'Mass Market Balanced',
        'NEW_TO_CREDIT' => 'New To Credit',
        'SEASONAL_INCOME' => 'Seasonal Income',
        'HIGH_RISK_MONITORED' => 'High Risk Monitored',
        'STRICT_SCORE_AND_DSR' => 'Strict Score and DSR',
        'COLLATERAL_LED_APPROVAL' => 'Collateral-led Approval',
        'CASHFLOW_FIRST' => 'Cashflow First',
        'BUREAU_REQUIRED' => 'Bureau Required',
        'MANUAL_COMMITTEE' => 'Manual Committee',
        'LOW_INSTALLMENT_LONG_TERM' => 'Low Installment Long Term',
        'BALANCED_STRUCTURE' => 'Balanced Structure',
        'HIGH_DOWNPAYMENT' => 'High Down Payment',
        'COLLATERAL_MAXIMIZE' => 'Collateral Maximize',
        'STEP_UP_INSTALLMENT' => 'Step-up Installment',
        'PD_CEILING_3' => 'PD Ceiling 3%',
        'PD_CEILING_5' => 'PD Ceiling 5%',
        'PD_CEILING_8' => 'PD Ceiling 8%',
        'PD_CEILING_12' => 'PD Ceiling 12%',
        'MANUAL_OVERRIDE_TRACKED' => 'Manual Override Tracked',
        'RISK_BASED_LOW_SPREAD' => 'Risk-based Low Spread',
        'RISK_BASED_STANDARD' => 'Risk-based Standard',
        'RISK_BASED_HIGH_SPREAD' => 'Risk-based High Spread',
        'PROMO_FIXED_RATE' => 'Promo Fixed Rate',
        'FEE_HEAVY_LOW_RATE' => 'Fee-heavy Low Rate',
        'MONTHLY_LIGHT' => 'Monthly Light',
        'MONTHLY_STANDARD' => 'Monthly Standard',
        'WEEKLY_HIGH_RISK' => 'Weekly High Risk',
        'REALTIME_ALERTS' => 'Realtime Alerts',
        'FIELD_VISIT_TRIGGER' => 'Field Visit Trigger',
        'SOFT_FIRST' => 'Soft First',
        'SOFT_TO_HARD_30D' => 'Soft to Hard 30D',
        'RESTRUCTURE_PRIORITY' => 'Restructure Priority',
        'LEGAL_FAST_TRACK' => 'Legal Fast Track',
        'WRITE_OFF_STRICT' => 'Strict Write Off',
        'NO_OVERRIDE' => 'No Override',
        'LIMITED_OVERRIDE' => 'Limited Override',
        'COMMITTEE_ONLY' => 'Committee Only',
        'CENTRAL_APPROVAL' => 'Central Approval',
        'FULL_AUDIT_TRAIL' => 'Full Audit Trail',
        'DRAFT' => 'Draft',
        'PENDING_CHECKER' => 'Pending Checker',
        'APPROVED' => 'Approved',
        'REJECTED' => 'Rejected',
        'DELETED' => 'Deleted',
    ];

    $key = strtoupper(trim($value));
    if (isset($labels[$key])) {
        return $labels[$key];
    }

    static $thaiMap = [
        'เธเธฒเธข' => 'Mr.',
        'เธเธฒเธ' => 'Mrs.',
        'เธเธฒเธเธชเธฒเธง' => 'Ms.',
        'เธญเธทเนเธเน' => 'Other',
        'เธเธฒเธข' => 'Male',
        'เธซเธเธดเธ' => 'Female',
        'เธเนเธฒเธเธ•เธฑเธงเน€เธญเธ' => 'Own House',
        'เธเนเธฒเธเธเธฒเธ•เธด' => 'Family House',
        'เธเนเธฒเธเน€เธเนเธฒ' => 'Rented House',
        'เธฃเธฒเธเธเธฒเธฃ' => 'Government',
        'เน€เธญเธเธเธ' => 'Private Sector',
        'เน€เธเธฉเธ•เธฃ' => 'Agriculture',
        'เธญเธดเธชเธฃเธฐ' => 'Freelance',
        'เธเธธเธฃเธเธดเธเธชเนเธงเธเธ•เธฑเธง' => 'Self-employed',
        'เธฃเธฒเธขเธงเธฑเธ' => 'Daily',
        'เธฃเธฒเธขเน€เธ”เธทเธญเธ' => 'Monthly',
        'เธฃเธฒเธขเธเธต' => 'Yearly',
    ];

    if (isset($thaiMap[$value])) {
        return $thaiMap[$value];
    }

    $english = ui_extract_english_parenthetical($value);
    if ($english !== '') {
        return $english;
    }

    if (preg_match('/^[A-Za-z0-9 _+\-\/.%(),]+$/', $value) === 1) {
        return $value;
    }

    return ui_humanize_key($value);
}

function now_dt(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function app_base_url(string $suffix = ''): string
{
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));

    if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
        $base = substr($projectRoot, strlen($documentRoot));
    } else {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(dirname(dirname($scriptName)), '/');
    }

    $base = rtrim((string)$base, '/');
    if ($base === '/' || $base === '.') {
        $base = '';
    }

    if ($suffix === '') {
        return $base === '' ? '' : $base;
    }

    return ($base === '' ? '' : $base) . '/' . ltrim($suffix, '/');
}

