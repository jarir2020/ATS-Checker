# Repository Guidance

## Project scope

- Read `ATS_Plan.md` before changing product behavior or architecture.
- This repository is for ATS-friendly CV creation, checking, conversion, and export. Do not add general-purpose toolbox utilities.
- The current CV editor is `pages/generator.php` with its shared model and renderer under `src/Cv/`. The former editor is archived as `legacy/cv-maker/index.php.legacy.txt`; the old URL redirects to the generator.

## Data and security

- Preserve existing `draft_cvs` data. The current database default is `cv_maker`; back up and plan a migration before moving existing drafts between databases.
- The app intentionally has no login screen or account system. Drafts are shared across everyone who can reach this app; do not add authentication or per-user behavior unless requested.
- Keep credentials and API keys in server-side environment configuration or a secret manager. Never put secrets in source files, browser JavaScript, commits, or logs.
- Keep `.env` and local `.env.*` files out of Git. `.env.example` may contain variable names and safe defaults only; never put credentials there.
- AI calls must be user-initiated, disclose which CV content is sent, and require review before suggestions alter a saved CV.
- AI is optional: hide AI controls whenever the selected provider's key is missing, and reject AI actions server-side in that state.
- AI import mapping uses the editable excerpt, requires consent, and must only stage a suggestion after the user chooses to review it in the generator.
- Gemini and Agnes AI adapters send requests from PHP; provider keys remain server-side. Gemini requests use `store: false`. Agnes uses `https://apihub.agnes-ai.com/v1/chat/completions` with Bearer authentication; do not make claims about provider-side retention. Add other providers only after verifying their API service and protocol.
- Do not change the `draft_cvs` schema or database default without a backup and a migration plan. Keep the legacy payload adapter working.
- Validate uploaded files, clean up temporary files, escape imported content, and protect state-changing requests against CSRF.

## Project conventions

- The app uses PHP, Composer, MySQL, and browser JavaScript; there is no JavaScript package/build system currently.
- Keep dependencies appropriate to active ATS features. When dependencies change, update `composer.json` and `composer.lock` together; do not hand-edit the lock file.
- Keep `README.md` accurate about which capabilities are implemented and which are only planned in `ATS_Plan.md`.
- Do not add or run automated test suites unless the user asks for testing or verification.
