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
    session_start();
}

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
