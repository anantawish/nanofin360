<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';

/**
 * UTF-8 stabilization tool:
 * - Removes UTF-8 BOM
 * - Repairs common mojibake patterns (Thai/UTF-8)
 * - Writes UTF-8 without BOM
 */

$root = __DIR__;
$excludeDirs = [
    'vendor',
    'keys',
    '.git',
    'node_modules',
];

$stats = [
    'scanned' => 0,
    'changed' => 0,
    'bom_removed' => 0,
    'mojibake_repaired' => 0,
    'invalid_utf8_left' => 0,
];

$changedFiles = [];

/**
 * @return array<int, array{full:string, rel:string}>
 */
function isLikelyTextFile(string $fullPath, string $relPath): bool
{
    $binaryExt = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'svgz',
        'zip', 'rar', '7z', 'gz', 'tar', 'tgz', 'phar',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'mp3', 'mp4', 'avi', 'mov', 'wav',
        'dll', 'exe', 'so', 'bin', 'dat',
        'woff', 'woff2', 'ttf', 'otf', 'eot',
    ];

    $ext = strtolower((string)pathinfo($relPath, PATHINFO_EXTENSION));
    if (in_array($ext, $binaryExt, true)) {
        return false;
    }

    $size = @filesize($fullPath);
    if (is_int($size) && $size > 10 * 1024 * 1024) {
        return false;
    }

    $bytes = @file_get_contents($fullPath);
    if (!is_string($bytes)) {
        return false;
    }
    if (strpos($bytes, "\0") !== false) {
        return false;
    }

    return true;
}

function collectTargetFiles(string $root, array $excludeDirs): array
{
    $targets = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $relPath = str_replace('\\', '/', substr($fullPath, strlen($root) + 1));
        if ($relPath === false || $relPath === '') {
            continue;
        }

        $parts = explode('/', $relPath);
        $skip = false;
        foreach ($parts as $part) {
            if (in_array($part, $excludeDirs, true)) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        if (!isLikelyTextFile($fullPath, $relPath)) {
            continue;
        }

        $targets[] = ['full' => $fullPath, 'rel' => $relPath];
    }

    return $targets;
}

$targets = collectTargetFiles($root, $excludeDirs);

foreach ($targets as $target) {
    $fullPath = $target['full'];
    $relPath = $target['rel'];

    $stats['scanned']++;
    $original = @file_get_contents($fullPath);
    if (!is_string($original)) {
        continue;
    }

    $updated = $original;
    $changed = false;
    $bomRemoved = false;
    $repaired = false;

    if (strncmp($updated, "\xEF\xBB\xBF", 3) === 0) {
        $updated = substr($updated, 3);
        $changed = true;
        $bomRemoved = true;
    }

    if (nanfin_contains_mojibake($updated)) {
        $candidate = nanfin_normalize_display_text($updated);
        if ($candidate !== $updated) {
            $updated = $candidate;
            $changed = true;
            $repaired = true;
        }
    }

    if ($changed) {
        file_put_contents($fullPath, $updated);
        $stats['changed']++;
        if ($bomRemoved) {
            $stats['bom_removed']++;
        }
        if ($repaired) {
            $stats['mojibake_repaired']++;
        }
        $changedFiles[] = $relPath;
    }
}

// Post-check invalid UTF-8 in scanned scope.
foreach ($targets as $target) {
    $fullPath = $target['full'];
    $contents = @file_get_contents($fullPath);
    if (!is_string($contents)) {
        continue;
    }
    if (preg_match('//u', $contents) !== 1) {
        $stats['invalid_utf8_left']++;
    }
}

echo "UTF8_STABILIZE_REPORT\n";
echo "scanned={$stats['scanned']}\n";
echo "changed={$stats['changed']}\n";
echo "bom_removed={$stats['bom_removed']}\n";
echo "mojibake_repaired={$stats['mojibake_repaired']}\n";
echo "invalid_utf8_left={$stats['invalid_utf8_left']}\n";
echo "changed_files=" . count($changedFiles) . "\n";
foreach ($changedFiles as $path) {
    echo $path . "\n";
}
