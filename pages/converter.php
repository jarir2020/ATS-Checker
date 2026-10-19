<?php
use ATS\Ai\AiAssistant;
$aiProviderLabel = AiAssistant::providerLabel();
$aiConfigured = AiAssistant::isConfigured();
pageHeader('ATS CV Converter');
?>
<section class="page-intro compact-intro"><div><p class="eyebrow">CONVERTER</p><h1>Convert an existing CV</h1><p>Extract a DOCX or text-based PDF into editable CV sections. Likely embedded profile photos are carried over when supported.</p></div><a class="button button-light" href="<?= BASE_URL ?>?page=generator">Open generator →</a></section>
<div class="converter-grid">
    <section class="panel">
        <div class="panel-heading"><div><h2>Import a document</h2><p>The file is uploaded to PHP for extraction and then removed.</p></div></div>
        <form id="importForm"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
            <label class="upload-drop" for="importFile"><span class="upload-symbol">↑</span><strong>Choose a CV document</strong><span>DOCX or PDF · maximum 10 MB</span><input id="importFile" name="cv_file" type="file" accept=".docx,.pdf,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required></label>
            <button class="button button-primary full-button" type="submit">Extract and map content</button>
        </form>
        <div class="notice notice-info"><strong>Privacy:</strong> the server can read the uploaded file while extracting it. The source file is removed after processing; extracted content is returned directly to your browser and is not saved in the PHP session. Scanned or image-only PDFs need OCR and are not supported yet.</div>
        <div id="importResult" aria-live="polite"></div>
    </section>
    <section class="panel"><div class="panel-heading"><div><h2>Review before exporting</h2><p>Imported fields may need correction.</p></div></div><ol class="steps-list"><li><span>1</span><div><strong>Extract content</strong><p>DOCX paragraphs, selectable PDF text, and likely profile photos are extracted on this server.</p></div></li><li><span>2</span><div><strong>Review the mapping</strong><p>The browser transfers the extracted CV directly to a new generator tab without storing it in the PHP session.</p></div></li><li><span>3</span><div><strong>Export your CV</strong><p>DOCX, text, and HTML are generated in the browser. PDF uses the browser print dialog.</p></div></li></ol></section>
</div>
<script>window.ATS_BASE_URL = <?= json_encode(BASE_URL) ?>; window.ATS_CSRF = <?= json_encode(csrfToken()) ?>; window.ATS_AI_PROVIDER = <?= json_encode($aiProviderLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>; window.ATS_AI_ENABLED = <?= $aiConfigured ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/converter.js" defer></script>
<?php pageFooter(); ?>
