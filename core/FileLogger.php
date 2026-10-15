<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\LoggerInterface;

/**
 * File logger — writes log entries to storage/logs/.
 *
 * Log levels: emergency, alert, critical, error, warning, notice, info, debug
 */
class FileLogger implements LoggerInterface
{
    protected string $path;

    protected array $levels = [
        'emergency', 'alert', 'critical', 'error',
        'warning', 'notice', 'info', 'debug',
    ];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (dirname(__DIR__) . '/storage/logs');
        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line = "[{$date}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        $file = $this->path . '/' . date('Y-m-d') . '.log';
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** Get all log files */
    public function files(): array
    {
        return glob($this->path . '/*.log') ?: [];
    }

    /** Clear all logs */
    public function clear(): void
    {
        foreach ($this->files() as $file) {
            unlink($file);
        }
    }
}
