<?php
use ATS\Cv\CvDocument;
use ATS\Ai\AiAssistant;
$aiProviderLabel = AiAssistant::providerLabel();
$aiConfigured = AiAssistant::isConfigured();
$isImport = ($_GET['imported'] ?? '') === '1' && is_array($_SESSION['imported_cv'] ?? null);
$initialCv = $isImport ? CvDocument::normalize($_SESSION['imported_cv']) : CvDocument::empty();
if ($isImport) unset($_SESSION['imported_cv']);
$initialJson = json_encode($initialCv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
pageHeader('ATS CV Generator');
?>
<section class="page-intro compact-intro">
    <div><p class="eyebrow">GENERATOR</p><h1>ATS-friendly CV generator</h1><p>Build a clear, single-column CV from structured sections.</p></div>
    <div class="toolbar-actions">
        <button class="button button-light" type="button" id="newCv">New CV</button>
        <button class="button button-outline" type="button" id="saveDraft">Save draft</button>
        <label class="format-select"><span class="sr-only">Export format</span><select id="exportFormat"><option value="docx">DOCX</option><option value="pdf">PDF</option><option value="txt">Plain text</option><option value="html">HTML</option></select></label>
        <button class="button button-primary" type="button" id="exportCv">Export</button>
    </div>
</section>
<?php if ($isImport): ?><div class="notice notice-success">Imported text is ready for review. Check every field before exporting.</div><?php endif; ?>
<div class="generator-layout">
    <section class="editor-panel panel">
        <div class="panel-heading"><div><h2>CV details</h2><p>Your content stays editable here.</p></div><span class="status-dot">ATS-first layout</span></div>
        <div id="editorFields"></div>
        <?php if ($aiConfigured): ?><section class="ai-box">
            <div><h3>Optional <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?> writing help</h3><p>For rewrites, only the selected summary or experience bullets and optional job description are sent. For the ATS autofixer, only the summary and experience bullets you select, plus local finding titles and brief messages, are sent. Contact fields and other CV sections are excluded. Review <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?>'s current privacy and retention terms before sending text.</p></div>
            <label class="checkline"><input id="aiConsent" type="checkbox"> I understand and want to send the selected text to <?= htmlspecialchars($aiProviderLabel, ENT_QUOTES) ?>.</label>
            <div class="ai-controls"><textarea id="targetJob" rows="3" placeholder="Optional target job description"></textarea><button class="button button-light" type="button" id="rewriteSummary">Suggest summary rewrite</button></div>
            <div id="aiSuggestions" class="suggestion-list" aria-live="polite"></div>
            <section class="autofix-box">
                <div><h3>ATS autofixer</h3><p>Run local ATS checks, then get conservative rewrites for selected text. Review each before applying it to the editor; nothing is saved automatically.</p></div>
                <div class="autofix-selection-head"><strong>Text to send</strong><span id="autofixTargetCount" class="muted">Choose up to 30 items.</span></div>
                <div class="autofix-selection-actions"><button type="button" class="text-button" id="selectAutofixTargets">Select first 30</button><button type="button" class="text-button" id="clearAutofixTargets">Clear selection</button></div>
                <div id="autofixTargets" class="autofix-targets"></div>
                <label class="checkline"><input id="includeAutofixJob" type="checkbox"> Also send the target job description above to guide this review.</label>
                <button type="button" class="button button-primary" id="runAutofix">Check and suggest fixes</button>
                <div id="autofixResults" class="autofix-results" aria-live="polite"></div>
            </section>
        </section><?php endif; ?>
    </section>
    <aside class="preview-column">
        <section class="panel preview-panel">
            <div class="panel-heading"><div><h2>Preview</h2><p>Review the layout or inspect extracted PDF text.</p></div><span id="previewState" class="muted">Updating…</span></div>
            <div class="preview-tabs" role="tablist" aria-label="CV preview mode">
                <button class="preview-tab is-active" id="visualPreviewTab" type="button" role="tab" aria-selected="true" aria-controls="visualPreviewView">Visual layout</button>
                <button class="preview-tab" id="atsTextTab" type="button" role="tab" aria-selected="false" aria-controls="atsTextView">ATS text check</button>
            </div>
            <div id="visualPreviewView" role="tabpanel" aria-labelledby="visualPreviewTab"><iframe id="cvPreview" title="Visual CV preview" sandbox="" referrerpolicy="no-referrer"></iframe></div>
            <section id="atsTextView" role="tabpanel" aria-labelledby="atsTextTab" hidden>
                <div class="ats-text-tools">
                    <p>Text extracted from a fresh PDF export. ATS products differ; use this to review reading order and split content, not to predict an employer’s result.</p>
                    <div><button class="button button-light" id="refreshAtsText" type="button">Check PDF text</button> <button class="button button-outline" id="copyAtsText" type="button" disabled>Copy text</button></div>
                </div>
                <p id="atsTextStatus" class="muted" aria-live="polite">Open this tab to inspect the current PDF text.</p>
                <div id="atsTextWarnings" class="ats-text-warnings" aria-live="polite"></div>
                <pre id="atsTextPreview" class="ats-text-preview">The extracted PDF text will appear here.</pre>
            </section>
        </section>
        <section class="panel draft-panel"><div class="panel-heading"><div><h2>Saved drafts</h2><p>Existing drafts remain in the current database table.</p></div><button class="text-button" id="refreshDrafts" type="button">Refresh</button></div><div id="draftList"><p class="muted">Loading drafts…</p></div></section>
    </aside>
</div>
<dialog id="saveDialog" class="save-dialog"><form method="dialog"><h2>Save CV draft</h2><label for="draftName">Draft name</label><input id="draftName" maxlength="255" autocomplete="off" placeholder="My CV" required><div class="dialog-actions"><button class="button button-light" value="cancel">Cancel</button><button class="button button-primary" id="confirmSave" value="save">Save draft</button></div></form></dialog>
<div id="appToast" class="toast" role="status" aria-live="polite"></div>
<script id="initialCv" type="application/json"><?= $initialJson ?: '{}' ?></script>
<script>window.ATS_BASE_URL = <?= json_encode(BASE_URL) ?>; window.ATS_CSRF = <?= json_encode(csrfToken()) ?>; window.ATS_AI_PROVIDER = <?= json_encode($aiProviderLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>; window.ATS_AI_ENABLED = <?= $aiConfigured ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/generator.js" defer></script>
<?php pageFooter(); ?>
