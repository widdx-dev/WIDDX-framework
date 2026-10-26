# Widdx — Framework Introduction and Getting Started

[العربية](../ar/INTRO.md)

> A new **framework**, not an application. This document introduces what it is, why it exists, its mental model,
> and what you can build with it from day one. For **structured learning** with levels, modules, and labs,
> continue to [CURRICULUM.md](CURRICULUM.md). The binding sources of truth remain [AGENTS.md](../../../AGENTS.md)
> and [`.agents/`](../../../.agents) — this page is an introduction, not a replacement for them.
>
> Based on **Widdx v0.3.8**. The framework test suite (`php widdx test`) is the reference for measured behavior.

---

## 1. What Widdx Is, in One Sentence

**A PHP (8.1+) MVC framework with a zero-code approach: you supply names, and the framework infers the rest.**

- `resource('posts')->public()` provides complete CRUD (7 routes + 5 pages) without hand-writing its implementation.
- `authRoutes()` provides an authentication system: registration, login, logout, password recovery, and email verification.
- `php widdx make:crud Product name:string price:float photo:image` generates a model, table migration, and routes together.
- The framework lives **inside your project folder**. No mandatory Composer, npm, or build step, and no configuration file to edit for the basic start.

---

## 2. The Problem It Solves

| Audience | Problem | What Widdx Provides |
|---|---|---|
| **Programmer new to PHP** | Traditional MVC starts with many files, setup steps, and boilerplate | Start with one command and working pages |
| **Shared hosting (cPanel)** | No terminal, Composer, or daemon | Upload a folder and use `/install.php`; one cron entry for scheduled scripts |
| **An Arabic-language application** | Manually setting RTL on every page | `APP_LOCALE=ar` sets `dir="rtl"` on built-in pages |
| **Laravel developer** | Familiar method names that do not exist here | Warnings against guessing fatal calls, plus a transition track in the curriculum |
| **Developer using AI agents** | An agent writes more code over something that already works | A binding handbook (AGENTS.md) that becomes a contract for reviewing the agent |

The result is a framework that reduces the distance between requesting a feature and having it work. It removes repetition, not the need for understanding.

---

## 3. The Mental Model: “Names Speak” — the Heart of Widdx

In Widdx, you often **name** behavior instead of configuring it. Rules, names, files, and the environment tell the framework what to do.
These six groups capture the inference model you need to understand:

1. **Name → table:** `Post` → `posts`; `OrderItem` → `order_items`. No `$table` configuration is needed for conventional names.
2. **Rule → form:** Input forms are built from `$rules`: `string` → text input, `text` → textarea,
   `bool` → checkbox, `in:a,b` → select, `file/image` → file input, and a `*_id` column with a corresponding model → table-backed dropdown.
3. **File → override:** `views/posts/index.php` overrides the built-in page. The directory always uses the **plural** table name.
4. **Relationship → key:** `hasMany(Player)` implies `team_id` on players; `belongsToMany(Permission)` implies
   the alphabetically named pivot `permission_role`. You do not need to supply conventional key names.
5. **Presence → protection:** The presence of `App\Policies\BookPolicy` guards CRUD automatically. Omitting `->public()` without a policy
   locks access: guests → `/login`; logged-in users → 403. **Deny is the default.**
6. **Environment → behavior:** Having both `created_at` and `updated_at` enables automatic timestamps in `save()`;
   `APP_LOCALE=ar` gives every built-in page `lang="ar" dir="rtl"`.

> **Important reminder:** Read the full rules in [`.agents/07-inference.md`](../../../.agents/07-inference.md). Do not guess
> behavior from another framework. `Model` has no `__callStatic`: an invented method fails on the first request that calls it.
> This is a particularly common trap when moving from Laravel to Widdx.

---

## 4. What You Can Build Now — a Feature Tour

Each feature below is paired with its entry point:

- **CRUD without hand-written implementation:** `resource('posts')->public();` in `routes/web.php` — list/create/show/edit/delete.
- **Authentication:** `authRoutes();` with `php widdx make:auth` and `php widdx migrate`; check the generated route registration rather than adding it twice.
- **JSON API:** `apiResource('posts');` — CRUD under `/api/posts`; returning an array produces JSON.
- **Image/file uploads:** One model rule: `'cover' => 'nullable|image|mimes:jpg,png|max:2048'`.
- **Relationships and dropdowns:** `make:crud Invoice amount:float "company:fk(Company)"` gives you a foreign key,
  a dropdown, and `exists:companies,id` validation.
- **Validation:** `request()->validate(['email' => 'required|email|unique:users,email']);` with translated messages (`en`, `ar`).
- **Queues, mail, and scheduling:** `queue(Job::class, $data)`, `mail('to@x.com', 'S', 'template', $data)`,
  and `Schedule::command(...)->daily()` with one cron entry.
- **Built-in security:** CSRF and security headers are enabled by default; use `ThrottleMiddleware(n, s)` for rate limiting.
- **Deployment without a terminal:** Prefer a web root pointed at `public/`; on shared hosting, use the guarded root `.htaccess` fallback. `/install.php` validates the database and required canonical `APP_URL`, runs migrations, and locks itself.
- **Built-in RTL:** `APP_LOCALE=ar` (also he/fa/ur/ps/sd/ug/yi/ku/dv).

The “zero-code” approach comes from inferring repetitive details from names. That is what the curriculum teaches you to use.

---

## 5. Quick Start: Your First Application in 10 Minutes

You need PHP 8.1+ with `pdo_sqlite`. From the framework folder, run:

```bash
php widdx new myapp --demo   # Local-only public users CRUD demo
cd myapp
php widdx migrate            # Create the tables
php widdx serve              # Start the server
```

Open http://localhost:8000 — the welcome page appears. `/users` is public only in this explicit demo scaffold.
For a real project, run `php widdx new myapp` without `--demo`; CRUD is protected until you configure auth/policies.

Then add an entity, such as products:

```bash
php widdx make:crud Product name:string price:float photo:image?
php widdx migrate
# Restart php widdx serve (Ctrl-C, then start it again), and open:
#   /products   — list, create, show, edit, and delete are ready
```

> **The first trap:** `php widdx serve` is a long-lived worker. Once a file has been loaded, subsequent edits are not
> reliably observed (the handbook records 9/9 stale trials). Restart before rewriting code over a 404;
> then investigate further if the problem remains.

**Add an invoice linked to a company (relationship + dropdown):**

```bash
php widdx make:crud Company name:string
php widdx make:crud Invoice amount:float "company:fk(Company)" note:text?
php widdx migrate
# Restart serve, then open /invoices: the form has a company dropdown and exists validation.
```

---

## 6. A Small Application Track: Blog (Post + Comment + Tag)

This exercise combines major features in successive steps. See [CURRICULUM.md](CURRICULUM.md), modules M4–M5 and M10–M13, for details:

1. `php widdx make:auth` + `migrate` → users, with users CRUD locked automatically; create the first real account through `/register`.
2. `php widdx make:crud Post title:string body:text cover:image?` → posts with image uploads.
3. `php widdx make:crud Tag name:string`; then add a relationship method in `app/Models/Tag.php` returning
   `return $this->belongsToMany(Post::class);` — the pivot name `post_tag` is inferred.
4. `php widdx make:crud Comment body:text "post:fk(Post)" "user:fk(User)"` → linked comments.
5. Add `hasMany(Comment::class)` and `belongsToMany(Tag::class)` relationships to `Post`. Check the required pivot migration
   and relationship UI separately; inferring a name does not create the pivot table or its editing interface.
6. **Protection:** Create `app/Policies/CommentPolicy.php` with all five abilities (`viewAny view create update delete`).
   Its presence guards CRUD automatically; remove `->public()` from `resource('comments')`.
7. **Background mail:** Widdx has **no job/policy generator**. Create `app/Jobs/SendCommentNotification.php` manually
   (`class … extends \Widdx\Job` with `public function handle(array $data): void`), then dispatch it using
   `queue(SendCommentNotification::class, ['comment_id' => $id])` on a new comment. Read `storage/logs` when using the `log` driver.
8. **Appearance override:** Copy `core/views/crud/index.php` to `views/posts/index.php` and customize it — your file wins.
   Remember: the directory is **plural** (`posts`); a singular directory is silently ignored.

These steps combine authentication, authorization, relationships, uploads, and mail in a small application,
while leaving the repetitive CRUD implementation to the framework.

---

## 7. Traps to Know Before You Start

| Scenario | What Happens | Response |
|---|---|---|
| You edit a loaded file and then see 404 | A stale worker can return an old page or 404 | Restart `php widdx serve` before further diagnosis |
| POST without `<?= csrf_field() ?>` | 419 appears only on submission; the page looks fine | Include the helper in each form; its field is `csrf_token`, not `_token` |
| A template is placed in `views/post/index.php` | It is silently ignored; the built-in page remains | Use the plural directory: `views/posts/` |
| A field exists in the form and table but not `$fillable` | The row saves but the column can remain NULL | Include the intended field in `$fillable` |
| A hand-written `resource('x')` has no `->public()` | Guest → `/login`; logged-in user → 403 without a policy | Choose the intended policy or explicit public access; do not open private data to fix a 403 |

For the full picture, see the silent-failure table in `AGENTS.md` and [`.agents/11-pitfalls.md`](../../../.agents/11-pitfalls.md).
The rule is **no claim without evidence**: `php widdx route:list`, `php -l`, `php widdx test`, and `curl -i`.

---

## 8. Where to Find What — Documentation Map

| Document | Purpose | When to Read It |
|---|---|---|
| `AGENTS.md` | Binding layer: recipe, 13 laws, definition of done, and router | First, every time |
| `.agents/00–13.md` | Inference, routes, models, auth, validation, views, queues, deployment, pitfalls, API surface, expert notes | One relevant reference at a time |
| `README.md` | Human-oriented documentation with fuller examples | For deeper reading |
| `INTRO.md` (this file) | What the framework is, why it exists, and how to start | The first introduction |
| `CURRICULUM.md` | Structured learning: 6 levels, 21 modules, labs, and a capstone | For the full learning path |
| [AI_GUIDE.md](AI_GUIDE.md) | AI tools: setup, workflow, prompts, and verification | When building with an agent or chat assistant |
| `llms.txt` | Reading map for machines and humans | Quick reference |
| `tests/integration.php` | Executable specification through real HTTP behavior | To confirm precise behavior |
| `core/helpers.php` | Internally documented global helpers | To look up a helper |

---

## 9. Three Learning Tracks

- **New-to-Widdx track (≈ 65 hours):** Follow all of [CURRICULUM.md](CURRICULUM.md): 6 levels and a capstone.
- **Laravel developer track (≈ 35 hours):** Curriculum section 6 contains the comparison table and the costly “does not exist” traps.
  Do not skip this introduction or M2, M3, M10, and M21: prior assumptions cause as many problems as missing knowledge.
- **AI-agent operator track:** Start with [AI_GUIDE.md](AI_GUIDE.md) for setup, workflow, and reusable prompts,
  alongside the binding `AGENTS.md` and curriculum module M21 for reviewing output against the definition of done.

---

## 10. Your Next Step

1. Read [AGENTS.md](../../../AGENTS.md) — short and binding.
2. Complete section 5 until your first project works.
3. Add the blog exercise from section 6.
4. Choose a track from section 9, then open [CURRICULUM.md](CURRICULUM.md).

> **Maintenance:** Keep this document aligned with the framework. When behavior changes, update the affected examples.
> When unsure, read `.agents/12-api-surface.md` before writing code; do not guess callable names.
