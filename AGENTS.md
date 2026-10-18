# Repository Guidance

## Project scope

- Read `ATS_Plan.md` before changing product behavior or architecture.
- This repository is for ATS-friendly CV creation, checking, conversion, and export. Do not add general-purpose toolbox utilities.
- The current `tools/cv-maker/index.php` is the legacy implementation and migration reference. The generator, checker, converter, and AI features in `ATS_Plan.md` are planned until implemented in code.

## Data and security

- Preserve existing `draft_cvs` data and the current `DB_NAME` default unless a migration has been planned and the data has been backed up.
- Keep credentials and API keys in server-side environment configuration or a secret manager. Never put secrets in source files, browser JavaScript, commits, or logs.
- AI calls must be user-initiated, disclose which CV content is sent, and require review before suggestions alter a saved CV.
- Validate uploaded files, clean up temporary files, escape imported content, and protect state-changing requests against CSRF.

## Project conventions

- The app uses PHP, Composer, MySQL, and browser JavaScript; there is no JavaScript package/build system currently.
- Keep dependencies appropriate to active ATS features. When dependencies change, update `composer.json` and `composer.lock` together; do not hand-edit the lock file.
- Keep `README.md` accurate about which capabilities are implemented and which are only planned in `ATS_Plan.md`.
- Do not add or run automated test suites unless the user asks for testing or verification.
