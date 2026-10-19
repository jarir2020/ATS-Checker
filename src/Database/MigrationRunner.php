<?php
namespace ATS\Database;

use PDO;

final class MigrationRunner
{
    private PDO $db;
    private string $directory;

    public function __construct(PDO $db, string $directory)
    {
        $this->db = $db;
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public function status(): array
    {
        $applied = $this->appliedRows();
        $pending = [];
        foreach ($this->migrationFiles() as $migration) {
            $id = basename($migration);
            $checksum = hash_file('sha256', $migration);
            if (!is_string($checksum)) throw new \RuntimeException('A migration file could not be read.');
            if (isset($applied[$id])) {
                if (!hash_equals($applied[$id], $checksum)) throw new \RuntimeException('An applied migration file was changed. Restore the original migration file.');
                continue;
            }
            if ($this->schemaState($id) === 'complete') continue;
            $pending[] = $id;
        }
        return ['pending' => $pending];
    }

    public function applyPending(bool $backupConfirmed): array
    {
        if (!$backupConfirmed) throw new \InvalidArgumentException('Confirm a verified offline database backup before applying migrations.');
        $this->db->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `migration_id` VARCHAR(190) NOT NULL PRIMARY KEY,
            `checksum` CHAR(64) NOT NULL,
            `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $applied = $this->appliedRows();
        $completed = [];
        foreach ($this->migrationFiles() as $migration) {
            $id = basename($migration);
            $checksum = hash_file('sha256', $migration);
            if (!is_string($checksum)) throw new \RuntimeException('A migration file could not be read.');
            if (isset($applied[$id])) {
                if (!hash_equals($applied[$id], $checksum)) throw new \RuntimeException('An applied migration file was changed. Restore the original migration file.');
                continue;
            }

            $schemaState = $this->schemaState($id);
            if ($schemaState === 'complete') {
                $this->recordApplied($id, $checksum);
                $completed[] = $id . ' (schema already present)';
                continue;
            }

            $sql = file_get_contents($migration);
            if (!is_string($sql) || trim($sql) === '') throw new \RuntimeException('A migration file is empty or unreadable.');
            foreach (preg_split('/;\s*(?:\R|$)/', trim($sql)) ?: [] as $statement) {
                $statement = trim($statement);
                if ($statement !== '') $this->db->exec($statement);
            }
            if ($this->schemaState($id) !== 'complete' && $id === '20261020_encrypted_cv_v1.sql') {
                throw new \RuntimeException('The encrypted draft migration did not create all expected columns.');
            }
            $this->recordApplied($id, $checksum);
            $completed[] = $id;
        }
        return ['applied' => $completed];
    }

    private function migrationFiles(): array
    {
        if (!is_dir($this->directory)) return [];
        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            if (is_link($file) || !is_file($file) || !preg_match('/^[0-9]{8}_[a-z0-9][a-z0-9_-]*\.sql$/D', basename($file))) {
                throw new \RuntimeException('Migration files must use the YYYYMMDD_name.sql format and cannot be symlinks.');
            }
        }
        return $files;
    }

    private function appliedRows(): array
    {
        if (!$this->tableExists('schema_migrations')) return [];
        $rows = $this->db->query('SELECT migration_id, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
        $applied = [];
        foreach ($rows as $row) $applied[(string)$row['migration_id']] = (string)$row['checksum'];
        return $applied;
    }

    private function recordApplied(string $id, string $checksum): void
    {
        $statement = $this->db->prepare('INSERT INTO schema_migrations (migration_id, checksum) VALUES (?, ?)');
        $statement->execute([$id, $checksum]);
    }

    private function tableExists(string $name): bool
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$name]);
        return (int)$statement->fetchColumn() > 0;
    }

    private function schemaState(string $id): ?string
    {
        if ($id !== '20261020_encrypted_cv_v1.sql') return null;
        $statement = $this->db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'draft_cvs' AND column_name IN ('encrypted_payload', 'encrypted_name', 'encryption_version', 'plaintext_cleared')");
        $columns = (int)$statement->fetchColumn();
        if ($columns === 0) return 'pending';
        if ($columns !== 4 || !$this->tableExists('cv_vault_meta')) {
            throw new \RuntimeException('The encrypted draft migration is partially applied. Inspect the database schema before retrying.');
        }
        return 'complete';
    }
}
