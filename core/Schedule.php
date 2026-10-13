<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Dead-simple cron: ONE crontab entry for the whole project.
 *
 *   * * * * * cd /path/to/project && php widdx schedule:run
 *
 * Tasks are declared in routes/console.php:
 *
 *   \Widdx\Schedule::command('queue:work --max=100')->everyMinute();
 *   \Widdx\Schedule::call(fn() => cleanup())->daily();
 *
 * `schedule:run` loads that file, compares each task's time bucket with
 * the last run stored in storage/app/schedule.json, and fires what's due.
 */
class Schedule
{
    /** @var ScheduledTask[] */
    protected static array $events = [];

    public static function command(string $command): ScheduledTask
    {
        $task = (new ScheduledTask('command', $command))->setSeq(count(static::$events));
        static::$events[] = $task;
        return $task;
    }

    public static function call(callable $callback): ScheduledTask
    {
        $task = (new ScheduledTask('call', $callback))->setSeq(count(static::$events));
        static::$events[] = $task;
        return $task;
    }

    /** @return ScheduledTask[] */
    public static function events(): array
    {
        return static::$events;
    }

    public static function flush(): void
    {
        static::$events = [];
    }

    /**
     * Run everything due right now. Returns the keys that fired.
     * State file keeps task key → last executed bucket.
     *
     * @return string[]
     */
    public static function run(string $basePath, bool $quiet = true): array
    {
        $console = $basePath . '/routes/console.php';
        if (is_file($console)) {
            require $console;
        }
        if (static::$events === []) {
            return [];
        }

        $stateFile = $basePath . '/storage/app/schedule.json';
        $state = is_file($stateFile)
            ? (array) (json_decode((string) file_get_contents($stateFile), true) ?: [])
            : [];

        $ran = [];
        foreach (static::$events as $task) {
            $key = $task->key();
            $last = (int) ($state[$key] ?? -1);
            if (!$task->due($last)) {
                continue;
            }
            if ($task->kind === 'command') {
                $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($basePath . '/widdx')
                    . ' ' . $task->task . ' 2>&1';
                exec($cmd, $out, $code);
                if (!$quiet) {
                    echo "ran [{$task->task}] exit={$code}\n";
                }
            } else {
                try {
                    call_user_func($task->task);
                } catch (\Throwable $e) {
                    logger('error', 'Scheduled closure failed: ' . $e->getMessage());
                }
            }
            $state[$key] = $task->bucket();
            $ran[] = $key;
        }

        $dir = dirname($stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
        return $ran;
    }
}
