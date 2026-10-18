<?php
namespace ATS\Cv;

final class DraftRepository
{
    public function list(): array
    {
        return \getDb()->query('SELECT id, draft_name, updated_at FROM draft_cvs ORDER BY updated_at DESC')->fetchAll();
    }

    public function get(int $id): ?array
    {
        $statement = \getDb()->prepare('SELECT cv_data FROM draft_cvs WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();
        if (!$row) return null;
        $decoded = json_decode($row['cv_data'], true);
        return is_array($decoded) ? CvDocument::normalize($decoded) : CvDocument::empty();
    }

    public function save(string $name, array $cv, int $id = 0): int
    {
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '') throw new \InvalidArgumentException('Enter a name for this draft.');
        $json = json_encode(CvDocument::normalize($cv), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new \InvalidArgumentException('This CV could not be saved.');
        $db = \getDb();
        if ($id > 0) {
            $statement = $db->prepare('UPDATE draft_cvs SET draft_name = ?, cv_data = ?, updated_at = NOW() WHERE id = ?');
            $statement->execute([$name, $json, $id]);
            if ($statement->rowCount() > 0) return $id;
            $check = $db->prepare('SELECT id FROM draft_cvs WHERE id = ?');
            $check->execute([$id]);
            if ($check->fetch()) return $id;
        }
        $statement = $db->prepare('INSERT INTO draft_cvs (draft_name, cv_data) VALUES (?, ?)');
        $statement->execute([$name, $json]);
        return (int)$db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $statement = \getDb()->prepare('DELETE FROM draft_cvs WHERE id = ?');
        $statement->execute([$id]);
    }
}
