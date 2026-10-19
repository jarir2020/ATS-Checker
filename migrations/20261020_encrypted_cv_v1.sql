-- Apply only after taking and verifying a database backup.
-- This adds encrypted storage alongside the current plaintext draft fields.
-- It does not copy, modify, or delete any existing draft rows.

ALTER TABLE `draft_cvs`
    ADD COLUMN `encrypted_payload` LONGTEXT NULL AFTER `cv_data`,
    ADD COLUMN `encrypted_name` TEXT NULL AFTER `encrypted_payload`,
    ADD COLUMN `encryption_version` SMALLINT UNSIGNED NULL AFTER `encrypted_name`,
    ADD COLUMN `plaintext_cleared` TINYINT(1) NOT NULL DEFAULT 0 AFTER `encryption_version`;

CREATE TABLE IF NOT EXISTS `cv_vault_meta` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `metadata_json` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
