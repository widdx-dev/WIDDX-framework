# Changelog

All notable changes to Widdx are documented here.

## Unreleased

### Security

- `lang_attrs()` no longer prints a caller-supplied locale raw inside the HTML `lang` attribute: non-locale characters are stripped (BCP-47 / setlocale shape) and the value is escaped with `e()`, closing an attribute-injection vector for request-derived locales.
- `View::make()`, `View::core()` and `View::exists()` now resolve templates with `realpath()` and prove the result stays inside the configured views directory — the same containment rule as `LocalStorage::fullPath()` — so raw separators or a symlink planted in `views/` can no longer render files outside it.
- SMTP `From` and `Reply-To` display names are RFC 2047 encoded when non-ASCII (and quoted when they contain RFC 5322 specials), so Arabic sender names are no longer mangled or rejected by strict SMTP servers; the encoded form stays single-line and ASCII-only.
- The root `.htaccess` (and the one `widdx new` generates) also blocks the `lang/` directory, and forbids direct hits on real files under `storage/` (logs, sessions, cache, uploaded payloads) while still letting the PHP-guarded `storageRoutes()` endpoint serve public uploads — nothing under `storage/` is ever served raw.
- `Lang` translation lookups refuse locale names outside `[A-Za-z0-9_-]` before `require` sees them — a request-derived locale like `../config/app` can no longer traverse out of `lang/` into an arbitrary file include (defense-in-depth LFI guard; tags like `pt-BR` and `en_US` stay legal).

### Fixes and reliability

- PostgreSQL `Database::tableColumns()` is scoped to one schema (`TABLE_SCHEMA = current_schema()`, or the explicit schema in a `schema.table` name): identically named tables in other schemas no longer leak their columns into `Model::columns()` and zero-code CRUD.
- New SQLite connections now run `PRAGMA foreign_keys = ON;` so `REFERENCES` / `ON DELETE CASCADE` constraints declared in migrations are actually enforced at runtime (SQLite leaves them per-connection opt-in).
- `Response::addHeader()` appends headers instead of replacing them — several `Set-Cookie` lines can now share one response — and `Response::send()` emits repeat values without wiping the session cookie queued by PHP (`Set-Cookie` is append-only).
- Query Builder `where()`/`orWhere()` accept a closure for parenthesized condition groups (`AND (... OR ...)`), with correct placeholder numbering across nesting and no-SQL no-op for empty groups.
- SMTP phrase encoding is now whole-phrase base64 UTF-8 — per RFC 2047 §6.2 conformant clients drop whitespace between adjacent encoded-words (a per-word encoding of "متجر ويديكس" would render "متجرويديكس"), so spaces now live inside the payload. It also wraps RFC 5322 specials of mixed names, works without mbstring, and covers the Subject line too.
- `View` template resolution rejects null-byte names before `realpath()`/`is_file()` can see them (PHP 8.1+ throws `ValueError` on null bytes; lookups now answer `ViewNotFoundException`).
- `Mailable::send()` queues attachments BEFORE each `raw()` call: drivers consume one-shot attachments while sending (SmtpMail clears them in a `finally` block), so attaching after the send silently dropped the file from that message and leaked it into the next mail sent through the shared connection.
- `SmtpMail::raw()` RFC 2047-encodes display names inside caller-supplied `From`/`Reply-To`/`Cc`/`Bcc` values (`Name <addr>`): a non-ASCII name set via `Mailable::from()` or `->header()` no longer ships as raw 8-bit UTF-8, while quoted pure-ASCII names pass through untouched.
- `PendingMailable::deliver()`/`queue()` only override the subject when the caller actually set one — an empty pending subject no longer wipes the subject a mailable class defines for itself.
- CLI `make:resource`: the generated `show.php` delete form now includes `csrf_field()`, so generated apps no longer answer 419 on Delete.
- CLI `db:wipe` is driver-aware: backtick identifier quoting on MySQL (double quotes are string literals without ANSI_QUOTES), `CASCADE` on pgsql, and foreign-key checks paused/restored around the drops (`FOREIGN_KEY_CHECKS` on MySQL, `PRAGMA foreign_keys` on sqlite).
- CLI `new` given an absolute path (`php widdx new /srv/apps/blog`) writes the folder `basename()` as the default MySQL/pgsql database name instead of the raw path.

## [0.4.0] - 2026-10-27

### Highlights

- Make new applications secure by default: generated CRUD routes are protected unless a route is deliberately opened with `->public()`; the framework repository's demo application now lives under `tests/fixtures/demo-app/` instead of the repository root.
- Expand authentication protections with automatic password hashing for model/CRUD/API writes, email-verification middleware, rate-limited credential flows, single-use password-reset tokens, and session invalidation after password changes.
- Harden file handling, SQL construction, request parsing, SMTP messages, installer writes, and Apache shared-hosting rules.
- Add Arabic and English learning paths and improve integration coverage for freshly scaffolded applications and shared-hosting rewrites.

### Security

- Apply CSRF validation and baseline security headers to web routes and API routes by default. API write requests carrying a PHP session cookie require a matching CSRF token; stateless API requests without that cookie remain exempt.
- Hash plaintext values written to a `password` column without re-hashing recognized password hashes. Password inputs stay blank, and password/token fields are excluded from model serialization by default.
- Deny privilege, verification, remember-session, and token fields in mass assignment and generated CRUD forms by default.
- Validate Query Builder SQL identifiers; limit raw request bodies while reading them; block symlink traversal in storage/upload paths; and serve active user-uploaded content and unknown binary files as safe downloads instead of executing them inline.
- Apply baseline security headers to error responses as well as successful responses. Normalize login work for unknown accounts and unusable hashes to reduce account-enumeration timing signals.
- Make password-reset tokens single-use and consume them atomically. Defer reset and verification lookups/delivery until after the HTTP response, return generic responses for unknown addresses, and invalidate existing sessions after a password change.
- Validate SMTP recipients and headers to prevent header injection; handle Bcc case-insensitively without exposing it in message data.
- Harden installation: validate the install key, require a canonical `APP_URL`, preserve exact database-password values, write `.env` with restrictive permissions, refuse to overwrite an existing `.env`, and create the install lock only after successful migrations. Failed migrations can be retried safely.
- Restrict GitHub Actions source access to read-only permissions.

### Fixes and reliability

- Isolate integration-test project environment variables so database matrix settings cannot unintentionally override a generated project's own configuration.
- Drain nested queued events after the response, isolate listener failures, and cap re-entrant dispatch rounds.
- Distinguish missing-table errors from database permission and connection failures.
- Improve generator path validation, model/auth behavior, local-storage URL and file safety, and environment-file parsing for quoted values and literal backslashes.

### Documentation and testing

- Expand the English and Arabic learning resources and clarify secure scaffolding, authentication, API CSRF, file storage, and deployment requirements.
- Validate the supported PHP 8.1–8.5 matrix against SQLite, MySQL 8, and PostgreSQL 16; run PHPStan; run tests from inside a freshly generated project; and exercise the Apache shared-hosting rewrite and private-file protection paths in CI.

### Upgrade notes from 0.3.8

- **Protected CRUD is now the default.** Review every generated `resource()` and `apiResource()` route. Configure authentication and policies for private data; use `->public()` only when public access is intentional.
- **Session-based browser API writes now need CSRF tokens.** Obtain a token and send it with POST, PUT, PATCH, and DELETE requests that include the PHP session cookie. Stateless integrations that do not send that cookie remain exempt by default.
- **Review password data before upgrading.** Plaintext values already stored in legacy databases are not automatically migrated. Reset affected passwords or run a carefully reviewed migration that accounts for the formats actually present.
- **Review sensitive mass assignment.** Applications that intentionally write protected privilege/verification/token columns must opt in through both `$fillable` and `$sensitiveFillable`, and must retain authorization checks.
- **Expect older sessions to be renewed.** Sessions created before password-hash fingerprinting do not have the new fingerprint and will need to authenticate again.
- **Review installation and hosting configuration.** The installer requires a valid install key and canonical `APP_URL`, and will not overwrite an existing `.env`. Prefer configuring the web root to `public/`; if using the Apache root fallback, verify `mod_rewrite`, `AllowOverride`, and access denial for private files on the actual host.
- The framework source repository no longer includes a runnable demo app at its root. Use `php widdx new my-app` for a secure scaffold, or `php widdx new demo-app --demo` only for a disposable local demo.
