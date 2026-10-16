# Models and relations

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

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

- `Post::create(['title' => 'x', 'body' => 'y'])` — validates `$rules`, honors `$fillable`,
  fills `created_at`/`updated_at` when the table has them (generators always add them).
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
`isset($post->comments)` and `$post->comments ?? []` see relations too (they resolve and
cache like the read itself), so a relation never looks empty to a `??` default.

Eager (kills N+1):

```
Post::with('comments.author')->get()
```

- Dots = depth. 3 relations = 3 total queries, not hundreds.
- After `with(...)`, `$post->comments` runs ZERO queries.

---
