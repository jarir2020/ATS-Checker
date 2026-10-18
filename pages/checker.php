<?php
use ATS\Ai\AiAssistant;
$aiProviderLabel = AiAssistant::providerLabel();
$aiConfigured = AiAssistant::isConfigured();
pageHeader('ATS CV Checker');
?>
<section class="page-intro compact-intro"><div><p class="eyebrow">CHECKER</p><h1>ATS-friendly checker</h1><p>Run a transparent, text-based review of a CV. Add a job description for term comparison.</p></div><a class="button button-light" href="<?= BASE_URL ?>?page=generator">Open generator →</a></section>
<div class="checker-grid">
    <section class="panel checker-input">
        <div class="panel-heading"><div><h2>CV text</h2><p>Paste text or extract it from a DOCX or PDF file.</p></div></div>
        <form id="checkForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
            <label class="field-label" for="cvText">Paste CV text</label><textarea id="cvText" name="cv_text" rows="16" placeholder="Paste selectable text from your CV here…"></textarea>
            <div class="divider-label"><span>or upload a file</span></div>
            <label class="upload-control"><span>Choose DOCX or text-based PDF</span><input type="file" name="cv_file" id="checkFile" accept=".docx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></label>
            <label class="field-label" for="jobDescription">Target job description <span class="optional">optional</span></label><textarea id="jobDescription" name="job_description" rows="6" placeholder="Paste a job description to compare common terms…"></textarea>
            <button class="button button-primary full-button" type="submit">Check my CV</button>
        </form>
        <p class="privacy-note">Uploaded files are extracted locally on this server and are not retained. A text-only check cannot confirm the visual layout of the source file.</p>
    </section>
    <section class="panel results-panel"><div class="panel-heading"><div><h2>Review results</h2><p>Evidence and suggested next steps.</p></div></div><div id="checkResults" class="empty-results"><div class="empty-icon">✓</div><p>Your findings will appear here.</p></div></section>
</div>
<?php if ($aiConfigured): ?>
<div class="panel ai-box checker-ai">
    <h3>Ask <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?> about a finding or job terms</h3>
    <p>Optional and user initiated. The editable excerpt and selected finding are sent for explanations. Job-term suggestions also send the job description. Email, phone, and profile links are removed automatically; remove names or narrow the excerpt here if you prefer. Review <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?>'s current privacy and retention terms before sending text.</p>
    <label class="field-label" for="aiExcerpt">Text excerpt to send</label>
    <textarea id="aiExcerpt" rows="5" placeholder="Run a check first, then review or edit the excerpt sent to <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?>…"></textarea>
    <label class="checkline"><input id="checkAiConsent" type="checkbox"> I understand and want to send the selected content to <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?>.</label>
    <div class="ai-controls"><button type="button" class="button button-light" id="suggestTerms">Suggest terms to review</button><div id="checkerAiResult" aria-live="polite"></div></div>
</div>
<?php endif; ?>
<script>window.ATS_BASE_URL = <?= json_encode(BASE_URL) ?>; window.ATS_CSRF = <?= json_encode(csrfToken()) ?>; window.ATS_AI_PROVIDER = <?= json_encode($aiProviderLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>; window.ATS_AI_ENABLED = <?= $aiConfigured ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/checker.js" defer></script>
<?php pageFooter(); ?>
