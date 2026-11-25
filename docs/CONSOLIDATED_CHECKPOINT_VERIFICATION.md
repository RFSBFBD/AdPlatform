# Consolidated clean-source verification

24 November 2026. Runtime source: 13164f6. Documentation/export consolidation: ba4a9eb, followed by this verification record only. Prior delivered d871b22 ZIP is unchanged, SHA256 d02c33434cb045b0716e69b007f7110c04401d39d924c8b2fa364323afb735cd.

Committed sources were exported to a separate empty directory, with no installed dependencies, credentials, Git history, database contents, internal continuation notes or local authoring aids. From that export:

- Composer manifest validation, locked installation including normal package-discovery scripts, and platform requirements passed on PHP 8.3.17.
- A separate synthetic PostgreSQL 16 database was migrated from zero with explicit DB_CONNECTION=pgsql. All 534 backend tests and 4219 assertions passed, including backup/restore and optional numerical solver tests. None were skipped.
- Clean npm install audited 317 packages with zero reported vulnerabilities at that time. All 145 frontend tests passed and the production build completed. Node 22.23.5 / npm 10.9.9 were used; the declared npm 11.12.1 was not tested.
- Application, routes, migrations, configuration and test PHP files passed syntax checks. The frozen custom-lead API route was registered.
- Runtime-equivalent desktop and 390px mobile renders from the successful synthetic API workflow were visually inspected and accepted before consolidation. No runtime code changed during packaging. Internal phone table scrolling is intentional; no page overflow was observed. This is not all browser/viewport coverage.

An initial verification command omitted DB_CONNECTION for the migration process, which defaulted to SQLite; PHPUnit used PostgreSQL and found no tables. That run failed and is not counted as verification. After explicitly selecting PostgreSQL, a fresh migration and the complete suite passed. No production or user data was involved. The complete passing output was inspected after a command-wrapper timeout during later syntax checks; the final explicit PostgreSQL fresh-migration/full-suite run also completed normally.

The source archive does not bundle runtime binaries, dependencies, generated build output, secrets, local environment files, database dumps or Git history. Example environment templates and synthetic fixture values are source, not real credentials. Docker/deployment, provider OAuth/permissions, real API responses, spend semantics, lead quality, business outcomes and production state were not verified. Optional numerical dependencies were installed separately, not embedded in the source package.

Use SOURCE_CHECKPOINT_2026_11_24.md first. Original larger scope remains open, cross-provider portfolio allocation remains unavailable, and REPORTED_PURCHASE_CREDIT_CONTRACT_HOLD.md remains in force. Feature work pauses at this coherent boundary. No push, deployment, real-data update, account/campaign mutation or purchase occurred.
