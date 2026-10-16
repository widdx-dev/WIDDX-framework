# Widdx 🚀

> **Building with an AI assistant?** Start at [AGENTS.md](AGENTS.md) — the short, **binding** layer of
> the handbook: the five-command recipe, thirteen numbered laws, the file-placement map, the command
> order and a six-point definition of done. It ends with a ROUTER table that opens exactly one file in
> [`.agents/`](.agents) — the reference layer (inference rules, routes, models, auth, validation,
> views, queues, helpers, deploy, pitfalls, the full callable API, and the measured traps). The split
> is deliberate: every tool (`CLAUDE.md`, `.cursorrules`, `GEMINI.md`,
> `.github/copilot-instructions.md`, [llms.txt](llms.txt) — all short pointers, all shipped with every
> scaffolded project) auto-loads this file on every task, so the part that must be obeyed is the part
> that stays small. Test `2.12b` guards the split (no section may be lost, duplicated, or unrouted) and
> `2.19` fails if a law disappears or a documented name stops existing in the code.
> It exists because an assistant given only prose rewrote working code over a stale-server 404;
> `.agents/12-api-surface.md` lists every callable name, and a name outside it does not exist
> (`Model` has no `__callStatic`, so guessing is a fatal error).

A very simple PHP framework, inspired by Laravel but much simpler.
Philosophy: **Write minimal code — the framework infers the rest.**

## Requirements

- PHP 8.1+
- `ext-pdo` plus one driver (`pdo_sqlite` is the default and needs no config)
- `ext-mbstring` — **optional**; string length rules count characters through PCRE when it is absent
- Composer — **optional** (`public/index.php` has a built-in fallback autoloader)
- SQLite (default, zero config) or MySQL/PostgreSQL/SQL Server/Oracle

## Installation

```bash
composer install
# Or for quick testing without composer — works directly thanks to fallback autoloader

# Create sqlite database (also auto-created on first run)
touch database.sqlite
sqlite3 database.sqlite "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL);"

# Start the server
php widdx serve
# Or
php -S localhost:8000 -t public
```

Open `http://localhost:8000`

## Quick Start

### 1. Routes (`routes/web.php`)

```php
get('/', fn() => 'Welcome to Widdx!');

// One line = full CRUD (->public() opens it; see "Zero-Code CRUD"):
resource('users', UserController::class)->public();

// Or explicit routes:
get('/users', [UserController::class, 'index']);
get('/users/{id}', [UserController::class, 'show']);
post('/users', [UserController::class, 'store']);
put('/users/{id}', [UserController::class, 'update']);
delete('/users/{id}', [UserController::class, 'destroy']);

// With middleware:
get('/admin', fn() => 'Private')->middleware(AuthMiddleware::class);

// Named routes:
get('/users', [UserController::class, 'index'])->name('users.index');
// Generate URL: route('users.index') → /users
```

Dynamic `{id}` params are passed to function parameters **by name automatically**.

### 2. Controller (`app/Controllers/UserController.php`)

```php
class UserController
{
    // Auto-injection: the framework creates User instance automatically
    public function index(User $user)
    {
        return view('users.index', ['users' => $user->all()]);
    }

    public function store()
    {
        $validated = request()->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
        ]);
        User::create($validated);
        return redirect('/users');
    }
}
```

### 3. Model (`app/Models/User.php`)

```php
class User extends Model
{
    // Nothing! Table 'users' is inferred automatically.
}
```

Available methods: `all()`, `find($id)`, `findOrFail($id)`, `create([...])`, `where(...)->get()/first()`, `$m->save()`, `$m->delete()`.

### 4. Views (`views/`)

```php
// view('users.index', [...]) → views/users/index.php
<?php foreach ($users as $user): ?>
    <li><?= e($user->name) ?> - <?= e($user->email) ?></li>
<?php endforeach; ?>
```

### 5. Helper Functions

| Function | Description |
|---|---|
| `view('name', $data)` | Render template |
| `redirect('/url')` | Redirect |
| `request('key')` / `request()` | Read input / request object |
| `db('table')` | Query Builder |
| `config('app.name')` | Read config |
| `env('KEY', 'default')` | Read environment variable |
| `e($v)` | HTML escape |
| `route('name', $params)` | Generate URL for named route |
| `csrf_token()` / `csrf_field()` | CSRF protection |
| `transaction(fn() => ...)` | Database transaction |
| `event('name', ...$args)` | Fire event |
| `listen('event', fn() => ...)` | Register event listener |
| `resource('name', Controller::class)` | Full CRUD routes (one line!) — add `->public()` to open them |
| `apiResource('name', Controller::class)` | JSON CRUD routes (one line!) — same `->public()` gate |
| `session('key')` / `session(['k' => 'v'])` | Read / write session |
| `flash('success', 'Saved!')` | Flash data for next request |
| `old('name', $default)` | Old input after validation redirect |
| `errors()` / `errors('email')` | Validation errors after redirect |
| `back()` | Redirect to previous page |
| `auth()` | Current logged-in user or null |
| `cache('key')` / `cache(['k' => 'v'], 3600)` | Cache with TTL |
| `logger('error', 'msg')` / `logger()` | File logger |
| `storage('path')` / `storage()` | Local filesystem |
| `queue(Job::class, $data)` / `queue()` | Push job to queue |
| `mail('to', 'subject', 'template')` / `mail()` | Send email |
| `paginate($query, 15)` | Paginate results |
| `layout('layouts.app', $data, $content)` | Render view in layout |
| `component('components.alert', $data)` | Render reusable component |
| `abort(404)` | HTTP error |

### 6. Database — Works with All Major Databases

Directly supported drivers: **SQLite** (default, zero config), **MySQL/MariaDB**, **PostgreSQL**, **SQL Server**, **Oracle**. For any other PDO driver there is a custom `dsn` option.

> **CI coverage:** the full unit + integration suite runs on **SQLite, MySQL 8 and
> PostgreSQL 16** in CI (`.github/workflows/tests.yml`). SQL Server and Oracle are
> **best-effort / not CI-tested** — the code paths exist (identifier quoting,
> `OFFSET/FETCH`, migrations) but are exercised only when someone runs them.
> Report an issue if you hit a problem there.

```bash
cp .env.example .env
```

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=widdx
DB_USERNAME=postgres
DB_PASSWORD=secret
```

Your application code never changes when switching drivers — the framework adapts identifier quoting (`` ` `` vs `""` vs `[]`) and `LIMIT/OFFSET` syntax (vs `OFFSET/FETCH` in SQL Server and Oracle) automatically:

```php
db('users')->where('age', '>', 18)->orderBy('name')->limit(10)->get();
db('users')->where('id', 5)->first();
db('users')->where('id', 5)->update(['name' => 'Ali']);
```

Named connections (second database):

```php
use Widdx\Database;
Database::connection('mysql')->table('users')->get();
```

Transactions:

```php
transaction(function () {
    User::create(['name' => 'Ali', 'email' => 'ali@example.com']);
    Post::create(['title' => 'Hello', 'user_id' => 1]);
});
```

For a driver not directly supported (ODBC, Firebird...):

```php
// In config/database.php under connections:
'custom' => [
    'driver' => 'ansi',
    'dsn' => 'odbc:Driver=...;Server=...;',
    'username' => '...',
    'password' => '...',
],
```

### 7. Validation

```php
$validated = request()->validate([
    'name' => 'required|string|max:255',
    'email' => 'required|email|unique:users,email',   // DB-aware rules
    'age' => 'int|min:18',
    'role' => 'in:admin,user,guest',
    'password' => 'required|confirmed|min:8',          // needs password_confirmation field
    'cover' => 'nullable|image|mimes:jpg,png|max:2048' // uploads: max/min are kilobytes
]);

// Custom messages ("field.rule" keys) override the built-in ones:
request()->validate(
    ['name' => 'required'],
    ['name.required' => 'الاسم مطلوب']
);
```

Available rules: `required`, `string`, `int`, `float`, `bool`, `email`, `url`, `numeric`, `array`, `min:N`, `max:N`, `in:a,b,c`, `confirmed`, `same:field`, `different:field`, `exists:table,column`, `unique:table,column[,ignoreId]`, `file`, `image`, `mimes:jpg,png` (for uploads `min`/`max` are in kilobytes)

Error messages are translatable: set `APP_LOCALE` in `.env` and edit `lang/{locale}.php` (`en` ships built-in as the fallback; `ar` is included as an example).

### RTL out of the box

Right-to-left locales need no extra work. Every built-in template (CRUD views, auth
views, scaffolded pages) opens with `<html <?= lang_attrs() ?>>`, which emits the right
`lang` + `dir` from `APP_LOCALE` — `lang="ar" dir="rtl"` with one env line:

```bash
# .env
APP_LOCALE=ar
```

`lang_attrs()` covers `ar, he, fa, ur, ps, sd, ug, yi, ku, dv` (see `Widdx\Lang::isRtl`),
and the built-in CSS uses logical properties (`text-align:start`) so tables and forms
mirror themselves automatically. Override per page with `lang_attrs('fr')` if you ever
need to.

### 8. CSRF Protection

CSRF + security headers are **already on** for every route in `routes/web.php` (the
ambient `web` group in `core/App.php`). Routes under `/api/...` are the `api` group,
empty by default. Do not register them again — that runs the middleware twice.

```php
// Only needed to change what a group contains (this replaces it — CSRF stays,
// the security headers do not):
\Widdx\Router::middlewareGroup('web', [\Widdx\CsrfMiddleware::class]); // drop SecurityHeaders

// Or per-route, for a route outside the web group:
post('/users', [UserController::class, 'store'])->middleware(\Widdx\CsrfMiddleware::class);

// In forms (built-in CRUD/auth forms already emit this):
<form method="POST" action="/users">
    <?= csrf_field() ?>
    ...
</form>
```

### 9. Events

```php
// Register listener:
listen('user.created', function ($user) {
    // Send welcome email, etc.
});

// Fire event:
event('user.created', $user);
```

### 10. Middleware

```php
// Per-route:
get('/admin', $handler)->middleware(App\Middleware\AuthMiddleware::class);

// Rate limiting (fixed window, cache-backed) — 30 requests / 60s per IP+path:
get('/api/search', $handler)->middleware(new \Widdx\ThrottleMiddleware(30, 60));
```

**Behind nginx/traefik?** The limiter (and anything logging clients) uses
`request()->ip()`, which IGNORES `X-Forwarded-For` unless the direct peer
is a proxy you declared — otherwise any client could spoof its IP and
escape throttling:

```bash
# .env — comma-separated proxy IPs, or '*' for a single trusted edge proxy
TRUSTED_PROXIES=127.0.0.1
```

```php
\Widdx\Request::setTrustedProxies(['127.0.0.1']); // or in code
request()->ip(); // right-most XFF hop appended by your proxy — spoof-proof
```

```php
// Custom middleware is any class with handle(Request, callable): Response
class Auth
{
    public function handle(Request $request, callable $next): Response
    {
        if (!isset($_SESSION['user'])) return redirect('/');
        return $next($request);
    }
}
```

### 11. HTML Forms for PUT/DELETE

```html
<form method="POST" action="/users/1">
    <input type="hidden" name="_method" value="PUT">
</form>
```

### 12. JSON & API-Friendly Requests

Widdx reads `application/json` (and PUT/PATCH/DELETE urlencoded) bodies
automatically — `request()->input()`, `all()` and `validate()` work the same
as with classic form posts. A malformed JSON body gets a clean **400**,
and when the client expects JSON (via `Accept`/`Content-Type` or an `/api`
path — see `Request::expectsJson()`) errors like 401/404/419/422 are returned
as JSON instead of HTML. `Response::json()` pretty-prints only while
`APP_DEBUG=true`, keeping production payloads compact.

```php
$data = request()->json();                 // decoded JSON body
$token = request()->bearerToken();         // Authorization: Bearer ...
return Response::json(['ok' => true]);      // application/json response
```

### 13. Zero-Code CRUD — the whole point of Widdx

**One command, zero written code.** The generator infers everything and the
framework ships working UI templates out of the box:

```bash
php widdx make:crud Post title:string body:text? views:integer?
php widdx migrate
php widdx serve        # → /posts is a complete CRUD app. You wrote nothing.
```

(`php widdx serve` is one long-lived worker: if it was already running, restart it —
it still holds the old `routes/web.php` in memory.)

What `make:crud` produced for you: a model (with `$fillable` + `$rules` derived
from the column types), a migration, and one appended route line:

```php
resource('posts')->public();   // no controller needed!
```

`resource()` resolves in this order — the framework writes the rest:

1. `App\Controllers\PostController` if you made one (any class, even empty
   `extends CrudController {}` — the model is inferred from the name).
2. Otherwise `App\Models\Post` drives a built-in CRUD controller, and the
   built-in templates in `core/views/crud/` render the list/form/show pages
   **automatically from your validation rules and real database columns**
   (email→email input, `in:a,b`→select, text→textarea, required→`*`…).

Own the look later without touching logic: just create `views/posts/index.php
| create.php | edit.php | show.php` — the framework prefers your files.

**Who is allowed to see those pages?** Deny is the default. `resource('posts')`
with no `->public()` and no `App\Policies\PostPolicy` redirects guests to
`/login` and answers logged-in users with 403. Three ways to open it, in order
of how little code they cost:

```php
resource('posts')->public();                  // 1. open to everyone
// 2. App\Policies\PostPolicy with viewAny/create/update/delete  — per-user rules
class PostController extends CrudController { protected bool $public = true; }  // 3. per controller
```

`make:crud` writes option 1 for you; delete `->public()` the moment you add a policy.

For JSON APIs the same trick:

```php
apiResource('posts');   // → GET/POST/PUT/PATCH/DELETE /api/posts — model-only
```

And when you DO want a controller, four properties (or even one name) replace
seven methods:

```php
use Widdx\CrudController;

class PostController extends CrudController {}   // model inferred: App\Models\Post
```

```php
resource('posts', PostController::class);   // or now simply: resource('posts');
```

### 14. Model Rules & Timestamps

Put validation on the model once — `create()` validates automatically.
Failures redirect back with errors + old input, no controller code needed:

```php
class User extends Model
{
    protected array $fillable = ['name', 'email'];
    protected array $rules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email',
    ];
}

// Controller stays a one-liner:
User::create(request()->only(['name', 'email']));
```

`created_at` / `updated_at` are filled automatically as soon as the table has both columns —
which every generator (`make:crud`, `make:model`, `make:migration`, `make:auth`) adds. No
property to remember. Override it explicitly when you want to force either way:

```php
class Post extends Model
{
    protected bool $timestamps = false; // never touch them (the default is true = fill when present)
}
```

Checking whether the columns exist costs one query per table, and the answer is remembered for the
rest of the process. `Schema::create/table/drop/dropIfExists` clear it for the table they touch, so a
migration that adds the pair takes effect immediately. Only DDL run through raw PDO in the same
process can go unnoticed — clear it by hand there:

```php
Model::forgetSchemaCache('posts'); // or forgetSchemaCache() for every table
```

Handy shortcuts:

```php
User::firstOrCreate(['email' => 'a@x.com'], ['name' => 'Ali']);
User::updateOrCreate(['email' => 'a@x.com'], ['name' => 'Ali']);
```

### ORM Relations — inferred like everything else

Define a relation with **one line and no key names** — foreign keys are
inferred from class names (`Team` → `players.team_id`, `Player` → `belongsTo(Team)` → `team_id`):

```php
class Team extends Model {
    public function players()     { return $this->hasMany(Player::class); }
}
class Player extends Model {
    public function team()        { return $this->belongsTo(Team::class); }
    public function role()        { return $this->hasOne(Role::class); }
}
class Role extends Model {
    public function permissions() { return $this->belongsToMany(Permission::class); }
    // pivot 'permission_role' + keys role_id/permission_id — all by convention
}
```

Use them as magic properties — lazy for one model, bulk with `with()` for many:

```php
$team->players;                        // lazy (one query, cached on the model)
Team::with('players')->get();         // 2 queries total — no N+1
Player::with('team.players')->get();  // dot chains nest arbitrarily
$player->team?->name;                 // null-safe belongsTo
```

Zero-code bonus: in built-in CRUD templates any `*_id` column whose model
exists (`author_id` + `App\Models\Author`) is **automatically rendered as a
select** fed by that table — `make:crud Book title:string author_id:integer`
gives you the whole form with no code:

```bash
php widdx make:crud Author name:string
php widdx make:crud Book title:string author_id:integer   # → dropdown of authors
php widdx make:crud Invoice amount:float company:fk(Company)  # real FOREIGN KEY + exists rule
```

### File Uploads — one line, or zero lines

```php
// Manual:
$path = request()->file('cover')->store('covers');  // → 'covers/20261013_ab12_sea.gif'
// $path goes into your DB column; render with storage()->url($path)

// The object gives you everything a real check needs:
$f = request()->file('cover');
$f->isValid(); $f->sizeKb(); $f->extension(); $f->mimeType(); // detected, not claimed
$f->isImage(); $f->move('/var/www/secret.gif');
```

Zero-code: give the column an upload rule and the built-in CRUD template
renders a proper `multipart/form-data` file input, validates it, stores the
file on the disk and keeps only the path in the database:

```bash
php widdx make:crud Photo caption:string cover:image   # image = nullable|image|mimes:...|max:4096
php widdx migrate                                     # → /photos/create already handles uploads
```

`file` and `image` columns are `nullable` by convention — editing a record
without touching the input keeps the existing file untouched.

### 15. Session & Flash Messages

```php
session(['user_id' => 5]);
session('user_id');           // 5
flash('success', 'Saved!');   // survives one redirect
old('name', $user->name);     // old input after validation failure
errors();                     // ['email' => ['The email must be valid.']]
back();                       // redirect to previous page
```

Validation failures redirect back automatically — display in views:

```php
<?php if (!empty(errors())): ?>
    <?php foreach (errors() as $messages): ?>
        <?php foreach ((array) $messages as $message): ?>
            <p><?= e($message) ?></p>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>
<input name="email" value="<?= e(old('email')) ?>">
```

### 16. Authentication — one command, zero code

```bash
php widdx make:auth   # migrations + authRoutes() — nothing to write
php widdx migrate
```

You now have complete, tested screens: **/register, /login, /logout,
/forgot-password, /reset-password/{token}, /verify-email/{token}** —
bcrypt hashing, hashed single-use tokens (1h reset / 7d verify),
anti-enumeration responses, `unique:users,email` + `confirmed` validation,
auto-login on register, verification mail when `email_verified_at` exists.
Every screen ships inside the framework and is overridable by simply
creating `views/auth/<name>.php` in your project.

```php
// Anywhere:
Auth::attempt(['email' => $email, 'password' => $password]);
auth();                       // current user or null
Auth::check();  Auth::logout();
Auth::register(request()->validate([...]));   // hash + verify-mail handled

// Protect routes:
get('/dashboard', [...])->middleware(\Widdx\AuthMiddleware::class);
```

**Authorization — Gate & policies.** Create `App\Policies\BookPolicy` and
the zero-code CRUD routes guard themselves (no wiring whatsoever):

```php
class BookPolicy {
    public function viewAny($user): bool           { return true; }
    public function update($user, Book $book): bool { return $book->user_id === $user?->id; }
    public function delete($user, Book $book): bool { return $user?->is_admin ?? false; }
}

// Or a plain ability + explicit check anywhere:
\Widdx\Gate::define('publish', fn($user) => $user?->is_admin ?? false);
authorize('publish');                    // 403 if denied
if (Gate::allows('publish')) { … }
```

Without any policy/ability defined, everything stays open — policies switch
on protection by EXISTENCE, still zero code until you need it. Inside a
policy, **unimplemented abilities are denied** (Laravel semantics): list
every action you intend to keep allowed.

### 17. CLI Tool

```bash
php widdx make:controller UserController --resource
php widdx make:model Post
php widdx make:middleware Auth
php widdx make:migration create_users_table
php widdx make:seeder UserSeeder
php widdx make:resource Post
php widdx make:auth                       # ZERO-CODE full auth (register/login/reset/verify)
php widdx make:view users/index
php widdx migrate
php widdx migrate:rollback
php widdx db:seed
php widdx db:wipe
php widdx route:list
php widdx serve --port=8080
php widdx serve --host=0.0.0.0 --port=8080   # inside a container / reachable off-box
php widdx queue:push 'App\Jobs\MyJob' '{...}'   # dispatch from CLI
php widdx queue:work --max=50
php widdx queue:failed / queue:retry <id>|all
php widdx schedule:run                    # cron: one entry runs the due tasks
```

## Structure

```
├── app/Controllers  app/Models  app/Middleware
├── core/
│   ├── Contracts/   CacheInterface, QueueInterface, MailInterface,
│   │                StorageInterface, LoggerInterface, SessionInterface,
│   │                AuthInterface, EventInterface
│   ├── Router, Container, Model, QueryBuilder, Database, View, App
│   ├── Event, Migration, CsrfMiddleware, ServiceProvider, ServiceProviderRegistry
│   ├── FileCache, ArrayCache, FileLogger, LocalStorage
│   ├── Session, Auth, AuthMiddleware, CrudController, ApiCrudController
│   ├── Queue, DatabaseQueue, SyncQueue, Job
│   ├── Mail, LogMail, SmtpMail, Mailable, PendingMailable, SendMailJob
│   ├── helpers.php, Exceptions/
├── config/          app.php, database.php, cache.php, logging.php, auth.php, queue.php, mail.php
├── database/migrations/  database/seeders/
├── routes/web.php
├── views/           welcome + users/* + errors/* + layouts/*
├── public/index.php Single entry point
├── widdx             CLI tool
└── composer.json
```

## Contracts (Extensibility)

Every core component has an interface in `core/Contracts/`. Swap any implementation:

```php
// In a service provider or App::run():
Container::getInstance()->singleton(CacheInterface::class, FileCache::class);
Container::getInstance()->singleton(MailInterface::class, SmtpMail::class);
Container::getInstance()->singleton(StorageInterface::class, LocalStorage::class);
```

Available contracts:
- `CacheInterface` — FileCache, ArrayCache included
- `LoggerInterface` — FileLogger included
- `StorageInterface` — LocalStorage included
- `SessionInterface` — Session included
- `AuthInterface` — Auth included
- `EventInterface` — Event included
- `QueueInterface` — SyncQueue, DatabaseQueue included
- `MailInterface` — LogMail, SmtpMail included

## Mail

```php
// Simple templated send:
Mail::send('user@example.com', 'Welcome', 'emails.welcome', ['name' => 'Ali']);

// Raw HTML or plain text:
Mail::html('user@example.com', 'Hi', '<p>Hello</p>');
Mail::plain('user@example.com', 'Hi', 'Hello');

// Mailable class:
class WelcomeEmail extends Mailable
{
    public function __construct(protected string $name) {}

    public function build(): array
    {
        return ['template' => 'emails.welcome', 'data' => ['name' => $this->name]];
    }
}

// Two ways to send a mailable:
Mail::to('user@example.com')->subject('Welcome')->deliver(new WelcomeEmail('Ali'));
(new WelcomeEmail('Ali'))->to('user@example.com')->subject('Welcome')->send();

// Builder extras:
Mail::to('a@x.com')
    ->cc('b@x.com')
    ->replyTo('no-reply@x.com')
    ->deliver(new WelcomeEmail('Ali'));

// Queue for background delivery:
Mail::queue('user@example.com', 'Welcome', 'emails.welcome', ['name' => 'Ali']);
```

The `smtp` driver is a real SMTP client (sockets): supports STARTTLS (`MAIL_ENCRYPTION=tls`),
implicit SSL (`ssl`, port 465), `AUTH LOGIN`, and dot-stuffing; header values containing
CR/LF are rejected to prevent header injection. Set `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/
`MAIL_PASSWORD` in `.env`. With an empty `MAIL_HOST` it falls back to PHP's `mail()`
(shared-hosting mode). `attach()` is not implemented yet — multipart MIME is on the roadmap.

## Queues

```php
// Define a job:
class SendWelcomeEmail extends Job
{
    public function handle(array $data): void
    {
        Mail::send($data['email'], 'Welcome', 'emails.welcome', ['name' => $data['name']]);
    }
}

// Dispatch:
SendWelcomeEmail::dispatch(['email' => 'user@example.com', 'name' => 'Ali']);
SendWelcomeEmail::dispatchLater(60, ['email' => 'user@example.com']); // delay 60s
SendWelcomeEmail::dispatch(['email' => 'x@y.com'])->onQueue('emails');

// Process jobs (CLI):
php widdx queue:work
php widdx queue:work --queue=emails --max=10
```

### Queue retries, backoff and failed jobs (database driver)

The `database` driver honors per-job policy with zero wiring:

```php
class ImportCsv extends Job
{
    protected int $tries = 5;     // attempts before it gets parked
    protected int $backoff = 10;  // seconds — doubles each retry

    public function handle(array $data): void { /* … */ }
}
```

A failing job under its `tries` budget is re-parked with exponential
backoff; once exhausted it moves to **`failed_jobs`** (payload, reason,
timestamp) where you can inspect and revive it:

```bash
php widdx queue:push 'App\Jobs\ImportCsv' '{"file":"a.csv"}'  # dispatch from CLI
php widdx queue:work --max=50
php widdx queue:failed
php widdx queue:retry 3      # or: php widdx queue:retry all
```

## Scheduler — ONE crontab entry for everything

`schedule:run` fires whatever is due from `routes/console.php`; buckets are
persisted in `storage/app/schedule.json` so a task can never double-run
inside its own window:

```php
// routes/console.php
\Widdx\Schedule::command('queue:work --max=100')->everyMinute();
\Widdx\Schedule::call(fn() => cleanup())->daily();
\Widdx\Schedule::command('db:seed')->hourly()->when(fn() => config('app.seeding'));
```

```cron
* * * * * cd /path/to/project && php widdx schedule:run >> /dev/null 2>&1
```

## Service Providers

Register bindings and boot logic cleanly:

```php
use Widdx\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MyService::class, fn() => new MyService());
    }

    public function boot(): void
    {
        // Runs after all providers registered
    }
}
```

## PSR Standards

- **PSR-4** autoloading (`Widdx\` ← `core/`, `App\` ← `app/`).
- **PSR-11** container (`ContainerInterface` with `get`/`has`).

## Complete Component List

| Component | Contract | Default Driver | Status |
|-----------|----------|----------------|--------|
| Router | — | — | ✅ |
| Container | PSR-11 | — | ✅ |
| Model / QueryBuilder | — | — | ✅ |
| Database | — | SQLite/MySQL/PostgreSQL/SQLServer/Oracle | ✅ (SQLite+MySQL+PgSQL CI-tested; SQLServer/Oracle best-effort) |
| View | — | PHP | ✅ |
| Session | SessionInterface | PHP Session | ✅ |
| Auth | AuthInterface | Session-based | ✅ |
| Event | EventInterface | In-memory | ✅ |
| Cache | CacheInterface | File / Array | ✅ |
| Logger | LoggerInterface | File | ✅ |
| Storage | StorageInterface | Local filesystem | ✅ |
| Queue | QueueInterface | Sync / Database | ✅ |
| Mail | MailInterface | Log / SMTP | ✅ |
| Migration | — | — | ✅ |
| Validation | — | — | ✅ |
| CSRF | — | — | ✅ |
| Service Provider | — | — | ✅ |
| CLI | — | — | ✅ |
| Testing | — | Built-in runner | ✅ |

---

## Testing

```bash
php widdx test               # unit tests + the full integration suite
composer run test            # same, via composer script
php tests/integration.php    # integration suite only
```

The integration suite (`tests/integration.php`) is genuinely end-to-end: it scaffolds a
fresh project with `php widdx new`, boots it on `php -S`, then speaks raw HTTP for CRUD,
`_method` spoofing, validation flash + old input, CSRF (419/302), 404/405, auth session
persistence, DB-aware validation rules, translated messages (`APP_LOCALE=ar`), security
headers, rate limiting, queued events, the CLI generators, and even drives the real
`SmtpMail` socket client against a scripted fake SMTP server.

CI (`.github/workflows/tests.yml`) runs lint + the whole suite on PHP 8.1 / 8.2 / 8.3 /
8.4 / 8.5, each against every driver shipped out of the box (SQLite, MySQL, PostgreSQL —
real servers, selected with `DB_CONNECTION`), plus a separate **PHPStan** job (level 1,
`phpstan.dist.neon`):

```bash
composer run phpstan          # static analysis (needs composer install first)
```

---

## Release & License

Widdx is licensed under the **MIT license** (see `LICENSE`) and follows
[semantic versioning](https://semver.org). The current baseline is **v0.3.8**.

Two ways to start a project:

```bash
# 1) Clone the framework and scaffold a project from it (zero config) — the supported path
git clone <repo-url> widdx && php widdx new my-app

# 2) As a composer dependency — NOT installable yet: widdx/framework is still
#    unpublished on Packagist (composer require returns 404 until it is released).
composer require widdx/framework
```

Packaging decision: the repository is **library + skeleton in one** — `widdx/framework`
is *ready to be* published on Packagist (PSR-4 `Widdx\` → `core/`), while `php widdx new` ships the
app skeleton (no separate template repo to keep in sync). `composer.lock` is not
committed, per library convention. Until the release happens, install from source (path
repository or a VCS requirement on this repo) rather than the Packagist name.

---

## Deploy on Shared Hosting (no terminal needed)

Widdx runs natively on Apache/LiteSpeed shared hosting (cPanel, Hostinger, Namecheap…):

- **Composer is optional** — `public/index.php` falls back to a built-in PSR-4
  autoloader plus the `polyfill/` stand-in for `psr/container`, so a plain file
  upload is enough (no `vendor/` required).
- **The web installer** does everything a terminal would: upload the project
  (zip → Extract in cPanel File Manager), open `https://your-site.com/install.php`,
  fill in the database form. It checks PHP/PDO/storage permissions, **tests the DB
  connection first**, writes `.env`, runs every pending migration, then locks itself
  forever (`install.php` returns 404 afterwards; it also refuses to overwrite an
  existing `.env`).
- **Cron jobs** (cPanel → Cron Jobs) replace the scheduler daemon:

  ```
  * * * * * php /home/USER/public_html/widdx schedule:run
  ```

- **Queues without a daemon**: `widdx queue:work` already processes everything
  available and exits when the queue is empty — perfect for cron:

  ```
  * * * * * php /home/USER/public_html/widdx queue:work --max=50
  ```

Typical no-terminal flow:

1. `php widdx new my-app` + `make:crud ...` locally (or receive the folder from a teammate).
2. Edit nothing — upload the whole folder to `public_html/` via FTP/File Manager.
3. Create the MySQL database + user in cPanel.
4. Open `/install.php`, submit the form, done. Delete `install.php` if you like —
   the lock already neutralizes it.

If you DO have SSH, everything also works through the usual `php widdx migrate` CLI.
