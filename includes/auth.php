<?php

function authPasswordHash(): string
{
    $hash = $_ENV['APP_PASSWORD_HASH'] ?? null;
    if ($hash === null) {
        $hash = getenv('APP_PASSWORD_HASH');
    }
    return is_string($hash) ? trim($hash) : '';
}

function authIsConfigured(): bool
{
    $hash = authPasswordHash();
    if ($hash === '') {
        return false;
    }

    $info = password_get_info($hash);
    return is_array($info) && !empty($info['algo']);
}

function authIsAuthenticated(): bool
{
    return ($_SESSION['authenticated'] ?? false) === true;
}

function authPasswordMatches(string $password): bool
{
    return authIsConfigured() && password_verify($password, authPasswordHash());
}

function authLoginAllowed(): bool
{
    $now = time();
    $attempts = $_SESSION['auth_login_attempts'] ?? [];
    if (!is_array($attempts)) {
        $attempts = [];
    }

    $attempts = array_values(array_filter($attempts, static function ($attempt) use ($now): bool {
        return is_int($attempt) && $attempt > $now - 900;
    }));
    $_SESSION['auth_login_attempts'] = $attempts;

    return count($attempts) < 8;
}

function authRecordFailedLogin(): void
{
    $attempts = $_SESSION['auth_login_attempts'] ?? [];
    if (!is_array($attempts)) {
        $attempts = [];
    }
    $attempts[] = time();
    $_SESSION['auth_login_attempts'] = $attempts;
}

function authMarkAuthenticated(): bool
{
    if (!session_regenerate_id(true)) {
        return false;
    }

    $_SESSION = [
        'authenticated' => true,
        'authenticated_at' => time(),
        'csrf_token' => bin2hex(random_bytes(32)),
    ];

    return true;
}

function authLogout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function requireAuthentication(bool $apiRequest = false): void
{
    if (authIsAuthenticated()) {
        return;
    }

    if ($apiRequest || (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET')) {
        jsonResponse(['ok' => false, 'error' => 'Sign in to continue.'], 401);
    }

    header('Location: ' . BASE_URL . '?page=login', true, 302);
    exit;
}
