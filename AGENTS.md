# Widdx — AI Agent Handbook

Two layers, and only one of them is binding.

- **This file**: the recipe, the LAWS, the file map, the command order, the definition of done, and the
  router. It is short on purpose — a long file is a file a model stops following.
- **`.agents/*.md`**: the reference. Open exactly the one your task needs (ROUTER below). Never read all of them.

One line = one fact. Version: v0.3.8. Every claim here is measured (unit suite + integration suite; the runner
prints its own totals), not assumed.

---

## PART 0 — Small model? Do EXACTLY these five lines

```
cd <the-unzipped-widdx-folder>
php widdx new myapp --demo
cd myapp
php widdx migrate
php widdx serve
```

This is a LOCAL demo: `--demo` deliberately exposes the sample users CRUD to guests. For real applications, omit `--demo`; the default scaffold protects CRUD until authentication and policies are configured. Never deploy a `--demo` scaffold unchanged.

To add any list of records (inside `myapp`) — ALWAYS two commands + a restart:

```
php widdx make:crud Product name:string price:float
php widdx migrate
```

`migrate` is NOT optional: `make:crud` writes the migration file, it does not run it. Then STOP and START the
dev server again (Ctrl-C on the `php widdx serve` window, then run it). Reason, measured on PHP 8.3 and 8.5:
`php widdx serve` is one long-lived worker, and after it has served its first request an edit to a file it
already loaded **is NOT reliably seen** — stale in 9/9 trials when the edit landed more than a second later,
while a same-second edit sometimes worked. That is the trap: it can look fine in a demo and then 404.
**Restart is the whole fix** — never rewrite working code over a 404. New files the worker never loaded are fine.
The page then lives at `/products` — always plural. If a step fails: read its error, fix THAT step, run it again.

---

## PART 2 — The five golden rules

1. Before writing code, check if the framework already does it (`.agents/07-inference.md`).
2. Prefer one model file + one route line over a controller + views.
3. Templates (`.php` in `views/`) run in the GLOBAL namespace: `<?= e(auth()->name) ?>` is RIGHT,
   `<?= \Widdx\Auth::user()->name ?>` is a fatal error.
4. Escape all user data: `<?= e($value) ?>`. Always. In every template.
5. When unsure, run `php widdx test` — the answer is inside the test files.

---

## PART 2A — THE LAWS (non-negotiable; read before writing a single line)

Not advice. A task that breaks one of them is a FAILED task, even when the page renders.

- **L1 — NEVER edit `core/` from inside a project.** It is the framework, not your app. If you think it is
  wrong: keep your workaround in `app/`, in one place, and report the file, the line, and the command output
  that proves the bug. Do not patch core, do not copy a core file into the project and edit that.
- **L2 — Call only what `.agents/12-api-surface.md` lists.** `Model` has no `__callStatic`, so a guessed
  `Post::pluck()` is a fatal error, not a soft failure. Guessing a name from Laravel is a law break even when
  it happens to work. The two most-guessed names that are NOT real: `Model::update()` and `Model::destroy()`.
- **L3 — NEVER hand-write what a generator emits** (`make:crud`, `make:model`, `make:auth`, `make:view`):
  hand copies miss the parts you did not know were required — the `csrf_field()`, the `_method` field, `->public()`.
- **L4 — Every template value goes through `e()`; every POST form carries `<?= csrf_field() ?>`.**
  A missing field is a silent 419 — the page looks perfect until someone presses submit. The input
  is named `csrf_token`, never Laravel's `_token`; use the helper, never type the input by hand.
- **L5 — NEVER rename what the framework infers**: tables plural, models singular PascalCase, controllers
  `XController`, pivots alphabetical (`permission_role`), FK columns `<name>_id`. A rename breaks inference and
  the failure looks unrelated to the rename.
- **L6 — No SQL built by concatenation.** Use the model, the builder, or bound parameters.
- **L7 — Build what was asked, nothing else.** No extra feature, no new dependency, no drive-by refactor.
  Comments in the language the project's comments already use.
- **L8 — NEVER put logic in `routes/web.php`.** A route action is `[XController::class, 'method']` or a
  redirect. No DB call, no loop, no HTML built as a string in a closure.
- **L9 — NEVER add a top-level folder, a scratch file, or a copy of a framework file.** No `test.php`,
  `debug.php`, `x.bak`, `notes.md` left behind; delete your own scratch before you report done.
- **L10 — Put the fix where the file owns it.** Broken view → fix the view. A file you did not need to
  change, you did not change.
- **L11 — The order IS part of the task:** name the files you will touch → check the inference list and the
  cheat sheet → run the generator → `php widdx migrate` → restart `php widdx serve` → prove it with `curl -i`
  (status AND body) → only then report. Nothing skipped, nothing reordered.
- **L12 — When it fails, read the error; fix THAT step; run it again.** A 404 or a stale page right after an
  edit is the dev-server worker (PART 0), not your code: restart before you change anything. Rewriting working
  code over a stale 404 is how an agent destroys a project.
- **L13 — Say what you cannot do.** Blocked, impossible, or ambiguous about the data model: stop and state it
  in one sentence with the command output. Never invent a workaround silently, never report "done" for a page
  you did not load.

### Law of file placement — one kind of code, exactly one folder

| The code you are writing | The ONLY place it goes |
|---|---|
| a table + its rules | `app/Models/Post.php`, `namespace App\Models` |
| logic bigger than a redirect | `app/Controllers/PostController.php` |
| a request guard | `app/Middleware/` |
| who may do what | `app/Policies/PostPolicy.php` — define all five abilities |
| a background task | `app/Jobs/` |
| HTML | `views/<plural-table>/<name>.php` — global namespace, no `use` |
| the chrome around a page | `views/layouts/app.php` |
| a repeated piece of HTML | `views/partials/` + `<?= component('partials.row', $data) ?>` |
| a schema change | `database/migrations/<ts>_create_x_table.php` |
| test data | `database/seeders/` |
| URLs | `routes/web.php` |
| cron + scheduled work | `routes/console.php` |
| settings | `config/*.php` or `.env` — NEVER a new config file elsewhere |
| translations | `lang/<locale>.php` |
| css / js / images | `public/assets/` (`/assets/js/x.js` = `public/assets/js/x.js`) |

### Definition of done — all six, or the task is not finished

1. `php -l` clean on every file you touched (`php -l widdx` for the CLI — it has no extension).
2. `php widdx migrate` reports nothing pending (or your task created no migration).
3. The dev server was restarted after your last edit.
4. `curl -i` on the real URL: the expected status and the expected text in the body — quoted, not assumed.
5. A POST form you added returns 200/302, not 419; `PUT`/`DELETE` forms carry the `_method` field.
6. Nothing in `core/` changed, no scratch file remains, no printed value skipped `e()`.

---

## SILENT FAILURES — these break without an error message

| What you did | What happens |
|---|---|
| Edited a loaded file, did not restart `serve` | 404 or the old page, unreliably |
| POST form without `<?= csrf_field() ?>` | 419, on submit only |
| `App\Models\Post` field missing from `$fillable` | row saves, column stays NULL, no error |
| `resource('posts')` typed by hand, no `->public()` | guests → `/login`, users → 403 (fail-closed on purpose) |
| Policy missing one ability | that ability 403s; undefined = denied |
| View override written as `views/post/index.php` | silently ignored — the folder is the PLURAL table: `views/posts/` |
| Trusted proxy not configured | throttling and `Request::ip()` count the proxy, not the visitor |

---

## ROUTER — open ONE file, not the handbook

| Your task | Read |
|---|---|
| what Widdx is, its philosophy, its version | `.agents/00-about.md` (PART 1) |
| what the framework infers from names (tables, FKs, form fields, pivots, view overrides, timestamps) | `.agents/07-inference.md` (PART 4) |
| first project, add a list of records, migrate, seed | `.agents/01-start.md` (PART 3, 5) |
| routes, groups, CSRF, middleware, rate limiting | `.agents/02-routes-middleware.md` (PART 6, 7) |
| models, query builder, relations, eager loading | `.agents/03-models-relations.md` (PART 8, 9) |
| login, register, password reset, Gate, policies | `.agents/04-auth.md` (PART 10) |
| validation rules, file and image uploads | `.agents/05-validation-uploads.md` (PART 11, 13) |
| templates, layouts, escaping, built-in page overrides | `.agents/06-views.md` (PART 12) |
| queues, jobs, scheduler, cron, mail, SMTP | `.agents/08-queues-scheduler-mail.md` (PART 14, 15, 16) |
| helper functions, every `.env` key, every CLI command | `.agents/09-helpers-env-cli.md` (PART 17, 18, 19) |
| deploy: VPS, shared hosting, `install.php`, permissions | `.agents/10-deploy.md` (PART 20) |
| something is wrong, or "user asks for X → what do I write" | `.agents/11-pitfalls.md` (PART 21, 22) |
| before calling ANY framework method or helper — the full callable list | `.agents/12-api-surface.md` (PART 23) |
| the measured traps: first-match router, `??` on relations, booleans, RTL, aggregates | `.agents/13-expert-notes.md` (PART 24) |

When in doubt: make the model, add ONE route line, run `php widdx migrate`, restart `serve`, open the page.
The framework probably already built what you were about to code.
