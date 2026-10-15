<?php

declare(strict_types=1);

namespace Widdx;

/**
 * 2.6: a tiny, portable table builder. Columns and indexes are collected
 * here and turned into per-driver SQL by toSql(). Deliberately minimal —
 * raw $pdo->exec() remains available for anything not covered.
 */
class Blueprint
{
    /** @var Column[] */
    public array $columns = [];

    /** @var array<int,array{type:string,cols:string[],name:string}> */
    public array $indexes = [];

    public function __construct(public string $table)
    {
    }

    public function id(string $name = 'id'): Column
    {
        return $this->addColumn($name, 'id');
    }

    public function bigId(string $name = 'id'): Column
    {
        return $this->addColumn($name, 'bigid');
    }

    public function increments(string $name): Column
    {
        return $this->addColumn($name, 'id');
    }

    public function string(string $name, int $length = 255): Column
    {
        $c = $this->addColumn($name, 'string');
        $c->length = $length;
        return $c;
    }

    public function char(string $name, int $length = 255): Column
    {
        $c = $this->addColumn($name, 'char');
        $c->length = $length;
        return $c;
    }

    public function text(string $name): Column
    {
        return $this->addColumn($name, 'text');
    }

    public function longText(string $name): Column
    {
        return $this->addColumn($name, 'longtext');
    }

    public function integer(string $name): Column
    {
        return $this->addColumn($name, 'integer');
    }

    public function tinyInteger(string $name): Column
    {
        return $this->addColumn($name, 'tinyint');
    }

    public function smallInteger(string $name): Column
    {
        return $this->addColumn($name, 'smallint');
    }

    public function mediumInteger(string $name): Column
    {
        return $this->addColumn($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->addColumn($name, 'bigint');
    }

    public function foreignId(string $name): Column
    {
        // matches id()/increments() so the FK column type lines up on pgsql.
        return $this->addColumn($name, 'integer');
    }

    public function float(string $name): Column
    {
        return $this->addColumn($name, 'float');
    }

    public function double(string $name): Column
    {
        return $this->addColumn($name, 'float');
    }

    public function decimal(string $name, int $precision = 8, int $scale = 2): Column
    {
        $c = $this->addColumn($name, 'decimal');
        $c->precision = $precision;
        $c->scale = $scale;
        return $c;
    }

    public function boolean(string $name): Column
    {
        return $this->addColumn($name, 'boolean');
    }

    public function date(string $name): Column
    {
        return $this->addColumn($name, 'date');
    }

    public function datetime(string $name): Column
    {
        return $this->addColumn($name, 'datetime');
    }

    public function timestamp(string $name): Column
    {
        return $this->addColumn($name, 'timestamp');
    }

    public function time(string $name): Column
    {
        return $this->addColumn($name, 'time');
    }

    public function json(string $name): Column
    {
        return $this->addColumn($name, 'json');
    }

    public function uuid(string $name): Column
    {
        return $this->addColumn($name, 'uuid');
    }

    public function enum(string $name, array $values): Column
    {
        $c = $this->addColumn($name, 'enum');
        $c->enumValues = array_values(array_map('strval', $values));
        return $c;
    }

    /** created_at + updated_at, both nullable timestamps. */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function index(string|array $columns, ?string $name = null): void
    {
        $cols = (array) $columns;
        $this->indexes[] = ['type' => 'index', 'cols' => $cols, 'name' => $name ?? $this->autoName('idx', $cols)];
    }

    public function unique(string|array $columns, ?string $name = null): void
    {
        $cols = (array) $columns;
        $this->indexes[] = ['type' => 'unique', 'cols' => $cols, 'name' => $name ?? $this->autoName('uniq', $cols)];
    }

    public function primary(string|array $columns): void
    {
        $cols = (array) $columns;
        $this->indexes[] = ['type' => 'primary', 'cols' => $cols, 'name' => 'primary'];
    }

    protected function addColumn(string $name, string $type): Column
    {
        $c = new Column($name, $type);
        $this->columns[] = $c;
        return $c;
    }

    protected function autoName(string $prefix, array $cols): string
    {
        return $prefix . '_' . $this->table . '_' . implode('_', $cols);
    }

    /**
     * @return string[] ordered SQL statements: CREATE TABLE then any indexes.
     */
    public function toSql(string $driver = 'sqlite'): array
    {
        $defs = [];
        foreach ($this->columns as $c) {
            $defs[] = $this->columnSql($c, $driver);
        }
        $ifne = in_array($driver, ['oci', 'oracle'], true) ? '' : 'IF NOT EXISTS ';
        $stmts = ["CREATE TABLE {$ifne}{$this->table} (\n  " . implode(",\n  ", $defs) . "\n)"];
        foreach ($this->indexes as $idx) {
            if ($idx['type'] === 'primary') {
                continue; // single auto pk already emitted inline
            }
            $cols = implode(', ', $idx['cols']);
            if ($idx['type'] === 'unique') {
                $stmts[] = "CREATE UNIQUE INDEX {$idx['name']} ON {$this->table} ({$cols})";
            } else {
                $stmts[] = "CREATE INDEX {$idx['name']} ON {$this->table} ({$cols})";
            }
        }
        return $stmts;
    }

    public function columnSql(Column $c, string $driver): string
    {
        $type = $this->typeSql($c, $driver);
        if ($c->isPrimary && $c->autoIncrement) {
            return $c->name . ' ' . $type; // pk clause already embedded in type
        }
        $sql = $c->name . ' ' . $type;
        $sql .= $c->nullable ? ' NULL' : ' NOT NULL';
        if ($c->isPrimary) {
            $sql .= ' PRIMARY KEY';
        }
        if ($c->hasDefault) {
            $sql .= ' DEFAULT ' . ($c->defaultRaw ? (string) $c->default : $this->literal($c->default, $driver));
        }
        if ($c->uniqueFlag) {
            $sql .= ' UNIQUE';
        }
        if ($c->references !== null) {
            $sql .= ' REFERENCES ' . $c->references;
        }
        return $sql;
    }

    protected function typeSql(Column $c, string $driver): string
    {
        switch ($c->type) {
            case 'id':
                return match ($driver) {
                    'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
                    'pgsql' => 'SERIAL PRIMARY KEY',
                    'sqlsrv', 'mssql' => 'INT IDENTITY(1,1) PRIMARY KEY',
                    'oci', 'oracle' => 'NUMBER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                    default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                };
            case 'bigid':
                return match ($driver) {
                    'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
                    'pgsql' => 'BIGSERIAL PRIMARY KEY',
                    'sqlsrv', 'mssql' => 'BIGINT IDENTITY(1,1) PRIMARY KEY',
                    'oci', 'oracle' => 'NUMBER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',
                    default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                };
            case 'string':
                return 'VARCHAR(' . ($c->length ?? 255) . ')';
            case 'char':
                return 'CHAR(' . ($c->length ?? 255) . ')';
            case 'text':
                return match ($driver) {
                    'sqlsrv', 'mssql' => 'VARCHAR(MAX)',
                    'oci', 'oracle' => 'CLOB',
                    default => 'TEXT',
                };
            case 'longtext':
                return match ($driver) {
                    'sqlsrv', 'mssql' => 'VARCHAR(MAX)',
                    'oci', 'oracle' => 'CLOB',
                    'mysql' => 'LONGTEXT',
                    default => 'TEXT',
                };
            case 'integer':
                return $driver === 'mysql' ? 'INT' : 'INTEGER';
            case 'tinyint':
                return $driver === 'pgsql' ? 'SMALLINT' : 'TINYINT';
            case 'smallint':
                return 'SMALLINT';
            case 'bigint':
                return match ($driver) {
                    'oci', 'oracle' => 'NUMBER(19)',
                    default => 'BIGINT',
                };
            case 'float':
                return match ($driver) {
                    'pgsql' => 'DOUBLE PRECISION',
                    'sqlite' => 'REAL',
                    default => 'DOUBLE',
                };
            case 'decimal':
                return 'DECIMAL(' . ($c->precision ?? 8) . ',' . ($c->scale ?? 2) . ')';
            case 'boolean':
                return match ($driver) {
                    'mysql' => 'TINYINT(1)',
                    'pgsql' => 'BOOLEAN',
                    'sqlsrv', 'mssql' => 'BIT',
                    'oci', 'oracle' => 'NUMBER(1)',
                    default => 'INTEGER',
                };
            case 'date':
                return 'DATE';
            case 'datetime':
                return match ($driver) {
                    'pgsql' => 'TIMESTAMP(0)',
                    'oci', 'oracle' => 'DATE',
                    default => 'DATETIME',
                };
            case 'timestamp':
                return match ($driver) {
                    'sqlsrv', 'mssql' => 'DATETIME',
                    default => 'TIMESTAMP',
                };
            case 'time':
                return 'TIME';
            case 'json':
                return match ($driver) {
                    'pgsql' => 'JSONB',
                    'oci', 'oracle' => 'CLOB',
                    'sqlite' => 'TEXT',
                    default => 'JSON',
                };
            case 'uuid':
                return match ($driver) {
                    'pgsql' => 'UUID',
                    default => 'CHAR(36)',
                };
            case 'enum':
                if ($driver === 'mysql' && $c->enumValues !== []) {
                    $quoted = array_map(fn($v) => "'" . str_replace("'", "''", $v) . "'", $c->enumValues);
                    return 'ENUM(' . implode(', ', $quoted) . ')';
                }
                return 'VARCHAR(255)';
            default:
                return strtoupper($c->type);
        }
    }

    protected function literal(mixed $value, string $driver): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            if ($driver === 'pgsql') {
                return $value ? 'true' : 'false';
            }
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }
}
