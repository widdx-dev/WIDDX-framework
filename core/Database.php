<?php

declare(strict_types=1);

namespace Widdx;

/**
 * PDO connection manager — supports all major databases:
 * sqlite, mysql, pgsql (PostgreSQL), sqlsrv (SQL Server), oci (Oracle).
 *
 * For any other driver: pass a custom DSN via the 'dsn' option in config
 * (works with any installed PDO driver).
 */
class Database
{
    protected static ?self $instance = null;

    /** Named connections (for multi-database setups) */
    protected static array $instances = [];

    protected \PDO $pdo;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->pdo = $this->connect($config);
    }

    /**
     * Default connection — or named: Database::connection('pgsql')
     */
    public static function connection(?string $name = null): self
    {
        $all = function_exists('config') ? config('database') : null;
        if (!is_array($all)) {
            $all = [];
        }

        // New format: ['default' => ..., 'connections' => [...]]
        if (isset($all['connections']) && is_array($all['connections'])) {
            $name ??= (string) ($all['default'] ?? 'sqlite');
            if (isset(static::$instances[$name])) {
                return static::$instances[$name];
            }
            if (!isset($all['connections'][$name])) {
                throw new Exceptions\DatabaseException(
                    "Database connection [{$name}] not defined. Define it in config/database.php under connections " .
                    "or change DB_CONNECTION in .env."
                );
            }
            return static::$instances[$name] = new self($all['connections'][$name]);
        }

        // Legacy flat array format — still supported for backward compatibility
        if (static::$instance === null) {
            if (empty($all)) {
                // Zero config: sqlite auto-created
                $all = ['driver' => 'sqlite', 'database' => dirname(__DIR__) . '/database.sqlite'];
            }
            static::$instance = new self($all);
        }
        return static::$instance;
    }

    /** Inject a custom connection (useful for testing) */
    public static function setConnection(self $db): void
    {
        static::$instance = $db;
    }

    /** Reset (useful for testing) */
    public static function reset(): void
    {
        static::$instance = null;
        static::$instances = [];
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** Current driver name: sqlite|mysql|pgsql|sqlsrv|oci */
    public function driver(): string
    {
        return strtolower((string) ($this->config['driver'] ?? 'sqlite'));
    }

    /**
     * Real column names of a table (empty array when the table is missing).
     * Powers zero-code CRUD forms/tables without any user configuration.
     */
    public function tableColumns(string $table): array
    {
        // Identifiers cannot be bound as parameters — validate first.
        if (preg_match('/[^a-zA-Z0-9_.]/', $table)) {
            return [];
        }
        $quoted = str_replace('"', '', $table);
        try {
            switch ($this->driver()) {
                case 'sqlite':
                    $stmt = $this->pdo->query('PRAGMA table_info("' . $quoted . '")');
                    $cols = [];
                    foreach ($stmt ?: [] as $row) {
                        $cols[] = (string) $row['name'];
                    }
                    return $cols;
                case 'mysql':
                    $stmt = $this->pdo->prepare(
                        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                         ORDER BY ORDINAL_POSITION'
                    );
                    break;
                case 'oci':
                    $stmt = $this->pdo->prepare(
                        'SELECT COLUMN_NAME FROM ALL_TAB_COLUMNS WHERE TABLE_NAME = ? ORDER BY COLUMN_ID'
                    );
                    break;
                default: // pgsql, sqlsrv, and any information_schema-compliant driver
                    $stmt = $this->pdo->prepare(
                        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
                    );
                    break;
            }
            $stmt->execute([stripos($this->driver(), 'oci') === false ? $table : strtoupper($table)]);
            $cols = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_NUM) as $row) {
                $cols[] = (string) $row[0];
            }
            return $cols;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function connect(array $cfg): \PDO
    {
        $driver = strtolower((string) ($cfg['driver'] ?? 'sqlite'));
        $username = $cfg['username'] ?? null;
        $password = $cfg['password'] ?? null;
        $options = $cfg['options'] ?? [];

        try {
            // Custom DSN = opens the door to any PDO driver (ibm, odbc, firebird, ...)
            if (!empty($cfg['dsn'])) {
                $pdo = new \PDO($cfg['dsn'], $username, $password, $options);
            } else {
                $pdo = match ($driver) {
                    'sqlite' => $this->connectSqlite($cfg),
                    'mysql' => new \PDO($this->mysqlDsn($cfg), $username, $password, $options),
                    'pgsql', 'postgres', 'postgresql' => new \PDO($this->pgsqlDsn($cfg), $username, $password, $options),
                    'sqlsrv', 'mssql' => new \PDO($this->sqlsrvDsn($cfg), $username, $password, $options),
                    'oci', 'oracle' => new \PDO($this->ociDsn($cfg), $username, $password, $options),
                    default => throw new Exceptions\DatabaseException(
                        "Database driver [{$driver}] not directly supported. " .
                        "Supported: sqlite, mysql, pgsql, sqlsrv, oci — " .
                        "or use the 'dsn' option for any other PDO driver."
                    ),
                };
            }

            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

            return $pdo;
        } catch (Exceptions\DatabaseException $e) {
            throw $e;
        } catch (\PDOException $e) {
            throw new Exceptions\DatabaseException(
                "Cannot connect to database ({$driver}): {$e->getMessage()}" . $this->extensionHint($driver)
            );
        }
    }

    /** Hint about missing PHP extension by driver */
    protected function extensionHint(string $driver): string
    {
        $ext = match ($driver) {
            'mysql' => 'pdo_mysql',
            'pgsql', 'postgres', 'postgresql' => 'pdo_pgsql',
            'sqlsrv', 'mssql' => 'pdo_sqlsrv',
            'oci', 'oracle' => 'pdo_oci or oci8',
            'sqlite' => 'pdo_sqlite',
            default => '',
        };
        if ($ext !== '' && !extension_loaded(explode(' ', $ext)[0])) {
            return " (PHP extension [{$ext}] appears missing or disabled — enable it in php.ini).";
        }
        return '';
    }

    protected function connectSqlite(array $cfg): \PDO
    {
        $path = self::resolveSqlitePath(
            (string) ($cfg['database'] ?? 'database.sqlite'),
            self::projectDirectory()
        );
        if ($path !== ':memory:' && !is_file($path)) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true); // parent dir may not exist yet
            }
            touch($path); // Auto-create for zero-config experience
        }
        return new \PDO('sqlite:' . $path);
    }

    /**
     * The project root: what App::boot() recorded, else the constant every entry point
     * defines, else this file's parent (core/ lives inside the project).
     */
    public static function projectDirectory(): string
    {
        $base = App::basePath();
        if ($base === '' && defined('WIDDX_BASE_PATH')) {
            $base = (string) WIDDX_BASE_PATH;
        }
        return $base !== '' ? rtrim($base, '/\\') : dirname(__DIR__);
    }

    /**
     * A relative SQLite path means "inside the project", never "wherever this process
     * happened to start": cron and a web server run with a CWD nobody chose, and SQLite
     * would otherwise open (and silently create) a second database somewhere else, so
     * `php widdx migrate` and the app would talk to two different files.
     */
    public static function resolveSqlitePath(string $path, ?string $base = null): string
    {
        $path = trim($path);
        if ($path === '' || $path === ':memory:') {
            return $path === '' ? 'database.sqlite' : $path;
        }
        // Absolute POSIX path, a UNC path, or a Windows drive path — leave alone.
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[a-zA-Z]:[\\\\/]#', $path)) {
            return $path;
        }
        $base ??= self::projectDirectory();
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    protected function mysqlDsn(array $cfg): string
    {
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 3306;
        $name = $cfg['database'] ?? 'widdx';
        $charset = $cfg['charset'] ?? 'utf8mb4';
        return "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    }

    protected function pgsqlDsn(array $cfg): string
    {
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 5432;
        $name = $cfg['database'] ?? 'widdx';
        $dsn = "pgsql:host={$host};port={$port};dbname={$name}";
        if (!empty($cfg['sslmode'])) {
            $dsn .= ";sslmode={$cfg['sslmode']}";
        }
        return $dsn;
    }

    protected function sqlsrvDsn(array $cfg): string
    {
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = $cfg['port'] ?? 1433;
        $name = $cfg['database'] ?? 'widdx';
        return "sqlsrv:Server={$host},{$port};Database={$name}";
    }

    protected function ociDsn(array $cfg): string
    {
        // Accepts: full service name, or assembles from host/port/service
        if (!empty($cfg['service'])) {
            $host = $cfg['host'] ?? '127.0.0.1';
            $port = $cfg['port'] ?? 1521;
            return "oci:dbname=//{$host}:{$port}/{$cfg['service']}";
        }
        return 'oci:dbname=' . ($cfg['database'] ?? 'XE');
    }

    /** Shorthand: db('users')->where(...)->get() */
    public function table(string $table, ?string $modelClass = null): QueryBuilder
    {
        return new QueryBuilder($table, $modelClass, $this->pdo, $this->driver());
    }

    // ---------- Transactions ----------

    /** Begin a transaction */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /** Commit the current transaction */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /** Rollback the current transaction */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /** Execute a callback inside a transaction (auto-commit/rollback) */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    /** Check if currently inside a transaction */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
