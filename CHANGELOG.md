# Changelog

All notable changes to Widdx are documented here.

## Unreleased

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
