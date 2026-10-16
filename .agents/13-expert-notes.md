# Expert notes, each one measured

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 24 — Expert notes (each one measured, not assumed)

1. **The router takes the FIRST match.** A specific `post('/login', …)` registered before `authRoutes()`
   wins; the one `authRoutes()` registers afterwards is never reached. Order your route file accordingly,
   and keep the specific routes at the top.
2. **`php widdx serve` is one long-lived worker.** An edit to a file it already served is NOT reliably
   seen (measured: stale in 9/9 trials when the edit landed more than a second after the first request).
   New files it never loaded are fine. Restart is the whole fix — see PART 0.
3. **A 404 right after an edit is the stale worker, not your code.** Restart before you change anything.
   Rewriting working code to "fix" a stale 404 is how an agent destroys a project.
4. **Eager loading**: `Post::with('comments.author')->get()` — dots are depth, and N relations cost N+1
   queries total, not N×rows. After `with(...)`, `$post->comments` runs zero further queries.
5. **Relation return shapes**: `hasMany`/`belongsToMany` give an ARRAY (`count($u->roles)` is right);
   `hasOne`/`belongsTo` give one object or `null` (`count()` on them is a TypeError).
6. **`isset($post->comments)` and `$post->comments ?? []` see relations too** — from v0.3.7. On an older
   copy, `??` on a relation silently returned the default while the rows were there; if the project pins a
   pre-0.3.7 `core/`, read relations directly, not through `??`.
7. **Timestamps are automatic only when both columns exist.** `created_at` + `updated_at` → `save()` fills
   them. Raw-PDO DDL that adds them needs `Model::forgetSchemaCache('table')` in the same process;
   `Schema::` calls already do that.
8. **Validation on update needs the ignored id**: `unique:users,email` fails on the row you are editing, so
   build the rule as `unique:users,email,{that id}` — the third part is the id to skip (PART 11). If you do
   not need the rule, `Model::updateOrCreate($attributes, $values)` is usually what you meant.
9. **`file` / `image` / `mimes` deliberately PASS a stored path string**, because the update form re-submits
   the old value when no new file was chosen. Do not "fix" the rule — records lose their attachment.
10. **A hand-written checkbox needs a hidden twin**: `<input type="hidden" name="active" value="0">`
    before `<input type="checkbox" name="active" value="1">`, or unchecking saves nothing at all.
    (The built-in form already does this.)
11. **A hand-written PUT/DELETE form needs `<input type="hidden" name="_method" value="PUT">`** — browsers
    only send GET/POST; the framework rewrites the method from that field.
12. **A non-fillable field is dropped in silence.** `Post::create(['title' => 'x'])` without `'title'` in
    `$fillable` validates fine, saves the row, and the column stays NULL. No error, nothing in the log.
13. **A policy is deny-by-default per ability**: define `viewAny view create update delete` all five, or the
    missing one 403s. That is intended, not a bug to work around.
14. **`resource('x')` you typed by hand is LOCKED** (guests → `/login`, users → 403) until you add
    `->public()`, a policy, or `protected bool $public = true` in your controller. Generators emit
    `->public()`; hand-written lines do not.
15. **`$this->viewFolder()` is the plural table name** — the view override folder is `views/posts/`,
    never `views/post/`, and a wrong folder fails silently (the built-in page just keeps rendering).
16. **RTL for free**: `APP_LOCALE=ar` (or he/fa/ur/…) makes every built-in page render `dir="rtl"`. Your own
    layout must put `<?= lang_attrs() ?>` on the `<html>` tag or it will not.
17. **`db:wipe` drops every table** — never run it on a database with data you have not backed up; and
    `install.php` self-locks (404) after one successful run by design.
18. **Diagnostics that answer the question**: `php widdx route:list` (every route + name + middleware),
    `php -l <file>` (syntax), `php widdx test` (behavior), `curl -i` (what the visitor actually gets).
    `php widdx test` on the framework repo is the source of truth for how any feature works.
19. **The generated `views/layouts/app.php` has no hooks.** There is no `pageScripts`, `slot`, `push`, or
    section system — a data key your controller passes is only visible if the layout actually reads it.
    Want per-page JS? Add `<script src="<?= e($script) ?>"></script>` inside your own layout and keep
    passing `['pageScripts' => [...]]`, or just put the script tag in the page view.
20. **`public/` is the docroot.** `/assets/js/x.js` is the file `public/assets/js/x.js` — the dev server
    serves real files and hands everything else to the framework. Never reference a path from `views/`.
