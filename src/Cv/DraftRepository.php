<?php
namespace ATS\Cv;

final class DraftRepository
{
    public function list(): array
    {
        $rows = \getDb()->query('SELECT id, draft_name, updated_at, encrypted_name, encrypted_payload, encryption_version, plaintext_cleared FROM draft_cvs ORDER BY updated_at DESC')->fetchAll();
        foreach ($rows as &$row) {
            $row['encrypted'] = !empty($row['encrypted_payload']);
            $row['plaintext_remaining'] = empty($row['plaintext_cleared']);
            $row['name_envelope'] = $row['encrypted'] ? $this->decodeEnvelope($row['encrypted_name']) : null;
            if ($row['encrypted']) $row['draft_name'] = null;
            unset($row['encrypted_name'], $row['encrypted_payload'], $row['encryption_version'], $row['plaintext_cleared']);
        }
        unset($row);
        return $rows;
    }

    public function getRecord(int $id): ?array
    {
        $statement = \getDb()->prepare('SELECT id, draft_name, cv_data, encrypted_name, encrypted_payload, encryption_version, plaintext_cleared FROM draft_cvs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) return null;
        if (is_string($row['encrypted_payload']) && $row['encrypted_payload'] !== '') {
            $version = (int)$row['encryption_version'];
            if ($version !== 1) throw new \RuntimeException('This encrypted draft uses an unsupported format version.');
            return [
                'encrypted' => true,
                'envelope' => $this->decodeEnvelope($row['encrypted_payload']),
                'name_envelope' => $this->decodeEnvelope($row['encrypted_name']),
                'plaintext_remaining' => empty($row['plaintext_cleared']),
            ];
        }
        $decoded = json_decode((string)$row['cv_data'], true);
        return [
            'encrypted' => false,
            'draft_name' => (string)$row['draft_name'],
            'cv' => is_array($decoded) ? CvDocument::normalize($decoded) : CvDocument::empty(),
        ];
    }

    /**
     * Return the legacy source only for the explicit migration workflow. Ordinary
     * draft reads expose ciphertext for encrypted rows and never return this copy.
     */
    public function getMigrationRecord(int $id): ?array
    {
        $statement = \getDb()->prepare('SELECT draft_name, cv_data, plaintext_cleared FROM draft_cvs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row || !empty($row['plaintext_cleared'])) return null;
        $decoded = json_decode((string)$row['cv_data'], true);
        return [
            'draft_name' => (string)$row['draft_name'],
            'cv' => is_array($decoded) ? CvDocument::normalize($decoded) : CvDocument::empty(),
        ];
    }

    public function reserve(): int
    {
        $this->migrationStatus();
        $statement = \getDb()->prepare("INSERT INTO draft_cvs (draft_name, cv_data, plaintext_cleared) VALUES ('Encrypted draft', JSON_OBJECT(), 1)");
        $statement->execute();
        $id = (int)\getDb()->lastInsertId();
        if ($id < 1) throw new \RuntimeException('Could not reserve an encrypted draft record.');
        return $id;
    }

    public function saveEncrypted(int $id, array $payload, array $name): void
    {
        $this->migrationStatus();
        if ($id < 1) throw new \InvalidArgumentException('Draft identifier is invalid.');
        $this->validatePayloadEnvelope($payload);
        $this->validateNameEnvelope($name);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $nameJson = json_encode($name, JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || !is_string($nameJson) || strlen($payloadJson) > 1200000 || strlen($nameJson) > 10000) {
            throw new \InvalidArgumentException('The encrypted draft is too large or could not be saved.');
        }
        $statement = \getDb()->prepare('UPDATE draft_cvs SET encrypted_name = ?, encrypted_payload = ?, encryption_version = 1, updated_at = NOW() WHERE id = ?');
        $statement->execute([$nameJson, $payloadJson, $id]);
        if ($statement->rowCount() === 0) {
            $check = \getDb()->prepare('SELECT id FROM draft_cvs WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) throw new \RuntimeException('Draft not found. Refresh the draft list and try again.');
        }
    }

    public function migrationStatus(): array
    {
        $row = \getDb()->query("SELECT COUNT(*) AS total, SUM(encryption_version = 1 AND encrypted_payload IS NOT NULL AND encrypted_payload <> '' AND encrypted_name IS NOT NULL AND encrypted_name <> '') AS encrypted, SUM(plaintext_cleared = 0) AS plaintext_remaining FROM draft_cvs")->fetch();
        return [
            'total' => (int)($row['total'] ?? 0),
            'encrypted' => (int)($row['encrypted'] ?? 0),
            'plaintext_remaining' => (int)($row['plaintext_remaining'] ?? 0),
        ];
    }

    public function clearPlaintextAfterMigration(): int
    {
        $status = $this->migrationStatus();
        if ($status['total'] !== $status['encrypted']) {
            throw new \RuntimeException('Encrypt every draft before removing the remaining plaintext.');
        }
        if ($status['plaintext_remaining'] === 0) return 0;
        $statement = \getDb()->prepare("UPDATE draft_cvs SET draft_name = CONCAT('Encrypted draft ', id), cv_data = JSON_OBJECT(), plaintext_cleared = 1, updated_at = NOW() WHERE encrypted_payload IS NOT NULL AND encrypted_payload <> '' AND plaintext_cleared = 0");
        $statement->execute();
        return $statement->rowCount();
    }

    public function getVaultMetadata(): ?array
    {
        $row = \getDb()->query('SELECT metadata_json FROM cv_vault_meta WHERE id = 1')->fetch();
        if (!$row) return null;
        $metadata = json_decode((string)$row['metadata_json'], true);
        if (!is_array($metadata)) throw new \RuntimeException('Vault metadata is unreadable.');
        $this->validateVaultMetadata($metadata);
        return $metadata;
    }

    public function initializeVault(array $metadata): array
    {
        $this->validateVaultMetadata($metadata);
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new \InvalidArgumentException('Vault metadata could not be saved.');
        $statement = \getDb()->prepare('INSERT IGNORE INTO cv_vault_meta (id, metadata_json) VALUES (1, ?)');
        $statement->execute([$json]);
        $saved = $this->getVaultMetadata();
        if (!$saved) throw new \RuntimeException('Vault metadata was not saved.');
        return ['metadata' => $saved, 'created' => $statement->rowCount() === 1];
    }

    public function replaceVaultMetadata(array $expected, array $replacement): array
    {
        $this->validateVaultMetadata($expected);
        $this->validateVaultMetadata($replacement);
        $oldJson = json_encode($expected, JSON_UNESCAPED_SLASHES);
        $newJson = json_encode($replacement, JSON_UNESCAPED_SLASHES);
        if (!is_string($oldJson) || !is_string($newJson)) throw new \InvalidArgumentException('Vault metadata could not be encoded.');
        $statement = \getDb()->prepare('UPDATE cv_vault_meta SET metadata_json = ?, updated_at = NOW() WHERE id = 1 AND metadata_json = ?');
        $statement->execute([$newJson, $oldJson]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Vault metadata changed in another session. Reload and try again.');
        return $replacement;
    }

    private function validateVaultMetadata(array $metadata): void
    {
        if (($metadata['version'] ?? null) !== 1 || ($metadata['kdf'] ?? null) !== 'PBKDF2-SHA-256' || ($metadata['iterations'] ?? null) !== 600000) {
            throw new \InvalidArgumentException('Unsupported vault metadata.');
        }
        foreach (['salt' => 16, 'wrap_nonce' => 12, 'wrapped_key' => 48, 'recovery_nonce' => 12, 'recovery_wrapped_key' => 48] as $key => $bytes) {
            $value = $metadata[$key] ?? null;
            $decoded = is_string($value) ? base64_decode($value, true) : false;
            if (!is_string($decoded) || strlen($decoded) !== $bytes) throw new \InvalidArgumentException('Vault metadata is incomplete or invalid.');
        }
    }

    private function validatePayloadEnvelope(array $envelope): void
    {
        if (($envelope['version'] ?? null) !== 1 || ($envelope['algorithm'] ?? null) !== 'AES-GCM-256') throw new \InvalidArgumentException('Unsupported encrypted draft format.');
        foreach (['data_nonce' => 12, 'wrap_nonce' => 12, 'wrapped_key' => 48] as $key => $bytes) {
            $decoded = isset($envelope[$key]) && is_string($envelope[$key]) ? base64_decode($envelope[$key], true) : false;
            if (!is_string($decoded) || strlen($decoded) !== $bytes) throw new \InvalidArgumentException('Encrypted draft envelope is invalid.');
        }
        $ciphertext = isset($envelope['ciphertext']) && is_string($envelope['ciphertext']) ? base64_decode($envelope['ciphertext'], true) : false;
        if (!is_string($ciphertext) || strlen($ciphertext) < 17) throw new \InvalidArgumentException('Encrypted draft content is invalid.');
    }

    private function validateNameEnvelope(array $envelope): void
    {
        if (($envelope['version'] ?? null) !== 1 || ($envelope['algorithm'] ?? null) !== 'AES-GCM-256') throw new \InvalidArgumentException('Unsupported encrypted draft name format.');
        $nonce = isset($envelope['nonce']) && is_string($envelope['nonce']) ? base64_decode($envelope['nonce'], true) : false;
        $ciphertext = isset($envelope['ciphertext']) && is_string($envelope['ciphertext']) ? base64_decode($envelope['ciphertext'], true) : false;
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($ciphertext) || strlen($ciphertext) < 17 || strlen($ciphertext) > 5000) {
            throw new \InvalidArgumentException('Encrypted draft name is invalid.');
        }
    }

    private function decodeEnvelope($json): ?array
    {
        if (!is_string($json) || $json === '') return null;
        $envelope = json_decode($json, true);
        return is_array($envelope) ? $envelope : null;
    }

    public function delete(int $id): void
    {
        $statement = \getDb()->prepare('DELETE FROM draft_cvs WHERE id = ?');
        $statement->execute([$id]);
    }
}
