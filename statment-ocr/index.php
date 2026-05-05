<?php
declare(strict_types=1);

if (function_exists('ini_set')) {
    ini_set('default_charset', 'UTF-8');
}
header('Content-Type: text/html; charset=UTF-8');

date_default_timezone_set('Asia/Bangkok');

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/statement_ocr.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function detect_default_key_path(): ?string
{
    $envKey = trim((string)getenv('NANFIN_OCR_GOOGLE_CREDENTIALS'));
    if ($envKey !== '' && is_file($envKey)) {
        return realpath($envKey) ?: $envKey;
    }

    $candidates = [];
    $candidates[] = dirname(__DIR__) . '/keys/google-vision-service-account.json';
    $candidates[] = __DIR__ . '/keys/google-vision-service-account.json';

    $scanDirs = [
        dirname(__DIR__) . '/keys',
        __DIR__ . '/keys',
        __DIR__,
    ];
    foreach ($scanDirs as $scanDir) {
        if (!is_dir($scanDir)) {
            continue;
        }
        $items = glob($scanDir . '/*.json');
        if (is_array($items)) {
            foreach ($items as $item) {
                $candidates[] = $item;
            }
        }
    }

    $candidates = array_values(array_unique($candidates));
    $valid = [];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $json = @file_get_contents($candidate);
        if (!is_string($json) || trim($json) === '') {
            continue;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            continue;
        }
        if (($decoded['type'] ?? '') !== 'service_account') {
            continue;
        }
        if (trim((string)($decoded['client_email'] ?? '')) === '' || trim((string)($decoded['private_key'] ?? '')) === '') {
            continue;
        }
        $valid[] = $candidate;
    }
    if ($valid === []) {
        return null;
    }

    usort($valid, static function (string $a, string $b): int {
        return filemtime($b) <=> filemtime($a);
    });

    $first = $valid[0] ?? '';
    if ($first === '') {
        return null;
    }

    $real = realpath($first);
    return $real !== false ? $real : $first;
}

function upload_destination_name(string $originalName): string
{
    $ext = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $ext = 'bin';
    }

    return 'statement_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
}

$errors = [];
$messages = [];
$ocrResult = null;
$savedRowId = null;

$keyPath = detect_default_key_path();
if ($keyPath !== null) {
    putenv('NANFIN_OCR_GOOGLE_CREDENTIALS=' . $keyPath);
}

$dbConfig = require __DIR__ . '/../config.php';
$pdo = null;
try {
    $pdo = db_connect($dbConfig['db']);
    ensure_customer_statement_ocr_table($pdo);
} catch (Throwable $e) {
    $errors[] = 'เชื่อมต่อฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerCode = trim((string)($_POST['customer_code'] ?? ''));
    $customerName = trim((string)($_POST['customer_name'] ?? ''));

    if (!isset($_FILES['statement_file']) || !is_array($_FILES['statement_file'])) {
        $errors[] = 'กรุณาเลือกไฟล์ Statement';
    } else {
        $file = $_FILES['statement_file'];
        $uploadErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errors[] = 'อัปโหลดไฟล์ไม่สำเร็จ (error code: ' . $uploadErr . ')';
        }
    }

    if ($keyPath === null) {
        $errors[] = 'ไม่พบไฟล์ Google Service Account JSON ในโฟลเดอร์ statment-ocr/keys';
    }

    if ($errors === []) {
        $uploadsDir = __DIR__ . '/uploads';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0775, true);
        }

        $tmpPath = (string)($_FILES['statement_file']['tmp_name'] ?? '');
        $originalName = (string)($_FILES['statement_file']['name'] ?? 'statement_file');
        $destName = upload_destination_name($originalName);
        $destPath = $uploadsDir . '/' . $destName;

        if (!is_uploaded_file($tmpPath)) {
            $errors[] = 'ไฟล์ที่ส่งมาไม่ใช่ไฟล์อัปโหลดจากฟอร์ม';
        } elseif (!move_uploaded_file($tmpPath, $destPath)) {
            $errors[] = 'ย้ายไฟล์เข้าโฟลเดอร์ uploads ไม่สำเร็จ';
        } else {
            $scan = statement_ocr_scan_file($destPath);
            $ocrResult = $scan;

            if (($scan['status'] ?? 'ERROR') === 'SUCCESS') {
                $messages[] = 'OCR สำเร็จ';
            } else {
                $errors[] = 'OCR ไม่สำเร็จ: ' . (string)($scan['error'] ?? 'unknown error');
            }

            if ($pdo instanceof PDO) {
                $recordUid = 'STAT-OCR-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
                $fileHash = hash_file('sha256', $destPath);
                if (!is_string($fileHash) || $fileHash === '') {
                    $fileHash = hash('sha256', $destName . microtime(true));
                }

                $sourceUrl = 'statment-ocr/uploads/' . $destName;
                statement_ocr_insert_result($pdo, [
                    'module_key' => 'statment_ocr',
                    'workflow_record_id' => 0,
                    'record_uid' => $recordUid,
                    'customer_code' => $customerCode,
                    'customer_name' => $customerName,
                    'source_field' => 'manual_statement_upload',
                    'source_file_url' => $sourceUrl,
                    'source_file_path' => realpath($destPath) ?: $destPath,
                    'source_file_hash' => $fileHash,
                    'source_file_mime' => statement_ocr_detect_mime($destPath),
                    'scan_status' => (string)($scan['status'] ?? 'ERROR'),
                    'page_count' => (int)($scan['page_count'] ?? 0),
                    'ocr_text' => (string)($scan['text'] ?? ''),
                    'ocr_meta_json' => json_encode(($scan['meta'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'error_message' => (string)($scan['error'] ?? ''),
                    'created_by' => 'ocr_tool',
                ]);

                $savedRowId = (int)$pdo->lastInsertId();
                $messages[] = 'บันทึกผล OCR ลงตาราง customer_statement_ocr แล้ว';
            }
        }
    }
}

$vendorAutoloadExists = is_file(__DIR__ . '/../vendor/autoload.php');
$imagickEnabled = extension_loaded('imagick') && class_exists('Imagick');
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement OCR Tool</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f3f6fb; margin: 0; color: #1f2937; }
        .wrap { max-width: 980px; margin: 24px auto; background: #fff; border: 1px solid #dbe2ea; border-radius: 12px; padding: 20px; }
        h1 { margin-top: 0; font-size: 22px; }
        .muted { color: #6b7280; font-size: 13px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .full { grid-column: 1 / -1; }
        label { display: block; font-weight: 700; margin-bottom: 6px; }
        input[type="text"], input[type="file"], textarea {
            width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 9px 10px; font-size: 14px;
        }
        textarea { min-height: 260px; }
        .btn { background: #2563eb; color: #fff; border: none; border-radius: 8px; padding: 10px 16px; cursor: pointer; font-weight: 700; }
        .alert { border-radius: 8px; padding: 10px 12px; margin-bottom: 12px; }
        .ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .status { display: flex; gap: 10px; flex-wrap: wrap; margin: 12px 0 16px; }
        .pill { background: #f8fafc; border: 1px solid #dbe2ea; border-radius: 999px; padding: 6px 10px; font-size: 12px; }
        code { background: #f1f5f9; padding: 2px 6px; border-radius: 6px; }
        @media (max-width: 840px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Google Statement OCR (Smart Finance)</h1>
    <div class="muted">เครื่องมือนี้ใช้ทดสอบ OCR และบันทึกผลลงฐานข้อมูลเดียวกับระบบหลัก</div>

    <div class="status">
        <div class="pill">Key: <?php echo $keyPath ? h($keyPath) : 'ยังไม่พบ'; ?></div>
        <div class="pill">Vendor: <?php echo $vendorAutoloadExists ? 'พร้อมใช้งาน' : 'ยังไม่ติดตั้ง'; ?></div>
        <div class="pill">Imagick (PDF): <?php echo $imagickEnabled ? 'พร้อมใช้งาน' : 'ยังไม่เปิด'; ?></div>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="alert ok"><?php echo h($message); ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert err"><?php echo h($error); ?></div>
    <?php endforeach; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="grid">
            <div>
                <label>รหัสลูกค้า (ถ้ามี)</label>
                <input type="text" name="customer_code" value="<?php echo h((string)($_POST['customer_code'] ?? '')); ?>" placeholder="เช่น CUS202600001">
            </div>
            <div>
                <label>ชื่อลูกค้า (ถ้ามี)</label>
                <input type="text" name="customer_name" value="<?php echo h((string)($_POST['customer_name'] ?? '')); ?>" placeholder="ชื่อ - นามสกุล">
            </div>
            <div class="full">
                <label>ไฟล์ Statement (PDF/JPG/JPEG/PNG/WEBP)</label>
                <input type="file" name="statement_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            </div>
            <div class="full">
                <button type="submit" class="btn">สแกน OCR และบันทึก</button>
            </div>
        </div>
    </form>

    <?php if (is_array($ocrResult)): ?>
        <hr>
        <div class="grid">
            <div><strong>สถานะ:</strong> <?php echo h((string)($ocrResult['status'] ?? 'UNKNOWN')); ?></div>
            <div><strong>จำนวนหน้า:</strong> <?php echo h((string)($ocrResult['page_count'] ?? 0)); ?></div>
            <?php if ($savedRowId !== null): ?>
                <div><strong>บันทึก DB ID:</strong> <?php echo (int)$savedRowId; ?></div>
            <?php endif; ?>
            <div class="full">
                <label>ข้อความ OCR</label>
                <textarea readonly><?php echo h((string)($ocrResult['text'] ?? '')); ?></textarea>
            </div>
        </div>
    <?php endif; ?>

    <hr>
    <div class="muted">
        ถ้ายังไม่ติดตั้งไลบรารี ให้รันคำสั่งในโฟลเดอร์ <code>nanofinance</code>:<br>
        <code>composer require google/cloud-vision</code>
    </div>
</div>
</body>
</html>
