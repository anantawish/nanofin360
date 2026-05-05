<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Please run via CLI only.\n");
    exit(1);
}

date_default_timezone_set('Asia/Bangkok');

$root = realpath(__DIR__);
if ($root === false) {
    fwrite(STDERR, "Project root not found.\n");
    exit(1);
}

$timestamp = (new DateTimeImmutable('now'))->format('Ymd_His');
$forUploadRoot = $root . DIRECTORY_SEPARATOR . 'forupload';
$buildName = 'smart_finance_obf_' . $timestamp;
$outputRoot = $forUploadRoot . DIRECTORY_SEPARATOR . $buildName;

if (!is_dir($forUploadRoot) && !mkdir($forUploadRoot, 0777, true) && !is_dir($forUploadRoot)) {
    fwrite(STDERR, "Cannot create forupload folder.\n");
    exit(1);
}

if (!mkdir($outputRoot, 0777, true) && !is_dir($outputRoot)) {
    fwrite(STDERR, "Cannot create build folder.\n");
    exit(1);
}

$excludeDirs = [
    'backup',
    'forupload',
    '.git',
    'vendor',
    'node_modules',
];

$excludeFilePatterns = [
    '/\.bak_/i',
    '/\.encoding_bak_/i',
    '/\.tmpbak_/i',
];

$copied = 0;
$obfPhp = 0;
$obfJs = 0;
$obfHtml = 0;

$directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $directory,
    static function (SplFileInfo $current, string $key, $iterator) use ($excludeDirs): bool {
        if ($current->isDir()) {
            return !in_array($current->getFilename(), $excludeDirs, true);
        }
        return true;
    }
);
$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

foreach ($iterator as $item) {
    if (!$item instanceof SplFileInfo) {
        continue;
    }

    $sourcePath = $item->getPathname();
    $relativePath = ltrim(str_replace($root, '', $sourcePath), DIRECTORY_SEPARATOR);
    if ($relativePath === '') {
        continue;
    }

    $targetPath = $outputRoot . DIRECTORY_SEPARATOR . $relativePath;

    if ($item->isDir()) {
        if (!is_dir($targetPath) && !mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
            fwrite(STDERR, "Cannot create dir: {$targetPath}\n");
            exit(1);
        }
        continue;
    }

    $fileName = $item->getFilename();
    $skipFile = false;
    foreach ($excludeFilePatterns as $pattern) {
        if (preg_match($pattern, $fileName) === 1) {
            $skipFile = true;
            break;
        }
    }
    if ($skipFile) {
        continue;
    }

    $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $content = file_get_contents($sourcePath);
    if ($content === false) {
        fwrite(STDERR, "Cannot read file: {$sourcePath}\n");
        exit(1);
    }

    if ($ext === 'php') {
        $content = obfuscate_php($content, $relativePath);
        $obfPhp++;
    } elseif ($ext === 'js') {
        $content = obfuscate_js($content, $relativePath);
        $obfJs++;
    } elseif ($ext === 'html' || $ext === 'htm') {
        $content = obfuscate_html($content, $relativePath);
        $obfHtml++;
    }

    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
        fwrite(STDERR, "Cannot create dir: {$targetDir}\n");
        exit(1);
    }

    if (file_put_contents($targetPath, $content) === false) {
        fwrite(STDERR, "Cannot write file: {$targetPath}\n");
        exit(1);
    }

    $copied++;
}

$notice = <<<TXT
Smart Finance 360 obfuscated build
Generated at: {$timestamp}

Important:
- This build obfuscates PHP/JS/HTML for harder reverse reading.
- Client-side JS/HTML cannot be made impossible to read in browsers.
- For strongest PHP protection in production, use commercial encoder (ionCube / SourceGuardian) and server hardening.
- Set file permissions to read-only for deploy user where possible.
TXT;

file_put_contents($outputRoot . DIRECTORY_SEPARATOR . 'OBFUSCATION_NOTICE.txt', $notice);

fwrite(STDOUT, "Build folder: {$outputRoot}\n");
fwrite(STDOUT, "Files processed: {$copied}\n");
fwrite(STDOUT, "PHP obfuscated: {$obfPhp}\n");
fwrite(STDOUT, "JS obfuscated: {$obfJs}\n");
fwrite(STDOUT, "HTML obfuscated: {$obfHtml}\n");
fwrite(STDOUT, "Done.\n");

/**
 * Wrap PHP source into compressed payload.
 */
function obfuscate_php(string $source, string $relativePath): string
{
    $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
    $source = preg_replace('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/i', '', $source) ?? $source;

    $payload = base64_encode(gzdeflate($source, 9));
    $chunks = chunk_base64($payload);
    $joined = implode(" .\n    ", $chunks);

    return "<?php declare(strict_types=1);\n"
        . "/* Smart Finance obfuscated: {$relativePath} */\n"
        . "\$__payload = {$joined};\n"
        . "\$__decoded = gzinflate(base64_decode(\$__payload));\n"
        . "if (\$__decoded === false) {\n"
        . "    http_response_code(500);\n"
        . "    exit('Decode error');\n"
        . "}\n"
        . "\$__result = eval('?>' . \$__decoded);\n"
        . "unset(\$__payload, \$__decoded);\n"
        . "return \$__result;\n";
}

/**
 * Wrap JS source into base64 eval payload.
 */
function obfuscate_js(string $source, string $relativePath): string
{
    $payload = base64_encode($source);
    $chunks = chunk_base64($payload);
    $joined = implode(" +\n    ", $chunks);

    return "/* Smart Finance obfuscated: {$relativePath} */\n"
        . "(function () {\n"
        . "    var __payload = {$joined};\n"
        . "    function __decodeBase64Utf8(b64) {\n"
        . "        var bin = atob(b64);\n"
        . "        if (typeof TextDecoder !== 'undefined') {\n"
        . "            var len = bin.length;\n"
        . "            var bytes = new Uint8Array(len);\n"
        . "            for (var i = 0; i < len; i++) {\n"
        . "                bytes[i] = bin.charCodeAt(i);\n"
        . "            }\n"
        . "            return new TextDecoder('utf-8').decode(bytes);\n"
        . "        }\n"
        . "        try {\n"
        . "            return decodeURIComponent(bin.replace(/(.)/g, function (m, ch) {\n"
        . "                var code = ch.charCodeAt(0).toString(16).toUpperCase();\n"
        . "                return '%' + (code.length < 2 ? '0' + code : code);\n"
        . "            }));\n"
        . "        } catch (e) {\n"
        . "            return bin;\n"
        . "        }\n"
        . "    }\n"
        . "    var __decoded = __decodeBase64Utf8(__payload);\n"
        . "    (0, eval)(__decoded);\n"
        . "    __decoded = '';\n"
        . "})();\n";
}

/**
 * Render HTML via base64 payload.
 */
function obfuscate_html(string $source, string $relativePath): string
{
    $payload = base64_encode($source);
    $chunks = chunk_base64($payload);
    $joined = implode(" +\n            ", $chunks);

    return "<!doctype html>\n"
        . "<html lang=\"th\">\n"
        . "<head>\n"
        . "    <meta charset=\"utf-8\">\n"
        . "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
        . "    <title>Smart Finance (Protected)</title>\n"
        . "    <script>\n"
        . "        (function () {\n"
        . "            var __payload = {$joined};\n"
        . "            function __decodeBase64Utf8(b64) {\n"
        . "                var bin = atob(b64);\n"
        . "                if (typeof TextDecoder !== 'undefined') {\n"
        . "                    var len = bin.length;\n"
        . "                    var bytes = new Uint8Array(len);\n"
        . "                    for (var i = 0; i < len; i++) {\n"
        . "                        bytes[i] = bin.charCodeAt(i);\n"
        . "                    }\n"
        . "                    return new TextDecoder('utf-8').decode(bytes);\n"
        . "                }\n"
        . "                try {\n"
        . "                    return decodeURIComponent(bin.replace(/(.)/g, function (m, ch) {\n"
        . "                        var code = ch.charCodeAt(0).toString(16).toUpperCase();\n"
        . "                        return '%' + (code.length < 2 ? '0' + code : code);\n"
        . "                    }));\n"
        . "                } catch (e) {\n"
        . "                    return bin;\n"
        . "                }\n"
        . "            }\n"
        . "            var __html = __decodeBase64Utf8(__payload);\n"
        . "            document.open();\n"
        . "            document.write(__html);\n"
        . "            document.close();\n"
        . "        })();\n"
        . "    </script>\n"
        . "</head>\n"
        . "<body></body>\n"
        . "</html>\n";
}

/**
 * @return array<int,string>
 */
function chunk_base64(string $payload): array
{
    $parts = str_split($payload, 120);
    $chunks = [];
    foreach ($parts as $part) {
        $chunks[] = "'" . $part . "'";
    }
    return $chunks;
}
