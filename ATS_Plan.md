# ATS Checker Rewrite Plan

**Prepared:** 18 October 2026
**Purpose:** Replace the general toolbox with a focused CV product offering an ATS-friendly generator, checker, converter, multiple export formats, and optional AI assistance.

## Product scope

The product will have three main workflows:

1. **ATS-friendly CV Generator** — create and edit a CV from structured sections, preview it, and save drafts.
2. **ATS-friendly Checker** — review an existing CV and report concrete readability and content issues.
3. **ATS-friendly Converter** — import an existing CV, extract its content into the editable CV model, and let the user correct the result before exporting.

“Multiple Formats” is an export capability shared by the generator and converter. The proposed first set is **DOCX, text-based PDF, plain text, and HTML**. DOCX will be the primary editable format. The converter will accept DOCX and text-based PDF files. Scanned/image-only PDFs will be identified as unsupported in the first version; OCR can be considered separately.

## Current project findings

- The project is a PHP application with no JavaScript build system. Environment loading and session startup live in `bootstrap.php`; sessions support CSRF tokens, import staging, and AI request limits. The login gate has since been removed at the user's request.
- The CV Maker is a single 1,170-line PHP file at `tools/cv-maker/index.php`. It currently has a live JavaScript preview, a separate PHP PDF renderer, saved drafts in MySQL, and five templates: Classic, Modern, Professional, Minimal, and Executive. The existing documentation says four templates, so it is out of date.
- The PDF download uses mPDF. Drafts use a `draft_cvs` table with a JSON payload. The current database helper defaults to the `toolbox` database; keep that default until existing data has been backed up and a migration is planned.
- The draft table has no per-user ownership. The app intentionally has no login or account system, so drafts are shared among everyone who can reach the instance.
- The original toolbox also contained standalone encoders, calculators, generators, text utilities, a Markdown-to-PDF tool, and an OutlookSenderPro trial checker. Those are outside the new product scope.
- The workspace has an empty `.git` directory and no usable Git history, so there is no Git diff/status available as a change baseline.

## Cleanup completed in this pass

- Removed all standalone toolbox tools except the legacy CV Maker, including their QR encoder asset and the shared tool registry.
- Removed the separate trial checker and the unauthenticated database-export endpoint.
- Removed the old toolbox-wide manual testing guide and utility expansion plan. Kept `documentation.md` as legacy CV Maker reference material.
- Initially changed the root route to require login and open the remaining CV Maker, and updated the visible default product name to ATS Checker. The login screen and access gate were later removed at the user's request; old login/logout URLs redirect to the app home.
- Updated Composer package name and description, while retaining the existing dependency lock and vendor tree so the current mPDF export remains available.

The old CV Maker is retained as migration reference only. It will be replaced during implementation; the cleanup has not added the checker, converter, or new export formats.

## Functional requirements

### Generator

- Use one canonical CV data model for the editor, preview, saved drafts, checker, converter, and exports.
- Support contact details, professional summary, skills, experience, education, projects, certifications, languages, and optional user-defined sections.
- Allow users to add, remove, and reorder repeated entries. Present experience and education in reverse chronological order where dates are available.
- Make the default template a readable, single-column layout with standard section headings, selectable text, conventional bullets, and a predictable top-to-bottom reading order.
- Avoid layout elements that can disrupt text extraction, such as sidebars, multi-column layouts, decorative icons, charts, images, and important information in headers or footers.
- Keep a live preview and saved drafts. Render the preview and exports from the same data and formatting rules to prevent the current PHP/JavaScript template drift.
- Provide an on-demand text view extracted from the generated PDF. Show the extracted reading order and warn about incomplete numeric dates or expected section headings that are missing or out of order; explain that ATS products can parse the same document differently.

### Checker

- Accept pasted CV text and supported uploaded files. Optionally compare the CV with a pasted job description.
- Report rule-based findings with severity, the affected text or section, evidence, and a clear explanation. Check text extraction, common section headings, contact details, date consistency, formatting risks, and job-description keyword coverage.
- Do not imply that one score predicts every employer's ATS. Explain that ATS products differ and show the evidence behind each finding.
- Never add keywords or experience without the user's review.

### Converter

- Parse supported DOCX and text-based PDF files locally into the canonical CV model.
- Show extracted content in an editable review step before saving or exporting. Keep the uploaded source unchanged.
- Clearly report content that could not be mapped. Do not claim OCR support for image-only PDFs in the first version.

### Formats and privacy

- Export DOCX, text-based PDF, plain text, and HTML from the same canonical CV data.
- Preserve readable text in PDF; do not rasterize the CV. Make HTML a clean preview/print option.
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

Keep the existing PHP hosting model and sessions for CSRF, import staging, and AI limits while separating the CV logic from page markup. Do not require a login:

- `src/Cv/` — canonical model, validation, normalization, and legacy draft conversion.
- `src/Ats/` — deterministic checker rules and finding format.
- `src/Import/` — DOCX/PDF extraction and mapping into the canonical model.
- `src/Export/` — DOCX, PDF, plain-text, and HTML exporters.
- `src/Ai/` — provider interface, provider-specific adapters, prompt construction, response validation, and usage controls.
- `pages/` — generator, checker, converter, and shared navigation screens.
- `assets/css/` and `assets/js/` — shared responsive UI and editor interactions.
- `includes/` — bootstrap, database access, and request security.

Use the existing mPDF installation for PDF output. Before adding DOCX/PDF parsing libraries, check PHP version and extension availability on the intended host, select maintained local libraries, and update `composer.json` and `composer.lock` together. Parsedown, TCPDF, and PHP_CodeSniffer are still listed in the existing Composer files even though their app features were removed; Composer could not reach Packagist in this environment, so they were left intact rather than editing the lock file by hand.

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
- Keep CSRF protection and safe draft operations. The app has no login by design, and all reachable users share the draft pool; user accounts and per-user ownership are out of scope unless requested.

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
- AI assistance is optional and user-initiated. The ATS autofixer may propose rewrites only for selected summary/bullet text; each change requires user review before it reaches the editor, and the user saves manually. AI cannot add unverified qualifications, silently change a saved CV, or provide an opaque ATS pass/fail score.
- Image-only PDF OCR, automatic keyword insertion, user accounts, and general toolbox utilities are out of the first version's scope.
- The shared-password login screen and its access gate were removed at the user's request. The legacy `login.php` and `logout.php` URLs redirect to the app home.
- Initial planning recommended retaining `toolbox` until a migration was planned. At the user's direction, the local database is now `cv_maker`; its schema is initialized, no draft rows were copied from `toolbox`, and `toolbox` was left untouched.

## Implementation status

**Code implementation started:** 18 October 2026.

- Foundation and compatibility: implemented. A versioned canonical CV schema, legacy JSON adapter, shared renderer, CSRF helpers, and prepared-statement draft repository are in place. At the user's direction, the local database is `cv_maker`; its `draft_cvs` schema was initialized. No draft rows were copied from `toolbox`, which was left untouched.
- Generator: implemented. The app now has repeatable editable sections, a server-rendered preview, draft save/load/delete, an ATS-first one-column template, and an on-demand generated-PDF text check with date-fragment and section-heading warnings. Experience and education are sorted newest-first when dates can be read.
- Checker: implemented. Rule-based findings and heuristic scoring cover common headings, contact details, date styles and incomplete numeric date fragments, bullets, measurable results, possible extraction artifacts, and optional job-description terms.
- Converter: implemented. DOCX and selectable-text PDF extraction are local, size-limited, and temporary-file based. Common experience, education, project, certification, and language content is mapped into editable fields where possible; less common headings remain in editable custom sections. Optional AI mapping uses an editable excerpt and requires a deliberate review action before staging the suggested CV.
- Checker: findings include a named affected area and concise evidence text alongside severity and guidance.
- Exports: implemented in code for DOCX, text-based PDF, TXT, and HTML using the canonical CV renderer.
- AI: implemented in code for opt-in Gemini and Agnes summary and experience-bullet rewrites, a review-first ATS autofixer, job-term suggestions, checker explanations, and import mapping. The autofixer runs local rule checks, sends only selected summary/bullet text and finding titles/messages, shows before/after text, and applies a suggestion to the unsaved editor only after the user clicks its button. It does not invent missing facts or save drafts. Other requests are server-side, use session limits, and require user review. Import mapping uses an editable excerpt of up to 16 KB and stages its proposed CV only after the user chooses to review it in the generator. AI controls are hidden when the selected provider key is missing, and server routes reject AI requests in that state. Gemini requests set `store: false` and can fall through configured models after overload/rate-limit/model-availability errors; Agnes requests use the documented OpenAI-compatible Chat Completions API and send no storage preference. `AI_PROVIDER` selects the configured adapter. Other providers remain out of scope until their APIs are confirmed.
- Local configuration: `.env.example` documents the app, database, and supported AI settings. Local provider credentials remain only in the ignored `.env` and are not stored in source control. The Agnes model field is intentionally blank so the owner can use a model ID enabled for their account. `APP_PASSWORD_HASH` is no longer required because the app has no login screen.
- Acceptance, production-host checks, browser walkthroughs, and deployment have not been completed in this implementation pass. Generic Gemini connectivity probes confirmed responses from Gemini 3.6 Flash, 3.5 Flash-Lite, and 2.5 Flash; 3.8 Flash was temporarily unavailable during probing. The `cv_maker` schema setup was applied successfully; no draft rows were copied from `toolbox`, which remains untouched.
