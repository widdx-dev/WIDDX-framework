# Helpers, .env keys, CLI commands

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 17 — Other helpers (one line each)

- `config('app.locale')` — read config files.
- `env('DB_HOST', 'default')` — read .env.
- `db('users')->where(...)->get()` — query without a model.
- `cache('key')` read; `cache(['key' => $v], 3600)` store with TTL; `cache()->forget('key')`.
- `logger('error', 'text', $context)` — writes to storage/logs.
- `storage()->put('a/b.txt', $data)` / `storage()->get(...)` — files on the disk.
- `event('name', $data)` + `listen('name', fn…)` — app events, flushed at shutdown.
- `paginate(Post::query(), 20)` — array with items/total/page/pages/perPage/hasMore.
- `redirect('/x')` `back()` `abort(404)` `transaction(fn() => …)`.
- `response('text', 200)` `Response::json($data)` `Response::redirect('/x')`.

---

## PART 18 — Every .env key (copy this block, delete what you don't need)

```
DB_CONNECTION=sqlite
DB_DATABASE=./database.sqlite
DB_HOST= DB_PORT= DB_USERNAME= DB_PASSWORD=
APP_DEBUG=true
APP_LOCALE=en
APP_URL=
CACHE_DRIVER=file
LOG_CHANNEL=file
QUEUE_DRIVER=sync
MAIL_DRIVER=log
MAIL_FROM= MAIL_HOST= MAIL_PORT=587 MAIL_USERNAME= MAIL_PASSWORD= MAIL_ENCRYPTION=tls
SECURITY_FRAME_OPTIONS=SAMEORIGIN
TRUSTED_PROXIES=
```

- Production checklist: set `APP_DEBUG=false`, `TRUSTED_PROXIES` behind any proxy, real DB credentials.
- A relative `DB_DATABASE` (the shipped `./database.sqlite`) is resolved against the project
  folder, never against the current directory — so `php /full/path/widdx schedule:run` from cron
  uses the same file the website does. Never assume a bare relative path elsewhere.
- Full key list with comments also lives in `.env.example`.

---

## PART 19 — CLI commands (what each one does)

- `php widdx new folder` — create a project. `new:api` — JSON-flavored one.
- `php widdx serve --port=8000` — dev server. `--host=0.0.0.0` when it runs inside a container.
  One long-lived worker: it does not reliably see a file it already served, so restart it after
  editing routes, models, controllers or views.
- `php widdx migrate` / `migrate:rollback` — run/undo table changes.
- `php widdx db:seed` / `db:wipe` — fill test data / drop tables.
- `php widdx make:crud Name field:type…` — THE main tool (PART 5).
- `php widdx make:model|controller|middleware|migration|seeder|view Name` — single generators.
- `php widdx make:resource Name` — model+controller+migration+views the classic way.
- `php widdx make:auth` — installs the whole login system.
- `php widdx route:list` — show every route.
- `php widdx test` — run all framework tests.
- `php widdx queue:work|push|failed|retry` — PART 14.
- `php widdx schedule:run` — PART 15.

---
