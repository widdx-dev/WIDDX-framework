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
 *   jobs(id, queue, payload, attempts, reserved_at, available_at, created_at)
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
            'mysql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT AUTO_INCREMENT PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, reserved_at TIMESTAMP NULL DEFAULT NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'pgsql' => "CREATE TABLE IF NOT EXISTS {$this->table} (id SERIAL PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, reserved_at TIMESTAMP NULL DEFAULT NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            'sqlsrv' => "CREATE TABLE IF NOT EXISTS {$this->table} (id INT IDENTITY(1,1) PRIMARY KEY, queue VARCHAR(255) NOT NULL, payload VARCHAR(MAX) NOT NULL, attempts INT DEFAULT 0, failed_reason VARCHAR(MAX) NULL, reserved_at DATETIME NULL, available_at DATETIME DEFAULT GETDATE(), created_at DATETIME DEFAULT GETDATE())",
            default => "CREATE TABLE IF NOT EXISTS {$this->table} (id INTEGER PRIMARY KEY AUTOINCREMENT, queue VARCHAR(255) NOT NULL, payload TEXT NOT NULL, attempts INT DEFAULT 0, failed_reason TEXT NULL, reserved_at TIMESTAMP NULL, available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
        };
        $this->pdo->exec($sql);
        // 2.5: jobs tables created before reserved_at existed get it added.
        $this->ensureReservedAt();
    }

    /** Idempotently ensure the jobs table has the reserved_at column. */
    protected function ensureReservedAt(): void
    {
        try {
            $this->pdo->exec("ALTER TABLE {$this->table} ADD COLUMN reserved_at TIMESTAMP NULL");
        } catch (\Throwable) {
            // Already present (fresh CREATE, or a driver that rejects re-add).
        }
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
        $expire = date('Y-m-d H:i:s', time() - $this->visibilitySeconds());
        $claimable = 'queue = ? AND available_at <= ? AND (reserved_at IS NULL OR reserved_at <= ?)';

        // 2.5: reserve atomically so two workers never run the same job.
        // mysql/pgsql row-lock the candidate (FOR UPDATE SKIP LOCKED); sqlite
        // takes an immediate write lock; the UPDATE is itself re-guarded on
        // reserved_at, so a lost race releases and simply tries the next job.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->beginReservation($driver);
            try {
                if (in_array($driver, ['sqlsrv', 'mssql'], true)) {
                    $select = "SELECT TOP 1 * FROM {$this->table} WHERE {$claimable} ORDER BY id ASC";
                } elseif (in_array($driver, ['oci', 'oracle'], true)) {
                    $select = "SELECT * FROM {$this->table} WHERE {$claimable} ORDER BY id ASC FETCH NEXT 1 ROWS ONLY";
                } else {
                    $lock = in_array($driver, ['mysql', 'pgsql'], true) ? ' FOR UPDATE SKIP LOCKED' : '';
                    $select = "SELECT * FROM {$this->table} WHERE {$claimable} ORDER BY id ASC LIMIT 1{$lock}";
                }
                $stmt = $this->pdo->prepare($select);
                $stmt->execute([$queue, $now, $expire]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if (!$row) {
                    $this->endReservation($driver, false);
                    return null;
                }
                $upd = $this->pdo->prepare(
                    "UPDATE {$this->table} SET reserved_at = ?, attempts = attempts + 1 WHERE id = ? AND (reserved_at IS NULL OR reserved_at <= ?)"
                );
                $upd->execute([$now, $row['id'], $expire]);
                if ($upd->rowCount() < 1) {
                    // Another worker claimed it first — release lock, retry.
                    $this->endReservation($driver, false);
                    continue;
                }
                $this->endReservation($driver, true);
                $row['attempts'] = (int) $row['attempts'] + 1; // report the NEW attempt number
                $row['reserved_at'] = $now;
                $row['payload'] = json_decode((string) $row['payload'], true);
                return $row;
            } catch (\Throwable $e) {
                $this->endReservation($driver, false);
                throw $e;
            }
        }
        return null;
    }

    /** Start the reservation transaction/lock for this driver. */
    protected function beginReservation(string $driver): void
    {
        if ($this->pdo->inTransaction()) {
            return; // reuse an ambient transaction (never nest BEGIN)
        }
        if ($driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } elseif (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->pdo->beginTransaction();
        }
        // sqlsrv/oci: rely on the re-guarded UPDATE under autocommit (best effort).
    }

    /** Finish the reservation transaction/lock. */
    protected function endReservation(string $driver, bool $commit): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec($commit ? 'COMMIT' : 'ROLLBACK');
            return;
        }
        if (in_array($driver, ['mysql', 'pgsql'], true) && $this->pdo->inTransaction()) {
            if ($commit) {
                $this->pdo->commit();
            } else {
                $this->pdo->rollBack();
            }
        }
    }

    /** Seconds a reserved job stays hidden before it is reclaimable. */
    protected function visibilitySeconds(): int
    {
        return max(1, (int) config('queue.retry_after', 90));
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
            $this->pdo->prepare("UPDATE {$this->table} SET available_at = ?, reserved_at = NULL WHERE id = ?")
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
        $stmt = $this->pdo->prepare("UPDATE {$this->table} SET available_at = ?, reserved_at = NULL WHERE id = ?");
        return $stmt->execute([date('Y-m-d H:i:s'), (string) $jobId]);
    }
}
