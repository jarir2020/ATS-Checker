# CV Data Encryption Plan

**Status:** Client-side vault code, migration flow, and `deploy.sh` migration integration are implemented locally. The database migration has not been applied, legacy drafts have not been migrated or cleared, and these changes have not been deployed from this workspace. Take and verify an offline backup before applying the SQL migration or changing any draft rows.

## Goal

Protect saved CV content if someone obtains a database dump or database backup without the encryption secret. The stronger goal of keeping saved CVs unreadable after a complete web-server compromise needs careful limits: a compromised server can change the JavaScript it serves and capture a vault passphrase when a user unlocks data. A normal web application cannot promise protection from a malicious version of its own client code.

## Current data and trust boundaries

- Sign-in uses one shared password verified by PHP through `APP_PASSWORD_HASH`. The password is sent to the application server during sign-in.
- Authenticated sessions share the same draft pool; drafts have no user ownership.
- `draft_cvs.draft_name` and `draft_cvs.cv_data` are currently stored as readable database values. CV JSON can include a profile photo.
- PHP receives CV content for draft operations, preview, export, import, checker actions, and user-approved AI requests. A key stored beside PHP on the same host would not protect against a full host compromise.

## Recommended design

Use **client-side envelope encryption** for saved drafts. Keep the existing shared sign-in as an access gate, and introduce a separate vault passphrase that is never submitted to PHP. Do not derive the vault key from the existing sign-in password: PHP receives that password and could access or capture it on a compromised host. The browser derives a wrapping key from the vault passphrase and uses it to wrap a random vault key; the vault key wraps each draft's random data key.

For each draft, the browser will:

1. Generate a random data-encryption key with the browser's cryptographically secure random generator.
2. Encrypt the entire CV payload, including the draft title and any photo, with authenticated encryption using AES-GCM.
3. Derive a vault key from the separate passphrase and a random salt, then use it to encrypt the per-draft data key. Store the KDF name, salt, parameters, algorithm, nonces, wrapped data key, ciphertext, and format version with the record.
4. Bind the ciphertext to its draft ID and format version as authenticated additional data so records cannot be silently swapped or edited.

Use the Web Crypto API where available. The first implementation uses PBKDF2-HMAC-SHA-256 with 600,000 iterations and records the KDF, salt, and parameters. Profile unlock time on the supported phone and desktop browsers before production use; keep the parameters versioned for future upgrades. Generate a fresh nonce for every AES-GCM encryption under a key; never reuse a nonce with the same key. The standards references below cover browser crypto primitives, password-based key derivation, authenticated encryption, and key management.

Encrypt the draft title as well as the CV body. Database IDs, timestamps, ciphertext sizes, and the fact that a draft exists may remain visible unless a later design removes them. Do not store the vault passphrase or unwrapped keys in the database, PHP session, `localStorage`, or server logs. Keep the unlocked key in browser memory and clear it on explicit lock and sign-out; explain that browser memory clearing is best-effort.

Because drafts are shared, the vault remains shared too: anyone with both the app sign-in password and vault passphrase can read all drafts. Encryption does not add per-user access or ownership.

## Feature impact

Feature boundary decisions:

- **Drafts:** browser code encrypts and decrypts title and CV envelopes. PHP stores ciphertext. A separate title envelope lets the browser show draft names without downloading each full CV payload.
- **Preview and text-order check:** render locally from the in-memory CV. The text-order view is not a PDF text extraction check.
- **DOCX, TXT, and HTML export:** generate in the browser. PDF uses the browser print dialog. No export action posts CV JSON to PHP.
- **Checker:** pasted text is checked in the browser. DOCX/PDF checker file uploads and converter uploads still reach PHP for extraction; disclose this path clearly and do not claim server-blind handling for uploaded files.
- **AI:** keep it optional and user-initiated with the existing consent and review flow. AI requests send selected plaintext to the PHP adapter and then to the provider, so those actions are outside the server-blind guarantee. Do not place provider keys in browser code.
- **Logs and temporary files:** ensure plaintext is not recorded in request logs, exception logs, session data, upload temp files, caches, or analytics longer than necessary.

The deployment can be described as protecting encrypted database records from a database-only leak. It must not be described as protecting a user's unlocked data from malicious same-origin JavaScript or a compromised application server.

Database IDs, timestamps, record counts, and ciphertext sizes remain visible. A database attacker can delete or roll back ciphertext but cannot decrypt it without the vault passphrase or recovery key. The salt and wrapped key allow offline passphrase guessing, so users need a long, unique passphrase.

## Data format and schema migration

Use a versioned encrypted-record envelope in `encrypted_payload` and a separate encrypted title in `encrypted_name`. `encryption_version` identifies the envelope format, and `plaintext_cleared` records whether legacy `draft_name` and `cv_data` have been replaced with generic placeholders. The `cv_vault_meta` singleton contains the KDF salt, wrapped random vault key, and wrapped recovery key; it contains no plaintext key. Keep the legacy JSON reader until migration is complete. The migration must encrypt both `draft_name` and `cv_data`; leaving names in plaintext would expose CV identifiers. Existing databases need [`migrations/20261020_encrypted_cv_v1.sql`](migrations/20261020_encrypted_cv_v1.sql) after backup; new tables are created with the columns.

Migration sequence:

1. Confirm deployment is complete. Take and verify an offline database backup before schema or row changes.
2. `deploy.sh` uploads the versioned migration SQL and checks the server schema through a token-protected HTTPS route. It applies pending migrations using the server's own DB credentials. It skips migrations whose expected schema is already present and records applied file checksums. For a pending migration, the script requires the interactive `BACKUP VERIFIED` confirmation or `--confirm-db-backup`; this is an operator attestation, not automated backup verification. Use `--no-migrations` for a code-only upload. `--check` never invokes the migration route.
3. In the generator, create the vault passphrase and save the displayed recovery key offline. The browser sends only wrapped-key metadata to PHP.
4. After confirming the verified backup, use the owner-triggered migration screen. It encrypts each legacy draft locally, writes the envelope, fetches it again, decrypts it locally, and checks the result. The vault key is never sent to the server.
5. Record counts and failures. The migration can be paused and retried; encrypted rows coexist with their legacy values until cleanup. After all rows pass verification, take and verify a fresh offline backup, then explicitly confirm removal of readable names and JSON from the live table. Keep the verified offline rollback backup for the agreed retention period; it remains plaintext.

Database backups, binary logs, replicas, and snapshots made before or during migration may still contain plaintext until their retention ends or they are securely removed. The live-table cleanup does not erase those copies. If a vault passphrase and recovery material are lost, encrypted drafts cannot be recovered.

## Recovery and key lifecycle

- Before migration, require creation and confirmation of an offline recovery key or recovery procedure. Never store it beside the database or web files.
- Document that a lost vault passphrase without the recovery key means permanent data loss; the shared app password cannot reset the vault.
- Support passphrase changes by re-wrapping each draft's data key. Plan full data-key rotation for suspected key compromise.
- Version keys and formats. Keep a tested procedure for key rotation, recovery, backup restoration, and eventual algorithm changes.
- Prefer a dedicated secret-management service for any server-side secrets. Shared hosting may not provide one; a key in the same `.env` as the app is not protection from host compromise.

## Delivery phases

1. **Threat model and design:** completed with a separate shared-vault passphrase and offline recovery key.
2. **Crypto/storage implementation:** completed locally with PBKDF2-SHA-256, AES-GCM, per-draft data keys, associated data bound to each row ID, encrypted names, and a versioned envelope.
3. **Feature boundaries:** browser preview, text-order check, DOCX/TXT/HTML export, print-to-PDF, and pasted-text checker are implemented. File uploads and explicit AI actions are disclosed plaintext paths.
4. **Database migration:** SQL and owner-triggered encrypt/verify/cleanup flow are implemented but not run. The live database still needs a verified backup and the one-time migration if it has the old schema.
5. **Migration rehearsal and security review:** pending. Rehearse with a copied backup and verify photos, row counts, pause/retry, recovery, and rollback before removing any live plaintext.
6. **Deployment and production migration:** the deployment integration is implemented locally, but production use is pending. Configure the same strong `DEPLOY_MIGRATION_TOKEN` in local and server `.env`, set local `DEPLOY_MIGRATION_URL` to the public HTTPS app URL, deploy, confirm the backup, then create the vault, migrate and verify, take a fresh verified backup, and confirm plaintext cleanup.

## Acceptance criteria

- A database dump contains no readable draft title, contact information, CV text, or photo bytes after migration.
- An authenticated user without the vault passphrase can list encrypted records but cannot decrypt, preview, export, or recover CV content.
- Wrong keys, modified ciphertext, modified metadata, and swapped records fail closed without returning partial plaintext.
- Save, load, rename, delete, preview, import, checker, all export formats, photos, and AI consent behavior are reviewed against the new trust boundaries.
- No vault secret or plaintext CV is written to local storage, server logs, application sessions, or persistent caches.
- Recovery, rotation, backup restore, and legacy-data rollback have been rehearsed before the plaintext backup is retired.
- Documentation explains visible metadata, shared-vault access, AI disclosures, password recovery, and the remaining risk from a compromised web origin.

## Current implementation boundary

The code changes are local only. No FTP deployment, SQL migration, backup, production row update, plaintext cleanup, or encryption deployment was performed. Until all saved rows have an encrypted envelope and the plaintext cleanup is confirmed, a database dump can still contain existing CV names and content. Files uploaded for conversion or file-based checking are still readable by PHP during extraction; AI requests disclose selected text to PHP and the configured provider after user consent.

## References

- [W3C Web Cryptography API](https://www.w3.org/TR/WebCryptoAPI/) — browser cryptographic primitives including AES-GCM and PBKDF2.
- [NIST SP 800-132](https://csrc.nist.gov/pubs/sp/800/132/final) — password-based key derivation for stored data; review current revision status before implementation.
- [OWASP Password Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html) — current PBKDF2-HMAC-SHA-256 work-factor reference; benchmark on supported devices.
- [NIST SP 800-38D](https://csrc.nist.gov/pubs/sp/800/38/d/final) — GCM authenticated encryption requirements.
- [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html) and [Key Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet) — threat modeling, key storage, lifecycle, and rotation.
