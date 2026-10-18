<?php
namespace ATS\Cv;

final class CvRenderer
{
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function html(array $cv): string
    {
        $cv = CvDocument::normalize($cv);
        $cv['experience'] = self::recentFirst($cv['experience'], 'end', 'start');
        $cv['education'] = self::recentFirst($cv['education'], 'year', 'year');
        $p = $cv['personal'];
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($p['name'] ?: 'CV') . '</title><style>';
        $html .= 'body{font-family:Arial,Helvetica,sans-serif;color:#171717;font-size:11pt;line-height:1.45;margin:0 auto;padding:28px;max-width:780px}h1{font-size:22pt;margin:0 0 2px}h2{font-size:12pt;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #444;padding-bottom:3px;margin:18px 0 8px}h3{font-size:11pt;margin:0 0 2px}p{margin:3px 0 7px}.meta,.subtle{color:#333}.entry{margin:0 0 9px}.date{font-weight:normal}.cv-list{padding-left:18px;margin:4px 0}.contacts{margin:4px 0 10px;overflow-wrap:anywhere}.small{font-size:10pt}@media print{body{max-width:none;padding:0}}';
        $html .= '</style></head><body>';
        $html .= '<h1>' . self::e($p['name']) . '</h1>';
        if ($p['title'] !== '') $html .= '<p class="subtle">' . self::e($p['title']) . '</p>';
        $contacts = array_filter([$p['email'], $p['phone'], $p['location'], $p['linkedin'], $p['github']]);
        if ($contacts) $html .= '<p class="contacts small">' . implode(' | ', array_map([self::class, 'e'], $contacts)) . '</p>';
        if ($cv['enabled_sections']['summary'] && $cv['summary'] !== '') $html .= self::section('Summary', '<p>' . nl2br(self::e($cv['summary'])) . '</p>');
        if ($cv['enabled_sections']['skills'] && $cv['skills']) {
            $body = '';
            foreach ($cv['skills'] as $row) $body .= '<p><strong>' . self::e($row['name']) . ($row['name'] && $row['details'] ? ': ' : '') . '</strong>' . self::e($row['details']) . '</p>';
            $html .= self::section('Skills', $body);
        }
        if ($cv['enabled_sections']['experience'] && $cv['experience']) {
            $body = '';
            foreach ($cv['experience'] as $row) {
                $title = implode(' | ', array_filter([$row['title'], $row['company'], $row['location']]));
                $dates = implode(' – ', array_filter([$row['start'], $row['end']]));
                $body .= '<div class="entry"><h3>' . self::e($title) . '</h3>' . ($dates ? '<p class="date">' . self::e($dates) . '</p>' : '');
                if ($row['bullets']) $body .= self::bullets($row['bullets']);
                $body .= '</div>';
            }
            $html .= self::section('Experience', $body);
        }
        if ($cv['enabled_sections']['education'] && $cv['education']) {
            $body = '';
            foreach ($cv['education'] as $row) {
                $body .= '<div class="entry"><h3>' . self::e(implode(' | ', array_filter([$row['degree'], $row['institution']]))) . '</h3>';
                $meta = implode(' | ', array_filter([$row['location'], $row['year']]));
                if ($meta) $body .= '<p class="subtle">' . self::e($meta) . '</p>';
                if ($row['details']) $body .= '<p>' . nl2br(self::e($row['details'])) . '</p>';
                $body .= '</div>';
            }
            $html .= self::section('Education', $body);
        }
        if ($cv['enabled_sections']['projects']) $html .= self::namedEntries($cv['projects'], 'Projects', ['name', 'technologies', 'description', 'url']);
        if ($cv['enabled_sections']['certifications']) $html .= self::namedEntries($cv['certifications'], 'Certifications', ['name', 'issuer', 'date', 'credential_id']);
        if ($cv['enabled_sections']['languages'] && $cv['languages']) {
            $body = '<p>' . implode('; ', array_map(static fn($r) => self::e($r['name'] . ($r['level'] ? ' — ' . $r['level'] : '')), $cv['languages'])) . '</p>';
            $html .= self::section('Languages', $body);
        }
        foreach ($cv['enabled_sections']['custom_sections'] ? $cv['custom_sections'] : [] as $row) {
            if ($row['title'] !== '') $html .= self::section($row['title'], '<p>' . nl2br(self::e($row['content'])) . '</p>');
        }
        return $html . '</body></html>';
    }

    public static function text(array $cv): string
    {
        $cv = CvDocument::normalize($cv);
        $cv['experience'] = self::recentFirst($cv['experience'], 'end', 'start');
        $cv['education'] = self::recentFirst($cv['education'], 'year', 'year');
        $p = $cv['personal'];
        $lines = array_filter([$p['name'], $p['title'], implode(' | ', array_filter([$p['email'], $p['phone'], $p['location'], $p['linkedin'], $p['github']])), '']);
        if ($cv['enabled_sections']['summary'] && $cv['summary']) { $lines[] = 'SUMMARY'; $lines[] = $cv['summary']; $lines[] = ''; }
        if ($cv['enabled_sections']['skills']) self::textRows($lines, $cv['skills'], 'SKILLS', static fn($r) => trim($r['name'] . ($r['name'] && $r['details'] ? ': ' : '') . $r['details']));
        if ($cv['enabled_sections']['experience']) self::textRows($lines, $cv['experience'], 'EXPERIENCE', static function ($r) {
            $v = implode(' | ', array_filter([$r['title'], $r['company'], $r['location']]));
            $dates = implode(' – ', array_filter([$r['start'], $r['end']]));
            return $v . ($dates ? "\n" . $dates : '') . ($r['bullets'] ? "\n" . implode("\n", array_map(static fn($b) => '- ' . $b, $r['bullets'])) : '');
        });
        if ($cv['enabled_sections']['education']) self::textRows($lines, $cv['education'], 'EDUCATION', static fn($r) => trim(implode(' | ', array_filter([$r['degree'], $r['institution'], $r['location'], $r['year'], $r['details']]))));
        if ($cv['enabled_sections']['projects']) self::textRows($lines, $cv['projects'], 'PROJECTS', static fn($r) => trim(implode(' | ', array_filter([$r['name'], $r['technologies'], $r['description'], $r['url']]))));
        if ($cv['enabled_sections']['certifications']) self::textRows($lines, $cv['certifications'], 'CERTIFICATIONS', static fn($r) => trim(implode(' | ', array_filter([$r['name'], $r['issuer'], $r['date'], $r['credential_id']]))));
        if ($cv['enabled_sections']['languages']) self::textRows($lines, $cv['languages'], 'LANGUAGES', static fn($r) => trim($r['name'] . ($r['level'] ? ' — ' . $r['level'] : '')));
        foreach ($cv['enabled_sections']['custom_sections'] ? $cv['custom_sections'] : [] as $r) if ($r['title']) { $lines[] = strtoupper($r['title']); $lines[] = $r['content']; $lines[] = ''; }
        return trim(implode("\n", $lines)) . "\n";
    }

    private static function textRows(array &$lines, array $rows, string $heading, callable $format): void
    {
        if (!$rows) return;
        $lines[] = $heading;
        foreach ($rows as $row) { $value = trim($format($row)); if ($value !== '') { $lines[] = $value; $lines[] = ''; } }
    }

    private static function section(string $title, string $body): string
    {
        return '<section><h2>' . self::e($title) . '</h2>' . $body . '</section>';
    }

    private static function bullets(array $items): string
    {
        $out = '<ul class="cv-list">';
        foreach ($items as $item) $out .= '<li>' . self::e($item) . '</li>';
        return $out . '</ul>';
    }

    private static function namedEntries(array $rows, string $title, array $fields): string
    {
        if (!$rows) return '';
        $body = '';
        foreach ($rows as $row) {
            $parts = [];
            foreach (array_slice($fields, 0, 2) as $field) if (($row[$field] ?? '') !== '') $parts[] = '<strong>' . self::e($row[$field]) . '</strong>';
            foreach (array_slice($fields, 2) as $field) if (($row[$field] ?? '') !== '') $parts[] = self::e($row[$field]);
            $body .= '<p class="entry">' . implode(' — ', $parts) . '</p>';
        }
        return self::section($title, $body);
    }

    private static function recentFirst(array $rows, string $primary, string $fallback): array
    {
        foreach ($rows as $index => $row) $rows[$index]['_sort_index'] = $index;
        usort($rows, static function ($a, $b) use ($primary, $fallback): int {
            $value = static function ($row) use ($primary, $fallback): int {
                $date = trim((string)($row[$primary] ?? ''));
                if (preg_match('/^(present|current|now)$/i', $date)) return PHP_INT_MAX;
                if ($date === '') $date = trim((string)($row[$fallback] ?? ''));
                if (preg_match('/^(present|current|now)$/i', $date)) return PHP_INT_MAX;
                $timestamp = strtotime($date);
                if ($timestamp === false && preg_match_all('/(?:19|20)\d{2}/', $date, $years) > 0 && !empty($years[0])) {
                    $yearList = $years[0];
                    $timestamp = (int)$yearList[count($yearList) - 1] * 31536000;
                }
                return $timestamp === false ? 0 : $timestamp;
            };
            $byDate = $value($b) <=> $value($a);
            return $byDate ?: (($a['_sort_index'] ?? 0) <=> ($b['_sort_index'] ?? 0));
        });
        foreach ($rows as &$row) unset($row['_sort_index']);
        return $rows;
    }
}
