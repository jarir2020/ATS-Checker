<?php pageHeader('Your CV workspace'); ?>
<section class="page-intro">
    <p class="eyebrow">ATS CHECKER</p>
    <h1>Your CV workspace</h1>
    <p>Create a readable CV, review its text, or import an existing document.</p>
</section>
<section class="workflow-grid" aria-label="CV tools">
    <a class="workflow-card" href="<?= BASE_URL ?>?page=generator">
        <span class="workflow-number">01</span><h2>Build a CV</h2>
        <p>Fill structured sections, preview a single-column layout, save drafts, and export four formats.</p><span class="workflow-link">Open generator →</span>
    </a>
    <a class="workflow-card" href="<?= BASE_URL ?>?page=checker">
        <span class="workflow-number">02</span><h2>Check a CV</h2>
        <p>Review text extraction, common sections, contact details, dates, bullets, and job-description terms.</p><span class="workflow-link">Open checker →</span>
    </a>
    <a class="workflow-card" href="<?= BASE_URL ?>?page=converter">
        <span class="workflow-number">03</span><h2>Convert a CV</h2>
        <p>Import a DOCX or text-based PDF, review the extracted content, then edit and export it.</p><span class="workflow-link">Open converter →</span>
    </a>
</section>
<p class="privacy-note">The checker uses transparent text-based rules. Results are guidance, not a prediction of how a specific employer’s ATS will rank a CV.</p>
<?php pageFooter(); ?>
