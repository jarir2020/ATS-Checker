<?php
namespace ATS\Cv;

final class CvDocument
{
    public const SCHEMA_VERSION = 1;

    private const ARRAY_LIMIT = 80;
    private const TEXT_LIMIT = 20000;

    public static function empty(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'personal' => ['name' => '', 'title' => '', 'phone' => '', 'email' => '', 'location' => '', 'linkedin' => '', 'github' => ''],
            'enabled_sections' => ['summary' => true, 'skills' => true, 'experience' => true, 'education' => true, 'projects' => true, 'certifications' => true, 'languages' => true, 'custom_sections' => true],
            'summary' => '', 'skills' => [], 'experience' => [], 'education' => [],
            'projects' => [], 'certifications' => [], 'languages' => [], 'custom_sections' => [],
        ];
    }

    public static function normalize(array $input): array
    {
        if (isset($input['schema_version']) && isset($input['personal'])) {
            return self::repairImportArtifacts(self::fromCanonical($input));
        }
        return self::repairImportArtifacts(self::fromLegacy($input));
    }

    private static function repairImportArtifacts(array $cv): array
    {
        foreach ($cv['education'] as &$row) {
            if (!preg_match('/^((?:0?[1-9]|1[0-2])\/)(?:\r\n|\r|\n|$)/', $row['details'], $match)) continue;
            if (!preg_match('/^(?:19|20)\d{2}$/', $row['year'])) continue;
            $row['year'] = $match[1] . $row['year'];
            $row['details'] = ltrim(substr($row['details'], strlen($match[0])), "\r\n");
        }
        unset($row);

        foreach ($cv['experience'] as &$row) {
            if ($row['title'] === '' || $row['company'] === '' || $row['location'] !== '') continue;
            if (!preg_match('/^(?:19|20)\d{2}$/', $row['start']) || !$row['bullets']) continue;
            if (!preg_match('/^(.+?)\s*\|\s*((?:0?[1-9]|1[0-2])\/(?:0?[1-9]|[12]\d|3[01])\/)\s*$/', $row['bullets'][0], $match)) continue;
            $role = trim($match[1]);
            if ($role === '') continue;
            $employer = $row['title'];
            $location = $row['company'];
            $row['start'] = $match[2] . $row['start'];
            $row['title'] = $role;
            $row['company'] = $employer;
            $row['location'] = $location;
            array_shift($row['bullets']);
        }
        unset($row);
        return $cv;
    }

    private static function fromCanonical(array $in): array
    {
        $out = self::empty();
        $p = is_array($in['personal'] ?? null) ? $in['personal'] : [];
        foreach ($out['personal'] as $key => $_) {
            $out['personal'][$key] = self::text($p[$key] ?? '');
        }
        $enabled = is_array($in['enabled_sections'] ?? null) ? $in['enabled_sections'] : [];
        foreach ($out['enabled_sections'] as $key => $_) {
            $out['enabled_sections'][$key] = array_key_exists($key, $enabled) ? (bool)$enabled[$key] : true;
        }
        $out['summary'] = self::text($in['summary'] ?? '');
        $legacySectionMap = ['summary' => 'summary', 'skills' => 'skills', 'experience' => 'experience', 'education' => 'education', 'projects' => 'projects', 'certifications' => 'certifications', 'languages' => 'languages', 'extra' => 'custom_sections'];
        if (is_array($in['sec_on'] ?? null)) {
            foreach ($legacySectionMap as $oldKey => $newKey) {
                if (array_key_exists($oldKey, $in['sec_on'])) $out['enabled_sections'][$newKey] = (string)$in['sec_on'][$oldKey] !== '0';
            }
        }
        $map = [
            'skills' => ['name', 'details'],
            'experience' => ['company', 'location', 'title', 'start', 'end', 'bullets'],
            'education' => ['institution', 'location', 'degree', 'year', 'details'],
            'projects' => ['name', 'technologies', 'description', 'url'],
            'certifications' => ['name', 'issuer', 'date', 'credential_id'],
            'languages' => ['name', 'level'],
            'custom_sections' => ['title', 'content'],
        ];
        foreach ($map as $section => $fields) {
            $items = is_array($in[$section] ?? null) ? array_slice($in[$section], 0, self::ARRAY_LIMIT) : [];
            foreach ($items as $item) {
                if (!is_array($item)) continue;
                $row = [];
                foreach ($fields as $field) {
                    if ($field === 'bullets') {
                        $row[$field] = self::stringList($item[$field] ?? []);
                    } else {
                        $row[$field] = self::text($item[$field] ?? '');
                    }
                }
                if (count(array_filter($row, static fn($v) => is_array($v) ? count($v) > 0 : $v !== '')) > 0) {
                    $out[$section][] = $row;
                }
            }
        }
        return $out;
    }

    private static function fromLegacy(array $in): array
    {
        $out = self::empty();
        $out['personal'] = [
            'name' => self::text($in['cv_name'] ?? $in['name'] ?? ''),
            'title' => self::text($in['cv_role'] ?? $in['role'] ?? ''),
            'phone' => self::text($in['phone'] ?? ''), 'email' => self::text($in['email'] ?? ''),
            'location' => self::text($in['location'] ?? ''), 'linkedin' => self::text($in['linkedin'] ?? ''),
            'github' => self::text($in['github'] ?? ''),
        ];
        $out['summary'] = self::text($in['summary'] ?? '');
        self::legacyRows($out, $in, 'skills', ['name' => 'skill_label', 'details' => 'skill_value']);
        self::legacyRows($out, $in, 'experience', [
            'company' => 'exp_company', 'location' => 'exp_location', 'title' => 'exp_title',
            'start' => 'exp_start', 'end' => 'exp_end', 'bullets' => 'exp_resp',
        ], ['bullets']);
        self::legacyRows($out, $in, 'education', [
            'institution' => 'edu_uni', 'location' => 'edu_location', 'degree' => 'edu_degree',
            'year' => 'edu_year', 'details' => 'edu_desc',
        ]);
        self::legacyRows($out, $in, 'projects', [
            'name' => 'proj_name', 'technologies' => 'proj_tech', 'description' => 'proj_desc', 'url' => 'proj_url',
        ]);
        self::legacyRows($out, $in, 'certifications', [
            'name' => 'cert_name', 'issuer' => 'cert_issuer', 'date' => 'cert_date', 'credential_id' => 'cert_id',
        ]);
        self::legacyRows($out, $in, 'languages', ['name' => 'lang_name', 'level' => 'lang_level']);
        self::legacyRows($out, $in, 'custom_sections', ['title' => 'extra_title', 'content' => 'extra_content']);
        return $out;
    }

    private static function legacyRows(array &$out, array $in, string $section, array $fields, array $multiline = []): void
    {
        $count = 0;
        foreach ($fields as $key) $count = max($count, count(is_array($in[$key] ?? null) ? $in[$key] : []));
        for ($i = 0; $i < min($count, self::ARRAY_LIMIT); $i++) {
            $row = [];
            foreach ($fields as $field => $oldKey) {
                $value = is_array($in[$oldKey] ?? null) ? ($in[$oldKey][$i] ?? '') : '';
                $row[$field] = in_array($field, $multiline, true)
                    ? self::stringList(preg_split('/\r\n|\r|\n/', (string)$value))
                    : self::text($value);
            }
            if (count(array_filter($row, static fn($v) => is_array($v) ? count($v) > 0 : $v !== '')) > 0) $out[$section][] = $row;
        }
    }

    private static function stringList($value): array
    {
        $items = is_array($value) ? $value : [];
        $items = array_slice($items, 0, self::ARRAY_LIMIT);
        return array_values(array_filter(array_map([self::class, 'text'], $items), static fn($v) => $v !== ''));
    }

    private static function text($value): string
    {
        if (!is_scalar($value)) return '';
        return mb_substr(trim(str_replace("\0", '', (string)$value)), 0, self::TEXT_LIMIT);
    }
}
