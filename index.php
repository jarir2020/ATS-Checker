<?php
use ATS\Ats\AtsChecker;
use ATS\Ai\AiAssistant;
use ATS\Cv\DraftRepository;
use ATS\Database\MigrationRunner;
use ATS\Import\CvImporter;

require_once __DIR__ . '/bootstrap.php';
require_once BASE_PATH . '/includes/security.php';
require_once BASE_PATH . '/includes/auth.php';
require_once BASE_PATH . '/includes/db.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function postedObject(string $key, int $limit = 1500000): array
{
    $value = requestString($key, $limit);
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) throw new InvalidArgumentException('The encrypted request data was not readable. Refresh and try again.');
    return $decoded;
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

// Authentication endpoints remain public so users can sign in and out. All CV,
// draft, export, import, and AI endpoints are protected below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auth_login') {
    requireCsrf();

    if (!authIsConfigured()) {
        $loginError = 'Authentication is not configured. Set APP_PASSWORD_HASH in the server .env file.';
        http_response_code(503);
    } elseif (!authLoginAllowed()) {
        $loginError = 'Too many unsuccessful attempts. Wait 15 minutes before trying again.';
        http_response_code(429);
    } elseif (authPasswordMatches(requestString('password', 4096))) {
        if (authMarkAuthenticated()) {
            header('Location: ' . BASE_URL . '?page=generator', true, 303);
            exit;
        }
        $loginError = 'A secure session could not be started. Refresh the page and try again.';
        http_response_code(500);
    } else {
        authRecordFailedLogin();
        $loginError = 'The password was not accepted.';
        http_response_code(401);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auth_logout') {
    requireCsrf();
    authLogout();
    header('Location: ' . BASE_URL . '?page=login', true, 303);
    exit;
}

// deploy.sh can request migrations through this explicit token-protected route.
// Ordinary page requests never apply migrations.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deploy_migrations') {
    $expectedToken = trim((string)($_ENV['DEPLOY_MIGRATION_TOKEN'] ?? ''));
    $providedToken = (string)($_SERVER['HTTP_X_ATS_DEPLOY_TOKEN'] ?? '');
    if (strlen($expectedToken) < 32 || !preg_match('/^[A-Za-z0-9._~-]{32,}$/D', $expectedToken)) {
        jsonResponse(['ok' => false, 'error' => 'Deployment migration token is not configured.'], 503);
    }
    if (!preg_match('/^[A-Za-z0-9._~-]{32,}$/D', $providedToken) || !hash_equals($expectedToken, $providedToken)) {
        jsonResponse(['ok' => false, 'error' => 'Deployment migration authorization failed.'], 403);
    }
    try {
        $runner = new MigrationRunner(getDb(), BASE_PATH . '/migrations');
        $mode = requestString('mode', 16);
        if ($mode === 'status') jsonResponse(['ok' => true] + $runner->status());
        if ($mode === 'apply') {
            $backupConfirmed = requestString('backup_confirmed', 3) === 'yes';
            jsonResponse(['ok' => true] + $runner->applyPending($backupConfirmed));
        }
        jsonResponse(['ok' => false, 'error' => 'Unknown migration operation.'], 400);
    } catch (InvalidArgumentException | RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        jsonResponse(['ok' => false, 'error' => 'The migration operation failed. Review the database schema and server logs.'], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    requireAuthentication(true);
    try {
        $repo = new DraftRepository();
        if ($_GET['action'] === 'vault_meta') jsonResponse(['ok' => true, 'metadata' => $repo->getVaultMetadata()]);
        if ($_GET['action'] === 'drafts_list') jsonResponse(['ok' => true, 'drafts' => $repo->list()]);
        if ($_GET['action'] === 'draft_get') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $record = $id ? $repo->getRecord((int)$id) : null;
            if ($record === null) jsonResponse(['ok' => false, 'error' => 'Draft not found.'], 404);
            jsonResponse(['ok' => true] + $record);
        }
        if ($_GET['action'] === 'draft_migration_source') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
            $source = $id ? $repo->getMigrationRecord((int)$id) : null;
            if ($source === null) jsonResponse(['ok' => false, 'error' => 'Legacy draft source is no longer available.'], 404);
            jsonResponse(['ok' => true, 'legacy' => $source]);
        }
        if ($_GET['action'] === 'migration_status') jsonResponse(['ok' => true] + $repo->migrationStatus());
        jsonResponse(['ok' => false, 'error' => 'Unknown request.'], 400);
    } catch (Throwable $error) {
        jsonResponse(['ok' => false, 'error' => 'Draft storage is unavailable. Check the database settings and, for an existing database, apply the encrypted-draft SQL migration after verifying a backup.'], 503);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['auth_login', 'auth_logout'], true)) {
    requireAuthentication(true);
    requireCsrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'vault_initialize') {
            $metadata = postedObject('metadata', 10000);
            jsonResponse(['ok' => true] + (new DraftRepository())->initializeVault($metadata));
        }
        if ($action === 'vault_update') {
            $expected = postedObject('expected_metadata', 10000);
            $replacement = postedObject('metadata', 10000);
            jsonResponse(['ok' => true, 'metadata' => (new DraftRepository())->replaceVaultMetadata($expected, $replacement)]);
        }
        if ($action === 'draft_reserve') {
            $repo = new DraftRepository();
            if (!$repo->getVaultMetadata()) jsonResponse(['ok' => false, 'error' => 'Initialize the encrypted vault before saving drafts.'], 409);
            jsonResponse(['ok' => true, 'id' => $repo->reserve()]);
        }
        if ($action === 'draft_save_encrypted' || $action === 'draft_migrate_save') {
            $repo = new DraftRepository();
            if (!$repo->getVaultMetadata()) jsonResponse(['ok' => false, 'error' => 'Initialize the encrypted vault before saving drafts.'], 409);
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) jsonResponse(['ok' => false, 'error' => 'Draft identifier is invalid.'], 422);
            $payload = postedObject('envelope', 1500000);
            $name = postedObject('name_envelope', 10000);
            $repo->saveEncrypted((int)$id, $payload, $name);
            jsonResponse(['ok' => true, 'id' => (int)$id]);
        }
        if ($action === 'draft_purge_plaintext') {
            if (($_POST['backup_confirmed'] ?? '') !== 'yes') {
                jsonResponse(['ok' => false, 'error' => 'Confirm that a fresh offline database backup was made and verified.'], 422);
            }
            if (requestString('confirmation', 32) !== 'REMOVE PLAINTEXT') {
                jsonResponse(['ok' => false, 'error' => 'Confirm removal of the legacy plaintext values.'], 422);
            }
            $cleared = (new DraftRepository())->clearPlaintextAfterMigration();
            jsonResponse(['ok' => true, 'cleared' => $cleared]);
        }
        if ($action === 'delete_draft') {
            (new DraftRepository())->delete((int)($_POST['id'] ?? 0));
            jsonResponse(['ok' => true]);
        }
        if ($action === 'check_file') {
            $file = (new CvImporter())->extractUpload($_FILES['cv_file'] ?? []);
            $result = (new AtsChecker())->inspect($file['text'], requestString('job_description', 80000));
            jsonResponse(['ok' => true, 'format' => $file['format'], 'text' => $file['text'], 'result' => $result]);
        }
        if ($action === 'import_file') {
            $importer = new CvImporter();
            $extracted = $importer->extractUpload($_FILES['cv_file'] ?? [], true);
            $mapped = $importer->mapToCv($extracted['text'], $extracted['photo'] ?? '');
            jsonResponse(['ok' => true, 'format' => $extracted['format'], 'cv' => $mapped['cv'], 'text' => $extracted['text'], 'notes' => $mapped['notes']]);
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
            $targets = postedObject('selected_targets', 400000);
            $findings = postedObject('local_findings', 200000);
            if (array_values($targets) !== $targets || array_values($findings) !== $findings) jsonResponse(['ok' => false, 'error' => 'Choose the text items to review.'], 422);
            $includeJobDescription = ($_POST['include_job_description'] ?? '') === 'yes';
            $jobDescription = $includeJobDescription ? requestString('job_description', 5000) : '';
            $result = (new AiAssistant())->suggestAutofixesFromSelection($targets, $findings, $jobDescription);
            jsonResponse(['ok' => true, 'result' => $result]);
        }
        jsonResponse(['ok' => false, 'error' => 'Unknown request.'], 400);
    } catch (InvalidArgumentException | RuntimeException $error) {
        jsonResponse(['ok' => false, 'error' => $error->getMessage()], 422);
    } catch (Throwable $error) {
        jsonResponse(['ok' => false, 'error' => 'The request could not be completed. Check the database and server configuration.'], 500);
    }
}

$page = !empty($loginError) ? 'login' : ($_GET['page'] ?? 'home');
$pages = ['home' => 'Dashboard', 'generator' => 'Generator', 'checker' => 'Checker', 'converter' => 'Converter', 'login' => 'Sign in'];
if (!isset($pages[$page])) $page = 'home';

if ($page === 'login') {
    if (authIsAuthenticated()) {
        header('Location: ' . BASE_URL . '?page=generator', true, 302);
        exit;
    }
    require BASE_PATH . '/includes/layout.php';
    require BASE_PATH . '/pages/login.php';
    exit;
}

requireAuthentication();

require BASE_PATH . '/includes/layout.php';
$pageFile = BASE_PATH . '/pages/' . $page . '.php';
require $pageFile;
