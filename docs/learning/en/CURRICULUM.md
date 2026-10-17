# Complete Widdx Curriculum — Teaching Programmers

[العربية](../ar/CURRICULUM.md)

> **Framework:** Widdx v0.3.8 · **Size:** approximately 65 training hours (12–14 weeks at 5 hours per week, or an intensive track) ·
> **Audience:** Programmers who know the basics of PHP or another programming language · **Language:** English.
>
> The framework test suite (`php widdx test`) is the reference for measured behavior. Each module points to its
> official reference in `AGENTS.md` and `.agents/*.md`. Reading the reference is part of the module:
> mastering Widdx includes mastering its handbook, rather than building from guesses.

---

## 1. Curriculum Philosophy

Widdx is not taught like a conventional framework. Its value lies less in how much code you write than in how much
code you do not need to write: `resource('posts')->public()` provides complete CRUD, while one `make:crud` command
generates a model, a table migration, and routes. Four principles apply throughout the curriculum:

1. **Predict, then run:** Before each demonstration, learners predict the table name, form controls, and resulting URL.
   They then execute the command and compare the outcome. Developing an intuition for inference comes first.
2. **Trap first:** Reproduce each silent failure from the handbook deliberately in the lab, then fix it.
   These traps matter because the framework may not announce them with an error.
3. **Diagnostics first:** No claim without evidence. Use `php widdx route:list`, `php -l`, `php widdx test`, and `curl -i`.
   The six-point definition of done is the agreement for every lab:
   1) lint every changed PHP file; 2) no pending migrations; 3) restart the server after the final edit;
   4) quote the actual HTTP status and expected body text; 5) valid POST forms return 200/302, not 419, and PUT/DELETE
   forms carry `_method`; 6) no changes to `core/`, no scratch files left behind, and no unescaped template output.
4. **The laws are binding:** The 13 laws in `AGENTS.md`, PART 2A, are not suggestions. Practice them from the first
   session and assess them explicitly in the capstone.

### Scope of This Curriculum — Honestly

This is a **learning path**, not a reproduction of every reference page. It teaches the most valuable application-building
skills and points to the reference rather than duplicating it. Detailed API names and edge cases belong in their source:

- [`AGENTS.md`](../../../AGENTS.md) and [`.agents/*`](../../../.agents): the binding handbook and detailed references for inference,
  routes, models, authentication, validation, templates, queues, deployment, pitfalls, the API surface, and expert notes.
- `.agents/12-api-surface.md`: the callable-name reference for global helpers, model statics, instance methods,
  QueryBuilder, Blueprint types, column modifiers, and template variables.
- `README.md`: fuller examples, including material not covered in depth by `.agents` alone.
- `tests/integration.php`: the executable specification. If prose disagrees with a test, inspect the tested behavior.

**Topics that require further reference reading:** the complete helper list (`session`, `config`, `env`, `response`,
`Response::json`, `storageRoutes`), every Blueprint type and modifier, `custom`/`dsn` database configuration in
`config/database.php`, and all generator options (`make:controller --resource`, `new:api`). Remember that **not every
class type has a generator**: there is no `make:job`, `make:policy`, `make:event`, or `make:mail`; jobs and policies are written manually.

---

## 2. Learning Outcomes

By the end of the curriculum, learners can:

1. Build complete CRUD — model, table, routes, and pages — with two commands, explaining what each generates and why.
2. Predict behavior from names: tables, foreign keys, pivot names, form controls, view overrides, access guards,
   timestamps, and RTL direction.
3. Design a relational schema through naming conventions, read and write with models and QueryBuilder,
   and eliminate N+1 queries through eager loading with `with()`.
4. Add routes, controllers, and custom templates only when necessary, recognizing when writing nothing is the right choice.
5. Secure an application with authentication, ability-specific policies, CSRF, security headers, rate limiting, and trusted proxies.
6. Add queues with retries, templated mail and SMTP, scheduled tasks with one cron entry, events, and caching.
7. Build a JSON API and understand JSON error semantics (400/401/403/404/419/422/429).
8. Deploy on a VPS or terminal-free shared hosting through `install.php`, using a production checklist.
9. Diagnose with the four tools instead of rewriting working code over a stale-worker 404.
10. Apply all 13 laws and supervise AI agents by checking their output against the definition of done and classifying violations.

---

## 3. Placement Test — Before You Begin

Ten questions; the passing threshold is 7/10:

1. What does `var_dump(2 === '2')` print, and why?
2. What does `namespace` do in PHP, and how does it relate to `use`?
3. Write an arrow function that takes two numbers and returns their sum.
4. Write a class with a protected typed property.
5. How do GET and POST differ in HTTP?
6. What do status codes 200, 302, 404, and 500 mean?
7. What is a foreign key in SQL, and why is it useful?
8. What purpose do sessions serve in a web application?
9. What do `cd` and `php -v` do in a terminal?
10. Write a simple `CREATE TABLE` statement with an identifier column and a text column.

- **7 or more:** Begin level one directly.
- **Below 7:** Spend a self-study foundation week on PHP 8 classes, namespaces, arrow functions, HTTP basics, and introductory
  SQL using a resource of your choice, then take the test again.

**Laravel developers:** Section 6 provides a dedicated track of approximately 35 hours. M2, M3, M10, and M21 cannot be
skipped: familiar assumptions are a major source of mistakes when switching to Widdx.

---

## 4. Curriculum Map

| Level | Modules | Hours | Reference Files |
|---|---|---|---|
| 1 — Getting Started | M1 Mental model, M2 First project and CRUD, M3 Inference engine | 6.5 | `.agents/00, 01, 07` |
| 2 — Data Layer | M4 Models, M5 Relationships, M6 Database | 8 | `.agents/03, 09, 12` |
| 3 — Web Layer | M7 Routes, M8 Controllers, M9 Templates, M10 Forms, validation, uploads | 10 | `.agents/02, 05, 06, 12` |
| 4 — Protection | M11 Authentication, M12 Authorization, M13 Built-in security | 6 | `.agents/02, 04` |
| 5 — Services | M14 Queues, M15 Mail, M16 Scheduler, events, storage | 6 | `.agents/08` |
| 6 — Professional Practice | M17 APIs, M18 Testing, M19 Extensibility, M20 Deployment, M21 Laws and AI agents | 10 | `.agents/09, 10, 11, 13` + `AGENTS.md` |
| — | Midpoint project (4) + bug-hunt exam (2) + capstone (12) | 18 | — |
| **Total** | | **≈ 64.5** | |

**Suggested rhythm: 12 weeks, approximately 5 hours per week.** Work on the capstone runs alongside the modules from week eight.

| Week | Content |
|---|---|
| 1 | M1 + M2 |
| 2 | M3 + level-one review |
| 3 | M4 |
| 4 | M5 |
| 5 | M6 + M7 |
| 6 | M8 + M9 |
| 7 | M10 + midpoint project |
| 8 | M11 + M12; begin the capstone |
| 9 | M13 + M14 |
| 10 | M15 + M16 + M17 |
| 11 | M18 + M19 + M20 + M21 + bug-hunt exam |
| 12 | Complete and present the capstone |

---

## 5. Module Details

Each module follows: objectives → lessons → lab → checkpoint → covered traps.

### Level One — Getting Started

#### M1: The Widdx Mental Model (1.5 hours) — Reference: `.agents/00-about.md` (PART 1) + README

**Objectives:** Explain “write minimal code — the framework infers the rest,” draw the project structure from memory,
and know which parts may be changed.

**Lessons:**
1. Widdx is a PHP 8.1+ framework inside your project folder. Composer is optional because of the fallback autoloader;
   no npm or build step is required. SQLite is the zero-configuration default.
2. Convention over configuration. Compare implementing the same feature in a conventional framework with multiple files
   against Widdx's route-based approach.
3. The project is the folder: tour `app/` (Models, Controllers, Middleware, Policies, Jobs), `views/`, `routes/`,
   `database/` (migrations, seeders), `config/`, `lang/`, `storage/`, `public/`, and `core/`.
4. L1: `core/` is the framework and must not be edited from an application. Read the entire “Law of file placement” table.
5. The two documentation layers: binding `AGENTS.md` contains the recipe, laws, placement map, definition of done, and router;
   `.agents/*.md` provides reference detail. Build the handbook habit on day one.

**Lab:** Check the environment (`php -v`, then `php -m | grep -i pdo`), open the framework repository, name each directory's
purpose, and play “Where does this code belong?” with ten questions about schemas, rules, controller logic, HTML, URLs, cron, and configuration.

**Checkpoint:** Draw the folder tree from memory and answer the eight placement questions without mistakes.

**Covered traps:** L1 (editing core), L9 (scratch files).

---

#### M2: First Project and First CRUD (2.5 hours) — Reference: `.agents/01-start.md` (PART 3, 5) + `AGENTS.md` PART 0

**Objectives:** Execute the starter recipe from memory, add a complete entity with two commands and a restart,
and demonstrate the result with `curl -i`.

**Lessons:**
1. Recipe: `php widdx new myapp` → `cd myapp` → `php widdx migrate` → `php widdx serve` → open
   http://localhost:8000. The users CRUD demo works immediately. Use `--no-demo` for a start without public demo CRUD.
2. `make:crud` is the main tool. Field types: `string text integer float bool date email url file image fk(Model)`;
   `?` makes a field optional. Example: `make:crud Invoice amount:float "company:fk(Company)" note:text?`
   generates a real foreign key, dropdown, and `exists:companies,id` rule.
3. Generated output: a model with `$fillable` and `$rules`, a migration, and
   `resource('products')->public();` appended to `routes/web.php`. URLs use plural names such as `/products`.
4. `migrate` is mandatory: `make:crud` writes migrations but does not execute them. The `migrations` table tracks execution.
5. **The major trap, taught before the first edit:** `php widdx serve` is a long-lived worker. Edits to loaded files are not
   reliably observed; the handbook records 9/9 stale trials. Restart before rewriting working code over a 404 (L12).
6. Binding work order (L11): name the files → generator → `migrate` → restart `serve` → prove the status and body with `curl -i` → report.

**Lab:** Products with an image (`make:crud Product name:string price:float photo:image`), then invoices with `fk(Company)`
after creating Company. Prove GET `/products` returns 200 and expected body text, and valid POST returns 302 rather than 419.

**Checkpoint:** Perform the complete recipe from memory in front of the instructor, then explain “Why migrate, then restart?”

**Traps:** Stale worker (9/9), missing migration execution, first introduction to 419; details in M10.

---

#### M3: The Inference Engine — Widdx's Core Idea (2.5 hours) — Reference: `.agents/07-inference.md` (PART 4)

**Objectives:** Predict framework behavior from names, rules, and files accurately enough to answer 20 inference questions.

**Lessons:** Teach the 24 inference rules in six groups:
1. **Name → table:** `Post` → `posts`, `OrderItem` → `order_items` (snake_case plus pluralization). Renaming conventions
   breaks inference, and the resulting failure can look unrelated (L5).
2. **Rule → form:** `string` → text input; `text` → textarea; `bool` → checkbox plus hidden 0; `in:a,b` → select;
   `file/image` → file input; a `*_id` column with a corresponding model → dropdown. Forms use `$rules` and actual columns.
3. **File → override:** `views/posts/index.php`, always plural, overrides the built-in page. A singular folder is silently ignored.
4. **Relationship → key:** `hasMany(Player)` implies `team_id` on players; Role's `belongsToMany(Permission)` implies
   the alphabetically named pivot `permission_role`.
5. **Presence → protection:** A `BookPolicy` under `app/Policies/` guards CRUD. Without `->public()` or a policy,
   guests go to `/login` and logged-in users get 403. Deny is the default.
6. **Environment → behavior:** Having `created_at` and `updated_at` enables automatic timestamps in `save()`;
   `APP_LOCALE=ar` gives built-in pages `dir="rtl"`.

**Lab — predict, then run:** For ten `make:crud` commands, predict tables, controls, URLs, and foreign keys, execute them,
and compare. Put an override in `views/product/index.php`, observe it being ignored, then move it to `views/products/`.

**Checkpoint:** A 20-question written test on table names, form controls, URLs, pivot names, and foreign keys. Pass: 16/20.

**Traps:** Singular template directory, locked-by-default resources, renaming that breaks inference.

---

### Level Two — The Data Layer

#### M4: Models in Depth (3 hours) — Reference: `.agents/03-models-relations.md` (PART 8) + `.agents/12-api-surface.md` (PART 23)

**Objectives:** Read and write using the model's actual API, respect its boundaries, and explain silent behavior.

**Lessons:**
1. Smallest conventional model: `make:model Post`, then `$fillable` and `$rules`. No explicit `$table` is needed.
2. Reading: `all()`, `find()`/`findOrFail()` (404 page), `where()` with `= != < <= > >= IN NOT IN BETWEEN`,
   `query()->orderBy()->limit()->get()`, `count()`, and `exists()`.
3. Writing: `create()` validates `$rules`, respects `$fillable`, and manages timestamps; `$m->fill([...])->save()`,
   `$m->delete()`, and `firstOrCreate()`/`updateOrCreate()` provide other write paths.
4. **Silent trap:** A field outside `$fillable` may be silently dropped while the row saves and its column remains NULL.
5. Memorize the boundaries: no `Model::update()`, `Model::destroy()`, `pluck()`, or `truncate()`.
   Update through `fill()->save()` or `Model::where(...)->update([...])`; the latter is QueryBuilder and bypasses `$rules`,
   `$fillable`, and automatic `updated_at`, refusing execution without `where()`. `columns()` is an instance method;
   `Post::columns()` is fatal.
6. Timestamps require both columns, which generators include. Disable with `protected bool $timestamps = false;`
   (opt-out only). Use `Model::forgetSchemaCache('table')` after raw-PDO DDL.

**Lab:** Work with a Post model: different where expressions, creation, and both update approaches. Reproduce three traps:
an unfillable field, an invented static call, and a builder write without where. Record the exact outcomes and errors.

**Checkpoint:** Ten “exists / does not exist” API questions, plus translating five Laravel snippets using unsupported names
into valid Widdx code.

**Traps:** Silent unfillable values, invented fatal calls, builder writes without where.

---

#### M5: The Four Relationships (3 hours) — Reference: PART 9 + `.agents/13-expert-notes.md` (4–6, 21)

**Objectives:** Design a conventional relational schema, use lazy and eager loading, and understand return shapes.

**Lessons:**
1. `hasMany`, `hasOne`, `belongsTo`, and `belongsToMany`: one line per relationship without explicit conventional keys.
   `Team hasMany Player` implies `players.team_id`; `Role belongsToMany Permission` implies pivot `permission_role`.
2. Lazy access: `$post->comments` queries once and caches the result. `isset()` and `??` recognize relationships in v0.3.7+,
   so a null-coalescing default no longer hides a relationship merely because it has not been loaded.
3. Return shapes: hasMany/belongsToMany → array (`count()` works); hasOne/belongsTo → object or null (`count()` causes TypeError).
4. Eager loading: `Post::with('comments.author')->get()`. Dots express nesting. Measure the total query count, including
   the parent query; accessing relationships already loaded by `with()` should not add queries.
5. `belongsTo(Role::class)` without `app/Models/Role.php` produces a message naming the missing file in v0.3.8.
   Create the model or correct its name rather than rewriting the relationship.

**Lab:** Implement Team/Player/Role/Permission with a pivot. Measure queries before and after `with()`.
Deliberately call `count()` on belongsTo and record the TypeError; reference a missing model, read the error, then create it.

**Checkpoint:** Design a school schema on paper (classes, students, subjects, teachers, and their cardinalities), naming
all foreign keys and pivots. Implement it with `make:crud` and demonstrate the dropdowns.

**Traps:** N+1, count TypeError, `??` on relationships, missing related classes.

---

#### M6: Databases Beyond SQLite (2 hours) — Reference: `.agents/09-helpers-env-cli.md` (PART 18) + README §6

**Objectives:** Switch database engines without changing application code, use `db()` with awareness of its limits,
and manage migrations and data.

**Lessons:**
1. SQLite is the zero-configuration default. Change to MySQL/PostgreSQL through `.env`; identifier quoting and LIMIT/OFFSET
   are adapted. CI covers SQLite/MySQL/PostgreSQL; SQL Server and Oracle are best-effort.
2. `db('users')` provides a model-free QueryBuilder with the same limits: no `sum/avg/min/max/groupBy/join/pluck`.
   Aggregate fetched data in PHP or use raw PDO through `Database::connection()->pdo()` when appropriate.
3. Named connections: `Database::connection('mysql')->table('users')->get()` accesses another database connection.
4. `transaction(fn() => …)`, `migrate:rollback`, and `db:seed` with seeders in `database/seeders/`.
   `db:wipe` drops all tables; never run it against data without a backup and authorization.
5. Relative `DB_DATABASE` paths resolve against the project directory, not the shell's working directory, including cron use.

**Lab:** Move the M5 project to MySQL or PostgreSQL without changing application code. Write a seeder, roll back a migration,
modify it, and run it again in the lab environment.

**Checkpoint:** Explain when to use `get()` plus PHP and when raw PDO is appropriate. Demonstrate the unchanged application
working after switching engines.

**Traps:** Guessing unsupported builder operations, `db:wipe`.

---

### Level Three — The Web Layer

#### M7: Routes (2.5 hours) — Reference: `.agents/02-routes-middleware.md` (PART 6) + Expert note 1

**Objectives:** Write a clean, ordered routes file, understand resource/apiResource guards, and interpret `route:list`.

**Lessons:**
1. `get/post/put/patch/delete`; `{id}` becomes a named argument. String returns become HTML; arrays become JSON.
   Name routes with `->name()` and generate URLs with `route('x.y', ['id' => 2])`.
2. `Router::group(['prefix' => …, 'middleware' => …], fn)`; the `api` middleware group is empty by default.
3. `resource('x')` provides seven routes; `apiResource('x')` provides JSON under `/api/x` without pages.
   Access can be governed by a policy or explicitly opened by `->public()` or `protected bool $public = true` in a controller.
   Web resources without a policy or public declaration redirect guests to `/login` and deny logged-in users with 403.
4. **First match wins:** `post('/login', …)` before `authRoutes()` takes precedence. Place specific routes before general ones.
5. `php widdx route:list` is the first diagnostic for routing problems. L8: no business logic, database calls, or generated
   HTML inside `routes/web.php`; use `[XController::class, 'method']` or a redirect.

**Lab:** Build routes with specific entries before `authRoutes()`, an admin group with a prefix and AuthMiddleware,
a JSON route, a named URL through `route()`, and a demonstration of first-match precedence followed by its correction.

**Checkpoint:** From `route:list` output alone, identify which route receives `POST /login` and explain why.

**Traps:** Registration order, locked hand-written resources, business logic in web.php.

---

#### M8: Controllers (2.5 hours) — Reference: PART 6 + README §13 + L3/L8/L10

**Objectives:** Know when a controller is needed and when it is unnecessary; implement custom search and sorting properly.

**Lessons:**
1. Ordinary CRUD needs no controller. L3: do not hand-write generated boilerplate. Controllers become useful for
   search/filter/sort, special display logic, or non-CRUD interfaces.
2. Route action form: `[PostController::class, 'index']`; models can be injected into method parameters automatically.
3. An empty `class PostController extends CrudController {}` is enough for conventional CRUD; its model is inferred from
   the name. Four properties can replace seven methods. `make:resource` generates the classic controller-and-views approach.
4. Request access: `request()->input()/all()/validate()/file()/json()/bearerToken()/ip()`.

**Lab:** Give `PostController` an `index()` that searches by title and sorts by date. Copy `core/views/crud/index.php` to
`views/posts/index.php` and customize it. Do not use `view('crud.index')` from an application: view lookup only searches
that application's `views/` and throws when the file is missing.

**Checkpoint:** Search works both with and without its URL parameter, demonstrated with `curl -i`.

**Traps:** Needless hand-written CRUD (L3), `view('crud.index')` from an application.

---

#### M9: Templates (2.5 hours) — Reference: `.agents/06-views.md` (PART 12) + Expert notes 15, 16, 19, 20 + L4

**Objectives:** Write escaped templates, override CRUD pages, and use layouts and RTL correctly.

**Lessons:**
1. Templates execute in the **global namespace**. Follow the handbook's helper-only convention rather than relying on
   controller imports: use `auth()`, not an unqualified `Auth::user()` call.
2. Apply `e()` to every displayed value (L4). Demonstrate an XSS attempt through a name field containing `<script>`.
3. CRUD variables: index receives `$items, $pagination, $routeBase, $title, $columns, $fields`;
   create/edit/show receive the corresponding page data and `$item`, which is null on create.
4. `layout('layouts.app', $data, $content)` and `component('partials.row', $data)`.
   The generated layout has **no sections/slots/push system**; put page scripts in the page file.
5. Overrides: copy the required view from `core/views/crud/` to `views/<plural-table>/`; your file wins automatically.
   Error pages use `views/errors/404.php` and `500.php`.
6. RTL: `APP_LOCALE=ar` (also he/fa/ur/ps/sd/ug/yi/ku/dv) gives built-in pages `<html lang="ar" dir="rtl">`
   through `lang_attrs()`. In a custom layout, add `<?= lang_attrs() ?>` to `<html>` yourself.
   L9 prohibits unnecessary top-level directories and scratch files.
7. `public/` is the web root: `/assets/js/x.js` maps to `public/assets/js/x.js`, not to a file inside `views/`.

**Lab:** A custom products index with correct edit/delete forms (`_method`), a custom layout, an XSS attempt neutralized
by `e()`, and RTL enabled throughout the application.

**Checkpoint:** Show the custom template working, reproduce the singular-directory silent failure, and explain it orally.

**Traps:** `Auth::user()` in a template, missing e(), singular directories, incorrect asset paths.

---

#### M10: Forms, Validation, and Uploads (2.5 hours) — Reference: `.agents/05-validation-uploads.md` (PART 11, 13) + Expert notes 8–12

**Objectives:** Write validation rules with correct semantics, manage the failure cycle (redirect, old input, errors),
and perform and validate uploads.

**Lessons:**
1. Rules: `required nullable string int float bool numeric array email url min max size between in confirmed same different
   exists unique file image mimes`. Min/max measure string length, numeric value, or file size in **kilobytes**.
   `string` counts characters even for a numeric-looking value: `'100'` passes `string|size:3`.
   Numeric semantics depend on `int/numeric/float` in the rule set.
2. Database-aware rules: `unique:users,email` with a third ID segment to ignore the current row during updates,
   and `exists:table,column`, generated for foreign-key fields.
3. Custom messages use `field.rule` keys; default messages follow `APP_LOCALE`, with en/ar included.
4. Validation failures redirect automatically. Templates use `old('field')`, `errors()`, `flash()`, and `back()`.
   `back()` only follows an allowed same-host Referer; an external one falls back to `/`.
5. Every POST needs `<?= csrf_field() ?>`. Its field is named `csrf_token`, not `_token`; a hand-written wrong name also
   leads to 419. A form can look perfect until someone submits it.
6. One `image` rule gives a file input, storage under `storage/app/`, and a path in the column. Updating without a new file
   preserves the old path. `file/image/mimes` intentionally allow stored path strings during revalidation; do not “fix” that.
   UploadedFile methods include `store() originalName() extension() mimeType() isImage() sizeKb()`;
   consult storage serving configuration when using `storage()->url($path)` for display.
7. **Three manual form companions:** Put `<input type="hidden" name="x" value="0">` before a checkbox so unchecking saves;
   PUT/DELETE forms need `_method`; POST forms need csrf_field.

**Lab:** Customize a generated product form with csrf, old input, errors, an optional image, a unique SKU with the current
ID ignored during updates, and a checkbox with its hidden companion.

**Checkpoint:** A small bug hunt: repair a form with missing CSRF, a checkbox without its companion, and unique validation
without the ignored ID. Demonstrate the corrected outcomes with `curl -i`.

**Traps:** 419, unique without ignore, unchecked checkbox not saved, `_token` instead of `csrf_token`, “fixing” stored-file rules.

---

### Level Four — Protection

#### M11: Authentication (2 hours) — Reference: `.agents/04-auth.md` (PART 10) + README §16

**Objectives:** Install authentication with one generator, protect routes, and understand why users CRUD becomes locked.

**Lessons:**
1. `make:auth` installs `authRoutes()` and routes `/register /login /logout /forgot-password /reset-password /email/verification`
   with built-in views and validation: bcrypt password hashing, protected single-use tokens (one hour for reset, seven days
   for verification), anti-enumeration responses, session regeneration, and automatic login after registration.
2. `make:auth` removes `->public()` from `resource('users')`. Once users becomes the authentication table, public CRUD
   is an account-takeover path. Fail-closed is intentional. The generated `UserSeeder` seeds no password;
   create the first real account through `/register`.
3. Code API: `Auth::attempt([...])`, `Auth::register([...])` with automatic hashing, `Auth::check()`, and `Auth::logout()`.
   In templates, always use `auth()`.
4. Protect routes or groups with `->middleware(\Widdx\AuthMiddleware::class)`.

**Lab:** Install authentication on the M6/M8 project, protect `/dashboard`, and prove guests redirect to /login while
successful authentication permits 200. Customize `views/auth/login.php`.

**Checkpoint:** Document registration → logout → login → forgot password through the log driver → password reset,
with the actual `curl -i` status for each step.

**Traps:** Public CRUD on the authentication table, `Auth::user()` in templates.

---

#### M12: Authorization — Gate and Policies (2 hours) — Reference: PART 10 + Expert note 13

**Objectives:** Implement “only administrators can delete” and write a policy covering all abilities.

**Lessons:**
1. `Gate::define('publish', fn($u) => $u->is_admin)`, `authorize('publish')` (403, or JSON 403 for apiResource), and `Gate::allows()`.
2. `app/Policies/BookPolicy.php` is discovered for Book by convention. The five abilities are
   `viewAny view create update delete`. **Undefined means denied**; missing abilities yield 403.
   Policy presence automatically guards the built-in CRUD pages.
3. Per-record context: `update($user, Book $book)`; ownership can compare `$book->user_id === $user?->id`.
4. When installing the policy, remove `->public()` from the resource; the policy becomes the guard.

**Lab:** Add `is_admin` to User and a complete BookPolicy: viewAny/create for everyone, update for the owner,
and delete for administrators. Remove public access and test three sessions — guest, ordinary user, administrator —
recording a 15-cell matrix (3 roles × 5 abilities) with real curl results.

**Checkpoint:** Every cell in the access matrix matches its intended result.

**Traps:** Missing ability → 403; forgetting to remove `->public()`.

---

#### M13: Built-in Security (2 hours) — Reference: PART 7 + README §8, §10 + L6

**Objectives:** Explain the default security layers and configure the production-specific parts.

**Lessons:**
1. CSRF is already enabled in the `web` group (`[CsrfMiddleware, SecurityHeaders]` in `core/App.php`).
   Registering `Router::use(CsrfMiddleware::class)` runs it twice. Change a group with `Router::middlewareGroup('web', [...])`.
   The `api` group is empty by default.
2. Security headers are enabled; configure `SECURITY_FRAME_OPTIONS` where needed.
3. Rate limiting: `->middleware(new \Widdx\ThrottleMiddleware(60, 60))` means 60 requests per 60 seconds per IP.
4. **Trusted proxies:** `Request::ip()` ignores `X-Forwarded-For` unless the peer is trusted through `TRUSTED_PROXIES`.
   Without correct proxy configuration, requests can be counted against the proxy instead of the visitor;
   blindly trusting forwarded headers would allow spoofing.
5. Production: `APP_DEBUG=false`; `install.php` locks after successful setup and refuses to overwrite `.env`.
   `e()` escapes template output. L6: never concatenate SQL; use models, builders, or bound parameters.

**Lab:** Apply a low rate limit to a local search route and demonstrate 429. Test a forged forwarded-IP header before
and after trusted-proxy configuration in a controlled environment, and inspect response security headers with curl.

**Checkpoint:** Explain why X-Forwarded-For cannot be implicitly trusted and demonstrate correct configuration.

**Traps:** Duplicate middleware, missing TRUSTED_PROXIES behind a proxy, APP_DEBUG=true in production.

---

### Level Five — Services

#### M14: Queues and Jobs (2 hours) — Reference: `.agents/08-queues-scheduler-mail.md` (PART 14) + README Queues

**Objectives:** Write and dispatch a Job, handle retries and failure, and understand daemon-free processing.

**Lessons:**
1. A job extends `Widdx\Job` and implements `handle(array $data): void`. Dispatch with `queue(Job::class, $data)`,
   `Job::dispatch($data)`, or `dispatchLater(60, $data)`. Set `->onQueue('emails')` on a Job instance before pushing it;
   `dispatch()` returns a string, not a chainable Job instance.
2. Drivers: `sync` runs immediately and suits development/shared-hosting scenarios; `database` supports background processing.
3. Per-job policy: `$tries` defaults to 3; backoff is `$backoff * 2^(attempt-1)`.
   Exhausted jobs move to `failed_jobs` with payload, failure reason, and time.
4. `php widdx queue:work --max=50` **exits when the queue is empty by design**. Do not change it to run forever;
   schedule it with cron. Management commands: `queue:failed`, `queue:retry <id>|all`, and `queue:push`.

**Lab:** Dispatch an order-receipt job after invoice creation. Then use an always-failing job to observe attempts,
increasing backoff, and failed_jobs; fix the cause, retry, and demonstrate success.

**Checkpoint:** Explain sync versus database and when daemon-free operation is appropriate; take a failed job through recovery.

**Traps:** Expecting an immortal worker, ignoring failed_jobs.

---

#### M15: Mail (1.5 hours) — Reference: PART 16 + README Mail

**Objectives:** Send templated mail in development through log and in production through SMTP, and connect it to events or queues.

**Lessons:**
1. `mail('to@site.com', 'Subject', 'template', ['name' => 'Ali'])`; the default log driver writes mail to `storage/logs`,
   where development and test output can be inspected.
2. Production uses `MAIL_DRIVER=smtp` and its configuration keys. The socket-based SMTP client supports STARTTLS, ssl,
   and AUTH LOGIN without external libraries; an empty host uses the shared-hosting `mail()` fallback.
3. A Mailable exposes `build(): array`. The builder uses `Mail::to()->cc()->replyTo()->subject()->deliver(...)`,
   or `->send()` on the object. Background delivery uses `Mail::queue(...)`.
4. Header values containing CR/LF throw MailException to prevent header injection; this is intentional.

**Lab:** Send welcome mail through `listen('user.registered', ...)`, inspect it in the log, move it into a background job,
and connect it to the real M11 registration event.

**Checkpoint:** A message appears in the log with the translated Arabic template and correct data.

**Traps:** Waiting on SMTP during development instead of using log, missing mail templates in `views/`.

---

#### M16: Scheduler, Events, and Storage (2.5 hours) — Reference: PART 15, 17

**Objectives:** Schedule work with one cron entry and use events, caching, and file storage.

**Lessons:**
1. Define schedules in `routes/console.php`: `Schedule::command('queue:work --max=50')->everyMinute()` and
   `Schedule::call(fn() => ...)`. Frequencies include `everyMinute everyFiveMinutes … hourly daily monthly`,
   `interval(seconds)`, and conditions through `->when(fn() => ...)`. Run with `php widdx schedule:run`.
2. One crontab entry: `* * * * * php /full/path/widdx schedule:run`. State is stored in `storage/app/schedule.json`.
   Check repeated invocations within the schedule window; do not infer a general exactly-once guarantee from the state file.
3. Events: `listen('user.registered', fn...)`, then `event('user.registered', $user)`. Listeners flush at request shutdown.
   Sending mail after registration is the standard example.
4. Helpers: `cache('k')`, `cache(['k' => $v], 3600)`, and `cache()->forget('k')` with file/array drivers;
   `logger('error', 'msg', $ctx)`; `storage()->put('a/b.txt', ...)/get(...)`;
   `paginate(Post::query(), 20)` returns items/total/page/pages/perPage/hasMore; also `abort()`, `redirect()`, and `back()`.

**Lab:** Schedule daily cleanup of old test records. Run `schedule:run` twice consecutively and verify the expected
non-repetition within that window. Cache a slow report and invalidate it after updates; use paginate in a custom template.

**Checkpoint:** Write the correct cron entry, show window behavior, and explain scheduling queue processing versus scheduling a task directly.

**Traps:** Expecting a scheduler daemon, assuming repeat behavior without understanding schedule.json.

---

### Level Six — Professional Practice

#### M17: Building JSON APIs (2 hours) — Reference: PART 6 + README §12, §13

**Objectives:** Build a complete API resource and test it with curl using correct error semantics.

**Lessons:**
1. `apiResource('products')` provides JSON CRUD at `/api/products`, without create/edit pages, using the same public/policy
   guard. Without an applicable policy or public declaration, logged-in callers receive JSON 403.
2. Returning an array produces JSON. `request()->json()` reads JSON bodies; malformed input produces a clean 400.
3. `expectsJson()` recognizes JSON expectations through Accept/Content-Type or `/api` paths, returning errors such as
   401/404/419/422 as JSON rather than HTML.
4. `request()->bearerToken()` extracts a token; extraction alone does not authenticate the caller.
   Use ThrottleMiddleware for rate limits, and `core/CorsMiddleware` with `config/cors.php` for CORS configuration.

**Lab:** Create a products apiResource and a custom search endpoint. Record curl tests for list 200, creation 201/200,
invalid input 422 JSON, rate limit 429, and missing resource 404 JSON.

**Checkpoint:** A ten-request curl script with successful and failing cases documented by status and body.

**Traps:** Expecting HTML error pages from an API, forgetting the apiResource access guard.

---

#### M18: Testing and Quality (2 hours) — Reference: README Testing + `tests/`

**Objectives:** Run the suite, read tests as an executable specification, and practice evidence before claims.

**Lessons:**
1. `php widdx test` runs unit and end-to-end integration coverage: a real project created by `widdx new`, a real
   `php -S` server, and HTTP requests for CRUD, `_method` overrides, validation flash/old input, CSRF (419/302),
   404/405, authentication session persistence, database-aware validation, translations, security headers, rate limiting,
   queues, generators, and SMTP against a programmable test server.
2. `tests/integration.php` is an executable specification; reading a relevant test is often the fastest way to understand behavior.
3. `widdx-test` refuses to start if required PDO support is missing and names the extension, separating environment failures from code failures.
4. PHPStan level 1 and CI cover PHP 8.1–8.5 with SQLite/MySQL/PostgreSQL.
5. Every lab ends with `curl -i`: this is the same habit of disciplined manual verification.

**Lab:** Run the suite and read its output. Explain what three integration tests establish, then write a repeatable
manual verification script with sequential curl requests for the M17 API.

**Checkpoint:** Given a simulated failure message, identify the tested behavior and a plausible cause.

**Traps:** Trusting a page's appearance without checking status and body.

---

#### M19: Extensibility — Contracts and Service Providers (2 hours) — Reference: README Contracts + Service Providers

**Objectives:** Replace a framework service through a contract without editing `core/`, and know when not to extend anything.

**Lessons:**
1. The container follows PSR-11 (`get/has`); bind services with `Container::getInstance()->singleton(...)`.
2. Eight contracts in `core/Contracts/`: Cache, Logger, Storage, Session, Auth, Event, Queue, Mail.
   Bundled implementations include FileCache/ArrayCache, FileLogger, LocalStorage, SyncQueue/DatabaseQueue,
   and LogMail/SmtpMail. Replace an implementation by binding a singleton to its interface.
3. ServiceProvider uses `register()` for bindings and `boot()` after registration is complete.
4. Extend in `app/` through contracts. Copying a core implementation and modifying it violates L1 even if it appears to work.
   Ask first whether the extension solves the actual request: L7 rules out unnecessary extensibility.

**Lab:** Implement a custom LoggerInterface formatter, register it through a service provider, and prove that the
`logger()` helper routes through it.

**Checkpoint:** Show output proving the replacement. Explain what to do if a problem appears to require a core edit:
a single application-level workaround where permitted, plus a report containing the source file, line, and observed output.

**Traps:** Copying and modifying core, extending without a requirement.

---

#### M20: Deployment (2 hours) — Reference: `.agents/10-deploy.md` (PART 20) + README Deploy

**Objectives:** Deploy on a VPS or shared hosting without a terminal, with a complete production checklist.

**Lessons:**
1. **VPS/SSH:** Point the document root at `public/`; run `php widdx migrate`; configure cron for `schedule:run`;
   set `APP_DEBUG=false`. Use the intended database, configure `TRUSTED_PROXIES` behind proxies, and disable public demo access.
2. **Shared hosting without a terminal:** Upload the full project (ZIP and Extract in cPanel), create a MySQL database
   and user, then open `https://site/install.php`. It checks PHP/PDO/storage permissions, tests the database first,
   writes `.env`, runs migrations, and locks after success. Subsequent access returns 404 and an existing `.env` is not overwritten.
3. Make `storage/` and its four subdirectories writable, commonly with 755 or 775 as appropriate to hosting ownership.
4. cPanel cron uses the same commands: `schedule:run` each minute, and `queue:work --max=50` for queued work.
5. Always back up data before any authorized `db:wipe`.

**Lab:** Deploy the lab application on a VPS, or simulate install.php locally. Demonstrate that the application works
after installer locking and that rerunning the installer is refused.

**Checkpoint:** A completed production checklist with curl evidence from the deployed URL.

**Traps:** Setting the document root to the project root and exposing `.env`/`core/`, missing APP_DEBUG=false, expecting a daemon script.

---

#### M21: Laws, Discipline, and AI Agents (2 hours) — Reference: all of `AGENTS.md`

**Objectives:** Recall the 13 laws and their reasons, apply the definition of done, and review AI-agent output.

**Lessons:**
1. Review each law, the incident or constraint behind it, and how it can be violated:
   L1 no core edits; L2 no guessed APIs; L3 no hand-written generator output; L4 e() and CSRF;
   L5 preserve inference conventions; L6 no concatenated SQL; L7 no work beyond the request; L8 no business logic in web.php;
   L9 no scratch files; L10 fix the owning file; L11 follow the work order; L12 read and fix the failing step;
   L13 state what cannot be done.
2. Treat the six-point definition of done as the same continuing contract used since M2.
3. Revisit the handbook's silent-failure table, one scenario at a time.
4. `AGENTS.md` is a binding contract: explicitly ask the agent to read it and check whether the tool supports loading it;
   do not assume automatic loading. [AI_GUIDE.md](AI_GUIDE.md) contains the workflow and 15 ready-to-use prompts.
   Review actual output rather than trusting confidence. Common mistakes are invented Laravel names without `__callStatic`,
   skipped migration/restart steps, business logic in routes, rewriting code over stale 404s, and editing `core/`.
   The programmer owns the rules.
5. Four-part diagnostics: `route:list` for routing → `php -l` for syntax → `php widdx test` for tested behavior →
   `curl -i` for what the client actually receives.

**Lab:** Give an AI agent, or a peer acting as one with written instructions, a standard task on a clean project:
“Add products with an image and name search.” Review the output, classify each violation, fix it minimally (L10),
and demonstrate the result with all four tools.

**Checkpoint:** The full bug-hunt exam in section 7.

---

## 6. Laravel Fast Track

**Duration:** approximately 35 hours. M2, M3, M10, and M21 remain mandatory because familiar assumptions cause costly errors.

**Comparison table:**

| Laravel | Widdx | Critical Difference |
|---|---|---|
| `artisan make:model -m` plus controller and resource routes | `php widdx make:crud Name field:type…` | One command generates model, migration, and resource line |
| `php artisan serve` | `php widdx serve` | Long-lived worker: restart after edits |
| `php artisan migrate` | `php widdx migrate` | Same concept, tracked in the migrations table |
| Blade (`@foreach @if @csrf @method`) | Plain PHP templates, `csrf_field()`, `_method` | No directives; templates run in the global namespace |
| FormRequest / `$request->validate()` | Model `$rules` or `request()->validate()` | Rules also build forms |
| Eloquent | `Model` | Smaller API; read the unsupported-name list |
| Migration Schema | `Blueprint` | Use `foreignId('user_id')`, not `unsignedBigInteger` |
| Middleware groups web/api | Default web group `[CsrfMiddleware, SecurityHeaders]`; empty api group | Never register CSRF again just to enable it |
| Registered/discovered policies | Policies discovered by convention | Undefined abilities are denied; define all five |
| Automatic `{{ }}` escaping | Explicit `e()` | No automatic escaping; L4 |
| Horizon / daemon worker | Cron with `queue:work --max=50` | Exits on an empty queue by design |
| Scheduler plus one cron entry | `routes/console.php` and `schedule:run` | Same idea; state in schedule.json |
| Tinker | Not provided | Use route:list, tests, and curl |
| npm/vite | Not required | `/assets/...` serves `public/assets/` files directly |

**Unsupported APIs and incompatible conventions — memorize these:**

- Model: `Model::update()`, `Model::destroy()`, `Model::truncate()`. Builder:
  `pluck/chunk/sum/avg/min/max/whereRaw/groupBy/having/join`. No `__callStatic` rescues guessed calls.
- `_token` is not the CSRF field name; it is `csrf_token`. Always use the helper.
- `Auth::user()` in a template instead of `auth()`; static `Post::columns()` instead of instance access;
  `unsignedBigInteger()` in Blueprint.
- Layout sections/slots/push are not provided.
- `view('crud.index')` searches the application's own views, not a framework fallback.

**Update-specific traps:** unique rules need an ignored current ID; `file/image/mimes` intentionally accept stored paths;
checkboxes need their hidden companion. See M10.

---

## 7. Assessment System

| Component | Weight | Assessment Tool |
|---|---|---|
| Checkpoints across 21 modules | 30% | Short tests and observed practical work |
| 21 labs documented with curl -i | 30% | Learner evidence log |
| Bug-hunt exam | 15% | A project with planted defects |
| Capstone | 25% | Section 8 rubric |

**Bug-hunt exam:** A medium-sized project with 10–12 planted defects. Accept a repair only with before/after status and body evidence.
Defect bank:

1. POST form without `csrf_field()` → 419 on submission.
2. A field in the form and migration but not `$fillable` → silent NULL.
3. An override in singular `views/product/` → silently ignored.
4. Routes edited without restarting the server → intermittent stale pages or 404.
5. Policy with only four abilities → 403 on the fifth.
6. Hand-written `resource('books')` without a public declaration or policy → guest login redirect and logged-in 403.
7. Checkbox without hidden companion → unchecking is not saved.
8. `unique:users,email` without the ignored current ID → updating the same record fails.
9. A `/login` route registered before `authRoutes()` → first match takes precedence.
10. `Auth::user()` in a template → fatal call resolution failure.
11. `Post::pluck()` or `Model::update()` → fatal unsupported call.
12. `Router::use(CsrfMiddleware::class)` added to enable existing CSRF → duplicate execution.

**Midpoint project (4 hours, after M10):** A small blog with Post/Comment/Tag, including belongsToMany, authentication,
comments restricted to logged-in users through a policy, a custom searchable index, and e() in every template.
Submit with all six definition-of-done checks.

---

## 8. Capstone Project (12 hours)

**Specification: a small shop/inventory application, or an equivalent office application.**

1. At least four models covering all relationship types; for example Product, Category, Order, User, with an Order–Product pivot.
2. Authentication and two roles, customer/administrator, with a complete product policy and public access removed where required.
3. Product-image upload using an image rule.
4. Custom search and sorting through a controller and view override, not `view('crud...')`.
5. Background order-confirmation mail (Job + queue) and a scheduled daily report or cleanup.
6. One JSON resource API, products, with rate limiting.
7. Actual VPS deployment or an install.php simulation, backed by a production checklist.
8. Deliver a repository, curl -i evidence log, and a signed-off definition-of-done checklist.

**Rubric — 100 points:**

| Dimension | Points |
|---|---|
| Data model: correct conventions, relationships, and clean migrations | 15 |
| Complete CRUD without unnecessary code, using generators correctly | 10 |
| Protection: complete policies, correct guards, no valid-form 419, no XSS vulnerability | 15 |
| Interface: overrides in the right directories, escaped output, RTL | 15 |
| Services: working Job, delivered mail, verified schedule-window behavior | 10 |
| API: correct JSON errors and rate limiting | 10 |
| Deployment: completed production checklist with evidence | 10 |
| Discipline: laws, definition of done, and clean tree; deductions for violations | 15 |

---

## 9. Instructor Guide

**Environment preparation, before week one:** Each learner needs PHP ≥ 8.1 and `pdo_sqlite` visible in `php -m`.
Composer, npm, and a build step are not required. An early diagnostic exercise is running `php widdx test` in an environment
missing the required extension and reading the refusal message: an environment problem is not a code defect.

**90-minute session model:** 15 minutes of concepts → 25 minutes of predict-then-run demonstration → 40-minute lab → 10-minute checkpoint.

**The first timing trap:** Teach the serve worker issue (M2) before the first file edit. Otherwise it consumes lab time.
Spend ten minutes reproducing stale behavior, restarting, and showing the corrected result; then ask the learner to explain it.

**Daily formative assessment:** Five API “exists / does not exist” cards at the end of each session.
The API reference, not Laravel memory, is the permitted source.

**Parallel reading:** Each module names its `.agents/` reference. Require reading it before the next session.
This establishes the same handbook habit learners will need when supervising AI agents in M21.

**When learners get stuck:** Anyone who rewrites code to fix a stale 404 returns to L12: read the error → restart the server →
retry → only then investigate the code. Do not skip the diagnostic step.

**Intensive workshop — 5 days × 8 hours:** Day 1: M1–M3. Day 2: M4–M5. Day 3: M7–M10, with M6 as reading.
Day 4: M11–M14. Day 5: M17, M20, a short bug hunt, and a four-hour small group-project demonstration.
Assign M15, M16, M18, M19, and M21 as guided reading with exercises.

---

## 10. Sources

- `AGENTS.md`: binding recipe, 13 laws, placement map, definition of done, and router.
- `.agents/00` through `13`: reference layer; module headings identify the relevant files.
- `README.md`: human-oriented documentation and fuller examples.
- `llms.txt`: reading map for machines and humans.
- `tests/integration.php`: executable behavior specifications.
- `core/helpers.php`: internally documented global helpers.
- `routes/web.php`: study the small working application first.

> **Maintenance:** Based on Widdx v0.3.8 (2026-10-17). When v0.4.0 is released, update the affected modules
> for version/update management and authRoutes using the new `.agents/` references. The curriculum follows the handbook, not the reverse.
