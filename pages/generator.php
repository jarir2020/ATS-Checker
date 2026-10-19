<?php
use ATS\Cv\CvDocument;
use ATS\Ai\AiAssistant;
$aiProviderLabel = AiAssistant::providerLabel();
$aiConfigured = AiAssistant::isConfigured();
$initialCv = CvDocument::empty();
$initialJson = json_encode($initialCv, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
pageHeader('ATS CV Generator');
?>
<section class="page-intro compact-intro">
    <div><p class="eyebrow">GENERATOR</p><h1>ATS-friendly CV generator</h1><p>Build a clear, single-column CV from structured sections.</p></div>
    <div class="toolbar-actions">
        <button class="button button-light" type="button" id="newCv">New CV</button>
        <button class="button button-outline" type="button" id="saveDraft">Save draft</button>
        <label class="format-select"><span class="sr-only">Export format</span><select id="exportFormat"><option value="docx">DOCX</option><option value="pdf">Print / PDF</option><option value="txt">Plain text</option><option value="html">HTML</option></select></label>
        <button class="button button-primary" type="button" id="exportCv">Export</button>
    </div>
</section>
<section class="panel vault-panel" id="vaultPanel">
    <div class="panel-heading"><div><h2>Encrypted draft vault</h2><p>New and resaved drafts are encrypted in your browser before storage. Existing drafts stay readable until migrated and plaintext cleanup is confirmed.</p></div><span id="vaultBadge" class="status-dot">Locked</span></div>
    <p class="vault-explanation">The vault uses a separate, long passphrase that is never sent to PHP. Do not reuse the shared app sign-in password. Everyone with both credentials can access the shared drafts. Database IDs, timestamps, and encrypted record sizes remain visible. A compromised web server can still serve malicious JavaScript while you use the site.</p>
    <div id="vaultLocked">
        <form id="vaultPassForm" class="vault-form">
            <label for="vaultPassphrase">Vault passphrase</label><input id="vaultPassphrase" type="password" autocomplete="new-password" minlength="12" required>
            <div id="vaultConfirmWrap" hidden><label for="vaultPassphraseConfirm">Confirm vault passphrase</label><input id="vaultPassphraseConfirm" type="password" autocomplete="new-password" minlength="12"></div>
            <button class="button button-primary" id="vaultUnlockButton" type="submit">Unlock vault</button>
        </form>
        <details class="vault-recovery"><summary>Use an offline recovery key</summary><label for="vaultRecoveryInput">Recovery key</label><textarea id="vaultRecoveryInput" rows="2" autocomplete="off" spellcheck="false"></textarea><button class="button button-light" id="vaultRecoveryUnlock" type="button">Unlock with recovery key</button></details>
    </div>
    <div id="vaultRecoveryConfirm" hidden>
        <p><strong>Save this recovery key offline before continuing.</strong> Anyone holding it can decrypt the vault. The server does not have a copy.</p>
        <pre id="vaultRecoveryDisplay" class="vault-recovery-key"></pre>
        <button class="button button-light" type="button" id="downloadRecoveryKey">Download recovery key</button>
        <label class="checkline"><input type="checkbox" id="recoverySaved"> I saved this recovery key somewhere offline.</label>
        <button class="button button-primary" type="button" id="confirmVaultSetup" disabled>Create encrypted vault</button>
    </div>
    <div id="vaultUnlocked" hidden>
        <div class="vault-unlocked-row"><p id="vaultMessage" class="notice notice-success">Vault unlocked in this browser tab. Lock it before leaving a shared computer.</p><button class="button button-light" type="button" id="lockVault">Lock vault</button></div>
        <details class="vault-recovery"><summary>Change vault passphrase</summary><form id="changeVaultPassForm" class="vault-form"><label for="newVaultPassphrase">New passphrase</label><input id="newVaultPassphrase" type="password" autocomplete="new-password" minlength="12" required><label for="newVaultPassphraseConfirm">Confirm new passphrase</label><input id="newVaultPassphraseConfirm" type="password" autocomplete="new-password" minlength="12" required><button class="button button-light" type="submit">Change passphrase</button></form></details>
        <div class="vault-migration">
            <strong>Existing draft migration</strong>
            <p id="migrationStatus" class="muted">Checking saved-draft status…</p>
            <label class="checkline"><input type="checkbox" id="backupConfirmed"> I have made and verified an offline database backup.</label>
            <button class="button button-light" type="button" id="migrateDrafts">Encrypt and verify legacy drafts</button>
            <button class="button button-outline" type="button" id="pauseMigration" hidden>Pause migration</button>
            <button class="button button-outline" type="button" id="purgePlaintext" hidden>Remove legacy plaintext</button>
        </div>
    </div>
    <p id="vaultFeedback" class="vault-feedback" role="status" aria-live="polite"></p>
</section>
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
            <div class="panel-heading"><div><h2>Preview</h2><p>Review the browser-rendered layout or text reading order.</p></div><span id="previewState" class="muted">Updating…</span></div>
            <div class="preview-tabs" role="tablist" aria-label="CV preview mode">
                <button class="preview-tab is-active" id="visualPreviewTab" type="button" role="tab" aria-selected="true" aria-controls="visualPreviewView">Visual layout</button>
                <button class="preview-tab" id="atsTextTab" type="button" role="tab" aria-selected="false" aria-controls="atsTextView">ATS text check</button>
            </div>
            <div id="visualPreviewView" role="tabpanel" aria-labelledby="visualPreviewTab"><iframe id="cvPreview" title="Visual CV preview" sandbox="" referrerpolicy="no-referrer"></iframe></div>
            <section id="atsTextView" role="tabpanel" aria-labelledby="atsTextTab" hidden>
                <div class="ats-text-tools">
                    <p>Text order generated locally from the CV content. PDF export uses your browser’s print dialog; review the saved PDF separately. ATS products differ.</p>
                    <div><button class="button button-light" id="refreshAtsText" type="button">Check text order</button> <button class="button button-outline" id="copyAtsText" type="button" disabled>Copy text</button></div>
                </div>
                <p id="atsTextStatus" class="muted" aria-live="polite">Open this tab to inspect the current PDF text.</p>
                <div id="atsTextWarnings" class="ats-text-warnings" aria-live="polite"></div>
                <pre id="atsTextPreview" class="ats-text-preview">The extracted PDF text will appear here.</pre>
            </section>
        </section>
        <section class="panel draft-panel"><div class="panel-heading"><div><h2>Saved drafts</h2><p>Draft titles unlock in this browser; legacy rows need migration and plaintext cleanup.</p></div><button class="text-button" id="refreshDrafts" type="button">Refresh</button></div><div id="draftList"><p class="muted">Loading drafts…</p></div></section>
    </aside>
</div>
<dialog id="saveDialog" class="save-dialog"><form method="dialog"><h2>Save CV draft</h2><label for="draftName">Draft name</label><input id="draftName" maxlength="255" autocomplete="off" placeholder="My CV" required><div class="dialog-actions"><button class="button button-light" value="cancel">Cancel</button><button class="button button-primary" id="confirmSave" value="save">Save draft</button></div></form></dialog>
<div id="appToast" class="toast" role="status" aria-live="polite"></div>
<script id="initialCv" type="application/json"><?= $initialJson ?: '{}' ?></script>
<script>window.ATS_BASE_URL = <?= json_encode(BASE_URL) ?>; window.ATS_CSRF = <?= json_encode(csrfToken()) ?>; window.ATS_AI_PROVIDER = <?= json_encode($aiProviderLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>; window.ATS_AI_ENABLED = <?= $aiConfigured ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/vault.js" defer></script>
<script src="<?= BASE_URL ?>assets/js/cv-renderer.js" defer></script>
<script src="<?= BASE_URL ?>assets/js/generator.js" defer></script>
<?php pageFooter(); ?>
