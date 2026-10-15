# Widdx — AI Agent Handbook

Read this file before writing any code in a Widdx project.
One line = one fact. Follow the lines in order. Do not skip sections.

---

## PART 0 — Small model? Do EXACTLY these five lines

You (a 3B model) only need this part for a basic project. Type, one by one:

```
cd <the-unzipped-widdx-folder>
php widdx new myapp
cd myapp
php widdx migrate
php widdx serve
```

Then open http://localhost:8000 — it works (demo users CRUD is live).

To add any list of records (run INSIDE the myapp folder) — ALWAYS two commands:

```
php widdx make:crud Product name:string price:float
php widdx migrate
```

The second command is NOT optional. `make:crud` creates the table file; `migrate` applies it.
Then restart nothing — the running `php widdx serve` picks it up on the next request.

The pages live at `/products` — plural. Always plural.

If a step fails: read its error, fix THAT step, run it again. Do not rewrite
earlier steps. If you need more than a list of records, read PART 4 + PART 22.

---

## PART 1 — What is Widdx

- Widdx is a PHP framework that lives inside your project folder.
- Your project IS this folder. There is nothing to install.
- PHP version needed: 8.1 or newer.
- Composer is optional. The autoloader works without it.
- There is no npm. There is no build step. There is no config file to edit.
- The idea of Widdx: you write almost nothing. The framework infers the rest from names.
- One line `resource('posts')->public()` = a full working CRUD website for posts.
- One line `authRoutes()` = register + login + logout + password reset + email verify.
- One command `make:crud` = model + database table + routes, all at once.
- Version: v0.3.0. All behavior below is tested (77 unit + the integration suite).

---

## PART 2 — The five golden rules

1. Before writing code, check if the framework already does it (PART 4 list).
2. Prefer one model file + one route line over a controller + views.
3. Every template (.php file in views/) runs in the GLOBAL namespace.
   - RIGHT: `<?= e(auth()->name) ?>`
   - WRONG: `<?= \Widdx\Auth::user()->name ?>` (this is a fatal error)
4. Escape all user data: `<?= e($value) ?>`. Always. In every template.
5. When unsure, run `php widdx test` — the answer is inside the test files.

---

## PART 3 — Start a project (one step per line)

The framework folder is the tool. All `php widdx …` commands need you to be INSIDE it (or inside a project made from it).

Have PHP on your machine? Type:

```
php widdx new myapp
```

No PHP on your machine? Download/zip this folder and unzip it. Same result.

Then inside the new folder:

```
cd myapp
```

SQLite is the default database. It needs zero setup. The file appears by itself.

To run the app:

```
php widdx serve
```

Open http://localhost:8000 — it already works (demo user CRUD exists).

To put data in the database for the first time:

```
php widdx migrate
```

No terminal at all (shared hosting)? Upload the folder, open `https://yoursite/install.php` in the browser, fill the form. Done.

---

## PART 4 — Inference: what Widdx guesses from names

Each item: "if you do X" → "Widdx does Y". Memorize these; they replace all config.

1. You write `resource('posts')` → 7 routes + 5 pages work, no controller needed.
2. You write `apiResource('posts')` → JSON API at `/api/posts`, no controller needed.
3. The framework looks for `App\Controllers\PostController` first. Not found? It uses `App\Models\Post` + built-in pages.
4. Class `Post` with no `$table` property → table name `posts`.
5. Class `OrderItem` → table `order_items` (snake_case + plural).
6. Model has `$rules` array → the create/edit form is BUILT from it automatically.
7. Rule contains `string` → form shows a text input.
8. Rule contains `text` → form shows a textarea.
9. Rule contains `bool` → form shows a checkbox (plus a hidden `0`, so unchecking
   really saves false). Built-in list/detail pages render it as `✓ yes` / `✗ no`.
10. Column name ends with `_id` and model `User` exists for `user_id` → form shows a dropdown filled from users. No code from you.
11. Rule contains `file` or `image` → form shows a file input and the upload is saved.
12. `$fillable = ['title']` → mass assignment allowed only for those columns.
13. You create a file `views/posts/index.php` → your file replaces the built-in page. Same for create/edit/show/form.
14. A migration file exists in `database/migrations/` → `php widdx migrate` runs it (name order, tracked in `migrations` table).
15. Class `Book` + method `author()` returning `belongsTo(Author::class)` → foreign key column `author_id` on books.
16. `Team` + `hasMany(Player::class)` → foreign key `team_id` on players.
17. `belongsToMany(Permission::class)` on Role → pivot table named alphabetically: `permission_role`.
18. File `App/Policies/BookPolicy.php` for model `App\Models\Book` → Gate finds it automatically.
19. A route action returns an array → client gets JSON. No Response object needed.
20. Forms POST `_method=PUT` or `_method=DELETE` → the real PUT/DELETE route runs.
21. `APP_LOCALE=ar` (or he/fa/ur/ps/sd/ug/yi/ku/dv) → every built-in page renders `dir="rtl"`. Zero extra work.
22. You write `resource('posts')` with NO `->public()` and no policy → the pages are LOCKED:
    a guest is redirected to `/login`, a logged-in user gets 403. Deny is the default.
23. You write `resource('posts')->public()` → the whole group is open to visitors. `make:crud`
    and `authRoutes()` scaffolding already emit `->public()` for you; hand-written lines do not.

---

## PART 5 — Build a CRUD feature (exact recipe, 4 steps)

Task: "add products with a name, a price, and a photo".

Step 1 — make the model + table + routes in one command:

```
php widdx make:crud Product name:string price:float photo:image?
```

Step 2 — run the migration:

```
php widdx migrate
```

Step 3 — open the app. Done. `/products` already does list/create/show/edit/delete. (URL is ALWAYS the plural name.)

Step 4 — only if the user asked for custom design:

- copy `core/views/crud/index.php` to `views/product/index.php` and edit it.

That is the whole job. Never write a controller for plain CRUD.

What `make:crud` produced:

- `app/Models/Product.php` (with `$fillable` and `$rules` filled in)
- `database/migrations/...create_products_table.php`
- one line `resource('products')->public();` added to `routes/web.php`
  (the `->public()` is what opens the pages; see PART 4 items 22-23)

Field types you may use: `string text integer float bool date email url file image fk(Model)` and a `?` suffix means optional. Example:

```
php widdx make:crud Invoice amount:float company:fk(Company) note:text?
```

`fk(Company)` creates a REAL foreign key + a dropdown + `exists:companies,id` validation. All free.

---

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

## PART 7 — Middleware (it is opt-in, one line to enable)

- Global CSRF: `\Widdx\Router::use(\Widdx\CsrfMiddleware::class);`
- Global security headers: `\Widdx\Router::use(\Widdx\SecurityHeaders::class);`
- Protect one route with login: `->middleware(\Widdx\AuthMiddleware::class)`
- Rate limit: `->middleware(new \Widdx\ThrottleMiddleware(60, 60))` — 60 requests per 60 seconds per IP.
- IP behind proxy: set `TRUSTED_PROXIES` in `.env` or throttling counts the proxy, not visitors.

CSRF warning: once CsrfMiddleware is on, EVERY POST form needs:

```
<?= csrf_field() ?>
```

Missing it gives status 419. This breaks the site silently — add it to all forms.

---

## PART 8 — Models

The smallest legal model (this is usually the ONLY file you write):

```
php widdx make:model Post
```

The file needs just:

```php
protected array $fillable = ['title', 'body'];
protected array $rules = ['title' => 'required|string|max:255', 'body' => 'required'];
```

Reading data:

- `Post::all()` — every row.
- `Post::find(3)` — row id 3 or null.
- `Post::findOrFail(3)` — row or a 404 page.
- `Post::where('title', 'like', '%a%')->get()` — filter. (also `!= < <= > >= IN NOT IN BETWEEN`)
- `Post::query()->orderBy('id', 'DESC')->limit(5)->get()` — chainable.
- `Post::count()`, `Post::where(...)->exists()`.

Writing data:

- `Post::create(['title' => 'x', 'body' => 'y'])` — validates `$rules`, honors `$fillable`, sets timestamps.
- `$p->save()` after changing attributes. `$p->delete()` to remove.

Columns exist at runtime: `$post->columns()` — an INSTANCE method, `Post::columns()` is a
fatal error. Forms and selects use it.

---

## PART 9 — Relations (4 kinds, names are all that matter)

Inside the model, one method per relation:

- `return $this->hasMany(Comment::class);` → `$post->comments` (array). FK `post_id` on comments.
- `return $this->hasOne(Profile::class);` → `$user->profile` (one or null). FK `user_id`.
- `return $this->belongsTo(Author::class);` → `$book->author`. FK `author_id` on books.
- `return $this->belongsToMany(Tag::class);` → `$post->tags`. Pivot table `post_tag` (alphabetical!).

Lazy access is free: `$post->comments` runs one query, only once, then caches.

Eager (kills N+1):

```
Post::with('comments.author')->get()
```

- Dots = depth. 3 relations = 3 total queries, not hundreds.
- After `with(...)`, `$post->comments` runs ZERO queries.

---

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

## PART 11 — Validation

One call checks everything and redirects back with the errors shown:

```
request()->validate(['email' => 'required|email|unique:users,email']);
```

Models do it automatically inside `create()` and `update()` using `$rules`.

Every rule, one line each:

- `required` — must be present and not empty.
- `nullable` — may be absent; other rules skipped when empty.
- `string` `int` `float` `bool` `numeric` `array` — type checks.
- `email` `url` — format checks.
- `min:5` `max:255` — length for text, value for numbers, KILOBYTES for files.
- `in:a,b,c` — must be one of the listed.
- `confirmed` — needs a matching `field_confirmation` input (passwords).
- `same:other` / `different:other` — compare two fields.
- `exists:users,id` — value must be a real row (used by fk dropdowns).
- `unique:users,email` — not taken. Optional 3rd part: `unique:users,email,5` ignores id 5.
- `file` — a real uploaded file.
- `image` — an uploaded image.
- `mimes:jpg,png` — uploaded extension check.

Important upload fact: `file/image/mimes` also PASS when the value is a stored path string.
Reason: update forms re-validate old records that have no new file. Do not change this.

Error output: HTML forms get redirected back; use `errors()` and `old('field')` in views.
Custom message: `validate($rules, ['email.required' => 'Email needed!'])`.
Default messages are translated by `APP_LOCALE` (en, ar included).

---

## PART 12 — Views (templates)

- `view('welcome')` renders `views/welcome.php`.
- `view('admin.panel')` renders `views/admin/panel.php`.
- Pass data: `return view('post', ['title' => $t]);` — then use `$title` in the file.
- Return it from a route or controller. Strings/arrays return directly too.
- ALWAYS escape: `<?= e($title) ?>`.
- Available helpers in views: `e()` `csrf_field()` `old()` `errors()` `session()` `auth()` `lang_attrs()`.
- Remember: views are GLOBAL namespace. `use` statements do not exist there. Class calls fail.
- Built-in pages live in `core/views/` (crud + auth). Copy one into `views/<name>/` to restyle it. Your file wins automatically.

---

## PART 13 — File uploads

You add ONE rule and get the whole feature:

```php
protected array $rules = ['cover' => 'nullable|image|mimes:jpg,png|max:2048'];
```

- The built-in form shows a file input (because the rule says `image`).
- On submit, the file is stored under `storage/app/` and the DB column keeps the path.
- Reading it yourself: `$f = request()->file('cover'); $f->store('covers');`
- `$f->originalName() $f->extension() $f->mimeType() $f->isImage() $f->sizeKb()` all exist.
- Editing a record and submitting WITHOUT a new file keeps the old path. Intended.

---

## PART 14 — Queues and jobs

A job is a class with `handle()`:

```php
class SendReceipt extends Widdx\Job {
    public function handle(array $data): void { … }
}
```

Push it:

```
queue(SendReceipt::class, ['id' => 7]);
```

- Default driver `sync` runs the job immediately (fine for dev and shared hosting).
- Set `QUEUE_DRIVER=database` in `.env` for real background processing.
- Jobs retry: `$tries = 3` default, wait = `$backoff * 2^(attempt-1)` seconds.
- After all tries fail the job moves to `failed_jobs`.

Cron-friendly commands (no daemon needed):

```
php widdx queue:work --max=50
```

- `queue:work` STOPS when the queue is empty. This is by design (shared-hosting safe).
- `php widdx queue:failed` lists dead jobs; `php widdx queue:retry all` revives them.

---

## PART 15 — Scheduler (one crontab line total)

Declare tasks in `routes/console.php`:

```php
Widdx\Schedule::command('queue:work --max=50')->everyMinute();
```

Available frequencies: `everyMinute everyFiveMinutes everyTenMinutes everyFifteenMinutes everyThirtyMinutes hourly daily monthly` and `interval(seconds)`. Add a condition with `->when(fn() => …)`. Run due tasks:

```
php widdx schedule:run
```

cPanel/crontab entry — the ONLY one you need:

```
* * * * * php /full/path/widdx schedule:run
```

State is remembered in `storage/app/schedule.json`, so repeated cron runs are safe.

---

## PART 16 — Mail

```
mail('to@site.com', 'Subject', 'welcome-template', ['name' => 'Ali']);
```

- Default driver `log` writes the mail to storage/logs (perfect for dev/tests).
- Set `MAIL_DRIVER=smtp` + `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION` for real delivery.
- The SMTP client is hand-written (sockets) — no external library, works on shared hosting.
- `mail()->attach('/path/file.pdf')->raw($to, $subject, $body);`
- Header injection into subjects/emails throws a MailException. Good.

---

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
- Full key list with comments also lives in `.env.example`.

---

## PART 19 — CLI commands (what each one does)

- `php widdx new folder` — create a project. `new:api` — JSON-flavored one.
- `php widdx serve --port=8000` — dev server. `--host=0.0.0.0` when it runs inside a container.
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

## PART 20 — Deploy

**Server with SSH:** point the web root to `public/`, run `php widdx migrate`, add one cron line for `schedule:run`, set `APP_DEBUG=false`.

**Shared hosting, NO terminal:**
1. Upload the project folder to `public_html/` (zip + extract is fine).
2. Create the MySQL database + user in cPanel.
3. Open `https://yoursite/install.php`.
4. Fill the form. It tests the DB first, writes `.env`, runs migrations, then locks forever.
5. `install.php` now returns 404. It can never overwrite your `.env`. That is security, not a bug.

Notes:
- Composer NOT required (fallback autoloader ships in `public/index.php`).
- `storage/` and its 4 subfolders must be writable (usually 755, sometimes 775).
- Cron: same `php widdx …` commands work from cPanel Cron Jobs.

---

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
10. WRONG: enabling `CsrfMiddleware` without `<?= csrf_field() ?>` in every form. RIGHT: add the field first, or 419 everywhere.
11. WRONG: trusting `X-Forwarded-For` implicitly. RIGHT: set `TRUSTED_PROXIES`; until then `Request::ip()` uses the real peer.
12. WRONG: inventing new config files. RIGHT: `config()` only loads `config/*.php`; extend those or use `.env`.
13. WRONG: writing `resource('x')` by hand and calling the 403/login-redirect a bug. RIGHT: it is fail-closed on purpose — add `->public()`, or a policy, or `protected bool $public = true` in your controller.
14. WRONG: reading a wall of "could not find driver" as broken code. RIGHT: that PHP lacks the PDO driver; `php widdx-test` now refuses to start and names the extension to install.

---

## PART 22 — "User asks for X → you write Y" cheat sheet

| Asked feature | Your move |
|---|---|
| A website with any list of records | `make:crud` (PART 5) |
| Search / filter / sort on a list | `resource('posts', PostController::class)` + your `index()` using `Post::where(…)` and `view('crud.index', $data)` — built-in pages are reusable |
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
