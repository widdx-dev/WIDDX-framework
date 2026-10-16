# Routes and middleware

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 6 — Routes (one line per form)

- `get('/x', fn() => 'hi')` — returns HTML.
- `get('/x', fn() => ['a' => 1])` — returns JSON (array = JSON, always).
- `post('/x', fn() => …)` — same idea for POST.
- `put('/x/{id}', fn(string $id) => …)` — `{id}` becomes a function argument.
- `patch`, `delete` — exist and work like put.
- `->name('x.y')` — names a route. `route('x.y', ['id' => 2])` builds its URL.
- `resource('x')` — the 7 CRUD routes (GET /x, GET /x/create, POST /x, GET /x/{id}, GET /x/{id}/edit, PUT /x/{id}, DELETE /x/{id}).
- `->public()` on that group — opens all 7 to visitors. Without it (and without a policy) the pages are locked: guests → `/login`, users → 403.
- `Router::group(['prefix' => 'admin', 'middleware' => […]], function () { … })` — prefix + middleware for the routes registered inside.
- `apiResource('x')` — JSON CRUD under `/api/x` (no create/edit pages).
- `authRoutes()` — the whole login system (see PART 10).

Routes file: `routes/web.php`. API and web share this one file.

---

## PART 7 — Middleware (CSRF + security headers are already ON; the rest is opt-in)

Every route declared in `routes/web.php` runs in the ambient `web` group, which is
`[CsrfMiddleware, SecurityHeaders]` by default (`core/App.php`). Routes under `/api/...`
run in the `api` group, which is empty by default. Do NOT add the CSRF or headers
middleware again — you would run them twice.

- Redefine a group: `\Widdx\Router::middlewareGroup('web', [\Widdx\CsrfMiddleware::class]);`
- Add a truly global middleware (every route, any group): `\Widdx\Router::use(SomeMw::class);`
- Protect one route with login: `->middleware(\Widdx\AuthMiddleware::class)`
- Rate limit: `->middleware(new \Widdx\ThrottleMiddleware(60, 60))` — 60 requests per 60 seconds per IP.
- IP behind proxy: set `TRUSTED_PROXIES` in `.env` or throttling counts the proxy, not visitors.

CSRF warning: since CSRF is on for web routes from the first request, EVERY POST form needs:

```
<?= csrf_field() ?>
```

Missing it gives status 419. This breaks the site silently — add it to all forms.
(The built-in CRUD/auth forms already carry it.)
The field it prints is named `csrf_token`, NOT Laravel's `_token` — hand-writing
`<input name="_token">` also gives 419. Use the helper; never type the field by hand.

---
