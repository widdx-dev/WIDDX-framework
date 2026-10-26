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

Email verification creates tokens when the user table has `email_verified_at`, but it does not automatically restrict every protected page. To require a verified email, add `VerifiedMiddleware` to only the routes that need it:

```php
get('/billing', [BillingController::class, 'index'])
    ->middleware(\\Widdx\\AuthMiddleware::class)
    ->middleware(\\Widdx\\VerifiedMiddleware::class);
```

Guests are sent to `/login`; signed-in users whose email is not verified are sent to `/email-verification`. JSON requests receive 401 for guests and 403 for unverified users. If the user model has no `email_verified_at` column, verification is disabled for that model. Leave `/email-verification` and `/verify-email/{token}` outside this middleware so people can complete verification.

Permissions (Gate):

- `Widdx\Gate::define('publish', fn($u) => $u->is_admin);`
- `authorize('publish');` — throws a 403 page if false (JSON 403 in apiResource).
- Policy file: `app/Policies/BookPolicy.php` for model `Book`. Methods: `viewAny view create update delete`.
- A method the policy does NOT define = DENIED. Write all five or the feature 403s. (This is intended, like Laravel.)
- If a policy exists, the built-in CRUD pages for that model are guarded automatically.

---
