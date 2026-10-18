<?php
namespace ATS\Import;

use ATS\Cv\CvDocument;

final class CvImporter
{
    public const MAX_BYTES = 10485760;

    public function extractUpload(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        $tmp = (string)($upload['tmp_name'] ?? '');
        $name = (string)($upload['name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($size < 1 || $size > self::MAX_BYTES || !is_uploaded_file($tmp)) throw new \RuntimeException('Choose a file smaller than 10 MB and upload it again.');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['docx', 'pdf'], true)) throw new \RuntimeException('Upload a DOCX or PDF file.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($extension === 'docx' && !in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'], true)) {
            throw new \RuntimeException('This file does not appear to be a DOCX document.');
        }
        if ($extension === 'pdf' && $mime !== 'application/pdf') throw new \RuntimeException('This file does not appear to be a PDF document.');
        if ($extension === 'pdf') return ['text' => $this->extractPdfText($tmp), 'format' => 'PDF'];
        return ['text' => $this->extractDocx($tmp), 'format' => 'DOCX'];
    }

    public function extractPdfText(string $path): string
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('PDF text extraction is unavailable on this server.');
        $binary = trim((string)($_ENV['PDFTOTEXT_BIN'] ?? ''));
        if ($binary === '') {
            foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) { $binary = $candidate; break; }
            }
        }
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) throw new \RuntimeException('PDF text extraction requires the pdftotext utility on the server.');
        $out = tempnam(sys_get_temp_dir(), 'ats-pdf-');
        $log = tempnam(sys_get_temp_dir(), 'ats-pdf-log-');
        if ($out === false || $log === false) { if ($out !== false) @unlink($out); if ($log !== false) @unlink($log); throw new \RuntimeException('Could not prepare temporary PDF output.'); }
        try {
            $process = proc_open([$binary, '-enc', 'UTF-8', '-layout', $path, $out], [0 => ['pipe', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new \RuntimeException('Could not start PDF text extraction.');
            fclose($pipes[0]);
            $started = microtime(true); $status = null;
            do {
                $processStatus = proc_get_status($process);
                if (!$processStatus['running']) { $status = $processStatus['exitcode']; break; }
                if (microtime(true) - $started > 20) { proc_terminate($process, 9); proc_close($process); throw new \RuntimeException('PDF text extraction took too long.'); }
                usleep(100000);
            } while (true);
            $closedStatus = proc_close($process);
            if (($status === null || $status < 0) && $closedStatus >= 0) $status = $closedStatus;
            $stderr = is_file($log) ? (string)file_get_contents($log) : '';
            $text = is_file($out) ? (string)file_get_contents($out) : '';
            if ($status !== 0 && trim($text) === '') throw new \RuntimeException('The PDF could not be read. It may be encrypted or damaged.');
            if (trim($text) === '') throw new \RuntimeException('No selectable text was found. Image-only PDFs are not supported in this version.');
            return mb_substr($text, 0, 200000);
        } finally {
            @unlink($out);
            @unlink($log);
        }
    }

    private function extractDocx(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('DOCX extraction requires the PHP zip extension.');
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \RuntimeException('The DOCX file is damaged or invalid.');
        try {
            $bodyStat = $zip->statName('word/document.xml');
            if (!is_array($bodyStat) || (int)($bodyStat['size'] ?? 0) > 20000000) throw new \RuntimeException('The DOCX document body is too large to process.');
            $xml = $zip->getFromName('word/document.xml');
            if (!is_string($xml) || strlen($xml) > 12000000) throw new \RuntimeException('The DOCX document body could not be read.');
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (!$loaded) throw new \RuntimeException('The DOCX document body contains invalid XML.');
            $xpath = new \DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = [];
            foreach ($xpath->query('//w:body//w:p') as $paragraph) {
                $parts = [];
                foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph) as $node) {
                    if ($node->localName === 't') $parts[] = $node->textContent;
                    elseif ($node->localName === 'tab') $parts[] = "\t";
                    else $parts[] = ' ';
                }
                $line = trim(implode('', $parts));
                if ($line !== '') $paragraphs[] = $line;
            }
            $text = implode("\n", $paragraphs);
            if ($text === '') throw new \RuntimeException('No text was found in the DOCX document.');
            return mb_substr($text, 0, 200000);
        } finally {
            $zip->close();
        }
    }

    public function mapToCv(string $text): array
    {
        $cv = CvDocument::empty();
        $lines = array_map('trim', preg_split('/\r\n|\r|\n/', $text) ?: []);
        $firstIndex = null;
        foreach ($lines as $index => $line) if ($line !== '') { $firstIndex = $index; break; }
        if ($firstIndex === null) return ['cv' => $cv, 'notes' => ['No text could be mapped.']];
        $cv['personal']['name'] = mb_substr($lines[$firstIndex], 0, 200);
        $headerIndexes = [$firstIndex => true];
        $titleSet = false;
        $headerLinesSeen = 0;
        foreach ($lines as $index => $line) {
            if ($index <= $firstIndex || $line === '') continue;
            if ($headerLinesSeen >= 7) break;
            $headerLinesSeen++;
            $isContact = false;
            if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $line, $match)) { $cv['personal']['email'] = $match[0]; $isContact = true; }
            $phoneLine = preg_replace('/\b(?:19|20)\d{2}\s*[-–—]\s*(?:19|20)\d{2}\b/', '', $line) ?? $line;
            if (preg_match('/(?:\+?\d[\d().\s-]{6,}\d)/', $phoneLine, $match)) { $cv['personal']['phone'] = trim($match[0]); $isContact = true; }
            if (preg_match('~(?:https?://)?(?:www\.)?linkedin\.com/\S+~i', $line, $match)) { $cv['personal']['linkedin'] = $match[0]; $isContact = true; }
            if (preg_match('~(?:https?://)?(?:www\.)?github\.com/\S+~i', $line, $match)) { $cv['personal']['github'] = $match[0]; $isContact = true; }
            $isHeading = self::sectionHeading($line) !== null;
            $isDate = self::dateRange($line) !== null;
            if (!$titleSet && !$isContact && !$isHeading && !$isDate && $headerLinesSeen <= 2 && mb_strlen($line) < 120) {
                $cv['personal']['title'] = mb_substr($line, 0, 200);
                $titleSet = true;
                $isContact = true;
            }
            if ($isContact) $headerIndexes[$index] = true;
        }

        $current = 'summary';
        $currentCustomTitle = '';
        $buckets = ['summary' => [], 'experience' => [], 'education' => [], 'skills' => [], 'projects' => [], 'certifications' => [], 'languages' => []];
        $customBuckets = [];
        foreach ($lines as $index => $line) {
            if (isset($headerIndexes[$index])) continue;
            $heading = self::sectionHeading($line);
            if ($heading !== null) {
                $current = $heading['section'];
                $currentCustomTitle = $heading['title'];
                if ($current === 'custom') $customBuckets[$currentCustomTitle] = $customBuckets[$currentCustomTitle] ?? [];
                if (($heading['inline'] ?? '') !== '') {
                    if ($current === 'custom') $customBuckets[$currentCustomTitle][] = $heading['inline'];
                    else $buckets[$current][] = $heading['inline'];
                }
                continue;
            }
            if ($current === 'custom') $customBuckets[$currentCustomTitle][] = $line;
            else $buckets[$current][] = $line;
        }
        $cv['summary'] = trim(implode(' ', array_filter($buckets['summary'], static fn($line) => $line !== '')));
        $cv['skills'] = self::mapSkills($buckets['skills']);
        $cv['experience'] = self::mapExperience($buckets['experience']);
        $cv['education'] = self::mapEducation($buckets['education']);
        $cv['projects'] = self::mapProjects($buckets['projects']);
        $cv['certifications'] = self::mapCertifications($buckets['certifications']);
        $cv['languages'] = self::mapLanguages($buckets['languages']);
        foreach ($customBuckets as $title => $contentLines) {
            $content = trim(implode("\n", array_filter($contentLines, static fn($line) => $line !== '')));
            if ($content !== '') $cv['custom_sections'][] = ['title' => $title, 'content' => $content];
        }

        $notes = ['Text was extracted locally and mapped into editable fields where possible. Review names, dates, entry boundaries, and every imported detail before saving or exporting.'];
        if (count($lines) > 1 && ($cv['personal']['title'] === '' || $cv['personal']['email'] === '' || $cv['personal']['phone'] === '')) $notes[] = 'Name, title, or contact details may need manual correction.';
        if ($cv['custom_sections']) $notes[] = 'Awards, publications, references, or other less common sections remain editable under Additional sections.';
        return ['cv' => CvDocument::normalize($cv), 'notes' => $notes, 'text' => $text];
    }

    private static function sectionHeading(string $line): ?array
    {
        static $headings = [
            'summary' => ['summary', 'Summary'], 'professional summary' => ['summary', 'Summary'],
            'profile' => ['summary', 'Summary'], 'professional profile' => ['summary', 'Summary'],
            'objective' => ['summary', 'Summary'], 'career objective' => ['summary', 'Summary'],
            'experience' => ['experience', 'Experience'], 'work experience' => ['experience', 'Experience'],
            'professional experience' => ['experience', 'Experience'], 'employment history' => ['experience', 'Experience'],
            'work history' => ['experience', 'Experience'], 'education' => ['education', 'Education'],
            'academic background' => ['education', 'Education'], 'academic qualifications' => ['education', 'Education'],
            'education and training' => ['education', 'Education'], 'skills' => ['skills', 'Skills'],
            'technical skills' => ['skills', 'Skills'], 'core competencies' => ['skills', 'Skills'],
            'key skills' => ['skills', 'Skills'], 'projects' => ['projects', 'Projects'],
            'selected projects' => ['projects', 'Projects'], 'personal projects' => ['projects', 'Projects'],
            'certifications' => ['certifications', 'Certifications'], 'certificates' => ['certifications', 'Certifications'],
            'licenses and certifications' => ['certifications', 'Certifications'], 'licenses & certifications' => ['certifications', 'Certifications'],
            'languages' => ['languages', 'Languages'], 'language skills' => ['languages', 'Languages'],
            'awards' => ['custom', 'Awards'], 'honors' => ['custom', 'Honors'], 'honours' => ['custom', 'Honors'],
            'publications' => ['custom', 'Publications'], 'volunteering' => ['custom', 'Volunteer Experience'],
            'volunteer experience' => ['custom', 'Volunteer Experience'], 'leadership' => ['custom', 'Leadership'],
            'professional memberships' => ['custom', 'Professional Memberships'], 'interests' => ['custom', 'Interests'],
            'additional information' => ['custom', 'Additional Information'], 'references' => ['custom', 'References'],
        ];
        $label = trim($line);
        $inline = '';
        if (strpos($label, ':') !== false) {
            [$candidate, $rest] = explode(':', $label, 2);
            if (isset($headings[mb_strtolower(trim($candidate))])) {
                $label = trim($candidate);
                $inline = trim($rest);
            }
        }
        $key = mb_strtolower(trim($label, " \t\n\r\0\x0B:"));
        if (!isset($headings[$key])) return null;
        return ['section' => $headings[$key][0], 'title' => $headings[$key][1], 'inline' => $inline];
    }

    private static function splitBlocks(array $lines): array
    {
        $blocks = [];
        $block = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                if ($block) { $blocks[] = $block; $block = []; }
                continue;
            }
            $block[] = $line;
        }
        if ($block) $blocks[] = $block;
        return $blocks;
    }

    private static function mapSkills(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $line = self::stripBullet(trim((string)$line));
            if ($line === '') continue;
            if (preg_match('/^([^:]{2,50}):\s*(.+)$/u', $line, $match)) {
                $rows[] = ['name' => trim($match[1]), 'details' => trim($match[2])];
                continue;
            }
            foreach (preg_split('/[,;|]+/u', $line) ?: [] as $skill) {
                $skill = trim($skill);
                if ($skill !== '') $rows[] = ['name' => $skill, 'details' => ''];
            }
        }
        return $rows;
    }

    private static function mapExperience(array $lines): array
    {
        $blocks = self::splitBlocks($lines);
        if (count($blocks) === 1) $blocks = self::splitExperienceBlock($blocks[0]);
        $rows = [];
        foreach ($blocks as $block) {
            $row = ['title' => '', 'company' => '', 'location' => '', 'start' => '', 'end' => '', 'bullets' => []];
            $bodyStarted = false;
            foreach ($block as $line) {
                $date = self::dateRange($line);
                if ($date !== null) {
                    if ($row['start'] === '') { $row['start'] = $date['start']; $row['end'] = $date['end']; }
                    $line = trim($date['rest']);
                    if ($line !== '' && $row['title'] !== '' && $row['company'] !== '' && !$bodyStarted && !$row['bullets']) {
                        $role = trim($line, " \t|");
                        if ($role !== '' && strpos($role, '|') === false) {
                            $row['location'] = $row['company'];
                            $row['company'] = $row['title'];
                            $row['title'] = $role;
                            continue;
                        }
                    }
                }
                if ($line === '') continue;
                $isBullet = preg_match('/^(?:[•●▪◦*\-]|\d+[.)])\s+/u', $line) === 1;
                $content = self::stripBullet($line);
                if ($content === '') continue;
                $parts = preg_split('/\s*\|\s*|\t+/u', $content) ?: [$content];
                if ($row['title'] === '') {
                    $row['title'] = trim((string)array_shift($parts));
                    if (isset($parts[0])) $row['company'] = trim((string)array_shift($parts));
                    if (isset($parts[0])) $row['location'] = trim(implode(' | ', $parts));
                    continue;
                }
                if (!$isBullet && $row['company'] === '' && !$bodyStarted) {
                    $row['company'] = trim((string)array_shift($parts));
                    if (isset($parts[0])) $row['location'] = trim(implode(' | ', $parts));
                    continue;
                }
                $row['bullets'][] = $content;
                $bodyStarted = true;
            }
            if ($row['title'] !== '' || $row['company'] !== '' || $row['bullets']) $rows[] = $row;
        }
        return $rows;
    }

    private static function splitExperienceBlock(array $lines): array
    {
        $blocks = [];
        $block = [];
        $hasDate = false;
        $hasBullets = false;
        foreach ($lines as $line) {
            $date = self::dateRange($line);
            $isBullet = preg_match('/^(?:[•●▪◦*\-]|\d+[.)])\s+/u', $line) === 1;
            if ($block && (($date !== null && $hasDate) || (!$isBullet && $hasDate && $hasBullets && self::looksLikeRoleHeader($line)))) {
                $blocks[] = $block;
                $block = [];
                $hasDate = false;
                $hasBullets = false;
            }
            $block[] = $line;
            if ($date !== null) $hasDate = true;
            if ($isBullet) $hasBullets = true;
        }
        if ($block) $blocks[] = $block;
        return $blocks;
    }

    private static function looksLikeRoleHeader(string $line): bool
    {
        return mb_strlen($line) <= 120 && preg_match('/[.!?]\s*$/u', $line) !== 1;
    }

    private static function mapEducation(array $lines): array
    {
        $blocks = self::splitBlocks($lines);
        $rows = [];
        foreach ($blocks as $block) {
            $row = ['degree' => '', 'institution' => '', 'location' => '', 'year' => '', 'details' => ''];
            $details = [];
            foreach ($block as $line) {
                $date = self::dateRange($line);
                if ($date !== null) {
                    if ($row['year'] === '') $row['year'] = $date['start'] . ' – ' . $date['end'];
                    $line = trim($date['rest']);
                } elseif (preg_match('/(?<!\d)(?:0?[1-9]|1[0-2])\/(?:19|20)\d{2}(?!\d)/', $line, $numericDate, PREG_OFFSET_CAPTURE)) {
                    if ($row['year'] === '') $row['year'] = $numericDate[0][0];
                    $offset = (int)$numericDate[0][1];
                    $line = trim(substr($line, 0, $offset) . ' ' . substr($line, $offset + strlen($numericDate[0][0])), " \t,;|–—-");
                } elseif (preg_match('/^(?:0?[1-9]|1[0-2])\/$/', $line) && preg_match('/^(?:19|20)\d{2}$/', $row['year'])) {
                    $row['year'] = $line . $row['year'];
                    $line = '';
                } elseif (preg_match('/\b(?:19|20)\d{2}\b/', $line, $yearMatch) && mb_strlen($line) < 50) {
                    if ($row['year'] === '') $row['year'] = $yearMatch[0];
                    $line = trim(str_replace($yearMatch[0], '', $line), " \t,;|–—-");
                }
                if ($line === '') continue;
                $parts = preg_split('/\s*\|\s*/u', $line) ?: [$line];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') continue;
                    $institutionPattern = '/\b(university|college|institute|school|academy|polytechnic|faculty)\b/i';
                    $degreePattern = '/\b(b\.?s\.?|b\.?a\.?|b\.?sc\.?|b\.?eng\.?|m\.?s\.?|m\.?a\.?|m\.?sc\.?|mba|ph\.?d\.?|doctorate|bachelor|master|diploma|associate|certificate)\b/i';
                    if (preg_match($institutionPattern, $part, $institutionMatch, PREG_OFFSET_CAPTURE) && preg_match($degreePattern, $part)) {
                        $offset = (int)$institutionMatch[0][1];
                        if ($offset > 0 && $row['degree'] === '') {
                            $row['degree'] = trim(substr($part, 0, $offset), " ,;|–—-");
                            $row['institution'] = trim(substr($part, $offset));
                            continue;
                        }
                    }
                    if (preg_match($degreePattern, $part) && $row['degree'] === '') $row['degree'] = $part;
                    elseif (preg_match($institutionPattern, $part) && $row['institution'] === '') $row['institution'] = $part;
                    elseif ($row['degree'] === '') $row['degree'] = $part;
                    elseif ($row['institution'] === '') $row['institution'] = $part;
                    else $details[] = $part;
                }
            }
            $row['details'] = implode("\n", $details);
            if ($row['degree'] !== '' || $row['institution'] !== '' || $row['year'] !== '' || $row['details'] !== '') $rows[] = $row;
        }
        return $rows;
    }

    private static function mapProjects(array $lines): array
    {
        $rows = [];
        foreach (self::splitBlocks($lines) as $block) {
            $row = ['name' => '', 'technologies' => '', 'description' => '', 'url' => ''];
            $description = [];
            foreach ($block as $index => $line) {
                if (preg_match('~https?://\S+~i', $line, $url)) {
                    $row['url'] = rtrim($url[0], '.,;)');
                    $line = trim(str_replace($url[0], '', $line));
                }
                if ($line === '') continue;
                $parts = preg_split('/\s*\|\s*/u', self::stripBullet($line)) ?: [$line];
                if ($index === 0) {
                    $row['name'] = trim((string)array_shift($parts));
                    if (isset($parts[0])) $row['technologies'] = trim(implode(' | ', $parts));
                } elseif (preg_match('/^(?:tools?|technologies)\s*:\s*(.+)$/i', $line, $match) && $row['technologies'] === '') {
                    $row['technologies'] = trim($match[1]);
                } else {
                    $description[] = self::stripBullet($line);
                }
            }
            $row['description'] = implode("\n", $description);
            if ($row['name'] !== '' || $row['description'] !== '' || $row['url'] !== '') $rows[] = $row;
        }
        return $rows;
    }

    private static function mapCertifications(array $lines): array
    {
        $rows = [];
        foreach (self::splitBlocks($lines) as $block) {
            $row = ['name' => '', 'issuer' => '', 'date' => '', 'credential_id' => ''];
            foreach ($block as $index => $line) {
                $date = self::dateRange($line);
                if ($date !== null) {
                    if ($row['date'] === '') $row['date'] = $date['start'] . ' – ' . $date['end'];
                    $line = trim($date['rest']);
                } elseif (preg_match('/\b(?:19|20)\d{2}\b/', $line, $yearMatch) && $row['date'] === '') {
                    $row['date'] = $yearMatch[0];
                    $line = trim(str_replace($yearMatch[0], '', $line), " \t,;|–—-");
                }
                if ($line === '') continue;
                if (preg_match('/^(?:credential|certificate|license)\s*(?:id|#)?\s*:\s*(.+)$/i', $line, $match)) {
                    $row['credential_id'] = trim($match[1]);
                } elseif ($index === 0 || $row['name'] === '') {
                    $row['name'] = self::stripBullet($line);
                } elseif ($row['issuer'] === '') {
                    $row['issuer'] = self::stripBullet($line);
                } else {
                    $row['credential_id'] = trim($row['credential_id'] . ' ' . self::stripBullet($line));
                }
            }
            if ($row['name'] !== '' || $row['issuer'] !== '' || $row['date'] !== '') $rows[] = $row;
        }
        return $rows;
    }

    private static function mapLanguages(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $line = self::stripBullet(trim((string)$line));
            if ($line === '') continue;
            $parts = preg_split('/\s*(?:\||:|–|—|->)\s*/u', $line, 2);
            $rows[] = ['name' => trim((string)($parts[0] ?? $line)), 'level' => trim((string)($parts[1] ?? ''))];
        }
        return $rows;
    }

    private static function dateRange(string $line): ?array
    {
        $month = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)';
        $numericDate = '(?:(?:0?[1-9]|[12]\d|3[01])\/(?:0?[1-9]|1[0-2])\/(?:19|20)\d{2}|(?:0?[1-9]|1[0-2])\/(?:19|20)\d{2})';
        $yearDate = '(?:(?:' . $month . ')\.?\s+)?(?:19|20)\d{2}';
        $date = '(?:' . $numericDate . '|' . $yearDate . '|present|current|now)';
        if (!preg_match('/(' . $date . ')\s*(?:–|—|-|\bto\b)\s*(' . $date . ')/i', $line, $match, PREG_OFFSET_CAPTURE)) return null;
        $whole = $match[0][0];
        $offset = (int)$match[0][1];
        return [
            'start' => trim($match[1][0]),
            'end' => trim($match[2][0]),
            'rest' => trim(substr($line, 0, $offset) . ' ' . substr($line, $offset + strlen($whole))),
        ];
    }

    private static function stripBullet(string $line): string
    {
        return trim(preg_replace('/^(?:[•●▪◦*\-]|\d+[.)])\s+/u', '', $line) ?? $line);
    }

    private function uploadError(int $code): string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) return 'The file exceeds the server upload limit.';
        if ($code === UPLOAD_ERR_NO_FILE) return 'Choose a DOCX or PDF file first.';
        return 'The upload did not complete. Try again.';
    }
}
