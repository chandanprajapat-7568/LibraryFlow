<?php
declare(strict_types=1);

$projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
$basePath = '';
if ($documentRoot !== '' && stripos($projectRoot, $documentRoot) === 0) {
    $basePath = substr($projectRoot, strlen($documentRoot));
}
define('APP_BASE_URL', rtrim(str_replace('\\', '/', $basePath), '/'));

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');
if (strtolower((string) (getenv('APP_ENV') ?: 'local')) === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE_URL === '' ? '/' : APP_BASE_URL . '/',
        'secure' => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:");
header('Cache-Control: no-store, private');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$guestRoutes = ['login.php', 'setup.php', 'forgot-password.php', 'reset-password.php', 'privacy.php', 'terms.php'];
$currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
if (!in_array($currentScript, $guestRoutes, true)) {
    require_login();
}
