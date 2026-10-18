# ATS Checker

ATS Checker is being reshaped from a general PHP toolbox into a focused CV platform for ATS-friendly CV creation, checking, conversion, and export.

> **Current status:** the repository contains the retained legacy CV Maker. The ATS checker, document converter, multi-format exports beyond PDF, and AI assistance are described in [ATS_Plan.md](ATS_Plan.md) and are not implemented yet.

## Current application

- Single shared-password login configured with `APP_PASSWORD_HASH`.
- Legacy CV Maker with structured CV fields, live preview, saved MySQL drafts, and PDF download through mPDF.
- Drafts are stored in the `draft_cvs` table. The default database name is still `toolbox` to avoid disconnecting existing data.

## Planned capabilities

- ATS-friendly CV generator with a clear single-column layout.
- ATS checker with explainable findings and optional job-description comparison.
- Converter that imports supported DOCX and text-based PDF CVs into editable sections.
- DOCX, PDF, TXT, and HTML exports.
- Optional, user-initiated AI suggestions using server-side provider integrations such as Gemini and other configured APIs.

See [ATS_Plan.md](ATS_Plan.md) for the detailed scope, assumptions, migration notes, and implementation sequence. [documentation.md](documentation.md) is historical documentation for the legacy CV Maker.

## Requirements

- PHP 8.5 CLI for the included `start.sh` script.
- Composer dependencies from `composer.json` and `composer.lock`.
- MySQL with PDO MySQL, plus PHP `mbstring` and `gd` extensions required by the current PDF library.

The database account must be able to create the configured database and the `draft_cvs` table if they do not already exist.

## Local setup

1. Install Composer dependencies:

   ```sh
   composer install
   ```

2. Create a `.env` file in the project root. `.env` is ignored by Git.

   ```dotenv
   APP_NAME=ATS Checker
   APP_URL=http://127.0.0.1:8000
   APP_PASSWORD_HASH=replace-with-a-password-hash
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=toolbox
   DB_USER=your_local_database_user
   DB_PASS=your_local_database_password
   ```

3. Generate a password hash with PHP and put the result in `APP_PASSWORD_HASH`:

   ```sh
   php8.5 -r 'echo password_hash("choose-a-password", PASSWORD_DEFAULT), PHP_EOL;'
   ```

4. Start the local server:

   ```sh
   ./start.sh
   ```

   The script serves the app at `http://127.0.0.1:8000` by default and updates `APP_URL` in `.env` to match. Set `PORT` to use another port.

The development server is for local use. Configure a production web server and protect its environment secrets separately before deployment.

## License

This project is licensed under the MIT License. See [LICENSE](LICENSE).
