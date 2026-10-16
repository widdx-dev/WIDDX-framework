# What Widdx guesses from a name

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

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
24. Your table has `created_at` + `updated_at` → `save()` fills them by itself. Every generator
    adds those columns, so you never set anything. `protected bool $timestamps = false;` turns
    it off; there is no way to force them onto a table that lacks the columns (the INSERT would
    fail), so the property is only ever an opt-out. `Schema::` calls clear the cached answer, so a
    migration that adds the pair works in the same process; only raw-PDO DDL needs
    `Model::forgetSchemaCache('table')` afterwards.

---
