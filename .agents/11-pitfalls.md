# Pitfalls and the feature cheat sheet

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 21 — Pitfalls (each: the wrong thing, then the right thing)

1. WRONG: `Auth::user()` inside a view. RIGHT: `auth()` inside a view. Always.
2. WRONG: hand-writing a controller + 4 views for CRUD. RIGHT: `resource('x')->public()`.
3. WRONG: expecting `create()` to save a field. RIGHT: put the field in `$fillable` first.
4. WRONG: tightening `file/image` rules to reject path strings. RIGHT: leave them — re-validation on update needs this.
5. WRONG: a policy with only `viewAny` and wondering why create is 403. RIGHT: define every ability you use; undefined = denied.
6. WRONG: running the framework repo's `php widdx make:crud …` on ANOTHER project. RIGHT: `cd` into the target project and use its own `widdx` file.
7. WRONG: two form inputs with the same name in hidden/shown sections. RIGHT: unique names per section (PHP keeps the last value).
8. WRONG: editing `widdx` and trusting normal lint. RIGHT: `php -l widdx` (it has no .php extension; CI covers it).
9. WRONG: "fixing" `queue:work` to run forever. RIGHT: cron it with `--max` — it exits when empty on purpose.
10. WRONG: writing a POST form without `<?= csrf_field() ?>`. CSRF is already ON for every `web`
route (PART 7), so a missing field gives 419 and the site breaks silently. RIGHT: every form gets
the field; built-in CRUD/auth forms already emit it.
10b. WRONG: calling `Router::use(CsrfMiddleware::class)` to "enable" CSRF. It is already in the `web`
group; that line makes it run twice. RIGHT: only use `Router::middlewareGroup()` to change a group.
11. WRONG: trusting `X-Forwarded-For` implicitly. RIGHT: set `TRUSTED_PROXIES`; until then `Request::ip()` uses the real peer.
12. WRONG: inventing new config files. RIGHT: `config()` only loads `config/*.php`; extend those or use `.env`.
13. WRONG: writing `resource('x')` by hand and calling the 403/login-redirect a bug. RIGHT: it is fail-closed on purpose — add `->public()`, or a policy, or `protected bool $public = true` in your controller.
14. WRONG: reading a wall of "could not find driver" as broken code. RIGHT: that PHP lacks the PDO driver; `php widdx-test` now refuses to start and names the extension to install.
15. WRONG: trusting a route or view edited while `php widdx serve` was running (it 404s or shows the
    old page — unreliably, which is worse than always failing). RIGHT: restart the server. Never
    "fix" a 404 by rewriting working code; restart first, then look.
16. WRONG: calling `Post::pluck()`, `Model::update()`, `$t->unsignedBigInteger()` because Laravel has them.
    RIGHT: PART 23 is the whole callable surface. Anything not listed there does not exist, and an
    undocumented static call is a fatal error on the request that touches it — not a caught exception.
17. WRONG: leaving business logic, DB calls or HTML built as a string inside a `routes/web.php` closure.
    RIGHT: it is a controller method (L8) — the next agent must be able to find it by the folder name.

---

## PART 22 — "User asks for X → you write Y" cheat sheet

| Asked feature | Your move |
|---|---|
| A website with any list of records | `make:crud` (PART 5) |
| Search / filter / sort on a list | `resource('posts', PostController::class)` + your `index()` returning `view('posts.index', ['items' => $list])`, having copied `core/views/crud/index.php` to `views/posts/index.php`. Never `view('crud.index')` from a project — `view()` only looks in the project's `views/` and throws when the file is not there. |
| Login / accounts / password reset | `authRoutes()` once + `make:auth` |
| "Only admins can delete" | a policy (PART 10) |
| Attach a PDF / photo upload | one `file`/`image` rule (PART 13) |
| Send email after signup | `listen('user.registered', …)` or a queued job |
| Slow report generation | a Job + `queue()` (PART 14) |
| Nightly cleanup | `Schedule::command(...)` + one cron line (PART 15) |
| Public JSON API | `apiResource('x')` |
| Multi-language site | `lang/{code}.php` + `APP_LOCALE` (RTL free for ar/he/…) |
| Custom error page | `views/errors/404.php` / `500.php` |
| File download / storage | `storage()` helper |
| Caching | `cache()` helper |
| Rate limiting | `ThrottleMiddleware` on the route |
| Deploy to shared hosting | upload + `/install.php` (PART 20) |

When in doubt: make the model, add ONE route line, run `php widdx migrate`, open the page.
The framework probably already built what you were about to code.

---
