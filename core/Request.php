<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Request object: wraps $_GET, $_POST, $_SERVER.
 */
class Request
{
    public array $get;
    public array $post;
    public array $server;
    public array $files;
    public array $cookies;

    /** Route parameters extracted from {id} */
    public array $routeParams = [];

    /**
     * Proxies we trust enough to read X-Forwarded-For from them.
     * Never trust XFF from random clients — only from listed peers (or '*').
     */
    protected static ?array $trustedProxies = null;

    public static function setTrustedProxies(?array $proxies): void
    {
        static::$trustedProxies = $proxies;
    }

    public function __construct(
        array $get = [],
        array $post = [],
        array $server = [],
        array $files = [],
        array $cookies = []
    ) {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->files = $files;
        $this->cookies = $cookies;
    }

    /** Create request from superglobals */
    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    /**
     * Best-guess CLIENT IP.
     *
     * Without trusted proxies configured this is simply REMOTE_ADDR —
     * headers a client forges are ignored entirely. With the direct peer
     * listed as trusted (or '*'), the RIGHT-MOST X-Forwarded-For entry is
     * used: that one is appended by your own proxy and cannot be spoofed
     * upstream. Rate limiting and logs must use this, not raw headers.
     */
    public function ip(): string
    {
        $peer = (string) ($this->server['REMOTE_ADDR'] ?? 'cli');
        $xff = $this->server['HTTP_X_FORWARDED_FOR'] ?? null;
        $trusted = static::$trustedProxies
            ?? array_values(array_filter((array) config('app.trusted_proxies', [])));
        if ($trusted === [] || $xff === null || $xff === '') {
            if (!in_array('*', $trusted, true)) {
                return $peer;
            }
        }
        if (!in_array('*', $trusted, true) && !in_array($peer, $trusted, true)) {
            return $peer; // peer is not a proxy — XFF is client noise
        }
        $hops = array_values(array_filter(array_map('trim', explode(',', (string) $xff))));
        if ($hops === []) {
            return $peer;
        }
        return (string) end($hops);
    }

    /** HTTP method with spoofing support via _method field */
    public function method(): string
    {
        $method = strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST') {
            $spoof = strtoupper((string) ($this->post['_method'] ?? $this->get['_method'] ?? ''));
            if (in_array($spoof, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $spoof;
            }
        }
        return $method;
    }

    /** Request path without Query String (e.g. /users/5) */
    public function uri(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        // Normalize: remove trailing slash except root
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /** All inputs (POST + GET + route params) */
    public function all(): array
    {
        return array_merge($this->get, $this->post, $this->routeParams);
    }

    /**
     * Normalized uploads: field name → UploadedFile (or UploadedFile[] for
     * name[] inputs). Invalid/absent entries are skipped.
     *
     * @return array<string, UploadedFile|UploadedFile[]>
     */
    public function uploads(): array
    {
        $out = [];
        foreach ($this->files as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $file = UploadedFile::fromFilesArray($entry);
            if ($file instanceof UploadedFile) {
                $out[$key] = $file;
            } elseif (is_array($file) && $file !== []) {
                $out[$key] = $file;
            }
        }
        return $out;
    }

    /** One upload by field name: request()->file('cover') */
    public function file(string $key): UploadedFile|array|null
    {
        return $this->uploads()[$key] ?? null;
    }

    /**
     * Read input: request('name') or $request->input('name', 'default')
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->all();
        // Hide spoofing field from inputs
        unset($data['_method']);
        if ($key === null) {
            return $data;
        }
        return $data[$key] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->get;
        }
        return $this->get[$key] ?? $default;
    }

    /** Only specified inputs */
    public function only(array $keys): array
    {
        $out = [];
        $data = $this->all();
        foreach ($keys as $k) {
            $out[$k] = $data[$k] ?? null;
        }
        return $out;
    }

    /** All inputs except the given keys */
    public function except(array $keys): array
    {
        $data = $this->all();
        unset($data['_method']);
        foreach ($keys as $k) {
            unset($data[$k]);
        }
        return $data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function isMethod(string $method): bool
    {
        return $this->method() === strtoupper($method);
    }

    // ---------- Validation ----------

    /**
     * Validate inputs against rules.
     *
     * Rules: ['name' => 'required|string|max:255', 'email' => 'required|email|unique:users,email']
     * Available rules: required, string, int, float, bool, email, url, numeric, array,
     *                  min:N, max:N, in:a,b,c, confirmed, same:field, different:field,
     *                  exists:table,column, unique:table,column[,ignoreId]
     *
     * @param array $rules    Pipe-separated rules per field.
     * @param array $messages Custom messages keyed by "field.rule" (override lang).
     * @return array Validated data
     * @throws Exceptions\ValidationException
     */
    public function validate(array $rules, array $messages = []): array
    {
        $data = $this->all();
        // Uploaded files participate in validation as first-class values.
        foreach ($this->uploads() as $key => $file) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $file;
            }
        }
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            $isRequired = in_array('required', $ruleList, true);

            // Skip optional fields that are empty
            if (!$isRequired && ($value === null || $value === '')) {
                continue;
            }

            foreach ($ruleList as $rule) {
                if ($rule === 'required') {
                    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                        $errors[$field][] = $this->message($field, 'required', [], $messages);
                    }
                    continue;
                }

                // Parse rule with parameter (e.g. min:3)
                $ruleName = $rule;
                $ruleParam = null;
                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                }

                $failure = $this->validateRule($field, $value, $ruleName, $ruleParam);
                if ($failure !== null) {
                    $errors[$field][] = $this->message($field, $ruleName, $failure, $messages);
                    break; // Stop at first error per field
                }
            }

            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new Exceptions\ValidationException('Validation failed.', $errors);
        }

        return $validated;
    }

    /**
     * Resolve an error message: custom ("field.rule") wins over the
     * translatable lang entry (config('app.locale'), fallback en).
     */
    protected function message(string $field, string $rule, array $failure, array $messages): string
    {
        $replace = $failure['replace'] ?? [];
        $replace['field'] = $field;

        $custom = $messages[$field . '.' . $rule] ?? null;
        if (is_string($custom) && $custom !== '') {
            foreach ($replace as $key => $value) {
                $custom = str_replace(':' . $key, (string) $value, $custom);
            }
            return $custom;
        }

        $langKey = 'validation.' . ($failure['langKey'] ?? $rule);
        return Lang::line($langKey, $replace);
    }

    /**
     * Validate a single rule.
     * @return null when valid, otherwise ['langKey' => ?, 'replace' => ?]
     */
    protected function validateRule(string $field, mixed $value, string $rule, ?string $param): ?array
    {
        switch ($rule) {
            case 'string':
                return is_string($value) ? null : [];
            case 'int':
            case 'integer':
                return filter_var($value, FILTER_VALIDATE_INT) !== false ? null : [];
            case 'float':
            case 'double':
                return filter_var($value, FILTER_VALIDATE_FLOAT) !== false ? null : [];
            case 'bool':
            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null ? null : [];
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : [];
            case 'url':
                return filter_var($value, FILTER_VALIDATE_URL) !== false ? null : [];
            case 'numeric':
                return is_numeric($value) ? null : [];
            case 'array':
                return is_array($value) ? null : [];
            case 'file':
                // Upload rules validate UploadedFile objects; an already
                // stored path string passes (Model::create re-validation).
                if (is_string($value) && $value !== '') {
                    return null;
                }
                return $value instanceof UploadedFile && $value->isValid() ? null : [];
            case 'image':
                if (is_string($value) && $value !== '') {
                    return null;
                }
                return $value instanceof UploadedFile && $value->isImage() ? null : [];
            case 'mimes':
                $exts = array_map('trim', explode(',', (string) $param));
                if (is_string($value) && $value !== '') {
                    return null;
                }
                if ($value instanceof UploadedFile && $value->isValid() && in_array($value->extension(), $exts, true)) {
                    return null;
                }
                return ['replace' => ['values' => implode(', ', $exts)]];
            case 'min':
                return $this->validateMin($value, $param);
            case 'max':
                return $this->validateMax($value, $param);
            case 'in':
                $values = explode(',', (string) $param);
                return in_array((string) $value, $values, true) ? null : ['replace' => ['values' => implode(', ', $values)]];
            case 'confirmed':
                $other = $this->all()[$field . '_confirmation'] ?? null;
                return $other === $value ? null : [];
            case 'same':
                $other = $this->all()[(string) $param] ?? null;
                return $other === $value ? null : ['replace' => ['other' => (string) $param]];
            case 'different':
                $other = $this->all()[(string) $param] ?? null;
                return $other !== $value ? null : ['replace' => ['other' => (string) $param]];
            case 'exists':
                [$table, $column] = array_pad(explode(',', (string) $param), 2, null);
                $found = db((string) $table)->where((string) ($column !== null && $column !== '' ? $column : $field), $value)->exists();
                return $found ? null : [];
            case 'unique':
                [$table, $column, $ignoreId] = array_pad(explode(',', (string) $param), 3, null);
                $query = db((string) $table)->where((string) ($column !== null && $column !== '' ? $column : $field), $value);
                if ($ignoreId !== null && $ignoreId !== '') {
                    $query->where('id', '!=', (int) $ignoreId);
                }
                return $query->exists() ? [] : null;
            default:
                return null; // Unknown rules pass silently
        }
    }

    /** Validate a min: rule across value types. */
    protected function validateMin(mixed $value, ?string $param): ?array
    {
        $min = (int) $param;
        if ($value instanceof UploadedFile && $value->sizeKb() < $min) {
            return ['langKey' => 'min.file', 'replace' => ['min' => $min]];
        }
        if (is_string($value) && mb_strlen($value) < $min) {
            return ['langKey' => 'min.string', 'replace' => ['min' => $min]];
        }
        if (is_numeric($value) && $value < $min) {
            return ['langKey' => 'min.numeric', 'replace' => ['min' => $min]];
        }
        if (is_array($value) && count($value) < $min) {
            return ['langKey' => 'min.array', 'replace' => ['min' => $min]];
        }
        return null;
    }

    /** Validate a max: rule across value types. */
    protected function validateMax(mixed $value, ?string $param): ?array
    {
        $max = (int) $param;
        if ($value instanceof UploadedFile && $value->sizeKb() > $max) {
            return ['langKey' => 'max.file', 'replace' => ['max' => $max]];
        }
        if (is_string($value) && mb_strlen($value) > $max) {
            return ['langKey' => 'max.string', 'replace' => ['max' => $max]];
        }
        if (is_numeric($value) && $value > $max) {
            return ['langKey' => 'max.numeric', 'replace' => ['max' => $max]];
        }
        if (is_array($value) && count($value) > $max) {
            return ['langKey' => 'max.array', 'replace' => ['max' => $max]];
        }
        return null;
    }

    // ---------- CSRF Protection ----------

    /**
     * Generate or retrieve CSRF token.
     * Store in session for persistence.
     */
    public function csrfToken(): string
    {
        Session::start();
        $token = Session::get('_csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf_token', $token);
        }
        return $token;
    }

    /**
     * Validate CSRF token from request.
     * Checks both 'csrf_token' input and 'X-CSRF-Token' header.
     */
    public function validateCsrf(): bool
    {
        Session::start();
        $token = (string) ($this->input('csrf_token')
            ?? $this->server['HTTP_X_CSRF_TOKEN']
            ?? '');
        $stored = (string) (Session::get('_csrf_token') ?? '');
        // An empty token on either side must never validate (prevents bypass
        // when hash_equals('', '') would otherwise return true).
        if ($stored === '' || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    /**
     * Middleware helper: verify CSRF token on non-GET requests.
     * Returns true if valid, false otherwise.
     */
    public function verifyCsrf(): bool
    {
        if (in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        return $this->validateCsrf();
    }
}
