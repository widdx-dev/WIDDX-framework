<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Base Model.
 *
 * Infers table name from class name automatically:
 *   User → users | UserProfile → user_profiles | Category → categories
 *
 * Example:
 *   class User extends Model {} // That's it! Everything else is automatic.
 *
 * Power-ups (all optional):
 *   protected array $rules = ['email' => 'required|email']; // auto-validated by create()
 *   protected bool $timestamps = true;                       // auto-manage created_at/updated_at
 */
abstract class Model implements \JsonSerializable
{
    /** Override to skip inference: protected ?string $table = 'my_users'; */
    protected ?string $table = null;

    /** Primary key */
    protected string $primaryKey = 'id';

    /**
     * Fields allowed for mass assignment. Empty = allow all (except primary key).
     * Example: protected array $fillable = ['name', 'email'];
     */
    protected array $fillable = [];

    /** Fields hidden from toArray/toJson (e.g. passwords) */
    protected array $hidden = [];

    /**
     * Validation rules auto-applied by create().
     * Example: protected array $rules = ['email' => 'required|email'];
     */
    protected array $rules = [];

    /**
     * Auto-manage created_at / updated_at columns.
     * Requires those columns in the table.
     */
    protected bool $timestamps = false;

    /** Row values */
    protected array $attributes = [];

    /** Loaded relation results (lazy property access + eager loading) */
    protected array $relations = [];

    /** Does the record exist in the database? */
    protected bool $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ---------- Table Name Inference ----------

    public function getTable(): string
    {
        if (!empty($this->table)) {
            return $this->table;
        }
        return static::inferTable();
    }

    /** Primary key column name (default 'id'). */
    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public static function inferTable(): string
    {
        $short = (new \ReflectionClass(static::class))->getShortName();
        // CamelCase → snake_case
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        return self::pluralize($snake);
    }

    /**
     * Actual database column names (via information_schema / PRAGMA).
     * Used by zero-code CRUD views; returns [] when the table is missing.
     */
    public function columns(): array
    {
        return Database::connection()->tableColumns($this->getTable());
    }

    /** Editable columns: table columns minus pk/timestamps/hidden. */
    public function formColumns(): array
    {
        $skip = array_unique([$this->primaryKey, 'created_at', 'updated_at', 'id', ...$this->hidden]);
        $cols = array_values(array_diff($this->columns(), $skip));
        if (!empty($this->fillable)) {
            $fillableOnly = array_values(array_intersect($cols, $this->fillable));
            $cols = $fillableOnly ?: $cols;
        }
        return $cols;
    }

    /** Simple English pluralization covering most cases */
    protected static function pluralize(string $word): string
    {
        if (str_ends_with($word, 'y') && !str_ends_with($word, 'ey') && !str_ends_with($word, 'ay')) {
            return substr($word, 0, -1) . 'ies'; // category → categories
        }
        if (str_ends_with($word, 's') || str_ends_with($word, 'x') || str_ends_with($word, 'ch') || str_ends_with($word, 'sh')) {
            return $word . 'es';
        }
        return $word . 's'; // user → users
    }

    // ---------- Magic Field Access ----------

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->attributes)) {
            return $this->attributes[$name];
        }
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }
        // Magic relation access: $user->posts — lazy unless already eager-loaded.
        if (method_exists($this, $name)) {
            $result = $this->{$name}();
            if ($result instanceof Relations\Relation) {
                return $this->relations[$name] = $result->getResults();
            }
        }
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    public function __toString(): string
    {
        return $this->toJson();
    }

    /** Internal fill without filtering (used when hydrating from DB) */
    public function forceFill(array $data): self
    {
        $this->attributes = array_merge($this->attributes, $data);
        return $this;
    }

    public function markAsExisting(): self
    {
        $this->exists = true;
        return $this;
    }

    /** Mass assignment respecting $fillable */
    public function fill(array $data): self
    {
        foreach ($data as $key => $value) {
            if ($key === $this->primaryKey && $this->exists) {
                continue;
            }
            if (!empty($this->fillable) && !in_array($key, $this->fillable, true)) {
                continue;
            }
            $this->attributes[$key] = $value;
        }
        return $this;
    }

    // ---------- Validation ----------

    /** Model validation rules */
    public static function getRules(): array
    {
        return (new static())->rules;
    }

    /**
     * Validate data against $rules.
     * @throws Exceptions\ValidationException
     */
    public function validate(array $data): array
    {
        if (empty($this->rules)) {
            return $data;
        }
        $request = new Request([], $data, [], [], []);
        return $request->validate($this->rules);
    }

    // ---------- Query Builder ----------

    public static function query(): QueryBuilder
    {
        $instance = new static();
        return new QueryBuilder($instance->getTable(), static::class);
    }

    /** @return static[] */
    public static function all(): array
    {
        return static::query()->get();
    }

    public static function find(mixed $id): ?static
    {
        $instance = new static();
        /** @var static|null */
        return static::query()->find($id, $instance->primaryKey);
    }

    /** Like find but throws 404 exception when not found */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);
        if ($model === null) {
            throw new Exceptions\NotFoundException('Record not found (ID = ' . $id . ').');
        }
        return $model;
    }

    public static function where(string $column, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        $q = static::query();
        return func_num_args() === 2
            ? $q->where($column, $operatorOrValue)
            : $q->where($column, $operatorOrValue, $value);
    }

    /** Create with automatic validation when $rules are defined */
    public static function create(array $data): static
    {
        $model = new static();
        $data = $model->validate($data);
        $model->fill($data);
        $model->save();
        return $model;
    }

    /** Find first matching record or create it */
    public static function firstOrCreate(array $attributes, array $values = []): static
    {
        $query = static::query();
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }
        $found = $query->first();
        if ($found !== null) {
            return $found;
        }
        return static::create(array_merge($attributes, $values));
    }

    /** Update matching record or create it */
    public static function updateOrCreate(array $attributes, array $values): static
    {
        $query = static::query();
        foreach ($attributes as $key => $value) {
            $query->where($key, $value);
        }
        $found = $query->first();
        if ($found !== null) {
            $found->fill($values);
            $found->save();
            return $found;
        }
        return static::create(array_merge($attributes, $values));
    }

    // ---------- Save & Delete ----------

    public function save(): bool
    {
        // Auto-manage timestamps when enabled
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');
            if (!$this->exists && !isset($this->attributes['created_at'])) {
                $this->attributes['created_at'] = $now;
            }
            $this->attributes['updated_at'] = $now;
        }

        if ($this->exists && isset($this->attributes[$this->primaryKey])) {
            $id = $this->attributes[$this->primaryKey];
            $data = $this->attributes;
            unset($data[$this->primaryKey]);
            if (empty($data)) {
                return true;
            }
            static::query()->where($this->primaryKey, $id)->update($data);
            return true;
        }

        $data = $this->attributes;
        // Don't insert empty primary key — let AUTOINCREMENT handle it
        if (array_key_exists($this->primaryKey, $data) && ($data[$this->primaryKey] === null || $data[$this->primaryKey] === '')) {
            unset($data[$this->primaryKey]);
        }
        $id = static::query()->insert($data);
        if (!isset($this->attributes[$this->primaryKey]) && is_numeric((string) $id)) {
            $this->attributes[$this->primaryKey] = (int) $id;
        }
        $this->exists = true;
        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists || !isset($this->attributes[$this->primaryKey])) {
            throw new Exceptions\ModelException('Cannot delete unsaved model (no primary key).');
        }
        $count = static::query()->where($this->primaryKey, $this->attributes[$this->primaryKey])->delete();
        if ($count > 0) {
            $this->exists = false;
        }
        return $count > 0;
    }

    // ---------- Relations ----------

    /**
     * One-to-many. Keys are INFERRED from class names when omitted:
     *
     *   public function posts() { return $this->hasMany(Post::class); }
     *   // → posts.user_id = $this->id
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasMany
    {
        return new Relations\HasMany(
            $this,
            $related,
            $foreignKey ?? (Relations\Relation::snake(static::class) . '_id'),
            $localKey ?? $this->primaryKey
        );
    }

    /** One-to-one: public function profile() { return $this->hasOne(Profile::class); } */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): Relations\HasOne
    {
        return new Relations\HasOne(
            $this,
            $related,
            $foreignKey ?? (Relations\Relation::snake(static::class) . '_id'),
            $localKey ?? $this->primaryKey
        );
    }

    /**
     * Inverse: public function author() { return $this->belongsTo(Author::class); }
     * // → $this->author_id = authors.id   (fk inferred from the RELATED class)
     */
    public function belongsTo(string $related, ?string $foreignKey = null, string $ownerKey = 'id'): Relations\BelongsTo
    {
        return new Relations\BelongsTo(
            $this,
            $related,
            $foreignKey ?? (Relations\Relation::snake($related) . '_id'),
            $ownerKey
        );
    }

    /**
     * Many-to-many through a pivot. Everything inferred by convention:
     *   Role belongsToMany Permission → pivot 'permission_role',
     *   keys role_id / permission_id.
     */
    public function belongsToMany(
        string $related,
        ?string $pivot = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
    ): Relations\BelongsToMany {
        $me = Relations\Relation::snake(static::class);
        $them = Relations\Relation::snake($related);
        $pair = [$me, $them];
        sort($pair);
        return new Relations\BelongsToMany(
            $this,
            $related,
            $pivot ?? implode('_', $pair),
            $foreignPivotKey ?? ($me . '_id'),
            $relatedPivotKey ?? ($them . '_id')
        );
    }

    /** Relation cache access (magic __get + eager loading use these). */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /** Eager-loading entry point: User::with('posts.author')->get() */
    public static function with(string ...$relations): QueryBuilder
    {
        return static::query()->with(...$relations);
    }

    /**
     * Bulk-load dot-chained relations for a set of same-class models.
     * One query per relation level — N+1 dies here.
     *
     * @param Model[] $models
     */
    public static function loadEager(array $models, array $names): void
    {
        if ($models === []) {
            return;
        }
        $first = $models[0];
        foreach ($names as $definition) {
            [$head, $rest] = array_pad(explode('.', $definition, 2), 2, null);
            if (!method_exists($first, $head)) {
                throw new Exceptions\ModelException(
                    'Undefined relation [' . static::class . '::' . $head . '()]. '
                    . 'Define it as: public function ' . $head . '() { return $this->hasMany(...); }'
                );
            }
            $relation = $first->{$head}();
            if (!$relation instanceof Relations\Relation) {
                throw new Exceptions\ModelException(
                    static::class . '::' . $head . '() must return a relation ($this->hasMany/hasOne/belongsTo/belongsToMany(...)).'
                );
            }
            $relation->eagerLoad($models, $head);

            if ($rest !== null && $rest !== '') {
                $children = [];
                foreach ($models as $model) {
                    $value = $model->getRelation($head);
                    if ($value instanceof Model) {
                        $children[] = $value;
                    } elseif (is_array($value)) {
                        foreach ($value as $child) {
                            if ($child instanceof Model) {
                                $children[] = $child;
                            }
                        }
                    }
                }
                if ($children !== []) {
                    get_class($children[0])::loadEager($children, [$rest]);
                }
            }
        }
    }

    // ---------- Conversion ----------

    public function toArray(): array
    {
        $data = $this->attributes;
        foreach ($this->hidden as $h) {
            unset($data[$h]);
        }
        return $data;
    }

    /** Serialize via toArray() so json_encode() sees fields, not protected props */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }
}
