# Auth, Gate and policies

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 10 — Auth (the full system in one line)

```
authRoutes();
```

Routes this creates: `/register /login /logout /forgot-password /reset-password /email/verification` — with built-in templates, validation, hashed passwords, session regeneration, reset tokens, email verification tokens.

Needs a `User` model with `email` + `password` columns. Easiest:

```
php widdx make:auth
```

(it adds the migrations + route line + User model for you).

`make:auth` also drops `->public()` from `resource('users')` in `routes/web.php`: once `users` is the
authentication table, anonymous CRUD on it is an account-takeover door, so the group goes fail-closed.
The scaffolded `UserSeeder` no longer seeds a password at all — register the first real user through
`/register`.

In PHP code:

- `Auth::attempt(['email' => …, 'password' => …])` — logs in, true/false.
- `auth()` helper — the logged-in user or null. USE THIS IN VIEWS, not `Auth::user()`.
- `Auth::check()` — logged in?
- `Auth::register(['name'…,'email'…,'password'…])` — hashes the password itself.
- `Auth::logout()`.

Verification-resend requests follow the same anti-enumeration rule: the HTTP handler always shows a generic success response, while the account lookup, verification-state check, token creation, and email delivery happen after the response. Unknown or already-verified addresses receive no verification token or email.

Password-reset requests intentionally do not look up the submitted email in the HTTP response path. `Auth::sendResetLink($email)` always returns `true` to mean “request accepted”; the user lookup, token creation, and mail delivery run after the response is sent, so callers must never use the return value to infer whether an account exists.

Password changes and password resets also revoke existing login sessions: each session records a non-reversible fingerprint of the saved password hash and is logged out on its next request if that hash changes. Sessions created before this protection was added have no fingerprint and must authenticate again after upgrading.

Email verification creates tokens when the user table has `email_verified_at`, but it does not automatically restrict every protected page. To require a verified email, add `VerifiedMiddleware` to only the routes that need it:

```php
get('/billing', [BillingController::class, 'index'])
    ->middleware(\Widdx\AuthMiddleware::class)
    ->middleware(\Widdx\VerifiedMiddleware::class);
```

Guests are sent to `/login`; signed-in users whose email is not verified are sent to `/email-verification`. JSON requests receive 401 for guests and 403 for unverified users. If the user model has no `email_verified_at` column, verification is disabled for that model. Leave `/email-verification` and `/verify-email/{token}` outside this middleware so people can complete verification.

Permissions (Gate):

- `Widdx\Gate::define('publish', fn($u) => $u->is_admin);`
- `authorize('publish');` — throws a 403 page if false (JSON 403 in apiResource).
- Policy file: `app/Policies/BookPolicy.php` for model `Book`. Methods: `viewAny view create update delete`.
- A method the policy does NOT define = DENIED. Write all five or the feature 403s. (This is intended, like Laravel.)
- If a policy exists, the built-in CRUD pages for that model are guarded automatically.

---
