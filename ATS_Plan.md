# ATS Checker Rewrite Plan

**Prepared:** 18 October 2026
**Purpose:** Replace the general toolbox with a focused CV product offering an ATS-friendly generator, checker, converter, multiple export formats, and optional AI assistance.

## Product scope

The product will have three main workflows:

1. **ATS-friendly CV Generator** — create and edit a CV from structured sections, preview it, and save drafts.
2. **ATS-friendly Checker** — review an existing CV and report concrete readability and content issues.
3. **ATS-friendly Converter** — import an existing CV, extract its content into the editable CV model, and let the user correct the result before exporting.

“Multiple Formats” is an export capability shared by the generator and converter. The proposed first set is **DOCX, text-based PDF, plain text, and HTML**. DOCX will be the primary editable format. The converter will accept DOCX and text-based PDF files. Scanned/image-only PDFs will be identified as unsupported in the first version; OCR can be considered separately.

## Initial project findings

- The project is a PHP application with no JavaScript build system. Environment loading and session startup live in `bootstrap.php`; sessions support authentication, CSRF tokens, import staging, and AI request limits. The shared-password gate was removed earlier and has now been re-enabled at the user's request.
- The CV Maker is a single 1,170-line PHP file at `tools/cv-maker/index.php`. It currently has a live JavaScript preview, a separate PHP PDF renderer, saved drafts in MySQL, and five templates: Classic, Modern, Professional, Minimal, and Executive. The existing documentation says four templates, so it is out of date.
- The PDF download uses mPDF. Drafts use a `draft_cvs` table with a JSON payload. The current database helper defaults to the `toolbox` database; keep that default until existing data has been backed up and a migration is planned.
- The draft table has no per-user ownership. The shared-password gate protects the app, and all authenticated sessions continue to share the same draft pool.
- The original toolbox also contained standalone encoders, calculators, generators, text utilities, a Markdown-to-PDF tool, and an OutlookSenderPro trial checker. Those are outside the new product scope.
- The workspace has an empty `.git` directory and no usable Git history, so there is no Git diff/status available as a change baseline.

## Cleanup completed in this pass

- Removed all standalone toolbox tools except the legacy CV Maker, including their QR encoder asset and the shared tool registry.
- Removed the separate trial checker and the unauthenticated database-export endpoint.
- Removed the old toolbox-wide manual testing guide and utility expansion plan. Kept `documentation.md` as legacy CV Maker reference material.
- Initially changed the root route to require login and open the remaining CV Maker, and updated the visible default product name to ATS Checker. The login screen and access gate were later removed at the user's request, then restored as shared-password authentication; the old `login.php` URL now leads to the sign-in page.
- Updated Composer package name and description, while retaining the existing dependency lock and vendor tree so the current mPDF export remains available.

The old CV Maker is retained as migration reference only. It will be replaced during implementation; the cleanup has not added the checker, converter, or new export formats.

## Functional requirements

### Generator

- Use one canonical CV data model for the editor, preview, saved drafts, checker, converter, and exports.
- Support contact details, professional summary, skills, experience, education, projects, certifications, languages, and optional user-defined sections.
- Allow users to add, remove, and reorder repeated entries. Present experience and education in reverse chronological order where dates are available.
- Make the default template a readable, single-column layout with standard section headings, selectable text, conventional bullets, and a predictable top-to-bottom reading order.
- Avoid layout elements that can disrupt text extraction, such as sidebars, multi-column layouts, decorative icons, charts, and important information in headers or footers. An optional profile photo may appear at the top-right of the contact block when the source CV contains one; the rest of the CV stays single-column. Compact clickable LinkedIn and GitHub/portfolio icons are allowed when a valid URL is supplied; retain full URLs in DOCX and plain-text exports.
- Keep a live preview and saved drafts. Render the preview and exports from the same data and formatting rules to prevent the current PHP/JavaScript template drift.
- Provide an on-demand text view extracted from the generated PDF. Show the extracted reading order and warn about incomplete numeric dates or expected section headings that are missing or out of order; explain that ATS products can parse the same document differently.

### Checker

- Accept pasted CV text and supported uploaded files. Optionally compare the CV with a pasted job description.
- Report rule-based findings with severity, the affected text or section, evidence, and a clear explanation. Check text extraction, common section headings, contact details, date consistency, formatting risks, and job-description keyword coverage.
- Do not imply that one score predicts every employer's ATS. Explain that ATS products differ and show the evidence behind each finding.
- Never add keywords or experience without the user's review.

### Converter

- Parse supported DOCX and text-based PDF files locally into the canonical CV model. Detect likely embedded profile photos while filtering out small logos and other non-portrait images; photo absence is normal and must not block import.
- Show extracted content in an editable review step before saving or exporting. Keep the uploaded source unchanged.
- Clearly report content that could not be mapped. Do not claim OCR support for image-only PDFs in the first version.

### Formats and privacy

- Export DOCX, text-based PDF, plain text, and HTML from the same canonical CV data.
- Preserve readable text in PDF; do not rasterize the CV. Make HTML a clean preview/print option.
- In visual HTML/PDF output, show LinkedIn and GitHub/portfolio links as clickable icons only when their URLs are present and valid; keep their full URLs in DOCX and plain-text output for text-based ATS workflows.
- Store an extracted or user-selected photo as a validated, resized image in the existing CV JSON payload. Show it in the visual preview, PDF, and DOCX at the top-right of the contact block; omit it from plain-text output. Do not change the `draft_cvs` schema for photo support.
- Process file imports locally on the application server. Delete temporary uploads after processing and avoid retaining source files by default.
- Apply file-size and type limits, validate uploads, escape imported text, and protect state-changing requests with CSRF tokens.

### AI assistance (opt-in)

- Let users request a rewrite of selected summary or experience bullets for a target job description. Show the original and proposed text side by side and require the user to accept edits.
- Add a review-first ATS autofixer that runs deterministic checks on the current structured CV, sends only user-selected summary/bullet text and finding titles/messages after consent, and applies individual suggestions to the editor only. Never auto-save fixes.
- Suggest relevant skills and job-description terms based on evidence in the CV. Label missing terms as gaps to review; never present them as skills the user has unless the user confirms them.
- Explain checker findings in plain language and suggest edits. Keep the ATS readability checks and any numeric score deterministic and separate from AI advice.
- Optionally help map ambiguous imported content to CV sections. Let the user edit the excerpt, require consent, and review all AI mappings in the generator before saving.
- Make each AI action user-initiated. Before sending text to a provider, disclose what will be sent and let the user remove contact details or narrow the selected content.
- Use server-side provider adapters for Gemini and Agnes AI. Agnes uses its OpenAI-compatible chat completions endpoint at `https://apihub.agnes-ai.com/v1/chat/completions` with Bearer authentication and a configured model ID. Add other providers only after verifying their exact API service and protocol.
- Store API keys only in server environment configuration or a secret manager. Never put keys in JavaScript, source control, the database, or application logs. Validate structured AI responses and keep request timeouts, size limits, and usage controls.
- Keep generator, checker, converter, and exports usable when no AI provider is configured or available.

## Proposed technical structure

Keep the existing PHP hosting model and sessions for CSRF, AI limits, and authentication while separating the CV logic from page markup. Do not stage CV contents in a PHP session. Protect application pages and API actions with one shared password hash from server-side environment configuration. Saved drafts remain a shared pool for authenticated sessions; do not add individual accounts or per-user ownership:

- `src/Cv/` — canonical model, validation, normalization, and legacy draft conversion.
- `src/Ats/` — deterministic checker rules and finding format.
- `src/Import/` — DOCX/PDF extraction and mapping into the canonical model.
- `assets/js/cv-renderer.js` — browser preview and DOCX/TXT/HTML output plus print-to-PDF. PHP must not receive a full CV for preview or export.
- `src/Ai/` — provider interface, provider-specific adapters, prompt construction, response validation, and usage controls.
- `pages/` — generator, checker, converter, and shared navigation screens.
- `assets/css/` and `assets/js/` — shared responsive UI and editor interactions.
- `includes/` — bootstrap, database access, and request security.

The existing application retains mPDF in Composer, but current PDF output uses the browser print dialog. Before adding DOCX/PDF parsing libraries, check PHP version and extension availability on the intended host, select maintained local libraries, and update `composer.json` and `composer.lock` together. Parsedown, TCPDF, and PHP_CodeSniffer are still listed in the existing Composer files even though their app features were removed; Composer could not reach Packagist in this environment, so they were left intact rather than editing the lock file by hand.

## Implementation sequence

### Phase 0 — Preserve data and confirm runtime constraints

- Back up the existing `draft_cvs` table before changing the schema or replacing the current generator.
- Confirm the production PHP version, required extensions, web server routing, upload limits, and writable temporary-storage path.
- Confirm the proposed format scope: DOCX, PDF, TXT, and HTML exports; DOCX and text-based PDF imports.
- Confirmed Gemini and Agnes AI provider names and API protocols. Configure keys on the server; do not share secrets in chat or commit them.

### Phase 1 — Foundation and data compatibility

- Define and document the canonical CV schema, including a version field.
- Add a conversion path from existing `draft_cvs.cv_data` records so old drafts remain usable.
- Separate request handling, data validation, persistence, and rendering.
- Keep CSRF protection and safe draft operations. Require the shared-password session gate for pages and API actions; the draft pool remains shared after sign-in. User accounts and per-user ownership are out of scope unless requested.

### Phase 2 — ATS-first generator

- Build the editor, repeatable sections, reordering, draft save/load, and live preview around the canonical schema.
- Ship one conservative single-column ATS template first. Add visual templates only if they preserve the same text order and parsing behavior.
- Remove the old duplicated JavaScript/PHP renderers and make the shared CV model the source for preview and exports.

### Phase 3 — Checker

- Implement explainable checks for file text extraction, layout risks, section structure, contact information, dates, and job-description term coverage.
- Return findings that link to the relevant imported text or editor section so users can fix issues directly.

### Phase 4 — Converter

- Add local DOCX and text-based PDF extraction using the selected libraries.
- Map common extracted sections into editable fields and retain less common or uncertain content in editable custom sections. Make the user review all mappings before saving.
- Handle malformed, encrypted, oversized, and image-only files with specific messages and safe cleanup.

### Phase 5 — Export formats

- Add DOCX, PDF, TXT, and HTML outputs from the canonical CV model.
- Keep headings, content order, links, and bullet text consistent across all outputs.

### Phase 6 — Optional AI assistance

- Add a provider interface and server-side adapters for Gemini and Agnes AI; add other providers after verifying their API details.
- Add user-requested bullet and summary rewrites, job-description suggestions, checker explanations, and optional import mapping.
- Require review before applying suggestions, validate provider responses, and keep user facts distinct from generated recommendations.
- Provide clear privacy disclosure, contact-detail redaction, timeouts, and configurable usage limits. Keep rule-based checking available as the fallback.

### Phase 7 — Acceptance and handoff

- Review generator, checker, converter, draft compatibility, responsive behavior, file handling, and each export format against the requirements above.
- Verify that generated DOCX and PDF contain selectable text in the expected reading order, and that plain-text export remains understandable without styling.
- Document supported input/output formats, known parser limits, deployment requirements, and database backup/migration steps.

## Decisions carried into implementation

- The converter means **import and normalize an existing CV into the ATS editor**, rather than a general-purpose document converter.
- Initial output formats are **DOCX, PDF, TXT, and HTML**; initial import formats are **DOCX and text-based PDF**.
- In visual HTML/PDF output, show valid LinkedIn and GitHub links as compact clickable icons; keep full social URLs in DOCX and TXT output.
- Profile photos are optional. The converter stages a likely portrait found in embedded PDF/DOCX images for user review, ignores other graphics, and leaves the photo field empty when none is found. Users can replace or remove a staged photo in the generator.
- AI assistance is optional and user-initiated. The ATS autofixer may propose rewrites only for selected summary/bullet text; each change requires user review before it reaches the editor, and the user saves manually. AI cannot add unverified qualifications, silently change a saved CV, or provide an opaque ATS pass/fail score.
- Image-only PDF OCR, automatic keyword insertion, user accounts, and general toolbox utilities are out of the first version's scope.
- At the user's request, shared-password authentication is enabled again. It uses `APP_PASSWORD_HASH`, protects application pages and API actions, and does not add user accounts or change draft ownership/schema.
- Initial planning recommended retaining `toolbox` until a migration was planned. At the user's direction, the local database is now `cv_maker`; its schema is initialized, no draft rows were copied from `toolbox`, and `toolbox` was left untouched.

## Implementation status

**Code implementation started:** 18 October 2026.

- Foundation and compatibility: implemented. A versioned canonical CV schema, legacy JSON adapter, shared renderer, CSRF helpers, and prepared-statement draft repository are in place. At the user's direction, the local database is `cv_maker`; its `draft_cvs` schema was initialized. No draft rows were copied from `toolbox`, which was left untouched.
- Generator: implemented. Repeatable editor sections, a browser-rendered preview, encrypted draft save/load/delete, an ATS-first one-column template, and a local text-order check with date-fragment and section-heading warnings are in place. The local text-order check is not a PDF extraction check.
- Checker: implemented. Pasted text is checked in the browser; file uploads still send DOCX/PDF to PHP for extraction. Rule-based findings and heuristic scoring cover common headings, contact details, date styles and incomplete numeric date fragments, bullets, measurable results, possible extraction artifacts, and optional job-description terms.
- Converter: implemented. DOCX and selectable-text PDF extraction run on PHP with size limits and temporary files. PDF extraction prefers Poppler `pdftotext` and falls back to the Composer PHP parser when the utility is unavailable or cannot read a file. PDF profile-photo extraction remains optional and uses Poppler `pdfimages` when configured. Extracted CV data is returned to the browser and transferred to the generator without PHP session staging. Optional AI mapping uses an editable excerpt and requires a deliberate review action.
- Checker: findings include a named affected area and concise evidence text alongside severity and guidance.
- Exports: DOCX, TXT, and HTML are generated in the browser; PDF uses the browser print dialog.
- AI: implemented in code for opt-in Gemini and Agnes summary and experience-bullet rewrites, a review-first ATS autofixer, job-term suggestions, checker explanations, and import mapping. The autofixer runs local rule checks and sends only selected text and local finding titles/messages after consent. Suggestions reach the unsaved editor only after review and are not saved automatically. Other requests are server-side, use session limits, and require user review. Import mapping uses an editable excerpt of up to 16 KB and transfers its proposed CV directly between browser tabs after the user chooses to review it. AI controls are hidden when the selected provider key is missing, and server routes reject AI requests in that state. Gemini requests set `store: false` and can fall through configured models after overload/rate-limit/model-availability errors; Agnes requests use the documented OpenAI-compatible Chat Completions API and send no storage preference. `AI_PROVIDER` selects the configured adapter. Other providers remain out of scope until their APIs are confirmed.
- Local configuration: `.env.example` documents the app, database, supported AI settings, shared-password hash, and FTP variable names without credentials. Local provider and FTP credentials remain in the ignored `.env`. The Agnes model field is intentionally blank so the owner can use a model ID enabled for their account. Login fails closed until a valid `APP_PASSWORD_HASH` is configured.
- FTP deployment: `deploy.sh` supports read-only comparison, full upload, and incremental uploads. It excludes `.env`, keys, and database dumps. The previously configured target corresponds to public `/ftp-tools/`, so it targets `../../` to reach that folder's parent and prints the resulting FTP working directory. If the FTP account is jailed there, the host must change its home directory to allow access to the parent. No FTP connection or upload was performed in this implementation pass.
- Acceptance, production-host checks, and browser walkthroughs have not been completed in this implementation pass. Generic Gemini connectivity probes confirmed responses from Gemini 3.6 Flash, 3.5 Flash-Lite, and 2.5 Flash; 3.8 Flash was temporarily unavailable during probing. The `cv_maker` schema setup was applied successfully; no draft rows were copied from `toolbox`, which remains untouched.

### Encrypted draft vault update — 20 October 2026

- Browser-side vault crypto and encrypted draft storage are implemented in the local code. A separate vault passphrase wraps a random vault key; each draft has a random AES-GCM data key, and draft names are encrypted too. The app sign-in password is not used as an encryption key.
- Generator preview, text-order review, DOCX/TXT/HTML output, and browser print-to-PDF are client-side. Pasted-text checker rules run in the browser. The AI autofixer sends selected text and local findings only after the existing consent action.
- DOCX/PDF converter uploads and checker file uploads are still processed by PHP; the UI now states that the server can read those files during extraction. AI actions remain explicit plaintext disclosures to PHP and the selected provider.
- `migrations/20261020_encrypted_cv_v1.sql` adds encrypted columns and vault metadata without changing existing rows. It has **not** been applied. No database backup, schema migration, production change, or legacy plaintext cleanup was run during this implementation.
- `deploy.sh` now uploads only versioned SQL files from `migrations/` and checks/applies pending migrations through a token-protected HTTPS endpoint after upload. Configure the same `DEPLOY_MIGRATION_TOKEN` in local and server `.env`, and set local `DEPLOY_MIGRATION_URL` to the public HTTPS URL. The script requires a `BACKUP VERIFIED` prompt (or explicit `--confirm-db-backup`) before applying pending SQL; `--no-migrations` skips SQL and `--check` never runs it. It still requires a later manual verified backup before plaintext cleanup.
- After deployment, take and verify an offline database backup before allowing the script to apply the SQL migration. Then create the vault, save its recovery key offline, encrypt and verify every existing draft, take a fresh verified backup, and confirm removal of plaintext names and JSON. Backups, binary logs, replicas, or snapshots may retain plaintext until their retention period ends or they are securely removed.
- Database-only protection is active for a draft only after it has an encrypted envelope. Protection against malicious JavaScript served by a compromised web origin is not possible while the user unlocks the vault on that origin.
