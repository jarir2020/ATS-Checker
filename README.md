# ATS Checker

ATS Checker is a PHP CV workspace with an ATS-friendly generator, a text-based checker, a DOCX/PDF importer, and four export formats.

## Features

- **Generator:** structured contact, optional profile photo, summary, skills, experience, education, projects, certifications, languages, and custom sections. Preview, text, HTML, DOCX, and print-to-PDF are rendered in the browser. The text-order check runs locally and flags suspicious split dates and missing or out-of-order headings; inspect the final PDF separately.
- **Encrypted drafts:** CV content and draft names use browser-side AES-GCM envelope encryption before they reach `cv_maker.draft_cvs`. A separate vault passphrase derives the wrapping key with PBKDF2; a random vault key wraps per-draft data keys. Neither the passphrase nor unwrapped keys are sent to PHP or saved in local storage. All signed-in users share the same vault. Existing drafts stay plaintext until they are migrated and plaintext cleanup is confirmed.
- **Checker:** pasted CV text is checked in the browser. DOCX and PDF uploads are sent to PHP for extraction and then checked. Findings cover contact details, common headings, dates and incomplete numeric date fragments, bullets, measurable outcomes, possible extraction artifacts, and optional job-description terms. Findings identify an affected area and evidence. Its heuristic score does not predict an employer’s ATS decision.
- **Converter:** import DOCX or text-based PDF files into editable sections. Likely embedded profile photos are included when detected; small logos and non-portrait graphics are skipped. Common sections map into structured fields; uncertain or less common sections remain editable for review. Scanned PDFs and OCR are not supported.
- **Exports:** DOCX, plain text, and HTML download directly from the browser. PDF uses the browser's print dialog. The optional profile photo appears in visual, PDF, and DOCX output; plain text remains text-only.
- **Optional AI help:** configure Gemini or Agnes AI for summary and experience-bullet rewrites, a review-first ATS autofixer, job-description terms to review, checker explanations, or suggested import mapping. Autofix suggestions update only the current editor after individual review; users save drafts themselves. AI controls are hidden when the selected provider key is missing, and the server rejects AI requests in that state. Email, phone, and profile links are removed from checker excerpts before they are sent; autofix sends only selected summary/bullet text and local finding titles, while import text can be edited before sending.

The app is protected by a single shared password, and the encrypted draft vault has a separate passphrase and offline recovery key. Everyone with both credentials shares access to the same drafts; there are no individual accounts or per-user draft records. It does not add general-purpose toolbox utilities. See [ATS_Plan.md](ATS_Plan.md) and [DATABASE_ENCRYPTION_PLAN.md](DATABASE_ENCRYPTION_PLAN.md) for architecture and migration status. The previous CV Maker source is archived in `legacy/cv-maker/index.php.legacy.txt`; `tools/cv-maker/` redirects to the new generator.

## Requirements

- PHP 7.4 or newer for the application and local development server. The start script uses `php` by default; set `PHP_BIN` if your PHP executable has another name or path.
- Composer dependencies from `composer.json` and `composer.lock` for PHP imports and AI adapters. Browser exports do not send CV data to PHP.
- MySQL with PDO MySQL. The database account must be allowed to create the configured database and `draft_cvs` table if needed.
- PHP `mbstring`, `fileinfo`, `zip`, `dom`, `iconv`, and `zlib` extensions. PDF text import uses Poppler `pdftotext` when available and falls back to the Composer PHP parser on hosts without it; set `PDFTOTEXT_BIN` when Poppler is installed outside the standard paths. Optional profile-photo import/editing requires PHP GD and Poppler `pdfimages`; set `PDFIMAGES_BIN` if it is installed outside the standard paths. If photo support is unavailable, text import still works and the photo remains empty.
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

   Edit `.env` with your database settings and set `APP_PASSWORD_HASH`. Generate a hash by typing your chosen password into this command's input; the hash is printed for you to paste into `.env`:

   ```sh
   read -rsp 'App password: ' APP_PASSWORD; printf '\n'; APP_PASSWORD_HASH="$(printf '%s' "$APP_PASSWORD" | php -r 'echo password_hash(stream_get_contents(STDIN), PASSWORD_DEFAULT);')"; unset APP_PASSWORD; printf 'APP_PASSWORD_HASH=%s\n' "$APP_PASSWORD_HASH"
   ```

   The app fails closed and displays a configuration message until a valid hash is set. The hash is never stored in source control.

   Optional AI keys are blank in the template. Enter a key only for a provider you intend to use, then set `AI_PROVIDER=gemini` or `AI_PROVIDER=agnes`. Gemini defaults to `gemini-3.6-flash` and tries the comma-separated `GEMINI_FALLBACK_MODELS` when a model is overloaded, rate-limited, or unavailable. The current sample fallback list uses models that responded in a generic connectivity check for this installation; model availability and account access can change. See Google's [model list](https://ai.google.dev/gemini-api/docs/models). For Agnes, enter a model ID available in your Agnes account in `AGNES_MODEL` ([Agnes API docs](https://agnes-ai.com/en/docs/overview)). The project currently integrates Gemini and Agnes; other API keys need a provider adapter before the app can use them.

3. Start the local server:

   ```sh
   ./start.sh
   ```

   The script serves the app at `http://127.0.0.1:8000` by default and updates `APP_URL` in `.env`. Set `PORT` to use another port or `PHP_BIN` to choose another PHP executable, for example `PHP_BIN=php8.5 ./start.sh`.

## AI data handling

AI is optional; the generator, checker, converter, and exports work without an AI key. Each AI action is started by the user and requires a consent checkbox. Generator rewrites send the selected summary or bullets and optional job description. The ATS autofixer checks the structured CV locally, then sends only the summary/bullets selected by the user and local finding titles/messages; contact fields, photos, and other CV fields are excluded. Each suggested replacement is shown with its original text and applied to the editor only after the user chooses it; the user reviews and saves the draft manually. Checker assistance sends the editable excerpt, finding, and, for term suggestions, the job description. Import mapping sends only the excerpt edited in the converter. Email addresses, phone numbers, and LinkedIn/GitHub links are redacted from checker excerpts on the server; imported text is editable so users can remove details before sending. Names and other text remain under the user's control in the editable excerpt.

Set `AI_PROVIDER` to `gemini` or `agnes`; the browser displays the selected provider before each AI action. Keys are read by PHP from the server-side environment, and AI actions are limited to five per minute and thirty per session hour. Gemini requests set `store: false`; model fallback uses the comma-separated IDs in `GEMINI_FALLBACK_MODELS`. Agnes requests use the documented OpenAI-compatible chat completions endpoint and do not include a provider storage option, so review Agnes's current privacy and retention terms before enabling it. This integration does not promise provider-side retention behavior. Do not put API keys in browser code, source control, the database, or this repository's docs. Add other providers only after verifying their API service and protocol.

## Data and limitations

- New databases are created with encrypted storage columns. On an existing database, `deploy.sh` checks the schema and applies [`migrations/20261020_encrypted_cv_v1.sql`](migrations/20261020_encrypted_cv_v1.sql) when needed, after the operator confirms a verified offline backup. The migration adds columns and vault metadata without changing existing draft rows. The SQL is not applied during ordinary app page requests.
- In the generator, create the separate vault passphrase and download the recovery key to offline storage. After verifying an offline backup, use **Encrypt and verify legacy drafts**. When every row is verified, take and verify a fresh backup, confirm the backup checkbox again, then explicitly choose **Remove legacy plaintext**. This clears readable names and CV JSON from the live table only; backups, binary logs, replicas, or snapshots may retain plaintext until their retention expires or they are securely removed. No database migration, backup, or plaintext cleanup was run during this implementation.
- The current local `cv_maker` database and `draft_cvs` table were initialized previously. The old `toolbox` database was left untouched; no rows were copied between databases.
- The vault protects stored database rows from someone who has only the database and no vault passphrase or recovery key. It cannot protect data from malicious JavaScript served by a compromised website while the vault is unlocked. The app sign-in password is not the vault passphrase.
- Row IDs, timestamps, record counts, and ciphertext sizes remain visible; a database attacker can also delete or roll back ciphertext. The salt and wrapped key allow offline passphrase guessing, so choose a long, unique passphrase. Losing both the vault passphrase and recovery key means the drafts cannot be decrypted.
- The converter supports DOCX and selectable-text PDF uploads up to 10 MB. Image-only PDF OCR, encrypted PDFs, and automatic high-confidence section mapping are outside the first version. Embedded photo detection is based on image size and shape, so review the staged photo and remove it if it is not a portrait.
- A detected or selected profile photo is resized and included inside the encrypted CV payload when a draft is saved. It is not included in AI requests.
- The checker evaluates extracted text and cannot confirm the source document's visual layout. Pasted text is checked in the browser; uploaded documents are processed by PHP. The generator's local text-order check does not inspect the final PDF bytes; ATS products differ, so findings are guidance, not a guarantee.
- The included PHP development server is for local use. Configure a production web server, upload limits, HTTPS, and environment secrets separately before deployment.

## FTP deployment

`deploy.sh` reads `FTP_HOST`, `FTP_PORT`, `FTP_USERNAME`, and `FTP_PASSWORD` from the ignored `.env` file. It requires FTPS and verifies both the certificate authority and hostname by default. If the server certificate name does not match `FTP_HOST`, set `FTP_HOST` to the FTP hostname supplied by the hosting provider. `FTP_TLS_CHECK_HOSTNAME=no` is available only when the provider confirms that the mismatch is expected; certificate authority validation remains enabled. Run `./deploy.sh --check` for a read-only remote comparison, `./deploy.sh --full` for an initial full upload, or `./deploy.sh` to upload files changed since the last successful deploy. Full uploads preserve remote-only files. The script excludes `.env`, keys, and database dumps.

For SQL migrations, set the same random `DEPLOY_MIGRATION_TOKEN` in the local `.env` and the server-side `.env` (the deployment never uploads `.env`). Set `DEPLOY_SITE_URL` or `DEPLOY_MIGRATION_URL` to the public HTTPS app base URL; either fills in the other when blank. The script uploads only versioned `.sql` files from `migrations/`, checks the migration API response, and confirms no migrations remain pending after application. If any are pending, it prompts for `BACKUP VERIFIED`; alternatively pass `--confirm-db-backup` after you have made and checked an offline database backup. The PHP app applies them with its server-side DB credentials. Use `--no-migrations` for a code-only upload; `--check` never uploads files, invokes migrations, runs live checks, or performs Git operations. The remote route requires a 32-character-or-longer deployment token and applies each migration once using its recorded checksum. The backup confirmation is your attestation; the script cannot verify the backup itself.

After a successful upload (and migration phase, when enabled), the script checks the public login page, the protected JSON API boundary, and the CSS/JavaScript assets used by the generator, checker, and converter. It then stages all non-ignored local changes, commits them if needed, and pushes the current branch to `origin`. Review `git status` before running deployment; use `--no-git` to skip the commit and push, or `--commit-message "Your message"` to set the automatic commit message. The `.env`, keys, and database dumps remain excluded from Git and FTP.

The `.env` `FTP_DIRECTORY` value is known to be mismatched. The previously configured target corresponds to the public `/ftp-tools/` folder, so the script now targets `../../` to move one level above it and prints the resulting FTP working directory. If the FTP account is jailed to `/ftp-tools/`, the host must change its home directory to allow access to the parent. The script does not run automatically during development, and `.env` is never sent; configure `APP_PASSWORD_HASH` separately on the host. Local file deletions are not propagated to the server.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
