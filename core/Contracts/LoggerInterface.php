<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Logger contract — any logger driver must implement this.
 *
 * Example drivers: FileLogger, DatabaseLogger, SyslogLogger
 */
interface LoggerInterface
{
    /** System is unusable */
    public function emergency(string $message, array $context = []): void;

    /** Action must be taken immediately */
    public function alert(string $message, array $context = []): void;

    /** Critical conditions */
    public function critical(string $message, array $context = []): void;

    /** Runtime errors */
    public function error(string $message, array $context = []): void;

    /** Exceptional occurrences that are not errors */
    public function warning(string $message, array $context = []): void;

    /** Normal but significant events */
    public function notice(string $message, array $context = []): void;

    /** Interesting events */
    public function info(string $message, array $context = []): void;

    /** Detailed debug information */
    public function debug(string $message, array $context = []): void;

    /** Log with arbitrary level */
    public function log(string $level, string $message, array $context = []): void;
}
