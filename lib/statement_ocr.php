<?php
declare(strict_types=1);

use Google\Cloud\Vision\V1\ImageAnnotatorClient;

function ensure_customer_statement_ocr_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS customer_statement_ocr (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                module_key VARCHAR(80) NOT NULL DEFAULT 'customer_360',
                workflow_record_id BIGINT UNSIGNED NOT NULL,
                record_uid VARCHAR(80) NOT NULL,
                customer_code VARCHAR(80) DEFAULT '',
                customer_name VARCHAR(255) DEFAULT '',
                source_field VARCHAR(80) NOT NULL DEFAULT 'bank_statement_files',
                source_file_url TEXT NOT NULL,
                source_file_path TEXT NULL,
                source_file_hash CHAR(64) NOT NULL,
                source_file_mime VARCHAR(100) DEFAULT '',
                scan_status VARCHAR(20) NOT NULL DEFAULT 'SUCCESS',
                page_count INT UNSIGNED NOT NULL DEFAULT 0,
                ocr_text LONGTEXT NULL,
                ocr_meta_json LONGTEXT NULL,
                error_message TEXT NULL,
                created_by VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_customer_statement_ocr_customer (customer_code, created_at),
                KEY idx_customer_statement_ocr_record (module_key, workflow_record_id),
                KEY idx_customer_statement_ocr_status (scan_status, created_at),
                UNIQUE KEY uniq_customer_statement_file (module_key, record_uid, source_field, source_file_hash)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
        // Never block primary workflow if this table cannot be created in current environment.
    }
}

function statement_ocr_scan_customer_bank_statements(array $module, array $record): array
{
    $result = [
        'processed' => 0,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'messages' => [],
    ];

    if ((string)($module['key'] ?? '') !== 'customer_360') {
        return $result;
    }

    $payload = $record['payload'] ?? null;
    if (!is_array($payload)) {
        return $result;
    }

    $statementItems = statement_ocr_decode_json_list($payload['bank_statement_files'] ?? '[]');
    if ($statementItems === []) {
        return $result;
    }

    $pdo = db();
    ensure_customer_statement_ocr_table($pdo);

    foreach ($statementItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $fileUrl = trim((string)($item['file'] ?? ''));
        if ($fileUrl === '') {
            continue;
        }

        $absolutePath = statement_ocr_resolve_absolute_path($fileUrl);
        if ($absolutePath === null) {
            $result['failed']++;
            $result['messages'][] = 'ไม่พบไฟล์ statement ในระบบ: ' . $fileUrl;
            continue;
        }

        $fileHash = hash_file('sha256', $absolutePath);
        if (!is_string($fileHash) || $fileHash === '') {
            $result['failed']++;
            $result['messages'][] = 'ไม่สามารถคำนวณ hash ของไฟล์ statement ได้';
            continue;
        }

        if (statement_ocr_result_exists($pdo, (string)($module['key'] ?? ''), (string)($record['record_uid'] ?? ''), 'bank_statement_files', $fileHash)) {
            $result['skipped']++;
            continue;
        }

        $scan = statement_ocr_scan_file($absolutePath);

        statement_ocr_insert_result($pdo, [
            'module_key' => (string)($module['key'] ?? 'customer_360'),
            'workflow_record_id' => (int)($record['id'] ?? 0),
            'record_uid' => (string)($record['record_uid'] ?? ''),
            'customer_code' => trim((string)($payload['customer_code'] ?? ($record['primary_ref'] ?? ''))),
            'customer_name' => trim((string)($payload['customer_name'] ?? ($record['primary_name'] ?? ''))),
            'source_field' => 'bank_statement_files',
            'source_file_url' => $fileUrl,
            'source_file_path' => $absolutePath,
            'source_file_hash' => $fileHash,
            'source_file_mime' => statement_ocr_detect_mime($absolutePath),
            'scan_status' => (string)($scan['status'] ?? 'ERROR'),
            'page_count' => (int)($scan['page_count'] ?? 0),
            'ocr_text' => (string)($scan['text'] ?? ''),
            'ocr_meta_json' => json_encode(($scan['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => (string)($scan['error'] ?? ''),
            'created_by' => current_user_name(),
        ]);

        $result['processed']++;
        if (($scan['status'] ?? '') === 'SUCCESS') {
            $result['success']++;
        } else {
            $result['failed']++;
            $message = trim((string)($scan['error'] ?? 'ไม่สามารถ OCR ไฟล์ statement ได้'));
            if ($message !== '') {
                $result['messages'][] = $message;
            }
        }
    }

    return $result;
}

function statement_ocr_decode_json_list($value): array
{
    if (is_array($value)) {
        return $value;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : [];
}

function statement_ocr_result_exists(PDO $pdo, string $moduleKey, string $recordUid, string $sourceField, string $fileHash): bool
{
    $stmt = $pdo->prepare(
        "SELECT id
         FROM customer_statement_ocr
         WHERE module_key = :module_key
           AND record_uid = :record_uid
           AND source_field = :source_field
           AND source_file_hash = :source_file_hash
         LIMIT 1"
    );
    $stmt->execute([
        ':module_key' => $moduleKey,
        ':record_uid' => $recordUid,
        ':source_field' => $sourceField,
        ':source_file_hash' => $fileHash,
    ]);
    return (bool)$stmt->fetchColumn();
}

function statement_ocr_insert_result(PDO $pdo, array $row): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO customer_statement_ocr (
            module_key, workflow_record_id, record_uid, customer_code, customer_name,
            source_field, source_file_url, source_file_path, source_file_hash, source_file_mime,
            scan_status, page_count, ocr_text, ocr_meta_json, error_message, created_by
        ) VALUES (
            :module_key, :workflow_record_id, :record_uid, :customer_code, :customer_name,
            :source_field, :source_file_url, :source_file_path, :source_file_hash, :source_file_mime,
            :scan_status, :page_count, :ocr_text, :ocr_meta_json, :error_message, :created_by
        )
        ON DUPLICATE KEY UPDATE
            scan_status = VALUES(scan_status),
            page_count = VALUES(page_count),
            ocr_text = VALUES(ocr_text),
            ocr_meta_json = VALUES(ocr_meta_json),
            error_message = VALUES(error_message),
            source_file_path = VALUES(source_file_path),
            source_file_mime = VALUES(source_file_mime),
            created_by = VALUES(created_by),
            created_at = CURRENT_TIMESTAMP"
    );

    $stmt->execute([
        ':module_key' => (string)($row['module_key'] ?? 'customer_360'),
        ':workflow_record_id' => (int)($row['workflow_record_id'] ?? 0),
        ':record_uid' => (string)($row['record_uid'] ?? ''),
        ':customer_code' => (string)($row['customer_code'] ?? ''),
        ':customer_name' => (string)($row['customer_name'] ?? ''),
        ':source_field' => (string)($row['source_field'] ?? 'bank_statement_files'),
        ':source_file_url' => (string)($row['source_file_url'] ?? ''),
        ':source_file_path' => (string)($row['source_file_path'] ?? ''),
        ':source_file_hash' => (string)($row['source_file_hash'] ?? ''),
        ':source_file_mime' => (string)($row['source_file_mime'] ?? ''),
        ':scan_status' => (string)($row['scan_status'] ?? 'ERROR'),
        ':page_count' => (int)($row['page_count'] ?? 0),
        ':ocr_text' => (string)($row['ocr_text'] ?? ''),
        ':ocr_meta_json' => (string)($row['ocr_meta_json'] ?? ''),
        ':error_message' => (string)($row['error_message'] ?? ''),
        ':created_by' => (string)($row['created_by'] ?? current_user_name()),
    ]);
}

function statement_ocr_scan_file(string $absolutePath): array
{
    if (!is_file($absolutePath)) {
        return statement_ocr_error_result('ไม่พบไฟล์สำหรับ OCR');
    }

    $mime = statement_ocr_detect_mime($absolutePath);
    $extension = strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION));

    $imageBlobs = [];
    $pageCount = 0;
    if ($mime === 'application/pdf' || $extension === 'pdf') {
        $pdfExtraction = statement_ocr_extract_pdf_images($absolutePath);
        if (($pdfExtraction['status'] ?? '') !== 'SUCCESS') {
            return statement_ocr_error_result((string)($pdfExtraction['error'] ?? 'ไม่สามารถแปลง PDF สำหรับ OCR ได้'));
        }
        $imageBlobs = (array)($pdfExtraction['images'] ?? []);
        $pageCount = (int)($pdfExtraction['page_count'] ?? 0);
    } else {
        $blob = @file_get_contents($absolutePath);
        if (!is_string($blob) || $blob === '') {
            return statement_ocr_error_result('ไม่สามารถอ่านไฟล์ภาพสำหรับ OCR ได้');
        }
        $imageBlobs = [$blob];
        $pageCount = 1;
    }

    $googleScan = statement_ocr_scan_image_blobs_with_google($imageBlobs);
    if (($googleScan['status'] ?? '') !== 'SUCCESS') {
        return statement_ocr_error_result((string)($googleScan['error'] ?? 'Google OCR ล้มเหลว'));
    }

    return [
        'status' => 'SUCCESS',
        'page_count' => $pageCount > 0 ? $pageCount : (int)($googleScan['page_count'] ?? 0),
        'text' => (string)($googleScan['text'] ?? ''),
        'error' => '',
        'meta' => [
            'provider' => 'google_vision',
            'mime' => $mime,
            'source_file' => $absolutePath,
        ],
    ];
}

function statement_ocr_scan_image_blobs_with_google(array $imageBlobs): array
{
    if ($imageBlobs === []) {
        return statement_ocr_error_result('ไม่พบภาพสำหรับ OCR');
    }

    $autoloadError = statement_ocr_require_google_library();
    if ($autoloadError !== '') {
        return statement_ocr_error_result($autoloadError);
    }

    if (!class_exists(ImageAnnotatorClient::class)) {
        return statement_ocr_error_result('ยังไม่ได้ติดตั้ง google/cloud-vision');
    }

    $credentialPath = statement_ocr_google_credential_path();
    if ($credentialPath === null) {
        return statement_ocr_error_result('ไม่พบไฟล์ credential สำหรับ Google Vision (NANFIN_OCR_GOOGLE_CREDENTIALS)');
    }

    $authOffsetSeconds = statement_ocr_google_time_offset_seconds();
    if ($authOffsetSeconds !== null) {
        putenv('NANFIN_GOOGLE_AUTH_TIME_OFFSET_SECONDS=' . (string)$authOffsetSeconds);
    }

    $client = null;
    $collectedText = [];
    $errors = [];
    try {
        $client = new ImageAnnotatorClient([
            'credentials' => $credentialPath,
        ]);

        foreach ($imageBlobs as $index => $blob) {
            if (!is_string($blob) || $blob === '') {
                continue;
            }

            $response = $client->documentTextDetection($blob);
            $status = $response->getError();
            if ($status && trim((string)$status->getMessage()) !== '') {
                $errors[] = 'หน้า ' . ((int)$index + 1) . ': ' . trim((string)$status->getMessage());
                continue;
            }

            $annotation = $response->getFullTextAnnotation();
            $text = $annotation ? trim((string)$annotation->getText()) : '';
            if ($text !== '') {
                $collectedText[] = $text;
            }
        }
    } catch (Throwable $e) {
        return statement_ocr_error_result('Google OCR error: ' . $e->getMessage());
    } finally {
        if ($client instanceof ImageAnnotatorClient) {
            $client->close();
        }
    }

    if ($collectedText === []) {
        $errorMessage = $errors !== [] ? implode(' | ', $errors) : 'ไม่พบข้อความจาก OCR';
        return statement_ocr_error_result($errorMessage);
    }

    return [
        'status' => 'SUCCESS',
        'page_count' => count($imageBlobs),
        'text' => implode("\n\n", $collectedText),
        'error' => '',
        'meta' => [
            'page_errors' => $errors,
            'auth_time_offset_seconds' => $authOffsetSeconds,
        ],
    ];
}

function statement_ocr_extract_pdf_images(string $absolutePath): array
{
    $maxPagesRaw = (int)(getenv('NANFIN_OCR_MAX_PAGES') ?: 10);
    $maxPages = max(1, min(30, $maxPagesRaw));

    if (!extension_loaded('imagick') || !class_exists('Imagick')) {
        return statement_ocr_extract_pdf_images_with_mutool($absolutePath, $maxPages);
    }

    $probe = null;
    $imagick = null;
    try {
        $probe = new Imagick();
        $probe->pingImage($absolutePath);
        $totalPages = (int)$probe->getNumberImages();
        if ($totalPages <= 0) {
            return statement_ocr_error_result('ไม่พบจำนวนหน้าใน PDF');
        }

        $lastPage = min($totalPages, $maxPages) - 1;
        $imagick = new Imagick();
        $imagick->setResolution(220, 220);
        $imagick->readImage($absolutePath . '[0-' . $lastPage . ']');

        $images = [];
        foreach ($imagick as $page) {
            $page->setImageFormat('png');
            $images[] = $page->getImageBlob();
        }

        if ($images === []) {
            return statement_ocr_error_result('ไม่สามารถแปลง PDF เป็นภาพสำหรับ OCR ได้');
        }

        return [
            'status' => 'SUCCESS',
            'images' => $images,
            'page_count' => count($images),
            'error' => '',
        ];
    } catch (Throwable $e) {
        $fallback = statement_ocr_extract_pdf_images_with_mutool($absolutePath, $maxPages);
        if (($fallback['status'] ?? '') === 'SUCCESS') {
            return $fallback;
        }
        return statement_ocr_error_result('PDF convert error: ' . $e->getMessage() . ' | fallback: ' . (string)($fallback['error'] ?? 'unknown'));
    } finally {
        if ($probe instanceof Imagick) {
            $probe->clear();
            $probe->destroy();
        }
        if ($imagick instanceof Imagick) {
            $imagick->clear();
            $imagick->destroy();
        }
    }
}

function statement_ocr_extract_pdf_images_with_mutool(string $absolutePath, int $maxPages): array
{
    $mutoolPath = statement_ocr_resolve_mutool_path();
    if ($mutoolPath === null) {
        return statement_ocr_error_result('OCR PDF convert unavailable (imagick/mutool missing)');
    }

    $randomBytes = function_exists('random_bytes') ? random_bytes(6) : pack('H*', substr(md5((string)microtime(true)), 0, 12));
    $tempDir = rtrim((string)sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'nanfin_ocr_' . bin2hex($randomBytes);
    if (!@mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
        return statement_ocr_error_result('Cannot create OCR temp directory');
    }

    $outputPattern = $tempDir . DIRECTORY_SEPARATOR . 'page-%03d.png';
    $command = statement_ocr_shell_quote($mutoolPath)
        . ' draw -r 220 -o ' . statement_ocr_shell_quote($outputPattern)
        . ' ' . statement_ocr_shell_quote($absolutePath)
        . ' 1-' . (int)$maxPages . ' 2>&1';

    $output = [];
    $exitCode = 1;
    @exec($command, $output, $exitCode);

    $images = [];
    $pageFiles = glob($tempDir . DIRECTORY_SEPARATOR . 'page-*.png');
    if (is_array($pageFiles)) {
        natsort($pageFiles);
        foreach ($pageFiles as $pageFile) {
            $blob = @file_get_contents($pageFile);
            if (is_string($blob) && $blob !== '') {
                $images[] = $blob;
            }
        }
    }

    if ($images === []) {
        statement_ocr_cleanup_temp_dir($tempDir);
        $commandError = trim(implode("\n", $output));
        if ($commandError === '') {
            $commandError = 'mutool failed to render PDF';
        }
        return statement_ocr_error_result('mutool error: ' . $commandError . ' (exit=' . $exitCode . ')');
    }

    statement_ocr_cleanup_temp_dir($tempDir);

    return [
        'status' => 'SUCCESS',
        'images' => $images,
        'page_count' => count($images),
        'error' => '',
    ];
}

function statement_ocr_cleanup_temp_dir(string $tempDir): void
{
    $files = glob(rtrim($tempDir, '/\\') . DIRECTORY_SEPARATOR . '*');
    if (is_array($files)) {
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
    @rmdir($tempDir);
}

function statement_ocr_shell_quote(string $value): string
{
    return '"' . str_replace('"', '\\"', $value) . '"';
}

function statement_ocr_resolve_mutool_path(): ?string
{
    $projectRoot = dirname(__DIR__);
    $candidates = [];

    $envPath = trim((string)getenv('NANFIN_OCR_MUTOOL_PATH'));
    if ($envPath !== '') {
        if (!preg_match('/^([a-zA-Z]:)?[\/\\\\]/', $envPath)) {
            $envPath = $projectRoot . '/' . ltrim($envPath, '/\\');
        }
        $candidates[] = $envPath;
    }

    $candidates[] = 'C:/Program Files/MuPDF/mutool.exe';

    $localAppData = trim((string)getenv('LOCALAPPDATA'));
    if ($localAppData !== '') {
        $pattern = str_replace('\\', '/', $localAppData)
            . '/Microsoft/WinGet/Packages/ArtifexSoftware.mutool_*/mupdf-*/mutool.exe';
        $wingetPaths = glob($pattern);
        if (is_array($wingetPaths)) {
            usort($wingetPaths, static function (string $a, string $b): int {
                return (int)@filemtime($b) <=> (int)@filemtime($a);
            });
            foreach ($wingetPaths as $wingetPath) {
                $candidates[] = $wingetPath;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $real = realpath((string)$candidate);
        if ($real !== false && is_file($real)) {
            return $real;
        }
    }

    return null;
}

function statement_ocr_google_time_offset_seconds(): ?int
{
    static $resolved = false;
    static $offsetSeconds = null;

    if ($resolved) {
        return $offsetSeconds;
    }
    $resolved = true;

    $manualOffset = trim((string)getenv('NANFIN_OCR_TIME_OFFSET_SECONDS'));
    if ($manualOffset !== '' && is_numeric($manualOffset)) {
        $offsetSeconds = max(-43200, min(43200, (int)$manualOffset));
        return $offsetSeconds;
    }

    if (!function_exists('curl_init')) {
        return null;
    }

    $headers = [];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=invalid',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$headers): int {
            $trimmed = trim($headerLine);
            if ($trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($headerLine);
        },
    ]);

    @curl_exec($ch);
    @curl_close($ch);

    $dateHeader = trim((string)($headers['date'] ?? ''));
    if ($dateHeader === '') {
        return null;
    }

    $serverTime = strtotime($dateHeader);
    if ($serverTime === false) {
        return null;
    }

    $delta = (int)$serverTime - time();
    if (abs($delta) > 43200) {
        return null;
    }

    $offsetSeconds = $delta;
    return $offsetSeconds;
}
function statement_ocr_google_credential_path(): ?string
{
    $projectRoot = dirname(__DIR__);
    $candidates = [];

    $envPath = trim((string)getenv('NANFIN_OCR_GOOGLE_CREDENTIALS'));
    if ($envPath !== '') {
        if (!preg_match('/^([a-zA-Z]:)?[\/\\\\]/', $envPath)) {
            $envPath = $projectRoot . '/' . ltrim($envPath, '/\\');
        }
        $candidates[] = $envPath;
    }

    $candidates[] = $projectRoot . '/keys/google-vision-service-account.json';
    $candidates[] = $projectRoot . '/statment-ocr/keys/google-vision-service-account.json';

    $rootKeyFiles = glob($projectRoot . '/keys/*.json');
    if (is_array($rootKeyFiles)) {
        usort($rootKeyFiles, static function (string $a, string $b): int {
            return (int)@filemtime($b) <=> (int)@filemtime($a);
        });
        foreach ($rootKeyFiles as $rootKeyFile) {
            $candidates[] = $rootKeyFile;
        }
    }

    $jsonKeyFiles = glob($projectRoot . '/statment-ocr/keys/*.json');
    if (is_array($jsonKeyFiles)) {
        usort($jsonKeyFiles, static function (string $a, string $b): int {
            return (int)@filemtime($b) <=> (int)@filemtime($a);
        });
        foreach ($jsonKeyFiles as $jsonKeyFile) {
            $candidates[] = $jsonKeyFile;
        }
    }

    $standaloneKeyFiles = glob($projectRoot . '/statment-ocr/*.json');
    if (is_array($standaloneKeyFiles)) {
        usort($standaloneKeyFiles, static function (string $a, string $b): int {
            return (int)@filemtime($b) <=> (int)@filemtime($a);
        });
        foreach ($standaloneKeyFiles as $standaloneKeyFile) {
            $candidates[] = $standaloneKeyFile;
        }
    }

    foreach ($candidates as $candidate) {
        $real = realpath((string)$candidate);
        if ($real !== false && is_file($real)) {
            return $real;
        }
    }

    return null;
}

function statement_ocr_require_google_library(): string
{
    $autoloadPath = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
    }

    return class_exists(ImageAnnotatorClient::class)
        ? ''
        : 'ยังไม่พบ vendor/autoload.php หรือคลาส google/cloud-vision';
}

function statement_ocr_detect_mime(string $absolutePath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }

    $ext = strtolower((string)pathinfo($absolutePath, PATHINFO_EXTENSION));
    $map = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

function statement_ocr_resolve_absolute_path(string $sourceUrl): ?string
{
    $path = parse_url($sourceUrl, PHP_URL_PATH);
    if (!is_string($path) || trim($path) === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $projectRoot = str_replace('\\', '/', dirname(__DIR__));
    $basePath = str_replace('\\', '/', app_base_url());
    $baseTrimmed = '/' . ltrim($basePath, '/');

    if ($baseTrimmed !== '/' && str_starts_with($path, $baseTrimmed . '/')) {
        $relative = ltrim(substr($path, strlen($baseTrimmed)), '/');
    } elseif ($baseTrimmed !== '/' && str_starts_with($path, ltrim($baseTrimmed, '/') . '/')) {
        $relative = ltrim(substr($path, strlen(ltrim($baseTrimmed, '/'))), '/');
    } else {
        $relative = ltrim($path, '/');
    }

    $absoluteCandidate = $projectRoot . '/' . $relative;
    $real = realpath($absoluteCandidate);
    if ($real === false || !is_file($real)) {
        return null;
    }

    $normalizedReal = str_replace('\\', '/', $real);
    if (!str_starts_with($normalizedReal, $projectRoot . '/')) {
        return null;
    }

    return $real;
}

function statement_ocr_error_result(string $message): array
{
    return [
        'status' => 'ERROR',
        'page_count' => 0,
        'text' => '',
        'error' => $message,
        'meta' => [],
    ];
}
