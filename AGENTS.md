# Repository Guidance

## Project scope

- Read `ATS_Plan.md` before changing product behavior or architecture.
- This repository is for ATS-friendly CV creation, checking, conversion, and export. Do not add general-purpose toolbox utilities.
- The current CV editor is `pages/generator.php` with its shared model and renderer under `src/Cv/`. The former editor is archived as `legacy/cv-maker/index.php.legacy.txt`; the old URL redirects to the generator.

## Data and security

- Preserve existing `draft_cvs` data. The current database default is `cv_maker`; back up and plan a migration before moving existing drafts between databases.
- The app uses a session-based shared-password login configured with `APP_PASSWORD_HASH`. Drafts remain shared across authenticated sessions; do not add accounts or per-user ownership unless requested.
- Keep credentials and API keys in server-side environment configuration or a secret manager. Never put secrets in source files, browser JavaScript, commits, or logs.
- Keep `.env` and local `.env.*` files out of Git. `.env.example` may contain variable names and safe defaults only; never put credentials there.
- Protect application pages and API actions with authentication. Keep login and logout CSRF-protected, regenerate the session ID on sign-in, and fail closed if the password hash is missing or invalid.
- AI calls must be user-initiated, disclose which CV content is sent, and require review before suggestions alter a saved CV.
- AI is optional: hide AI controls whenever the selected provider's key is missing, and reject AI actions server-side in that state.
- AI import mapping uses the editable excerpt, requires consent, and must only stage a suggestion after the user chooses to review it in the generator.
- Gemini and Agnes AI adapters send requests from PHP; provider keys remain server-side. Gemini requests use `store: false`. Agnes uses `https://apihub.agnes-ai.com/v1/chat/completions` with Bearer authentication; do not make claims about provider-side retention. Add other providers only after verifying their API service and protocol.
- Do not change the `draft_cvs` schema or database default without a backup and a migration plan. Keep the legacy payload adapter working.
- Encrypted drafts use browser-side Web Crypto. Never send a vault passphrase, raw vault key, decrypted draft title, or decrypted CV to PHP for ordinary draft reads, preview, checking pasted text, or exports. Keep only ciphertext envelopes in server responses and database rows after migration.
- Apply versioned SQL migrations only after taking and verifying an offline database backup. `deploy.sh` may invoke the dedicated token-protected migration route after upload; ordinary page requests never apply migrations. Legacy rows remain available until every draft is encrypted and verified; clear readable `draft_name` and `cv_data` values only through the explicit owner-confirmed cleanup after a fresh verified backup.
- Do not persist imported CVs in PHP sessions, browser local storage, or analytics. The current DOCX/PDF converter uploads documents to PHP; disclose that the server can read uploaded content during extraction. AI requests remain explicit plaintext disclosures and must send only selected content.
- Browser-side DOCX/TXT/HTML exports and browser print-to-PDF must not POST CV content to PHP. Keep AES-GCM nonces unique per key, bind encrypted records to their database IDs, and preserve the versioned envelope and vault recovery path.
- Validate uploaded files, clean up temporary files, escape imported content, and protect state-changing requests against CSRF.

## Project conventions

- The app uses PHP, Composer, MySQL, and browser JavaScript; there is no JavaScript package/build system currently.
- Keep dependencies appropriate to active ATS features. When dependencies change, update `composer.json` and `composer.lock` together; do not hand-edit the lock file.
- Keep `README.md` accurate about which capabilities are implemented and which are only planned in `ATS_Plan.md`.
- Do not add or run automated test suites unless the user asks for testing or verification.
- `deploy.sh` reads FTP credentials, deployment URLs, and a separate migration token from `.env`, excludes local secrets and database dumps, and targets `../../` relative to the FTP login directory to move one level above the previously configured public `/ftp-tools/` destination. Only versioned SQL files under `migrations/` are uploaded; pending files run over HTTPS after a verified-backup confirmation. After upload it checks the public login page, protected JSON API response, and key frontend assets, then commits non-ignored local changes and pushes the current branch to `origin` unless `--no-git` is supplied. `--check` stays read-only and skips migrations, live checks, and Git operations. It prints the resulting FTP working directory. If the FTP account is jailed there, the host must change its home directory to allow access to the parent. Do not run a deployment unless the user asks.
