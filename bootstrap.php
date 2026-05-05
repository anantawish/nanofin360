<?php
declare(strict_types=1);

$webConfigPath = __DIR__ . '/webconfig.php';
$defaultConfigPath = __DIR__ . '/config.php';
$configPath = is_file($webConfigPath) ? $webConfigPath : $defaultConfigPath;
$config = require $configPath;

date_default_timezone_set($config['timezone']);

if (function_exists('ini_set')) {
    ini_set('default_charset', 'UTF-8');
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
if (function_exists('mb_http_output')) {
    mb_http_output('UTF-8');
}
if (function_exists('mb_regex_encoding')) {
    mb_regex_encoding('UTF-8');
}

if (PHP_SAPI !== 'cli') {
    // Force UTF-8 and disable page cache so updated Thai labels are always rendered.
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name']);
    session_start();
}

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/statement_ocr.php';
require_once __DIR__ . '/lib/modules.php';
require_once __DIR__ . '/lib/lei_scenarios.php';
require_once __DIR__ . '/lib/module_engine.php';

if (PHP_SAPI !== 'cli') {
    nanfin_normalize_request_encoding();
}

if (PHP_SAPI !== 'cli' && !defined('NANFIN_OUTPUT_REPAIR_STARTED')) {
    ob_start('nanfin_output_repair_mojibake');
    define('NANFIN_OUTPUT_REPAIR_STARTED', true);
}

$appConfig = $config;
try {
    $pdo = db_connect($config['db']);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h2>Failed to connect to database</h2>';
    echo '<p>Please check the NANFIN_DB_* value and import the schema.sql before using.</p>';
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    exit;
}

set_app_config($appConfig);
set_db($pdo);
ensure_workflow_performance_indexes($pdo);
ensure_event_ledger_table($pdo);
ensure_customer_statement_ocr_table($pdo);

if (!isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = '';
}
if (!isset($_SESSION['role_name'])) {
    $_SESSION['role_name'] = '';
}
if (!isset($_SESSION['user_profile']) || !is_array($_SESSION['user_profile'])) {
    $_SESSION['user_profile'] = [];
}
if (!isset($_SESSION['branch_code'])) {
    $_SESSION['branch_code'] = '';
}
if (!isset($_SESSION['region_name'])) {
    $_SESSION['region_name'] = '';
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$isCli = PHP_SAPI === 'cli';
$isLoginPage = $currentScript === 'login.php';

/**
 * Prevent open redirect by accepting only paths within the system
 */
function auth_normalize_return_to(string $target): string
{
    $target = trim($target);
    if ($target === '') {
        return app_base_url('index.php');
    }

    if (preg_match('/^https?:\/\//i', $target) === 1) {
        return app_base_url('index.php');
    }

    if (!str_starts_with($target, '/')) {
        $target = '/' . ltrim($target, '/');
    }

    return $target;
}

if (!$isCli && $requestMethod === 'POST' && ($_POST['__action'] ?? '') === 'logout') {
    verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie((string)session_name(), '', time() - 42000, (string)($params['path'] ?? '/'), (string)($params['domain'] ?? ''), (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
    session_name($config['session_name']);
    session_start();

    add_flash('success', 'Log out successfully');
    redirect_to(app_base_url('login.php'));
}

if (!$isCli && $requestMethod === 'POST' && ($_POST['__action'] ?? '') === 'login') {
    verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

    $userName = strtolower(trim((string)($_POST['user_name'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $returnTo = auth_normalize_return_to((string)($_POST['return_to'] ?? app_base_url('index.php')));

    if ($userName === '' || $password === '') {
        add_flash('danger', 'Please enter your username and password.');
        redirect_to(app_base_url('login.php') . '?return_to=' . rawurlencode($returnTo));
    }

    $stmtUser = db()->prepare(
        "SELECT user_name, role_name, profile_json
         FROM system_users
         WHERE user_name = :user_name AND is_latest = 1 AND is_deleted = 0
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmtUser->execute([':user_name' => $userName]);
    $rowUser = $stmtUser->fetch();

    if (!$rowUser) {
        add_flash('danger', 'The username or password is incorrect.');
        redirect_to(app_base_url('login.php') . '?return_to=' . rawurlencode($returnTo));
    }

    $profile = json_decode((string)($rowUser['profile_json'] ?? ''), true);
    if (!is_array($profile)) {
        $profile = [];
    }

    $passwordHash = (string)($profile['password_hash'] ?? '');
    $passwordPlain = (string)($profile['password'] ?? '');

    $verified = false;
    if ($passwordHash !== '') {
        $verified = password_verify($password, $passwordHash);
    } elseif ($passwordPlain !== '') {
        $verified = hash_equals($passwordPlain, $password);
    }

    if (!$verified) {
        add_flash('danger', 'The username or password is incorrect.');
        redirect_to(app_base_url('login.php') . '?return_to=' . rawurlencode($returnTo));
    }

    session_regenerate_id(true);

    $_SESSION['user_name'] = (string)$rowUser['user_name'];
    $_SESSION['role_name'] = trim((string)$rowUser['role_name']);
    $_SESSION['user_profile'] = $profile;
    $_SESSION['branch_code'] = strtoupper(trim((string)($profile['branch_code'] ?? '')));
    $_SESSION['region_name'] = trim((string)($profile['region_name'] ?? ''));
    if ($_SESSION['region_name'] === '' && $_SESSION['branch_code'] !== '') {
        $_SESSION['region_name'] = branch_region_name((string)$_SESSION['branch_code']);
    }

    add_flash('success', 'Successfully logged in');
    redirect_to($returnTo);
}

if (!$isCli && $requestMethod === 'POST' && ($_POST['__action'] ?? '') === 'change_password') {
    $redirectBack = auth_normalize_return_to((string)($_SERVER['REQUEST_URI'] ?? app_base_url('index.php')));

    try {
        verify_csrf_or_fail((string)($_POST['csrf_token'] ?? ''));

        $sessionUser = trim((string)($_SESSION['user_name'] ?? ''));
        $sessionRole = trim((string)($_SESSION['role_name'] ?? ''));
        if ($sessionUser === '' || $sessionRole === '') {
            add_flash('warning', 'Please log in before using.');
            redirect_to(app_base_url('login.php') . '?return_to=' . rawurlencode($redirectBack));
        }

        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            throw new RuntimeException('Please fill in your password in all fields.');
        }
        if (strlen($newPassword) < 3) {
            throw new RuntimeException('The new password must be at least 3 characters.');
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('The new password must be unique from the current password.');
        }
        if (!hash_equals($newPassword, $confirmPassword)) {
            throw new RuntimeException('Confirm new password does not match');
        }

        $stmtUser = db()->prepare(
            "SELECT id, profile_json
             FROM system_users
             WHERE user_name = :user_name AND is_latest = 1 AND is_deleted = 0
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmtUser->execute([':user_name' => $sessionUser]);
        $rowUser = $stmtUser->fetch();
        if (!$rowUser) {
            throw new RuntimeException('No user information found.');
        }

        $profile = json_decode((string)($rowUser['profile_json'] ?? ''), true);
        if (!is_array($profile)) {
            $profile = [];
        }

        $passwordHash = (string)($profile['password_hash'] ?? '');
        $passwordPlain = (string)($profile['password'] ?? '');
        $verified = false;

        if ($passwordHash !== '') {
            $verified = password_verify($currentPassword, $passwordHash);
        } elseif ($passwordPlain !== '') {
            $verified = hash_equals($passwordPlain, $currentPassword);
        }

        if (!$verified) {
            throw new RuntimeException('The current password is incorrect.');
        }

        $profile['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        unset($profile['password']);
        $profile['password_changed_at'] = now_dt();
        $profile['password_changed_by'] = $sessionUser;
        $profile['password_changed_ip'] = request_ip();

        $updatedAt = now_dt();
        $stmtUpdate = db()->prepare(
            "UPDATE system_users
             SET profile_json = :profile_json,
                 updated_by = :updated_by,
                 updated_at = :updated_at
             WHERE id = :id AND is_latest = 1 AND is_deleted = 0"
        );
        $stmtUpdate->execute([
            ':profile_json' => json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':updated_by' => $sessionUser,
            ':updated_at' => $updatedAt,
            ':id' => (int)$rowUser['id'],
        ]);

        $_SESSION['user_profile'] = $profile;
        add_flash('success', 'Password changed successfully.');
    } catch (Throwable $e) {
        add_flash('danger', 'Unable to change password: ' . $e->getMessage());
    }

    redirect_to($redirectBack);
}

$isAuthenticated = trim((string)($_SESSION['user_name'] ?? '')) !== '' && trim((string)($_SESSION['role_name'] ?? '')) !== '';

if (!$isCli && !$isAuthenticated && !$isLoginPage) {
    $returnTo = auth_normalize_return_to((string)($_SERVER['REQUEST_URI'] ?? app_base_url('index.php')));
    add_flash('warning', 'Please log in before using.');
    redirect_to(app_base_url('login.php') . '?return_to=' . rawurlencode($returnTo));
}

if (!$isCli && $isAuthenticated && $isLoginPage) {
    redirect_to(app_base_url('index.php'));
}
