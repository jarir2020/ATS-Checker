<?php
namespace ATS\Import;

use ATS\Cv\CvDocument;

final class CvImporter
{
    public const MAX_BYTES = 10485760;

    public function extractUpload(array $upload, bool $includePhoto = false): array
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
        if ($extension === 'pdf') {
            return ['text' => $this->extractPdfText($tmp), 'format' => 'PDF', 'photo' => $includePhoto ? $this->extractPdfPhoto($tmp) : ''];
        }
        return ['text' => $this->extractDocx($tmp), 'format' => 'DOCX', 'photo' => $includePhoto ? $this->extractDocxPhoto($tmp) : ''];
    }

    public function extractPdfText(string $path): string
    {
        $binary = trim((string)($_ENV['PDFTOTEXT_BIN'] ?? ''));
        if ($binary === '') {
            foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/homebrew/bin/pdftotext'] as $candidate) {
                if (is_file($candidate) && is_executable($candidate)) { $binary = $candidate; break; }
            }
        }

        if (function_exists('proc_open') && $binary !== '' && is_file($binary) && is_executable($binary)) {
            try {
                return $this->extractPdfTextWithBinary($path, $binary);
            } catch (\Throwable $exception) {
                // Fall back to the bundled PHP parser if Poppler cannot process this file.
            }
        }

        return $this->extractPdfTextWithPhpParser($path);
    }

    private function extractPdfTextWithBinary(string $path, string $binary): string
    {
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

    private function extractPdfTextWithPhpParser(string $path): string
    {
        if (!class_exists(\Smalot\PdfParser\Parser::class)) {
            throw new \RuntimeException('PDF text extraction is unavailable. Install pdftotext or deploy the Composer dependencies.');
        }

        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(67108864);
            $config->setMaxNestingDepth(1000);
            $parser = new \Smalot\PdfParser\Parser([], $config);
            $pdf = $parser->parseFile($path);
            $text = trim($pdf->getText());
        } catch (\Throwable $exception) {
            throw new \RuntimeException('The PDF could not be read. It may be encrypted, damaged, or unsupported.');
        }

        if ($text === '') throw new \RuntimeException('No selectable text was found. Image-only PDFs are not supported in this version.');
        return mb_substr($text, 0, 200000);
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

    public function mapToCv(string $text, string $photo = ''): array
    {
        $cv = CvDocument::empty();
        $cv['personal']['photo'] = $photo;
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
        $notes[] = $cv['personal']['photo'] !== ''
            ? 'A likely profile photo was detected and added to the contact block. Review it in the generator; you can replace or remove it.'
            : 'No likely profile photo was detected, so the photo field was left empty.';
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

    private function extractPdfPhoto(string $path): string
    {
        if (!function_exists('proc_open') || !function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) return '';
        $binary = $this->findPdfImagesBinary();
        if ($binary === '') return '';
        $directory = '';
        try {
            $listed = $this->runBinary([$binary, '-list', $path], 12);
            if ($listed['exit'] !== 0) return '';
            $pages = [];
            foreach (preg_split('/\r\n|\r|\n/', $listed['stdout']) ?: [] as $line) {
                if (!preg_match('/^\s*(\d+)\s+\d+\s+image\s+(\d+)\s+(\d+)\b/i', $line, $match)) continue;
                $score = self::photoCandidateScore((int)$match[2], (int)$match[3]);
                if ($score === null) continue;
                $page = (int)$match[1];
                $pages[$page] = max($pages[$page] ?? 0, $score);
            }
            if (!$pages) return '';
            arsort($pages, SORT_NUMERIC);
            $page = (int)array_key_first($pages);
            $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ats-photo-' . bin2hex(random_bytes(8));
            if (!mkdir($directory, 0700)) return '';
            $extracted = $this->runBinary([$binary, '-all', '-f', (string)$page, '-l', (string)$page, $path, $directory . DIRECTORY_SEPARATOR . 'image'], 20);
            if ($extracted['exit'] !== 0) return '';
            $files = glob($directory . DIRECTORY_SEPARATOR . 'image-*') ?: [];
            $candidates = [];
            $totalBytes = 0;
            foreach (array_slice($files, 0, 80) as $file) {
                $size = @filesize($file);
                if (!is_int($size) || $size < 1 || $size > 6000000) continue;
                $totalBytes += $size;
                if ($totalBytes > 20000000) break;
                $imageInfo = @getimagesize($file);
                if (!is_array($imageInfo)) continue;
                $score = self::photoCandidateScore((int)$imageInfo[0], (int)$imageInfo[1]);
                if ($score !== null) $candidates[] = ['path' => $file, 'score' => $score];
            }
            usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
            foreach (array_slice($candidates, 0, 5) as $candidate) {
                $bytes = @file_get_contents($candidate['path']);
                if (is_string($bytes)) {
                    $photo = $this->makeProfilePhoto($bytes);
                    if ($photo !== '') return $photo;
                }
            }
        } catch (\Throwable $error) {
            return '';
        } finally {
            if ($directory !== '' && is_dir($directory)) {
                foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
                @rmdir($directory);
            }
        }
        return '';
    }

    private function extractDocxPhoto(string $path): string
    {
        if (!class_exists(\ZipArchive::class) || !function_exists('imagecreatefromstring') || !function_exists('getimagesizefromstring')) return '';
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return '';
        try {
            $candidates = [];
            $totalBytes = 0;
            $count = min((int)$zip->numFiles, 2000);
            for ($index = 0; $index < $count && count($candidates) < 60; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || strpos($name, 'word/media/') !== 0 || strpos($name, '..') !== false) continue;
                $stat = $zip->statIndex($index);
                $size = (int)($stat['size'] ?? 0);
                if ($size < 1 || $size > 6000000) continue;
                $totalBytes += $size;
                if ($totalBytes > 20000000) break;
                $bytes = $zip->getFromIndex($index);
                if (!is_string($bytes)) continue;
                $imageInfo = @getimagesizefromstring($bytes);
                if (!is_array($imageInfo)) continue;
                $score = self::photoCandidateScore((int)$imageInfo[0], (int)$imageInfo[1]);
                if ($score !== null) $candidates[] = ['bytes' => $bytes, 'score' => $score];
            }
            usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);
            foreach (array_slice($candidates, 0, 8) as $candidate) {
                $photo = $this->makeProfilePhoto($candidate['bytes']);
                if ($photo !== '') return $photo;
            }
        } catch (\Throwable $error) {
            return '';
        } finally {
            $zip->close();
        }
        return '';
    }

    private static function photoCandidateScore(int $width, int $height): ?float
    {
        if ($width < 120 || $height < 120 || $width * $height < 16000 || $width * $height > 20000000 || $width > 10000 || $height > 10000) return null;
        $ratio = $width / $height;
        if ($ratio < 0.48 || $ratio > 1.5) return null;
        return log(min($width * $height, 160000)) - abs(log($ratio / 0.75)) * 2.2;
    }

    private function makeProfilePhoto(string $bytes): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || !function_exists('getimagesizefromstring')) return '';
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) return '';
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 32 || $height < 32 || $width > 6000 || $height > 6000 || $width * $height > 20000000) return '';
        $source = @imagecreatefromstring($bytes);
        if ($source === false) return '';
        $targetRatio = 0.75;
        $cropX = 0; $cropY = 0; $cropWidth = $width; $cropHeight = $height;
        if ($width / $height > $targetRatio) {
            $cropWidth = max(1, (int)floor($height * $targetRatio));
            $cropX = (int)floor(($width - $cropWidth) / 2);
        } else {
            $cropHeight = max(1, (int)floor($width / $targetRatio));
            $cropY = (int)floor(($height - $cropHeight) / 2);
        }
        $scale = min(384 / $cropWidth, 512 / $cropHeight, 1);
        $targetWidth = max(1, (int)round($cropWidth * $scale));
        $targetHeight = max(1, (int)round($cropHeight * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) { imagedestroy($source); return ''; }
        $white = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $white);
        imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        imagedestroy($source);
        $photo = '';
        foreach ([82, 72, 62] as $quality) {
            ob_start();
            $written = imagejpeg($target, null, $quality);
            $jpeg = ob_get_clean();
            if ($written && is_string($jpeg) && strlen($jpeg) <= 400000) { $photo = 'data:image/jpeg;base64,' . base64_encode($jpeg); break; }
        }
        imagedestroy($target);
        return $photo;
    }

    private function findPdfImagesBinary(): string
    {
        $configured = trim((string)($_ENV['PDFIMAGES_BIN'] ?? ''));
        if ($configured !== '' && is_file($configured) && is_executable($configured)) return $configured;
        foreach (['/usr/bin/pdfimages', '/usr/local/bin/pdfimages', '/opt/homebrew/bin/pdfimages'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
        return '';
    }

    private function runBinary(array $command, int $timeout): array
    {
        $stdout = tempnam(sys_get_temp_dir(), 'ats-command-out-');
        $stderr = tempnam(sys_get_temp_dir(), 'ats-command-err-');
        if ($stdout === false || $stderr === false) {
            if ($stdout !== false) @unlink($stdout);
            if ($stderr !== false) @unlink($stderr);
            throw new \RuntimeException('Could not prepare temporary image extraction output.');
        }
        try {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new \RuntimeException('Could not start local image extraction.');
            fclose($pipes[0]);
            $started = microtime(true); $status = null;
            do {
                $processStatus = proc_get_status($process);
                if (!$processStatus['running']) { $status = $processStatus['exitcode']; break; }
                if (microtime(true) - $started > $timeout) { proc_terminate($process, 9); proc_close($process); throw new \RuntimeException('Image extraction took too long.'); }
                usleep(100000);
            } while (true);
            $closedStatus = proc_close($process);
            if (($status === null || $status < 0) && $closedStatus >= 0) $status = $closedStatus;
            $output = is_file($stdout) ? (string)file_get_contents($stdout) : '';
            return ['exit' => (int)$status, 'stdout' => mb_substr($output, 0, 2000000)];
        } finally {
            @unlink($stdout);
            @unlink($stderr);
        }
    }

    private function uploadError(int $code): string
    {
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) return 'The file exceeds the server upload limit.';
        if ($code === UPLOAD_ERR_NO_FILE) return 'Choose a DOCX or PDF file first.';
        return 'The upload did not complete. Try again.';
    }
}
