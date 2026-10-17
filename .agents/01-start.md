# Start a project and add your first list

Part of the Widdx agent handbook. The binding laws, the five-line recipe (PART 0) and the routing
table live in [AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

---

## PART 3 — Start a project (one step per line)

The framework folder is the tool. All `php widdx …` commands need you to be INSIDE it (or inside a project made from it).

Have PHP on your machine? Type:

```
php widdx new myapp
```

Starting a REAL app rather than trying the demo? `php widdx new myapp --no-demo` scaffolds the same
project with `resource('users')` NOT public, so guests are redirected to `/login` instead of seeing CRUD.

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

Step 3 — restart `php widdx serve` (Ctrl-C, then run it again), then open `/products`.
Done. `/products` already does list/create/show/edit/delete. (URL is ALWAYS the plural name.)

Step 4 — only if the user asked for custom design:

- copy `core/views/crud/index.php` to `views/products/index.php` and edit it.
  The folder name is the PLURAL table name (`posts`, `products`), never the model name:
  `views/posts/index.php`, `views/posts/create.php`, `views/posts/edit.php`, `views/posts/show.php`.
  A file named `views/post/index.php` is silently never loaded — you get the built-in page and no error.

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
