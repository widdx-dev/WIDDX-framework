<?php

declare(strict_types=1);

namespace Widdx;

/**
 * One-click web installer for people WITHOUT terminal access
 * (shared hosting / cPanel / FTP-only deployment).
 *
 * Flow: upload the project zip → open /install.php in the browser →
 * fill the DB form → this class writes .env, runs every pending
 * migration, and locks itself forever (storage/app/installed.lock).
 *
 * Security stance:
 *   - Once locked, install.php returns 404 — one-shot endpoint.
 *   - It REFUSES to overwrite an existing .env (attacker cannot
 *     re-point the app's database); the UI only offers "lock only".
 *   - The DB connection is TESTED before anything is written.
 *   - Proof of possession: storage/app/install.key must be typed back
 *     into the form — only someone with FTP/SSH access can read it.
 *   - POSTs carry a session CSRF token; .env is written 0600.
 */
class Installer
{
    public const LOCK = '/storage/app/installed.lock';
    public const KEY = '/storage/app/install.key';

    /**
     * Get — or create — the one-time install key (32 hex chars).
     * The form asks the installer to READ this file over FTP/SSH and paste
     * its content: anonymous web callers cannot, so the installer page
     * never leaks the key itself.
     */
    public static function installKey(string $base): string
    {
        $file = $base . self::KEY;
        if (is_link($file)) {
            throw new \RuntimeException('Installer key path must not be a symbolic link.');
        }
        if (is_file($file)) {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException('The installer key exists but cannot be read. Check storage/app permissions.');
            }
            $key = trim($contents);
            if (preg_match('/\A[a-f0-9]{32}\z/i', $key) !== 1) {
                throw new \RuntimeException('The installer key file is invalid. Restore a valid 32-character hexadecimal key.');
            }
            return $key;
        }

        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage/app for the installer key. Check directory permissions.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('storage/app is not writable. Fix its permissions before installing.');
        }

        $key = bin2hex(random_bytes(16));
        if (@file_put_contents($file, $key . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot persist the installer key. Check disk space and storage/app permissions.');
        }
        @chmod($file, 0600);
        return $key;
    }

    /** Constant-time proof-of-possession check of the typed-in key. */
    public static function keyMatches(string $base, mixed $given): bool
    {
        return is_string($given)
            && $given !== ''
            && hash_equals(static::installKey($base), trim($given));
    }

    /** Installed (locked) already? */
    public static function installed(string $base): bool
    {
        return is_file($base . self::LOCK);
    }

    /** A .env already exists — configuration was done (CLI or earlier). */
    public static function configured(string $base): bool
    {
        return is_file($base . '/.env');
    }

    /**
     * Environment readiness for the checklist UI.
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public static function requirements(string $base): array
    {
        $drivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $checks = [
            [
                'label' => 'PHP >= 8.1',
                'ok' => PHP_VERSION_ID >= 80100,
                'hint' => 'Current: ' . PHP_VERSION . ' — pick a newer version in cPanel → Select PHP Version',
            ],
            [
                'label' => 'PDO extension',
                'ok' => extension_loaded('pdo'),
                'hint' => 'Enable the pdo extension in cPanel → Select PHP Version → Options',
            ],
            [
                'label' => 'A PDO driver is available',
                'ok' => $drivers !== [],
                'hint' => 'Enable pdo_sqlite or pdo_mysql in cPanel → Select PHP Version → Options',
            ],
            [
                'label' => 'storage/ is writable',
                'ok' => static::storageWritable($base),
                'hint' => 'CHMOD 755 (or 775) on storage/cache, storage/logs, storage/sessions',
            ],
            [
                'label' => 'Project root is writable (.env)',
                'ok' => is_writable($base),
                'hint' => 'The installer must create .env in the project root — CHMOD 755 on the folder',
            ],
        ];
        return $checks;
    }

    protected static function storageWritable(string $base): bool
    {
        foreach (['storage/cache', 'storage/logs', 'storage/sessions', 'storage/app'] as $dir) {
            $full = $base . '/' . $dir;
            if (!is_dir($full) || !is_writable($full)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Try a real PDO connection BEFORE writing anything.
     * @param array<string,mixed> $v form values
     * @return string|null error message, or null when the connection works
     */
    public static function testConnection(string $base, array $v): ?string
    {
        $driver = strtolower(trim((string) ($v['DB_CONNECTION'] ?? 'sqlite')));
        $host = trim((string) ($v['DB_HOST'] ?? '127.0.0.1'));
        $port = trim((string) ($v['DB_PORT'] ?? ''));
        $db = trim((string) ($v['DB_DATABASE'] ?? ''));
        $user = trim((string) ($v['DB_USERNAME'] ?? ''));
        $pass = (string) ($v['DB_PASSWORD'] ?? '');

        try {
            if ($driver === 'sqlite') {
                $db = $db !== '' ? $db : './database.sqlite';
                if ($db !== ':memory:') {
                    // Same resolver the running app uses, so the file this form tests and
                    // the file .env points at can never be two different files.
                    $db = Database::resolveSqlitePath($db, $base);
                    $dir = dirname($db);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    if (!is_writable($dir)) {
                        return "directory of the database file is not writable: {$dir}";
                    }
                }
                new \PDO('sqlite:' . $db);
                return null;
            }

            $defaultPorts = ['mysql' => '3306', 'pgsql' => '5432', 'sqlsrv' => '1433', 'oci' => '1521'];
            if (!isset($defaultPorts[$driver])) {
                return "unknown driver '{$driver}'";
            }
            $port = $port !== '' ? $port : $defaultPorts[$driver];
            // Test WITHOUT dbname so a not-yet-created database is not a
            // false negative — creating it is a separate cPanel step.
            $dsn = match ($driver) {
                'mysql' => "mysql:host={$host};port={$port}",
                'pgsql' => "pgsql:host={$host};port={$port}",
                'sqlsrv' => "sqlsrv:Server={$host},{$port}",
                'oci' => 'oci:dbname=//' . $host . ':' . $port . '/' . ($db !== '' ? $db : 'XE'),
            };
            new \PDO($dsn, $user, $pass);
            return null;
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'could not find driver')) {
                return "PHP extension pdo_{$driver} is not available on this host";
            }
            return $msg;
        }
    }

    /**
     * Build .env contents from form values. Only non-empty keys are written;
     * values with spaces or '=' get quoted (App::loadEnv strips the quotes).
     * @param array<string,mixed> $v
     */
    public static function buildEnv(array $v): string
    {
        $lines = ['# Generated by public/install.php on ' . date('Y-m-d H:i:s')];
        $driver = strtolower(trim((string) ($v['DB_CONNECTION'] ?? 'sqlite')));
        $put = static function (string $key, mixed $value) use (&$lines): void {
            $value = (string) $value;
            if ($value === '') {
                return;
            }
            // A CR/LF inside a value would append attacker-chosen KEY=VALUE
            // lines to .env — refuse the whole install instead.
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new \RuntimeException("REFUSED: the value of {$key} contains a line break.");
            }
            if (preg_match('/[\s="]/', $value)) {
                $value = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
            }
            $lines[] = "{$key}={$value}";
        };
        $put('DB_CONNECTION', $driver);
        if ($driver === 'sqlite') {
            $put('DB_DATABASE', trim((string) ($v['DB_DATABASE'] ?? './database.sqlite')));
        } else {
            $put('DB_HOST', $v['DB_HOST'] ?? '');
            $put('DB_PORT', $v['DB_PORT'] ?? '');
            $put('DB_DATABASE', $v['DB_DATABASE'] ?? '');
            $put('DB_USERNAME', $v['DB_USERNAME'] ?? '');
            $put('DB_PASSWORD', $v['DB_PASSWORD'] ?? '');
        }
        $put('APP_URL', $v['APP_URL'] ?? '');
        $locale = strtolower(trim((string) ($v['APP_LOCALE'] ?? '')));
        if ($locale !== '' && $locale !== 'en') {
            $put('APP_LOCALE', $locale);
        }
        $put('APP_DEBUG', !empty($v['APP_DEBUG']) ? 'true' : 'false');
        return implode("\n", $lines) . "\n";
    }

    public static function writeEnv(string $base, array $v): void
    {
        $target = $base . '/.env';
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('Refusing to overwrite an existing .env file.');
        }
        if (!is_dir($base) || !is_writable($base)) {
            throw new \RuntimeException('Project root is not writable; cannot create .env.');
        }

        // Write beside the destination, apply restrictive permissions before it
        // becomes live, then rename so a short/failed write never becomes config.
        $contents = static::buildEnv($v);
        $temp = $base . '/.env.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $written = @file_put_contents($temp, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new \RuntimeException('Could not write the complete .env file. Check disk space and permissions.');
            }
            if (!@chmod($temp, 0600)) {
                throw new \RuntimeException('Could not restrict .env permissions to 0600. Check hosting file-permission support.');
            }
            if (file_exists($target) || is_link($target)) {
                throw new \RuntimeException('Refusing to overwrite an existing .env file.');
            }
            if (!@rename($temp, $target)) {
                throw new \RuntimeException('Could not activate the .env file. Check project-root permissions.');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Full finish: load env + config, run pending migrations, write the lock.
     * @return string[] migration files executed (may be empty)
     */
    public static function finish(string $base): array
    {
        App::loadEnv($base);
        App::loadConfig($base . '/config');
        $executed = (new Migration())->run();
        static::lock($base);
        return $executed;
    }

    /** Create the self-lock file (storage/app may not exist yet). */
    public static function lock(string $base): void
    {
        $path = $base . self::LOCK;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage/app to lock the installer.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('storage/app is not writable; the installer cannot be locked.');
        }

        $contents = 'installed ' . date('c') . "\n";
        $temp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $written = @file_put_contents($temp, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new \RuntimeException('Could not persist the installer lock. The installer remains available for recovery.');
            }
            if (!@rename($temp, $path)) {
                throw new \RuntimeException('Could not activate the installer lock. Check storage/app permissions.');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        // Delete the proof-of-possession key only after the lock is on disk.
        @unlink($base . self::KEY);
    }
}
