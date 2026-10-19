<?php
// 2026-05-08
define('BASE_PATH', __DIR__);

if (PHP_VERSION_ID < 70400) {
    http_response_code(503);
    exit('ATS Checker requires PHP 7.4 or newer.');
}

require_once __DIR__ . '/vendor/autoload.php';

// Parse .env
$envPath = BASE_PATH . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (strlen($val) >= 2 && (($val[0] === '"' && $val[strlen($val) - 1] === '"') || ($val[0] === "'" && $val[strlen($val) - 1] === "'"))) {
            $val = substr($val, 1, -1);
        }
        $_ENV[$key] = $val;
    }
}

define('APP_NAME', $_ENV['APP_NAME'] ?? 'ATS Checker');
define('BASE_URL', rtrim($_ENV['APP_URL'] ?? '/', '/') . '/');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $appUsesHttps = strpos(BASE_URL, 'https://') === 0
        || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    $sessionPath = parse_url(BASE_URL, PHP_URL_PATH);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => is_string($sessionPath) && $sessionPath !== '' ? $sessionPath : '/',
        'secure' => $appUsesHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Remove imported CV drafts left by older converter flows from the session.
unset($_SESSION['imported_cv']);

spl_autoload_register(static function (string $class): void {
    $prefix = 'ATS\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
