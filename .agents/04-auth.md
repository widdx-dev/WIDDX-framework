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

In PHP code:

- `Auth::attempt(['email' => …, 'password' => …])` — logs in, true/false.
- `auth()` helper — the logged-in user or null. USE THIS IN VIEWS, not `Auth::user()`.
- `Auth::check()` — logged in?
- `Auth::register(['name'…,'email'…,'password'…])` — hashes the password itself.
- `Auth::logout()`.

Permissions (Gate):

- `Widdx\Gate::define('publish', fn($u) => $u->is_admin);`
- `authorize('publish');` — throws a 403 page if false (JSON 403 in apiResource).
- Policy file: `app/Policies/BookPolicy.php` for model `Book`. Methods: `viewAny view create update delete`.
- A method the policy does NOT define = DENIED. Write all five or the feature 403s. (This is intended, like Laravel.)
- If a policy exists, the built-in CRUD pages for that model are guarded automatically.

---
