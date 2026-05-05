<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script is CLI only.\n";
    exit(1);
}

$root = __DIR__;
$extensions = ['php', 'js', 'css', 'html', 'sql', 'md'];
$skipDirs = ['backup', '.git', 'vendor', 'node_modules'];
$mojibakePattern = '/(?:Ã|à|â€|�)/u';

$invalidUtf8Files = [];
$suspiciousFiles = [];
$scannedFiles = 0;

$directoryIterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filterIterator = new RecursiveCallbackFilterIterator(
    $directoryIterator,
    static function (SplFileInfo $current) use ($skipDirs): bool {
        if ($current->isDir()) {
            return !in_array($current->getFilename(), $skipDirs, true);
        }
        return true;
    }
);
$iterator = new RecursiveIteratorIterator($filterIterator);

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $extension = strtolower($file->getExtension());
    if (!in_array($extension, $extensions, true)) {
        continue;
    }

    $scannedFiles++;
    $path = (string)$file->getPathname();
    $relativePath = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    $raw = file_get_contents($path);
    if ($raw === false) {
        $invalidUtf8Files[] = $relativePath . ' (read_failed)';
        continue;
    }

    $isUtf8 = function_exists('mb_check_encoding')
        ? mb_check_encoding($raw, 'UTF-8')
        : (preg_match('//u', $raw) === 1);
    if (!$isUtf8) {
        $invalidUtf8Files[] = $relativePath;
        continue;
    }

    if ($relativePath !== 'utf8_guard.php' && preg_match($mojibakePattern, $raw) === 1) {
        $suspiciousFiles[] = $relativePath;
    }
}

if ($invalidUtf8Files === [] && $suspiciousFiles === []) {
    echo "UTF8_CHECK_OK scanned_files={$scannedFiles}\n";
    exit(0);
}

echo "UTF8_CHECK_FAIL scanned_files={$scannedFiles}\n";

if ($invalidUtf8Files !== []) {
    echo "Invalid UTF-8 files:\n";
    foreach ($invalidUtf8Files as $item) {
        echo "- {$item}\n";
    }
}

if ($suspiciousFiles !== []) {
    echo "Mojibake-pattern files:\n";
    foreach ($suspiciousFiles as $item) {
        echo "- {$item}\n";
    }
}

exit(1);
