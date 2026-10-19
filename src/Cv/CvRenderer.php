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
        $html .= 'body{font-family:Arial,Helvetica,sans-serif;color:#171717;font-size:11pt;line-height:1.45;margin:0 auto;padding:28px;max-width:780px}h1{font-size:22pt;margin:0 0 2px}h2{font-size:12pt;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #444;padding-bottom:3px;margin:18px 0 8px}h3{font-size:11pt;margin:0 0 2px}p{margin:3px 0 7px}.meta,.subtle{color:#333}.entry{margin:0 0 9px}.date,.date-token{font-weight:normal;white-space:nowrap}.cv-list{padding-left:18px;margin:4px 0}.contacts{margin:4px 0 10px;overflow-wrap:anywhere}.contact-divider{display:inline-block;margin:0 4px;color:#666}.social-links{display:inline-block;white-space:nowrap;vertical-align:middle}.social-link{display:inline-block;width:18px;height:18px;text-align:center;text-decoration:none;vertical-align:middle;margin:0 1px}.social-mark-svg{display:inline-block;vertical-align:middle}.social-mark-svg svg{display:block;width:17px;height:17px}.small{font-size:10pt}@media print{body{max-width:none;padding:0}}';
        $html .= '</style></head><body>';
        $html .= '<h1>' . self::e($p['name']) . '</h1>';
        if ($p['title'] !== '') $html .= '<p class="subtle">' . self::e($p['title']) . '</p>';
        $contactText = array_values(array_filter([$p['email'], $p['phone'], $p['location']], static fn($value) => trim($value) !== ''));
        $contactItems = array_map([self::class, 'e'], $contactText);
        $socialLinks = array_filter([
            self::socialLink('linkedin', $p['linkedin']),
            self::socialLink('github', $p['github']),
        ]);
        $contactHtml = implode(' <span class="contact-divider" aria-hidden="true">|</span> ', $contactItems);
        if ($socialLinks) {
            if ($contactHtml !== '') $contactHtml .= ' <span class="contact-divider" aria-hidden="true">|</span> ';
            $contactHtml .= '<span class="social-links">' . implode(' &nbsp; ', $socialLinks) . '</span>';
        }
        if ($contactHtml !== '') $html .= '<p class="contacts small">' . $contactHtml . '</p>';
        if ($cv['enabled_sections']['summary'] && $cv['summary'] !== '') $html .= self::section('Summary', '<p>' . nl2br(self::e($cv['summary'])) . '</p>');
        if ($cv['enabled_sections']['skills'] && $cv['skills']) {
            $body = '';
            foreach ($cv['skills'] as $row) {
                $name = self::e($row['name']);
                $details = self::e($row['details']);
                if ($name !== '' && $details !== '') $body .= '<p><strong>' . $name . ':</strong> ' . $details . '</p>';
                elseif ($name !== '') $body .= '<p>' . $name . '</p>';
                elseif ($details !== '') $body .= '<p>' . $details . '</p>';
            }
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
                if ($meta) {
                    $metaParts = [];
                    if ($row['location'] !== '') $metaParts[] = self::e($row['location']);
                    if ($row['year'] !== '') $metaParts[] = '<span class="date">' . self::e($row['year']) . '</span>';
                    $body .= '<p class="subtle">' . implode(' | ', $metaParts) . '</p>';
                }
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

    private static function socialLink(string $platform, string $value): string
    {
        $url = self::safeWebUrl($value);
        if ($url === null) return '';

        $label = $platform === 'linkedin' ? 'LinkedIn' : 'GitHub or portfolio';
        $class = $platform;
        if ($platform === 'github') {
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($host !== 'github.com' && substr($host, -11) !== '.github.com') {
                $class = 'website';
                $label = 'Portfolio website';
            }
        }

        if ($class === 'linkedin') {
            $svg = '<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#0a66c2" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.35V9h3.414v1.561h.049c.476-.9 1.637-1.85 3.37-1.85 3.6 0 4.264 2.37 4.264 5.455v6.286ZM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124Zm1.782 13.019H3.555V9h3.564v11.452ZM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003Z"/></svg>';
        } elseif ($class === 'github') {
            $svg = '<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#181717" d="M12 .297a12 12 0 0 0-3.79 23.39c.6.111.82-.259.82-.577v-2.234c-3.338.726-4.043-1.416-4.043-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.09-.745.083-.729.083-.729 1.205.085 1.84 1.237 1.84 1.237 1.07 1.835 2.807 1.305 3.492.997.108-.775.418-1.305.762-1.604-2.665-.305-5.466-1.332-5.466-5.93 0-1.31.469-2.381 1.236-3.221-.124-.303-.536-1.524.117-3.176 0 0 1.008-.322 3.301 1.23a11.5 11.5 0 0 1 6.004 0c2.293-1.552 3.297-1.23 3.297-1.23.655 1.652.243 2.873.12 3.176.77.84 1.235 1.911 1.235 3.221 0 4.61-2.805 5.621-5.477 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.216.694.825.576A12.005 12.005 0 0 0 12 .297Z"/></svg>';
        } else {
            $svg = '<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="none" stroke="#444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M14 3h7v7M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>';
        }

        $icon = '<span class="social-mark-svg" style="display:inline-block" aria-hidden="true">' . $svg . '</span>';
        return '<a class="social-link ' . self::e($class) . '" href="' . self::e($url) . '" target="_blank" rel="noopener noreferrer" aria-label="' . self::e($label) . '" title="' . self::e($label) . '">' . $icon . '</a>';
    }

    private static function safeWebUrl(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x20\x7f]/', $value)) return null;
        if (substr($value, 0, 2) === '//') $value = 'https:' . $value;
        elseif (!preg_match('/^https?:\/\//i', $value)) $value = 'https://' . $value;

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        if (isset($parts['user']) || isset($parts['pass']) || filter_var($value, FILTER_VALIDATE_URL) === false) return null;
        return $value;
    }

    private static function bullets(array $items): string
    {
        $out = '<ul class="cv-list">';
        foreach ($items as $item) $out .= '<li>' . self::inlineText($item) . '</li>';
        return $out . '</ul>';
    }

    private static function inlineText(string $value): string
    {
        $escaped = self::e($value);
        return preg_replace_callback(
            '/(?<![0-9\/])(?:(?:0?[1-9]|[12][0-9]|3[01])\/(?:0?[1-9]|1[0-2])\/(?:19|20)[0-9]{2}|(?:0?[1-9]|1[0-2])\/(?:19|20)[0-9]{2})(?![0-9\/])/',
            static fn($match) => '<span class="date-token">' . $match[0] . '</span>',
            $escaped
        ) ?? $escaped;
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
