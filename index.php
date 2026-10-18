<?php
use ATS\Ats\AtsChecker;
use ATS\Ai\AiAssistant;
use ATS\Cv\CvDocument;
use ATS\Cv\CvRenderer;
use ATS\Cv\DraftRepository;
use ATS\Export\CvExporter;
use ATS\Import\CvImporter;

require_once __DIR__ . '/bootstrap.php';
require_once BASE_PATH . '/includes/security.php';
require_once BASE_PATH . '/includes/db.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function postedCv(): array
{
    $json = requestString('cv_json', 2000000);
    $data = json_decode($json, true);
    if (!is_array($data)) throw new InvalidArgumentException('The CV data was not readable. Refresh and try again.');
    return CvDocument::normalize($data);
}

function redactForAi(string $text): string
{
    $dates = [];
    $text = preg_replace_callback('/\b(?:19|20)\d{2}\s*[-–]\s*(?:19|20)\d{2}\b/', static function ($match) use (&$dates) { $key = '__ATS_DATE_' . count($dates) . '__'; $dates[$key] = $match[0]; return $key; }, $text) ?? $text;
    $text = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $text) ?? $text;
    $text = preg_replace('/(?:\+?\d[\d().\s-]{6,}\d)/', '[phone removed]', $text) ?? $text;
    $text = preg_replace('~(?:https?://)?(?:www\.)?(?:linkedin|github)\.com/\S+~i', '[profile link removed]', $text) ?? $text;
    $text = strtr($text, $dates);
    return mb_substr($text, 0, 8000);
}

function consumeAiRequest(): void
{
    $now = time();
    $requests = array_values(array_filter($_SESSION['ai_request_times'] ?? [], static fn($time) => is_int($time) && $time > $now - 3600));
    if (count($requests) >= 30 || count(array_filter($requests, static fn($time) => $time > $now - 60)) >= 5) {
        jsonResponse(['ok' => false, 'error' => 'AI usage is temporarily limited. Please wait before trying again.'], 429);
    }
    $requests[] = $now;
    $_SESSION['ai_request_times'] = $requests;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    try {
        $repo = new DraftRepository();
        if ($_GET['action'] === 'drafts_list') jsonResponse(['ok' => true, 'drafts' => $repo->list()]);
        if ($_GET['action'] === 'draft_get') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $cv = $id ? $repo->get((int)$id) : null;
            if ($cv === null) jsonResponse(['ok' => false, 'error' => 'Draft not found.'], 404);
            jsonResponse(['ok' => true, 'cv' => $cv]);
        }
        jsonResponse(['ok' => false, 'error' => 'Unknown request.'], 400);
    } catch (Throwable $error) {
        jsonResponse(['ok' => false, 'error' => 'Draft storage is unavailable. Check the database configuration.'], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'preview') {
            $cv = postedCv();
            jsonResponse(['ok' => true, 'html' => CvRenderer::html($cv)]);
        }
        if ($action === 'ats_pdf_preview') {
            $cv = postedCv();
            if (trim(CvRenderer::text($cv)) === '') {
                jsonResponse(['ok' => true, 'text' => '', 'warnings' => [], 'message' => 'Add CV content to check its PDF text.']);
            }
            $pdf = (new CvExporter())->export($cv, 'pdf')['body'];
            if (strlen($pdf) > 20000000) throw new RuntimeException('This PDF is too large to inspect. Reduce the CV content and try again.');
            $tempPdf = tempnam(sys_get_temp_dir(), 'ats-preview-');
            if ($tempPdf === false) throw new RuntimeException('Could not prepare a temporary PDF for inspection.');
            try {
                if (file_put_contents($tempPdf, $pdf) !== strlen($pdf)) throw new RuntimeException('Could not prepare the generated PDF for inspection.');
                $text = (new CvImporter())->extractPdfText($tempPdf);
            } finally {
                @unlink($tempPdf);
            }

            $review = (new AtsChecker())->inspect($text);
            $warnings = array_values(array_filter($review['findings'], static fn($finding) => $finding['severity'] === 'warning'));
            $expectedHeadings = [];
            if ($cv['enabled_sections']['summary'] && trim($cv['summary']) !== '') $expectedHeadings[] = 'SUMMARY';
            foreach ([
                'skills' => 'SKILLS', 'experience' => 'EXPERIENCE', 'education' => 'EDUCATION',
                'projects' => 'PROJECTS', 'certifications' => 'CERTIFICATIONS', 'languages' => 'LANGUAGES',
            ] as $section => $heading) {
                if ($cv['enabled_sections'][$section] && !empty($cv[$section])) $expectedHeadings[] = $heading;
            }
            if ($cv['enabled_sections']['custom_sections']) {
                foreach ($cv['custom_sections'] as $section) {
                    $heading = strtoupper(trim($section['title']));
                    if ($heading !== '' && !in_array($heading, $expectedHeadings, true)) $expectedHeadings[] = $heading;
                }
            }
            $lastHeadingOffset = -1;
            foreach ($expectedHeadings as $heading) {
                $pattern = '/^[\h\f]*' . preg_quote($heading, '/') . '[\h]*$/mi';
                if (!preg_match($pattern, $text, $headingMatch, PREG_OFFSET_CAPTURE)) {
                    $warnings[] = ['severity' => 'warning', 'title' => 'Section heading missing from PDF text', 'message' => 'The ' . $heading . ' heading was not found in the extracted PDF text.', 'section' => $heading, 'evidence' => $heading];
                    continue;
                }
                $headingOffset = (int)$headingMatch[0][1];
                if ($headingOffset < $lastHeadingOffset) {
                    $warnings[] = ['severity' => 'warning', 'title' => 'Section order differs in PDF text', 'message' => 'The ' . $heading . ' heading appears out of order in the extracted PDF text.', 'section' => $heading, 'evidence' => $heading];
                } else {
                    $lastHeadingOffset = $headingOffset;
                }
            }
            jsonResponse(['ok' => true, 'text' => $text, 'warnings' => $warnings]);
        }
        if ($action === 'save_draft') {
            $cv = postedCv();
            $id = (new DraftRepository())->save(requestString('draft_name', 255), $cv, (int)($_POST['draft_id'] ?? 0));
            jsonResponse(['ok' => true, 'id' => $id]);
        }
        if ($action === 'delete_draft') {
            (new DraftRepository())->delete((int)($_POST['id'] ?? 0));
            jsonResponse(['ok' => true]);
        }
        if ($action === 'export') {
            $cv = postedCv();
            $file = (new CvExporter())->export($cv, requestString('format', 10));
            $filename = 'cv.' . $file['extension'];
            header('Content-Type: ' . $file['mime']);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($file['body']));
            header('X-Content-Type-Options: nosniff');
            echo $file['body'];
            exit;
        }
        if ($action === 'check_text') {
            $text = requestString('cv_text', 200000);
            if (trim($text) === '') jsonResponse(['ok' => false, 'error' => 'Paste CV text or upload a file first.'], 422);
            $result = (new AtsChecker())->inspect($text, requestString('job_description', 80000));
            jsonResponse(['ok' => true, 'result' => $result]);
        }
        if ($action === 'check_file') {
            $file = (new CvImporter())->extractUpload($_FILES['cv_file'] ?? []);
            $result = (new AtsChecker())->inspect($file['text'], requestString('job_description', 80000));
            jsonResponse(['ok' => true, 'format' => $file['format'], 'text' => $file['text'], 'result' => $result]);
        }
        if ($action === 'import_file') {
            $extracted = (new CvImporter())->extractUpload($_FILES['cv_file'] ?? []);
            $mapped = (new CvImporter())->mapToCv($extracted['text']);
            $_SESSION['imported_cv'] = $mapped['cv'];
            jsonResponse(['ok' => true, 'format' => $extracted['format'], 'cv' => $mapped['cv'], 'text' => $extracted['text'], 'notes' => $mapped['notes']]);
        }
        if ($action === 'stage_import') {
            $_SESSION['imported_cv'] = postedCv();
            jsonResponse(['ok' => true]);
        }
        if ($action === 'ai_import_map') {
            if (!AiAssistant::isConfigured()) jsonResponse(['ok' => false, 'error' => 'AI assistance is not configured for the selected provider.'], 503);
            if (($_POST['ai_consent'] ?? '') !== 'yes') jsonResponse(['ok' => false, 'error' => 'Confirm the AI data disclosure before continuing.'], 422);
            consumeAiRequest();
            $result = (new AiAssistant())->mapImportedText(requestString('cv_text', 200000));
            jsonResponse(['ok' => true, 'result' => $result]);
        }
        if ($action === 'ai_suggest') {
            if (!AiAssistant::isConfigured()) jsonResponse(['ok' => false, 'error' => 'AI assistance is not configured for the selected provider.'], 503);
            if (($_POST['ai_consent'] ?? '') !== 'yes') jsonResponse(['ok' => false, 'error' => 'Confirm the AI data disclosure before continuing.'], 422);
            consumeAiRequest();
            $task = requestString('task', 40);
            $data = [];
            if ($task === 'rewrite_summary' || $task === 'rewrite_bullet') {
                $data = ['selected_text' => requestString('selected_text', 5000), 'job_description' => requestString('job_description', 5000)];
                if (trim($data['selected_text']) === '') jsonResponse(['ok' => false, 'error' => 'Add the text you want help with first.'], 422);
            } elseif ($task === 'terms') {
                $data = ['cv_text' => redactForAi(requestString('cv_text', 8000)), 'job_description' => requestString('job_description', 5000)];
                if (trim($data['cv_text']) === '' || trim($data['job_description']) === '') jsonResponse(['ok' => false, 'error' => 'Provide both CV text and a job description.'], 422);
            } elseif ($task === 'explain') {
                $data = ['finding' => requestString('finding', 2000), 'cv_excerpt' => redactForAi(requestString('cv_excerpt', 4000))];
                if (trim($data['finding']) === '') jsonResponse(['ok' => false, 'error' => 'Select a checker finding first.'], 422);
            } else {
                jsonResponse(['ok' => false, 'error' => 'Unknown AI action.'], 400);
            }
            $result = (new AiAssistant())->suggest($task, $data);
            jsonResponse(['ok' => true, 'result' => $result]);
        }
        if ($action === 'ai_autofix') {
            if (!AiAssistant::isConfigured()) jsonResponse(['ok' => false, 'error' => 'AI assistance is not configured for the selected provider.'], 503);
            if (($_POST['ai_consent'] ?? '') !== 'yes') jsonResponse(['ok' => false, 'error' => 'Confirm the AI data disclosure before continuing.'], 422);
            consumeAiRequest();
            $targetIds = json_decode(requestString('target_ids', 10000), true);
            if (!is_array($targetIds)) jsonResponse(['ok' => false, 'error' => 'Choose the text items to review.'], 422);
            $includeJobDescription = ($_POST['include_job_description'] ?? '') === 'yes';
            $jobDescription = $includeJobDescription ? requestString('job_description', 5000) : '';
            $result = (new AiAssistant())->suggestAutofixes(postedCv(), $targetIds, $jobDescription);
            jsonResponse(['ok' => true, 'result' => $result]);
        }
        jsonResponse(['ok' => false, 'error' => 'Unknown request.'], 400);
    } catch (InvalidArgumentException | RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        jsonResponse(['ok' => false, 'error' => 'The request could not be completed. Check the database and server configuration.'], 500);
    }
}

$page = $_GET['page'] ?? 'home';
$pages = ['home' => 'Dashboard', 'generator' => 'Generator', 'checker' => 'Checker', 'converter' => 'Converter'];
if (!isset($pages[$page])) $page = 'home';
require BASE_PATH . '/includes/layout.php';
$pageFile = BASE_PATH . '/pages/' . $page . '.php';
require $pageFile;
