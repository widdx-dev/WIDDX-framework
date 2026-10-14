# Widdx Framework — AI Agent Handbook

> **Audience**: AI coding assistants (Claude, GPT, Gemini, Cursor, Copilot, Codex…) and humans.
> **Mission of Widdx**: the programmer writes the *fewest possible lines of code* for any project.
> When you build with Widdx, prefer the framework's conventions over hand-written code.
> If you find yourself writing boilerplate that Widdx can infer — stop, and delete it.

Version documented: **v0.2.0** (tagged). PHP **>= 8.1**. Zero required external packages.

---

## 1. What Widdx is (30 seconds)

Widdx is a self-contained PHP MVC micro-framework that lives in `core/`. It is NOT a
composer library you install into a project — **the project IS the framework folder**:
download/clone the repo (or run `php widdx new my-app`) and you have a complete
application skeleton with router, ORM, auth, queues, scheduler, views, CLI, and a
web installer. There is no build step, no npm, and composer is optional (a built-in
PSR-4 fallback autoloader ships in `public/index.php`).

Its defining feature is **zero-code CRUD**: one line `resource('posts');` in
`routes/web.php` gives a fully working list/create/show/edit/delete web UI (and
`apiResource('posts');` gives a JSON API) driven by the `App\Models\Post` model and
the framework's own templates — with **no controller, no view files, no routes**.

---

## 2. Hard facts an agent must respect

| Fact | Detail |
|---|---|
| Language level | PHP >= 8.1, `declare(strict_types=1)` in every core file |
| Autoload | PSR-4: `Widdx\` → `core/`, `App\` → `app/`; helpers autoload from `core/helpers.php` |
| Entry point | `public/index.php` only. `.htaccess` routes everything through it |
| Namespaces in views | **Templates run in the GLOBAL namespace.** In any `.php` view use global helpers (`auth()`, `e()`, `errors()`, `old()`, `csrf_field()`, `lang_attrs()`) — NEVER bare class names like `Auth::user()` (this is a real fatal-error trap) |
| CLI | `php widdx <command>` from the project root (extension-less file, valid PHP) |
| Tests | `php widdx test` runs unit (`widdx-test`) + integration (`tests/integration.php`) |
| Database | PDO; drivers sqlite (default), mysql, pgsql, sqlsrv, oci, or a raw `DB_DSN` |
| Shared hosting | Fully supported; `/install.php` web installer exists for terminal-less deploy |

---

## 3. Project structure

```
app/Controllers/     your controllers (often NONE needed)
app/Middleware/      your middleware
app/Models/          your models — usually the only code you write
config/              app, auth, cache, database, logging, mail, queue  (each .env-driven)
core/                THE FRAMEWORK (router, ORM, auth, queue, view, CLI classes…)
core/Contracts/      interfaces for swappable drivers (cache, mail, queue, logger…)
core/views/crud/     built-in CRUD templates (index/create/edit/show/form)
core/views/auth/     built-in auth templates (login/register/forgot/reset/verify)
database/migrations/ timestamped migration classes
database/seeders/    seeders (php widdx db:seed)
lang/                validation/auth messages per locale (en, ar included)
public/              web root: index.php, install.php, .htaccess, your css/js
routes/web.php       ALL routes (web + api — same file)
routes/console.php   schedule definitions (Schedule::command/call)
storage/             cache/ logs/ app/ sessions/ (writable; runtime state)
views/               YOUR templates — overrides core/views automatically
views/errors/        404.php / 500.php error pages
widdx                CLI entry point (php widdx help)
widdx-test           unit suite
```

**Override rule**: any file you add under `views/<resource>/` with the same name as a
core template (`index, create, edit, show, form`) replaces the built-in one. Same for
`views/errors/*.php`.

---

## 4. Request lifecycle (mental model)

```
HTTP request
  └─ public/index.php            define WIDDX_BASE_PATH, autoload
      └─ App::run(base)
          ├─ App::loadEnv(base)  read .env (no vendor needed)
          ├─ App::loadConfig()   require config/*.php into a static array
          ├─ Session::start()    file sessions under storage/sessions
          ├─ Router::dispatch(Request)
          │    ├─ match GET/POST/PUT/PATCH/DELETE on uri (PUT/DELETE via
          │    │   _method spoofing in forms)
          │    ├─ run global middleware  (Router::use(X::class))
          │    ├─ run route middleware   (->middleware(X::class))
          │    └─ invoke action: closure / [Controller::class, 'method']
          │        Controller instances are auto-wired by Container (DI by type-hint)
          └─ Response::send()    json / html / redirect / 404/419/500 pages
```

Errors: `ValidationException` auto-redirects back with `errors()` + `old()` flash
(no controller code needed). Unmatched route → `views/errors/404.php`. Exception →
`views/errors/500.php` (with details when `APP_DEBUG=true`).

---

## 5. The golden path — build a full app in ONE command

```bash
php widdx new shop && cd shop           # scaffold (or just clone/zip-upload the folder)
php widdx make:crud Product name:string price:float photo:image? category:fk(Category)
php widdx make:crud Category name:string
php widdx migrate                        # or open /install.php in the browser — same thing
php widdx serve                          # http://localhost:8000/product  ← full CRUD
```

That produces: 2 models with validation rules, 2 migrations (with a REAL
`REFERENCES categories(id)` foreign key), 2 `resource(...)` route lines — and NOTHING
else. List/form/show/edit/delete pages, upload handling, and a `<select>` for
`category_id` all work because the framework infers them (see §6).

---

## 6. Inference conventions (READ THIS — it is the framework's brain)

Widdx replaces configuration with naming conventions. Every rule below is enforced by
code, and knowing them lets you write zero boilerplate:

| If you… | The framework infers… |
|---|---|
| `resource('posts')` with no controller | model `App\Models\Post`; if `PostController` exists it is used instead |
| class `Post` (no `$table`) | table `posts` (snake + pluralize); class `OrderItem` → `order_items` |
| model has `protected array $rules` | the create/edit **form fields, labels, and types** (string→text, text→textarea, bool→checkbox, `*_id`→select of related model, `file`/`image`→file input) |
| column name ends `_id` and `App\Models\{Studly}id` exists | select options loaded from that model automatically |
| `make:crud x.y:fk(Model)` | column `model_id INTEGER NOT NULL REFERENCES models(id)` + rule `exists:models,id` |
| field type suffix `?` (`body:text?`) | nullable column + `nullable` rule (uploads default to nullable on purpose) |
| model property `$fillable` | mass-assignment whitelist for `Model::create($data)` and `fill()` |
| `hasMany/hasOne(Player::class)` on `Team` | FK `team_id` on players, local key = parent primary key |
| `belongsTo(Author::class)` on `Book` | FK `author_id` on books, owner key `id` |
| `belongsToMany(Permission::class)` | pivot table name = two models joined **alphabetically**: `permission_role` |
| policy class named `App\Policies\BookPolicy` for `App\Models\Book` | Gate auto-discovers it by convention (no registration call) |
| form POSTs `_method=PUT|DELETE` | real PUT/DELETE routing |
| route action returns **array** | JSON response automatically (very useful in API closures) |
| file `views/posts/index.php` exists | your template is used, core template is skipped |
| locale is `ar/he/fa/ur/ps/sd/ug/yi/ku/dv` | templates render `dir="rtl"` via `lang_attrs()` |
| migration file `database/migrations/*_create_*_table.php` | `php widdx migrate` runs all pending in filename order |

Migrations are plain PHP classes returning `new class { public function up(\PDO $pdo): void {…} }`
stored in `database/migrations/` — the `migrate` command records executed names in a
`migrations` table; `migrate:rollback` reverses the last batch.

---

## 7. Routes (`routes/web.php`)

```php
get('/', fn() => view('welcome'));                    // HTML
get('/health', fn() => ['status' => 'ok']);           // arrays auto-JSON
post('/pay', [PaymentController::class, 'store']);    // controller action
put('/books/{id}', fn(string $id) => …);              // params {name} → positional closure args
delete('/books/{id}', …)->name('books.destroy');      // named routes → route('books.destroy', ['id'=>1])

resource('posts');                    // full HTML CRUD (7 routes, names posts.index … posts.destroy)
resource('users', UserController::class);  // explicit controller
apiResource('books');                 // JSON CRUD under /api/books (+PATCH)
authRoutes();                         // register/login/logout/forgot/reset/verify — 1 line, full flow
```

Middleware — **opt-in**, two levels:

```php
\Widdx\Router::use(\Widdx\CsrfMiddleware::class);    // global (all non-GET requests)
\Widdx\Router::use(\Widdx\SecurityHeaders::class);   // global security headers
get('/admin', …)->middleware(\Widdx\AuthMiddleware::class);          // login required
get('/api/x', …)->middleware(new \Widdx\ThrottleMiddleware(60, 60)); // 60 req/min per IP
```

CSRF rule: once `CsrfMiddleware` is enabled, every POST form must include
`<?= csrf_field() ?>` (reads token from session; also accepts `X-CSRF-TOKEN` header).
Missing/mismatched → **419**.

A controller is just a class in `app/Controllers/`; constructor parameters are
auto-resolved from the container. Returning a string sends HTML, returning an array
sends JSON, returning a `Response` passes through.

---

## 8. Models & ORM

```php
namespace App\Models;
use Widdx\Model;

class Post extends Model
{
    protected array $fillable = ['title', 'body', 'user_id'];   // mass-assignment whitelist
    protected array $rules = [                                   // drives validate() AND the auto-form
        'title' => 'required|string|max:255',
        'body'  => 'required|string',
        'user_id' => 'required|int|exists:users,id',
    ];

    public function comments() { return $this->hasMany(Comment::class); }   // comments.post_id
    public function author()   { return $this->belongsTo(User::class); }    // posts.user_id
    public function profile()  { return $this->hasOne(Profile::class); }
    public function tags()     { return $this->belongsToMany(Tag::class); } // pivot tag_post
}
```

Static API: `Post::all()`, `Post::find($id)`, `Post::findOrFail($id)`,
`Post::create($data)` (validates `$rules`, respects `$fillable`),
`Post::where($col, $op, $val)` → QueryBuilder, `Post::query()`, `Post::with('a.b')`.
Instance API: `$post->save()`, `$post->delete()`, `$post->fill($data)`,
lazy relation access `$post->comments` (cached), `$post->relationLoaded('comments')`.

QueryBuilder: `select() allowColumns() where(col,op,val) orWhere() orderBy() limit()
offset() with() get() first() find() insert() update() delete() count() exists()`.
`where` supports `=, !=, <, <=, >, >=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN`.
`db('table')` helper gives a QueryBuilder without a model.
Timestamps `created_at/updated_at` are set automatically on save.

**Eager loading kills N+1**: `Post::with('comments.author')->get()` runs 3 bulk
queries total, and dot-chains work to any depth.

---

## 9. Validation

Anywhere: `request()->validate($rules, $customMessages)` → throws
`ValidationException` (HTTP: redirect back with `errors()`; API adjust per your route).
In models it is automatic on `create()`/`update()`.

Available rules:
`required, nullable, string, int|integer, float|double, bool|boolean, email, url,
numeric, array, in:a,b,c, min:N, max:N, confirmed, same:field, different:field,
exists:table,column, unique:table,column[,ignoreId], file, image, mimes:jpg,png`

Semantics notes an agent MUST know:
- `min/max`: string length OR numeric value; **for `file`/`image` they are kilobytes**.
- `file/image/mimes` accept an **already-stored path string** (a record re-validated
  on update passes when no new upload is sent) — this is deliberate; do not "fix" it.
- `unique` third parameter ignores an id (used by edit forms automatically? no — pass it yourself when needed).
- Custom message keys: `['email.required' => 'We need :field!', …]` with `:field`, `:min`, `:max`, `:values` placeholders; defaults come from `lang/{locale}.php`.

---

## 10. Views

`view('welcome')` → `views/welcome.php`; `view('users.index')` → `views/users/index.php`.
Data: `view('posts.show', ['post' => $p])` → variables by key, already escaped with
`e($x)` — **always escape** user data in templates.

Helpers inside templates (global namespace!):
`e() csrf_field() old('name') errors() session('key') auth() lang_attrs() asset via plain /paths`.

`layout('app', ['title' => …], $content)` renders `views/app.php` (or any view name)
with `$content` available as a `$content` variable; `component('shared/alert', $data)`
is an alias of `view()` for reusable partials.

Built-in CRUD UI: never create controllers/views to customize CRUD unless asked —
either drop a file in `views/{resource}/{index|create|edit|show|form}.php` (it wins
over the core template) or subclass `Widdx\CrudController` / `ResourceController`.

---

## 11. Auth & authorization

`authRoutes();` in routes/web.php ships the ENTIRE flow with framework templates:
`/register /login /logout /forgot-password /reset-password /email/verification`.
Requirements: `App\Models\User` with `email` + `password` columns; run migrations
(the `make:auth` CLI adds `auth_tokens` + `email_verified_at` migrations for you).

Programmatic API (all static, `Widdx\Auth`):
```php
Auth::attempt(['email' => …, 'password' => …]);   // sets session, regenerates id
Auth::user(); Auth::id(); Auth::check(); Auth::guest(); Auth::logout();
Auth::register(['name'…,'email'…,'password'…]);   // hashes password itself (bypasses fillable — deliberate)
Auth::sendResetLink($email); Auth::resetPassword($email, $token, $new);
Auth::sendVerification($user); Auth::verifyEmail($email, $token);
```
In views/controllers use the **helper** `auth()` (never `Auth::user()` inside a .php template).

Authorization — Gate + policies, **deny-by-default**:
```php
Widdx\Gate::define('publish', fn($user) => $user->is_admin);
// or convention policy: App\Models\Book → App\Policies\BookPolicy
class BookPolicy {
    public function viewAny($user): bool { … }
    public function create($user): bool  { … }   // abilities: viewAny view create update delete
}
authorize('create', Book::class);     // throws HttpException 403 (HTML page or JSON per route type)
```
If a policy exists for a model used by `resource()`, the built-in CRUD controller
guards all seven actions automatically; abilities the policy doesn't define are DENIED.

---

## 12. File uploads

- Model rule `file`/`image` + `make:crud cover:image?` = full multipart upload handled:
  the file lands in `storage/app/uploads/…` and **the relative path string is stored
  in the DB column**. `$request->file('cover')` → `Widdx\UploadedFile`
  (`store(), move(), originalName(), extension(), mimeType(), isImage(), sizeKb()`).
- `storage()` helper: `storage()->put('x.json', …)`, `storage()->get/exists/delete/url`.
- Editing a record without re-uploading keeps the stored path (see §9 upload semantics).

---

## 13. Queues, jobs & scheduler

```php
class SendWelcomeEmail extends Widdx\Job            // app/Jobs/ or any autoloaded class
{
    protected int $tries = 3;      // retries with exponential backoff (backoff * 2^(n-1) s)
    protected int $backoff = 5;    // seconds
    protected string $queue = 'default';
    public function handle(array $data): void { … } // throw to fail/retry
}

queue(SendWelcomeEmail::class, ['user_id' => 7]);   // push via helper
Queue::later(3600, MyJob::class, $data);            // delayed
```

Drivers (`QUEUE_DRIVER` in .env): `sync` (default, inline), `database` (jobs table
+ failed_jobs, retries/backoff handled by the driver). CLI:

```bash
php widdx queue:push 'App\Jobs\MyJob' '{"k":1}'   # enqueue
php widdx queue:work [--queue=name] [--max=N]     # processes, EXITS when empty → cron-safe
php widdx queue:failed                            # list dead jobs (database driver)
php widdx queue:retry <id>|all                    # re-queue
```

Scheduler — ONE crontab line for everything, `routes/console.php`:

```php
\Widdx\Schedule::command('queue:work --max=50')->everyMinute();
\Widdx\Schedule::call(fn() => logger('info', 'heartbeat'))->everyFiveMinutes()
    ->when(fn() => env('APP_ENV') === 'production');
```
```
* * * * * php /path/to/project/widdx schedule:run
```
Due-task state lives in `storage/app/schedule.json` (time-bucket model, safe for
cron-driven repeat). Frequency methods on `ScheduledTask`:
`everyMinute() everyFiveMinutes() everyTenMinutes() everyFifteenMinutes()
everyThirtyMinutes() hourly() daily() monthly() interval($seconds)` + gate with
`when(fn() => bool)`.

---

## 14. Other subsystems (helpers first — they cover 95% of use)

```php
config('app.locale');  env('DB_HOST', '127.0.0.1');
cache('key'); cache(['key' => $val], 3600); cache()->forget('key');   // CACHE_DRIVER=file|array
logger('error', 'msg', $context);  // LOG_CHANNEL=file, daily-ish
storage()->put($rel, $contents); storage('sub/dir');                  // LocalStorage disk
mail($to, $subject, $template, $data); mail()->attach($path)->raw(…); // MAIL_DRIVER=log|smtp
event('order.paid', $order);  listen('order.paid', fn($o) => …);      // Event + Event::flush() on shutdown
app(SomeClass::class);  Container::getInstance()->bind(Interface::class, Impl::class);
paginate($queryBuilder, 20)  → ['items','total','page','pages'] for list views
transaction(fn() => …);   abort(403, 'no');   redirect('/x');   back();
```

Swappable drivers implement `core/Contracts/*Interface` (Cache, Mail, Queue, Logger,
Session, Storage, Auth, Event). Bind your own in the container to replace them.

---

## 15. Configuration — every `.env` key

```
APP_DEBUG=true|false   APP_LOCALE=en|ar|…   APP_URL=…
DB_CONNECTION=sqlite|mysql|pgsql|sqlsrv|oci   DB_DATABASE=… (sqlite: file path)
DB_HOST=… DB_PORT=… DB_USERNAME=… DB_PASSWORD=… DB_SERVICE=… DB_DSN=… (custom PDO)
CACHE_DRIVER=file|array   LOG_CHANNEL=file   QUEUE_DRIVER=sync|database
MAIL_DRIVER=log|smtp  MAIL_FROM=…  MAIL_FROM_NAME=…
MAIL_HOST=… MAIL_PORT=587 MAIL_USERNAME=… MAIL_PASSWORD=…
MAIL_ENCRYPTION=|tls|ssl  MAIL_TIMEOUT=30  MAIL_VERIFY_PEER=true
SECURITY_FRAME_OPTIONS=…  SECURITY_REFERRER_POLICY=…  SECURITY_CSP=…
TRUSTED_PROXIES=127.0.0.1,10.0.0.2   (or '*' behind one edge proxy)
```

`Request::ip()` only trusts `X-Forwarded-For` when the peer is listed — keep it set
behind any proxy/CDN or rate-limiting will see the proxy's IP.

---

## 16. CLI reference (`php widdx …`)

| Command | What it does |
|---|---|
| `new <dir>` / `new:api <dir>` | scaffold a project (api flavor = JSON routes) |
| `serve [--port=8000]` | dev server (php -S on public/) |
| `migrate` / `migrate:rollback` | run / undo migrations |
| `db:seed` / `db:wipe` | seeders / drop all tables |
| `make:crud <Name> field:type…` | model + migration + `resource()` line. Types: `string text integer float bool date email url file image fk(Model)` + `?` suffix = optional |
| `make:model/middleware/migration/seeder/view/controller <Name>` | classic generators |
| `make:resource <Name>` | model + controller + migration + views (opinionated full stack) |
| `make:auth` | adds users table + auth_tokens + email_verified_at migrations, `authRoutes()`, User model |
| `route:list` | table of routes |
| `test` | unit + integration suites |
| `queue:work/push/failed/retry` | see §13 |
| `schedule:run` | run due scheduled tasks (put this in crontab) |
| `help` | full list |

---

## 17. Testing an app built with Widdx

Framework self-test: `php widdx test` (needs `pdo_sqlite`; CI uses PHP 8.1–8.4).
For YOUR app, the zero-code defaults are the test surface:
- hit `GET /{resource}` for the list, `POST /{resource}` with `csrf_token` for create;
- invalid POST redirects 302 back and `session('_errors')` holds messages;
- API: same payloads return JSON with validation errors keyed by field.
Integration suite (`tests/integration.php`) is a worked example of end-to-end HTTP
testing with raw sockets + cookie jar — copy its style for app-level e2e tests.

---

## 18. Deployment

**VPS/SSH**: point the vhost `DocumentRoot` at `public/`, `php widdx migrate`,
cron `schedule:run` (+ `queue:work` under supervisor or cron `--max`). Set
`APP_DEBUG=false`.

**Shared hosting (no terminal)**: upload the project folder to `public_html/`,
create the MySQL DB in cPanel, open `https://site/install.php` — the one-shot web
installer checks PHP/PDO/permissions, tests the DB connection, writes `.env`, runs
migrations, then locks itself (404 afterwards; it never overwrites an existing
`.env`). Composer is NOT required: `public/index.php` has a built-in PSR-4 autoloader
plus `polyfill/` for `psr/container`. Cron entries use the same `widdx` file.

---

## 19. Pitfalls for agents (real bugs we found — do not reintroduce)

1. **Templates are global-namespace PHP.** `Auth::user()` in a view = fatal. Use `auth()`.
2. **Never hand-write what inference gives** — a controller+4 views for CRUD of a model is ~200 wasted lines; `resource('x')` does it.
3. **`Model::create()` respects `$fillable`** — a field missing from `$fillable` is silently dropped. `Auth::register` bypasses mass assignment ON PURPOSE (password hashing).
4. **Upload rules accept stored-path strings** so edit-forms without re-upload pass; do not "tighten" `file/image/mimes` to reject strings.
5. **Policies deny undefined abilities** — `viewAny` alone does NOT permit create; define all needed methods.
6. **`php widdx` CLI must be run inside the target project** (it uses its own folder as the base path) — running the framework-repo CLI against another project writes into the repo.
7. **Hidden form inputs still submit**; two same-named inputs → last wins. Conditional UI sections must use distinct field names.
8. **Extension-less files (`widdx`) are PHP** — lint them (`php -l widdx`); CI does.
9. **`queue:work` exits when the queue is empty** by design (cron-safe). Don't "fix" it to block forever.
10. **CSRF/SecurityHeaders are opt-in**; enabling `CsrfMiddleware` without adding `csrf_field()` to every form breaks the whole site (419).

## 20. Anti-goals — things Widdx deliberately is NOT

- Not Laravel: no facades beyond static helpers, no service-provider boot pyramid, no Eloquent magic beyond documented inference.
- No ORM relations beyond the four (`hasMany/hasOne/belongsTo/belongsToMany`).
- No Blade/Twig — plain PHP templates, escape with `e()`.
- No built-in admin panel/dashboard; scaffolded CRUD IS the admin.
- No npm/vite pipeline; put CSS in `public/css/` and reference `/css/app.css`.

When an AI agent is asked to "add a feature" in a Widdx project, the correct first
move is always: **create the model (+ rules), add ONE route line, run migrate — then
check whether the framework already handles what was requested.**
