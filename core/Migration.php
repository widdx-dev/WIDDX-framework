<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Simple migration runner.
 *
 * Usage:
 *   php widdx make:migration create_users_table
 *   php widdx migrate
 *   php widdx migrate:rollback
 *
 * Migration files are stored in database/migrations/
 */
class Migration
{
    protected \PDO $pdo;
    protected string $table = 'migrations';

    public function __construct()
    {
        $this->pdo = Database::connection()->pdo();
        $this->ensureMigrationsTable();
    }

    /** Create migrations tracking table if not exists */
    protected function ensureMigrationsTable(): void
    {
        $driver = Database::connection()->driver();
        $sql = match ($driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id SERIAL PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'sqlsrv' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT IDENTITY(1,1) PRIMARY KEY, migration VARCHAR(255) NOT NULL, batch INT NOT NULL, created_at DATETIME DEFAULT GETDATE())",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (id INTEGER PRIMARY KEY AUTOINCREMENT, migration VARCHAR(255) NOT NULL, batch INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        };
        $this->pdo->exec($sql);
    }

    /**
     * Run all pending migrations.
     */
    public function run(): array
    {
        $migrationsPath = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $files = glob($migrationsPath . '/*.php') ?: [];
        sort($files);

        $ran = $this->ranMigrations();
        $executed = [];
        $batch = $this->nextBatch();

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $ran, true)) {
                continue;
            }

            $migration = require $file;
            if (!is_object($migration) || !method_exists($migration, 'up')) {
                continue;
            }

            $migration->up($this->pdo);

            $this->pdo->prepare("INSERT INTO {$this->table} (migration, batch) VALUES (?, ?)")
                ->execute([$name, $batch]);

            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Rollback the last batch of migrations.
     */
    public function rollback(): array
    {
        $migrationsPath = dirname(__DIR__) . '/database/migrations';
        if (!is_dir($migrationsPath)) {
            return [];
        }

        $lastBatch = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}")->fetchColumn();
        if (!$lastBatch) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT migration FROM {$this->table} WHERE batch = ? ORDER BY id DESC");
        $stmt->execute([$lastBatch]);
        $rolledBack = [];

        foreach ($stmt->fetchAll() as $row) {
            $file = $migrationsPath . '/' . $row['migration'] . '.php';
            if (is_file($file)) {
                $migration = require $file;
                if (is_object($migration) && method_exists($migration, 'down')) {
                    $migration->down($this->pdo);
                }
            }
            $this->pdo->prepare("DELETE FROM {$this->table} WHERE migration = ?")->execute([$row['migration']]);
            $rolledBack[] = $row['migration'];
        }

        return $rolledBack;
    }

    /** Get list of already-run migration names */
    protected function ranMigrations(): array
    {
        $stmt = $this->pdo->query("SELECT migration FROM {$this->table} ORDER BY id ASC");
        return array_column($stmt->fetchAll(), 'migration');
    }

    /** Get next batch number */
    protected function nextBatch(): int
    {
        $max = $this->pdo->query("SELECT MAX(batch) FROM {$this->table}")->fetchColumn();
        return ($max ?: 0) + 1;
    }
}
