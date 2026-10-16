<?php

declare(strict_types=1);

namespace Widdx;

/**
 * 2.6: minimal portable schema builder.
 *
 *   Schema::create('posts', function (Blueprint $t) {
 *       $t->id();
 *       $t->string('title', 255);
 *       $t->text('body')->nullable();
 *       $t->integer('views')->default(0);
 *       $t->foreignId('user_id');
 *       $t->timestamps();
 *   });
 *
 * Emits correct DDL for sqlite / mysql / pgsql and best-effort for
 * sqlsrv / oci. Anything exotic can still use raw $pdo in a migration.
 */
class Schema
{
    public static function connection(): \PDO
    {
        return Database::connection()->pdo();
    }

    public static function driver(): string
    {
        return Database::connection()->driver();
    }

    public static function create(string $table, ?callable $callback = null, ?\PDO $pdo = null): void
    {
        $blueprint = new Blueprint($table);
        if ($callback !== null) {
            $callback($blueprint);
        }
        $pdo ??= static::connection();
        $driver = static::driver();
        foreach ($blueprint->toSql($driver) as $sql) {
            $pdo->exec($sql);
        }
        Model::forgetSchemaCache($table);
    }

    /** Add columns/indexes to an existing table. */
    public static function table(string $table, callable $callback, ?\PDO $pdo = null): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);
        $pdo ??= static::connection();
        $driver = static::driver();
        $addKw = $driver === 'sqlite' ? 'ADD COLUMN ' : 'ADD ';
        foreach ($blueprint->columns as $c) {
            if ($c->isPrimary && $c->autoIncrement) {
                continue; // cannot add an auto pk to an existing table
            }
            $c->nullable = true; // new columns must be nullable on existing rows
            $pdo->exec("ALTER TABLE {$table} {$addKw}" . $blueprint->columnSql($c, $driver));
        }
        foreach ($blueprint->indexes as $idx) {
            if ($idx['type'] === 'primary') {
                continue;
            }
            $cols = implode(', ', $idx['cols']);
            $kw = $idx['type'] === 'unique' ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX';
            $pdo->exec("{$kw} {$idx['name']} ON {$table} ({$cols})");
        }
        Model::forgetSchemaCache($table);
    }

    public static function drop(string $table, ?\PDO $pdo = null): void
    {
        ($pdo ?? static::connection())->exec("DROP TABLE {$table}");
        Model::forgetSchemaCache($table);
    }

    public static function dropIfExists(string $table, ?\PDO $pdo = null): void
    {
        $pdo ??= static::connection();
        $driver = static::driver();
        if (in_array($driver, ['oci', 'oracle'], true)) {
            try {
                $pdo->exec("DROP TABLE {$table}");
            } catch (\Throwable) {
                // does not exist — nothing to do
            }
            Model::forgetSchemaCache($table);
            return;
        }
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
        Model::forgetSchemaCache($table);
    }

    public static function hasTable(string $table, ?\PDO $pdo = null): bool
    {
        $pdo ??= static::connection();
        $driver = static::driver();
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND lower(name) = lower(?)");
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        }
        if ($driver === 'mysql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        }
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_name = ?');
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        }
        // best effort for sqlsrv/oci: try to select from it
        try {
            $pdo->query("SELECT 1 FROM {$table} WHERE 1 = 0");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
