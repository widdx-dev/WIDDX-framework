<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\QueueInterface;

/**
 * Database queue driver.
 *
 * Stores jobs in a database table. Simple, reliable, no external dependencies.
 *
 * Table schema (auto-created):
 *   jobs(id, queue, payload, attempts, available_at, created_at)
 */
class DatabaseQueue implements QueueInterface
{
    protected \PDO $pdo;
    protected string $table = 'jobs';
    protected string $failedTable = 'failed_jobs';

    public function __construct(?\PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection()->pdo();
        $this->ensureTable();
        $this->ensureFailedTable();
    }

    protected function ensureTable(): void
    {
        $driver = Database::connection()->driver();
        $sql = match ($driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id SERIAL PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'sqlsrv' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT IDENTITY(1,1) PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload VARCHAR(MAX) NOT NULL, attempts INT DEFAULT 0, failed_reason VARCHAR(MAX) NULL, available_at DATETIME DEFAULT GETDATE(), created_at DATETIME DEFAULT GETDATE())",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        };
        $this->pdo->exec($sql);
    }

    protected function ensureFailedTable(): void
    {
        $driver = Database::connection()->driver();
        $failedSql = match ($driver) {
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->failedTable} (id INT AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, reason TEXT NULL, failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->failedTable} (id SERIAL PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, reason TEXT NULL, failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            default => "CREATE TABLE IF NOT EXISTS {$this->failedTable} (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, reason TEXT NULL, failed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        };
        $this->pdo->exec($failedSql);
    }

    /**
     * Carry per-job policy inside the payload: tries/backoff come from the
     * Job class itself, so `queue:work` can retry with backoff and park
     * exhausted jobs in failed_jobs — without any interface changes.
     *
     * @return array{tries:int,backoff:int}
     */
    protected function meta(string $jobClass): array
    {
        $tries = 3;
        $backoff = 5;
        if (class_exists($jobClass) && is_subclass_of($jobClass, Job::class)) {
            $instance = new $jobClass();
            $tries = $instance->getTries();
            $backoff = $instance->getBackoff();
        }
        return ['tries' => $tries, 'backoff' => $backoff];
    }

    public function push(string $job, array $data = [], string $queue = 'default'): string
    {
        $payload = json_encode(array_merge(['job' => $job, 'data' => $data], $this->meta($job)));
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (queue, payload, available_at) VALUES (?, ?, ?)");
        $stmt->execute([$queue, $payload, date('Y-m-d H:i:s')]);
        return (string) $this->pdo->lastInsertId();
    }

    public function later(int $delay, string $job, array $data = [], string $queue = 'default'): string
    {
        $payload = json_encode(array_merge(['job' => $job, 'data' => $data], $this->meta($job)));
        $available = date('Y-m-d H:i:s', time() + $delay);
        $stmt = $this->pdo->prepare("INSERT INTO {$this->table} (queue, payload, available_at) VALUES (?, ?, ?)");
        $stmt->execute([$queue, $payload, $available]);
        return (string) $this->pdo->lastInsertId();
    }

    public function pop(string $queue = 'default'): ?array
    {
        $driver = Database::connection()->driver();
        $now = date('Y-m-d H:i:s');
        // Portable single-row select: LIMIT (mysql/sqlite/pgsql) vs TOP/FETCH (sqlsrv/oci).
        if (in_array($driver, ['sqlsrv', 'mssql'], true)) {
            $select = "SELECT TOP 1 * FROM {$this->table} WHERE queue = ? AND available_at <= ? ORDER BY id ASC";
        } elseif (in_array($driver, ['oci', 'oracle'], true)) {
            $select = "SELECT * FROM {$this->table} WHERE queue = ? AND available_at <= ? ORDER BY id ASC FETCH NEXT 1 ROWS ONLY";
        } else {
            $select = "SELECT * FROM {$this->table} WHERE queue = ? AND available_at <= ? ORDER BY id ASC LIMIT 1";
        }
        $stmt = $this->pdo->prepare($select);
        $stmt->execute([$queue, $now]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        // Mark as reserved
        $this->pdo->prepare("UPDATE {$this->table} SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $row['attempts'] = (int) $row['attempts'] + 1; // report the NEW attempt number
        $row['payload'] = json_decode($row['payload'], true);
        return $row;
    }

    public function completed(string|int $jobId): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([(string) $jobId]);
    }

    /**
     * Retry policy lives here, not in the worker loop:
     * attempts < tries → park the job with exponential backoff;
     * exhausted → copy into failed_jobs and remove from the hot table.
     */
    public function failed(string|int $jobId, string $reason): void
    {
        $jobId = (string) $jobId;
        $stmt = $this->pdo->prepare("SELECT queue, payload, attempts FROM {$this->table} WHERE id = ?");
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        $payload = json_decode((string) $row['payload'], true) ?: [];
        $tries = max(1, (int) ($payload['tries'] ?? 3));
        $backoff = max(0, (int) ($payload['backoff'] ?? 5));
        $attempts = (int) $row['attempts'];

        if ($attempts < $tries) {
            $wait = min(3600, $backoff * (2 ** max(0, $attempts - 1)));
            $this->pdo->prepare("UPDATE {$this->table} SET available_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', time() + $wait), $jobId]);
            return;
        }

        $this->pdo->prepare("INSERT INTO {$this->failedTable} (queue, payload, reason, failed_at) VALUES (?, ?, ?, ?)")
            ->execute([$row['queue'], (string) $row['payload'], $reason, date('Y-m-d H:i:s')]);
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?")->execute([$jobId]);
    }

    /** @return array<int,array<string,mixed>> */
    public function failedJobs(): array
    {
        return $this->pdo->query("SELECT * FROM {$this->failedTable} ORDER BY id ASC")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Move a failed job back onto the queue with a fresh budget. */
    public function retryFailed(int $failedId): bool
    {
        $stmt = $this->pdo->prepare("SELECT queue, payload FROM {$this->failedTable} WHERE id = ?");
        $stmt->execute([$failedId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $this->pdo->prepare("INSERT INTO {$this->table} (queue, payload, attempts, available_at) VALUES (?, ?, 0, ?)")
            ->execute([$row['queue'], (string) $row['payload'], date('Y-m-d H:i:s')]);
        $this->pdo->prepare("DELETE FROM {$this->failedTable} WHERE id = ?")->execute([$failedId]);
        return true;
    }

    public function size(string $queue = 'default'): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->table} WHERE queue = ?");
        $stmt->execute([$queue]);
        return (int) $stmt->fetchColumn();
    }

    public function retry(string|int $jobId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET available_at = ? WHERE id = ?");
        return $stmt->execute([date('Y-m-d H:i:s'), (string) $jobId]);
    }
}
