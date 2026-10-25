# The real API surface

Part of the Widdx agent handbook. The binding laws and the routing table live in
[AGENTS.md](../AGENTS.md) — read that first; this file is reference, not permission.

## PART 23 — The real API surface (L2: call nothing outside this list)

Read from the code, not from memory. If you need a name that is not here, grep `core/` or run
`php widdx test` and read the test files — do NOT guess; `Model` has no `__callStatic`, so a guessed
static is a fatal error on the first request that touches it.

- **Global helpers** (usable anywhere, and the only functions a view may call):
  `abort apiResource app auth authorize authRoutes back cache component config csrf_field csrf_token
  db dd delete e env errors event flash get lang_attrs layout listen logger mail old paginate patch post
  put queue redirect request resource response route session singular storage storageRoutes transaction view`
- **Model statics**: `query all find findOrFail where create firstOrCreate updateOrCreate with count
  getRules inferTable forgetSchemaCache`.
  There is NO `Model::update()`, NO `Model::destroy()`, NO `Model::all()->pluck()`, NO `Model::truncate()`.
  Update = `$m->fill([...])->save()` (or `Model::where(...)->update([...])`, which is a QueryBuilder call).
  Delete many = `Model::where(...)->delete()`.
  The QueryBuilder forms skip `$rules`, `$fillable` AND `updated_at` — they are raw SQL with bindings, and
  they refuse to run without a `where()` first. Prefer `save()` unless you mean to bypass the model.
- **Model instance methods**: `save delete fill forceFill getFillable getSensitiveFillable isSensitiveFieldAllowed validate columns formColumns
  getTable getPrimaryKey relationLoaded setRelation getRelation toArray toJson
  hasMany hasOne belongsTo belongsToMany`. `columns()` is an INSTANCE method — `Post::columns()` is fatal. Sensitive fields are blocked by default; explicit opt-in requires both `$fillable` and `$sensitiveFillable`.
- **QueryBuilder** (what `Model::query()` / `Model::where()` / `db('users')` gives you):
  `select allowColumns where orWhere orderBy limit offset with get first find insert update delete
  count exists toSelectSql`. There is NO `pluck chunk sum avg min max in whereRaw groupBy having join`.
  For an aggregate the builder lacks, use `->get()` and count in PHP, or raw PDO:
  `db('users')->get()` / `\Widdx\Database::connection()->pdo()`.
- **Blueprint** (inside `Schema::create('x', function (Blueprint $t) { … })`):
  `id bigId increments string char text longText integer tinyInteger smallInteger mediumInteger bigInteger
  foreignId float double decimal boolean date datetime timestamp time json uuid enum timestamps index unique
  primary`. There is NO `unsignedBigInteger()` / `unsignedInteger()` — use `foreignId('user_id')` or
  `integer('user_id')`.
- **Column modifiers** (chained on the Blueprint call above):
  `nullable default useCurrent unique index primary autoIncrement constrained`.
- **CRUD view variables** (what your `views/<table>/…php` receives):
  index → `$items`, `$pagination`, `$routeBase`, `$title`, `$columns`, `$fields`;
  create/edit/show → `$item` (null on create) + the same four.
- **Auth view overrides**: `views/auth/login.php register.php forgot.php reset.php verify.php`.

---
