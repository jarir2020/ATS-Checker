<?php
// Database helper — 2026-05-08
function getDb(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4',
        $_ENV['DB_HOST'] ?? '127.0.0.1',
        $_ENV['DB_PORT'] ?? '3306'
    );
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? 'root', $_ENV['DB_PASS'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db = $_ENV['DB_NAME'] ?? 'cv_maker';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
        throw new RuntimeException('DB_NAME may contain only letters, numbers, and underscores.');
    }
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db}`");
    $draftTableExists = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'draft_cvs'")->fetchColumn() > 0;
    $pdo->exec("CREATE TABLE IF NOT EXISTS `draft_cvs` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `draft_name` VARCHAR(255) NOT NULL,
        `cv_data`    JSON        NOT NULL,
        `encrypted_payload` LONGTEXT NULL,
        `encrypted_name` TEXT NULL,
        `encryption_version` SMALLINT UNSIGNED NULL,
        `plaintext_cleared` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME    DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Initialize a fresh install fully. Existing databases get schema changes
    // only from the explicit, backup-gated migration runner.
    if (!$draftTableExists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `cv_vault_meta` (
            `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            `metadata_json` TEXT NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    return $pdo;
}
