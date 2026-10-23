<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Query Builder: fluent interface over PDO.
 *
 * Example:
 *   db('users')->where('age', '>', 18)->orderBy('name')->limit(10)->get();
 */
class QueryBuilder
{
    protected string $table;
    protected ?string $modelClass;
    protected \PDO $pdo;

    /** Current database driver — determines identifier quoting and LIMIT/OFFSET syntax */
    protected string $driver;

    protected array $selects = ['*'];
    protected array $wheres = [];   // [col, op, value, boolean]
    protected array $orders = [];   // [col, dir]
    protected ?int $limit = null;
    protected ?int $offset = null;

    /** Whitelist of allowed columns for orderBy/select (empty = allow all) */
    protected array $allowedColumns = [];

    /** Relations to eager-load after hydration (Model::with('posts.author')) */
    protected array $eager = [];

    /** Whitelist of allowed SQL operators */
    protected array $allowedOperators = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

    public function __construct(string $table, ?string $modelClass = null, ?\PDO $pdo = null, string $driver = '')
    {
        if (preg_match('/\\A[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*\\z/', $table) !== 1) {
            throw new Exceptions\DatabaseException("Invalid table name [{$table}]. Use simple identifiers, optionally schema-qualified.");
        }
        $this->table = $table;
        $this->modelClass = $modelClass;
        // 2.7: when no pdo/driver is passed, both come from the real default
        // connection. Deriving the driver here is what makes identifier quoting
        // correct on mysql/pgsql — a Model built with `new QueryBuilder($t, __class__)`
        // used to fall through to sqlite's ANSI quotes and break on MySQL.
        if ($pdo === null && $driver === '') {
            $conn = Database::connection();
            $this->pdo = $conn->pdo();
            $this->driver = strtolower($conn->driver());
            return;
        }
        $this->pdo = $pdo ?? Database::connection()->pdo();
        $this->driver = strtolower($driver !== '' ? $driver : 'sqlite');
    }

    // ---------- Fluent Build ----------

    public function select(string ...$columns): self
    {
        if (!empty($columns)) {
            foreach ($columns as $column) {
                if ($column !== '*') {
                    $this->validateColumn($column);
                }
            }
            $this->selects = $columns;
        }
        return $this;
    }

    /**
     * Restrict which columns this query may reference (empty = allow all).
     * Anything outside the whitelist throws DatabaseException on use.
     *
     *   db('users')->allowColumns(['id', 'name', 'email'])->get()
     */
    public function allowColumns(array $columns): self
    {
        $this->allowedColumns = array_values($columns);
        return $this;
    }

    /**
     * where('age', 18) or where('age', '>', 18) or where('name', 'LIKE', '%a%')
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operatorOrValue;
            $operator = is_array($value) ? 'IN' : '=';
        } else {
            $operator = strtoupper((string) $operatorOrValue);
        }
        $this->validateOperator($operator);
        $this->validateColumn($column);
        $this->wheres[] = [$column, $operator, $value, 'AND'];
        return $this;
    }

    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operatorOrValue;
            $operator = is_array($value) ? 'IN' : '=';
        } else {
            $operator = strtoupper((string) $operatorOrValue);
        }
        $this->validateOperator($operator);
        $this->validateColumn($column);
        $this->wheres[] = [$column, $operator, $value, 'OR'];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->validateColumn($column);
        $this->orders[] = [$column, $direction];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Eager-load relations for the hydrated models — kills the N+1 problem.
     * Dot chains nest: with('posts.author').
     */
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            if ($relation !== '' && !in_array($relation, $this->eager, true)) {
                $this->eager[] = $relation;
            }
        }
        return $this;
    }

    // ---------- Execution ----------

    /** Fetch all results (array of models or stdClass) */
    public function get(): array
    {
        [$sql, $bindings] = $this->toSelectSql();
        $rows = $this->run($sql, $bindings);
        $models = array_map([$this, 'hydrate'], $rows);
        if ($this->eager !== [] && $this->modelClass !== null) {
            ($this->modelClass)::loadEager($models, $this->eager);
        }
        return $models;
    }

    /** First result or null */
    public function first(): mixed
    {
        $clone = clone $this;
        $rows = $clone->limit(1)->get();
        return $rows[0] ?? null;
    }

    /** Find by primary key */
    public function find(mixed $id, string $primaryKey = 'id'): mixed
    {
        return $this->where($primaryKey, $id)->first();
    }

    /** Insert a row and return the new ID */
    public function insert(array $data): int|string
    {
        if (empty($data)) {
            throw new Exceptions\DatabaseException('Cannot insert empty row: pass a non-empty array to insert().');
        }
        $cols = array_keys($data);
        foreach ($cols as $col) {
            $this->validateWriteColumn((string) $col);
        }
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->wrap($this->table),
            implode(', ', array_map([$this, 'wrap'], $cols)),
            implode(', ', $placeholders)
        );
        $this->run($sql, $data);
        return $this->lastInsertId();
    }

    /** Update matching rows and return count */
    public function update(array $data): int
    {
        if (empty($data)) {
            throw new Exceptions\DatabaseException('Cannot update with empty data.');
        }
        if (empty($this->wheres)) {
            throw new Exceptions\DatabaseException(
                'Refusing to execute UPDATE without where() — protection against updating the entire table. Add where() first.'
            );
        }
        $sets = [];
        $bindings = [];
        foreach ($data as $col => $val) {
            $this->validateColumn((string) $col);
            $sets[] = $this->wrap((string) $col) . ' = :set_' . $col;
            $bindings['set_' . $col] = $val;
        }
        [$whereSql, $whereBindings] = $this->buildWhere($bindings === [] ? 0 : count($bindings));
        $sql = sprintf('UPDATE %s SET %s %s', $this->wrap($this->table), implode(', ', $sets), $whereSql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge($bindings, $whereBindings));
        return $stmt->rowCount();
    }

    /** Delete matching rows and return count */
    public function delete(): int
    {
        if (empty($this->wheres)) {
            throw new Exceptions\DatabaseException(
                'Refusing to execute DELETE without where() — protection against deleting the entire table. Add where() first.'
            );
        }
        [$whereSql, $bindings] = $this->buildWhere();
        $sql = sprintf('DELETE FROM %s %s', $this->wrap($this->table), $whereSql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    public function count(): int
    {
        // count() is the total of everything matching the WHERE clause — unless the
        // caller set a limit/offset, in which case it means "how many rows THIS page
        // has", so `where(...)->limit(10)->count()` no longer silently returns the
        // global total.
        if ($this->limit !== null || $this->offset !== null) {
            [$inner, $bindings] = $this->toSelectSql();
            // Bare alias, no AS: Oracle rejects `FROM (...) AS x` while every other
            // supported driver accepts both spellings.
            $rows = $this->run('SELECT COUNT(*) AS aggregate FROM (' . $inner . ') __w', $bindings);
            return (int) ($rows[0]['aggregate'] ?? 0);
        }
        // NOTE: build the SQL directly — the aggregate must NOT go through
        // wrap(), which quotes identifiers and would turn COUNT(*) into a
        // string literal (making every count() silently return 0).
        [$whereSql, $bindings] = $this->buildWhere();
        $sql = 'SELECT COUNT(*) AS aggregate FROM ' . $this->wrap($this->table) . ' ' . $whereSql;
        $rows = $this->run($sql, $bindings);
        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ---------- Internal ----------

    /** Convert row to model (if exists) or generic object */
    protected function hydrate(array $row): mixed
    {
        if ($this->modelClass !== null && class_exists($this->modelClass)) {
            $model = new ($this->modelClass)();
            if ($model instanceof Model) {
                $model->forceFill($row);
                $model->markAsExisting();
                return $model;
            }
        }
        return (object) $row;
    }

    /** @return array{0:string,1:array} */
    public function toSelectSql(): array
    {
        $cols = implode(', ', array_map(
            fn($c) => $c === '*' ? '*' : $this->wrap($c),
            $this->selects
        ));
        $sql = sprintf('SELECT %s FROM %s', $cols, $this->wrap($this->table));
        [$whereSql, $bindings] = $this->buildWhere();
        $sql .= ' ' . $whereSql;

        if (!empty($this->orders)) {
            $parts = array_map(fn($o) => $this->wrap($o[0]) . ' ' . $o[1], $this->orders);
            $sql .= ' ORDER BY ' . implode(', ', $parts);
        } elseif ($this->driver === 'sqlsrv' && ($this->limit !== null || $this->offset !== null)) {
            // SQL Server requires ORDER BY with OFFSET/FETCH syntax
            $sql .= ' ORDER BY (SELECT NULL)';
        }
        $sql .= $this->buildLimitOffset();
        return [trim($sql), $bindings];
    }

    /**
     * LIMIT/OFFSET syntax by driver:
     * - mysql/sqlite/pgsql: LIMIT x OFFSET y
     * - sqlsrv (SQL Server) and oci (Oracle 12c+): OFFSET x ROWS FETCH NEXT y ROWS ONLY
     */
    protected function buildLimitOffset(): string
    {
        if ($this->limit === null && $this->offset === null) {
            return '';
        }
        if (in_array($this->driver, ['sqlsrv', 'mssql', 'oci', 'oracle'], true)) {
            $offset = $this->offset ?? 0;
            $sql = " OFFSET {$offset} ROWS";
            if ($this->limit !== null) {
                $sql .= " FETCH NEXT {$this->limit} ROWS ONLY";
            }
            return $sql;
        }
        $sql = '';
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }
        return $sql;
    }

    /** Get last insert ID — handles driver differences (e.g. pgsql sequences) */
    protected function lastInsertId(): int|string
    {
        try {
            if (in_array($this->driver, ['pgsql', 'postgres', 'postgresql'], true)) {
                // PostgreSQL requires sequence name: table_id_seq (default naming)
                $short = explode('.', $this->table);
                $sequence = end($short) . '_id_seq';
                try {
                    return $this->pdo->lastInsertId($sequence);
                } catch (\Throwable) {
                    return $this->pdo->lastInsertId();
                }
            }
            return $this->pdo->lastInsertId();
        } catch (\Throwable) {
            // Drivers without auto last-insert-id (e.g. Oracle without trigger) — return empty
            return '';
        }
    }

    /** @return array{0:string,1:array} */
    protected function buildWhere(int $startIndex = 0): array
    {
        if (empty($this->wheres)) {
            return ['', []];
        }
        $parts = [];
        $bindings = [];
        $i = $startIndex;
        foreach ($this->wheres as $idx => [$col, $op, $val, $boolean]) {
            $prefix = $idx === 0 ? 'WHERE' : $boolean;
            if ($val === null) {
                $parts[] = $prefix . ' ' . $this->wrap($col) . ($op === '!=' || $op === '<>' ? ' IS NOT NULL' : ' IS NULL');
                continue;
            }
            // IN / NOT IN with an array value: expand to a placeholder list.
            if (is_array($val)) {
                if ($op !== 'IN' && $op !== 'NOT IN') {
                    throw new Exceptions\DatabaseException(
                        "Array value for [{$col}] needs an IN or NOT IN operator, got [{$op}]. "
                        . 'Use where($col, $array) for "in this list", or where($col, \'IN\', $array).'
                    );
                }
                if ($val === []) {
                    // Empty set: IN matches nothing, NOT IN matches everything.
                    $parts[] = $prefix . ' ' . ($op === 'IN' ? '(1 = 0)' : '(1 = 1)');
                    continue;
                }
                $keys = [];
                foreach (array_values($val) as $v) {
                    $key = 'w' . $i++;
                    $keys[] = ':' . $key;
                    $bindings[$key] = $v;
                }
                $parts[] = $prefix . ' ' . $this->wrap($col) . " {$op} (" . implode(', ', $keys) . ')';
                continue;
            }
            $key = 'w' . $i++;
            $parts[] = $prefix . ' ' . $this->wrap($col) . " {$op} :{$key}";
            $bindings[$key] = $val;
        }
        return [implode(' ', $parts), $bindings];
    }

    protected function run(string $sql, array $bindings): array|int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($bindings);
            if (str_starts_with(strtoupper(ltrim($sql)), 'SELECT')) {
                return $stmt->fetchAll();
            }
            return $stmt->rowCount();
        } catch (\PDOException $e) {
            throw new Exceptions\DatabaseException(
                "Database error: {$e->getMessage()}\nQuery: {$sql}"
            );
        }
    }

    /**
     * Quote table/column names by driver:
     * - mysql: `name`
     * - sqlsrv: [name]
     * - others (sqlite/pgsql/oracle): "name"
     */
    protected function wrap(string $identifier): string
    {
        $parts = explode('.', $identifier);
        $parts = array_map(function ($p) {
            return match ($this->driver) {
                'mysql' => '`' . str_replace('`', '', $p) . '`',
                'sqlsrv', 'mssql' => '[' . str_replace(['[', ']'], '', $p) . ']',
                default => '"' . str_replace('"', '', $p) . '"',
            };
        }, $parts);
        return implode('.', $parts);
    }

    /** Validate SQL operator against whitelist */
    protected function validateOperator(string $operator): void
    {
        if (!in_array($operator, $this->allowedOperators, true)) {
            throw new Exceptions\DatabaseException(
                "Invalid SQL operator [{$operator}]. Allowed: " . implode(', ', $this->allowedOperators)
            );
        }
    }

    /** Validate columns used in INSERT/UPDATE placeholders (no qualification). */
    protected function validateWriteColumn(string $column): void
    {
        if (preg_match('/\\A[A-Za-z_][A-Za-z0-9_]*\\z/', $column) !== 1) {
            throw new Exceptions\DatabaseException("Invalid write column name [{$column}].");
        }
        $this->validateColumn($column);
    }

    /** Validate column name against whitelist (if set) */
    protected function validateColumn(string $column): void
    {
        if (!empty($this->allowedColumns) && !in_array($column, $this->allowedColumns, true)) {
            throw new Exceptions\DatabaseException(
                "Column [{$column}] is not in the allowed list. Allowed: " . implode(', ', $this->allowedColumns)
            );
        }
        // Strip dangerous characters as baseline protection
        if (preg_match('/[^a-zA-Z0-9_.]/', $column)) {
            throw new Exceptions\DatabaseException(
                "Invalid column name [{$column}]. Only alphanumeric, underscore, and dot are allowed."
            );
        }
    }
}
