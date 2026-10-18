# ATS Checker

ATS Checker is a PHP CV workspace with an ATS-friendly generator, a text-based checker, a DOCX/PDF importer, and four export formats.

## Features

- **Generator:** structured contact, summary, skills, experience, education, projects, certifications, languages, and custom sections. The preview and exports share one single-column renderer. An on-demand ATS text check extracts text from a fresh PDF export, shows its reading order, and flags suspicious split dates or missing/out-of-order section headings.
- **Drafts:** save, load, and delete CV drafts in the `cv_maker.draft_cvs` table. Existing legacy draft JSON is converted when loaded; new records use a versioned canonical format. `DB_NAME` can be changed in `.env` if needed.
- **Checker:** explainable rule-based findings for contact details, common headings, dates and incomplete numeric date fragments, bullets, measurable outcomes, possible extraction artifacts, and optional job-description terms. Findings identify an affected area and evidence. Its heuristic score does not predict an employer’s ATS decision.
- **Converter:** import DOCX or text-based PDF files into editable sections. Common sections map into structured fields; uncertain or less common sections remain editable for review. Scanned PDFs and OCR are not supported.
- **Exports:** DOCX, text-based PDF, plain text, and HTML, rendered from the same CV data.
- **Optional AI help:** configure Gemini or Agnes AI for summary and experience-bullet rewrites, a review-first ATS autofixer, job-description terms to review, checker explanations, or suggested import mapping. Autofix suggestions update only the current editor after individual review; users save drafts themselves. AI controls are hidden when the selected provider key is missing, and the server rejects AI requests in that state. Email, phone, and profile links are removed from checker excerpts before they are sent; autofix sends only selected summary/bullet text and local finding titles, while import text can be edited before sending.

The app opens directly without a login screen or user accounts. Everyone who can reach this instance shares access to the same saved drafts. It does not add general-purpose toolbox utilities. See [ATS_Plan.md](ATS_Plan.md) for scope, architecture, migration notes, and implementation status. The previous CV Maker source is archived in `legacy/cv-maker/index.php.legacy.txt`; `tools/cv-maker/` redirects to the new generator. Old `login.php` and `logout.php` URLs redirect to the app home.

## Requirements

- PHP 7.4 or newer for the application and local development server. The start script uses `php` by default; set `PHP_BIN` if your PHP executable has another name or path.
- Composer dependencies from `composer.json` and `composer.lock` (mPDF is used for PDF export).
- MySQL with PDO MySQL. The database account must be allowed to create the configured database and `draft_cvs` table if needed.
- PHP `mbstring`, `fileinfo`, `zip`, and `dom` extensions. PDF import also requires `pdftotext` on the server. The local development environment has it at `/usr/bin/pdftotext`; set `PDFTOTEXT_BIN` if it is installed elsewhere.
- For uploads, configure `upload_max_filesize` to at least `10M` and `post_max_size` above `10M`.

## Local setup

1. Install dependencies:

   ```sh
   composer install
   ```

2. Create a local `.env` file from the tracked template if you do not already have one. `.env` and other local `.env.*` files are ignored by Git; `.env.example` contains no credentials.

   ```sh
   if [ ! -f .env ]; then cp .env.example .env; fi
   ```

   Edit `.env` with your database settings. No app password is required.

   Optional AI keys are blank in the template. Enter a key only for a provider you intend to use, then set `AI_PROVIDER=gemini` or `AI_PROVIDER=agnes`. Gemini defaults to `gemini-3.6-flash` and tries the comma-separated `GEMINI_FALLBACK_MODELS` when a model is overloaded, rate-limited, or unavailable. The current sample fallback list uses models that responded in a generic connectivity check for this installation; model availability and account access can change. See Google's [model list](https://ai.google.dev/gemini-api/docs/models). For Agnes, enter a model ID available in your Agnes account in `AGNES_MODEL` ([Agnes API docs](https://agnes-ai.com/en/docs/overview)). The project currently integrates Gemini and Agnes; other API keys need a provider adapter before the app can use them.

3. Start the local server:

   ```sh
   ./start.sh
   ```

   The script serves the app at `http://127.0.0.1:8000` by default and updates `APP_URL` in `.env`. Set `PORT` to use another port or `PHP_BIN` to choose another PHP executable, for example `PHP_BIN=php8.5 ./start.sh`.

## AI data handling

AI is optional; the generator, checker, converter, and exports work without an AI key. Each AI action is started by the user and requires a consent checkbox. Generator rewrites send the selected summary or bullets and optional job description. The ATS autofixer checks the structured CV locally, then sends only the summary/bullets selected by the user and local finding titles/messages; contact fields and other CV fields are excluded. Each suggested replacement is shown with its original text and applied to the editor only after the user chooses it; the user reviews and saves the draft manually. Checker assistance sends the editable excerpt, finding, and, for term suggestions, the job description. Import mapping sends only the excerpt edited in the converter. Email addresses, phone numbers, and LinkedIn/GitHub links are redacted from checker excerpts on the server; imported text is editable so users can remove details before sending. Names and other text remain under the user's control in the editable excerpt.

Set `AI_PROVIDER` to `gemini` or `agnes`; the browser displays the selected provider before each AI action. Keys are read by PHP from the server-side environment, and AI actions are limited to five per minute and thirty per session hour. Gemini requests set `store: false`; model fallback uses the comma-separated IDs in `GEMINI_FALLBACK_MODELS`. Agnes requests use the documented OpenAI-compatible chat completions endpoint and do not include a provider storage option, so review Agnes's current privacy and retention terms before enabling it. This integration does not promise provider-side retention behavior. Do not put API keys in browser code, source control, the database, or this repository's docs. Add other providers only after verifying their API service and protocol.

## Data and limitations

- The local `cv_maker` database and `draft_cvs` table have been initialized. The previous `toolbox` database was left untouched; no legacy draft rows were copied. Keep a backup before moving any existing drafts between databases.
- The converter supports DOCX and selectable-text PDF uploads up to 10 MB. Image-only PDF OCR, encrypted PDFs, and automatic high-confidence section mapping are outside the first version.
- The checker evaluates extracted text and cannot confirm the source document's visual layout. The generator's ATS text check reviews text extracted from the generated PDF with `pdftotext`; it does not predict every ATS parser. ATS products differ, so findings and scores are guidance, not a guarantee.
- The included PHP development server is for local use. Configure a production web server, upload limits, HTTPS, and environment secrets separately before deployment.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
