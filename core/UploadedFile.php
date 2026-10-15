<?php

declare(strict_types=1);

namespace Widdx;

/**
 * One uploaded file (normalized $_FILES entry).
 *
 *   request()->file('cover')->store('covers');  // → 'covers/20260113_ab12_sea.gif'
 *
 * store() writes into the storage disk (storage/app/<dir>) and returns the
 * RELATIVE path — exactly what a CRUD column should hold; render it with
 * storage()->url($path) or the `asset`-style helper in views.
 */
class UploadedFile
{
    /** @var array<string,string> detected mime → canonical extension (the ONLY one store() trusts). */
    protected const EXT_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'application/x-bzip2' => 'bz2',
        'application/x-gzip' => 'gz',
        'application/x-7z-compressed' => '7z',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'audio/mpeg' => 'mp3',
        'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4',
        'video/mpeg' => 'mpeg',
        'video/webm' => 'webm',
    ];

    /** @var string[] extensions that must NEVER reach the disk, whatever the client claims. */
    protected const FORBIDDEN_EXT = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phar', 'pht',
        'cgi', 'pl', 'pm', 'py', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd',
        'htaccess', 'htpasswd', 'jsp', 'asp', 'aspx',
    ];

    public function __construct(
        protected string $tmpPath,
        protected string $originalName,
        protected string $clientMime,
        protected int $error,
        protected int $clientSize,
    ) {
    }

    /** Normalize one raw $_FILES element (handles multi-file name[] shapes). */
    public static function fromFilesArray(array $entry): array|self|null
    {
        if (isset($entry['name']) && is_array($entry['name'])) {
            // <input name="photos[]" type="file">
            $out = [];
            foreach (array_keys($entry['name']) as $i) {
                $one = self::fromFilesArray([
                    'name' => $entry['name'][$i],
                    'type' => $entry['type'][$i] ?? '',
                    'tmp_name' => $entry['tmp_name'][$i] ?? '',
                    'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $entry['size'][$i] ?? 0,
                ]);
                if ($one !== null && !is_array($one)) {
                    $out[] = $one;
                }
            }
            return $out;
        }

        $error = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
        $name = (string) ($entry['name'] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
            return null;
        }
        return new self(
            (string) ($entry['tmp_name'] ?? ''),
            $name,
            (string) ($entry['type'] ?? ''),
            $error,
            (int) ($entry['size'] ?? 0)
        );
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK
            && $this->tmpPath !== ''
            && is_file($this->tmpPath);
    }

    public function error(): int
    {
        return $this->error;
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large for this server.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'The upload failed (error code ' . $this->error . ').',
        };
    }

    public function path(): string
    {
        return $this->tmpPath;
    }

    public function size(): int
    {
        return is_file($this->tmpPath) ? (int) filesize($this->tmpPath) : $this->clientSize;
    }

    public function sizeKb(): float
    {
        return round($this->size() / 1024, 2);
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function extension(): string
    {
        return strtolower((string) pathinfo($this->originalName, PATHINFO_EXTENSION));
    }

    /**
     * Extension DERIVED from the real detected content — never from the
     * client-supplied filename. Unknown types collapse to 'bin'/'txt',
     * and executable extensions are hard-denied in every case.
     */
    public function safeExtension(): string
    {
        $mime = $this->mimeType();
        if (isset(self::EXT_BY_MIME[$mime])) {
            return self::EXT_BY_MIME[$mime];
        }
        if (str_starts_with($mime, 'text/')) {
            return 'txt';
        }
        return 'bin';
    }

    /** Detected mime (real content, not the browser-sent claim). */
    public function mimeType(): string
    {
        if ($this->tmpPath !== '' && is_file($this->tmpPath) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                // No finfo_close(): deprecated in 8.5, the object frees itself.
                $detected = finfo_file($finfo, $this->tmpPath);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        return $this->clientMime;
    }

    public function isImage(): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        return @getimagesize($this->tmpPath) !== false;
    }

    /** Move to an absolute destination file. @return bool success */
    public function move(string $destination): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (is_uploaded_file($this->tmpPath)) {
            return move_uploaded_file($this->tmpPath, $destination);
        }
        return rename($this->tmpPath, $destination);
    }

    /**
     * Persist into the storage disk under $directory with a collision-safe
     * name. Returns the relative path ('covers/20260113_9f3c_sea.gif').
     */
    public function store(string $directory = 'uploads'): string
    {
        if (!$this->isValid()) {
            throw new Exceptions\HttpException('Cannot store an invalid upload: ' . $this->errorMessage(), 400);
        }
        $disk = storage();
        if (!method_exists($disk, 'root')) {
            throw new Exceptions\WiddxException('The active storage disk does not support file uploads.');
        }
        $ext = $this->safeExtension();
        if (in_array($ext, self::FORBIDDEN_EXT, true)) {
            $ext = 'bin'; // belt & braces — safeExtension never returns these
        }
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $this->originalName) ?: 'file';
        $stem = strtolower((string) pathinfo($sanitized, PATHINFO_FILENAME));
        $stem = substr((string) preg_replace('/[^a-z0-9]+/', '-', $stem), 0, 40);
        $name = date('Ymd') . '_' . bin2hex(random_bytes(4)) . ($stem !== '' && $stem !== '-' ? rtrim($stem, '-') : '') . '.' . $ext;
        $relative = ($directory === '' ? '' : trim($directory, '/') . '/') . $name;

        $target = $disk->root() . '/' . $relative;
        if (!$this->move($target)) {
            throw new Exceptions\HttpException('Failed to store uploaded file: ' . $relative, 500);
        }
        return $relative;
    }
}
