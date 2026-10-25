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

    /** 2.10: matched route URI template (e.g. /users/{id}) for stable keys. */
    public string $routePattern = '';

    /** Raw request body (JSON / urlencoded PUT-PATCH-DELETE). Populated by capture(). */
    public string $raw = '';

    /** True when the body advertised JSON but could not be decoded (=> 400). */
    public bool $malformedJson = false;

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

    /** Create request from superglobals, then fold the raw body into $post. */
    public static function capture(): self
    {
        $request = new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
        $request->parseRawBody();
        return $request;
    }

    /**
     * 2.3: PHP only fills $_POST for urlencoded/multipart POST. So JSON
     * bodies and form bodies sent with PUT/PATCH/DELETE are read from
     * php://input here and merged into $post, which makes input()/all()/
     * validate()/create() work identically for JSON and form requests.
     * Multipart PUT/PATCH/DELETE is not parsed (PHP cannot) — use _method
     * spoofing on a POST form for file uploads on update.
     */
    public function parseRawBody(): void
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? ''));
        $type = strtolower((string) ($this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? ''));
        $isJson = str_contains($type, 'application/json') || str_contains($type, '+json');
        $isFormBody = in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            && str_contains($type, 'application/x-www-form-urlencoded');
        if (!$isJson && !$isFormBody) {
            return;
        }
        // Enforce the limit using both the advertised length and the bytes
        // actually read. CONTENT_LENGTH may be absent or inaccurate (e.g. a
        // chunked request), so it must never be the only guard.
        $maxBytes = 2 * 1024 * 1024;
        $len = (int) ($this->server['CONTENT_LENGTH'] ?? 0);
        if ($len > $maxBytes) {
            if ($isJson) {
                $this->malformedJson = true;
            }
            return;
        }
        // Read at most maxBytes + 1 so an absent/false length cannot make this
        // method buffer an unbounded request body in memory.
        if ($this->raw === '') {
            $this->raw = (string) file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        }
        // Also cover test/integration callers that provide $raw directly and
        // bodies whose advertised length was smaller than the actual payload.
        if (strlen($this->raw) > $maxBytes) {
            if ($isJson) {
                $this->malformedJson = true;
            }
            $this->raw = '';
            return;
        }
        if ($this->raw === '') {
            return;
        }
        if ($isJson) {
            $decoded = json_decode($this->raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->malformedJson = true;
                return;
            }
            if (is_array($decoded)) {
                $this->post = array_replace($this->post, $decoded);
            }
            return;
        }
        parse_str($this->raw, $parsed);
        if (is_array($parsed) && $parsed !== []) {
            $this->post = array_replace($this->post, $parsed);
        }
    }

    /** Decoded JSON body (whole payload, or one key). */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $data = json_decode($this->raw, true);
        if (!is_array($data)) {
            $data = [];
        }
        return $key === null ? $data : ($data[$key] ?? $default);
    }

    /** Read a request header by name (case/dash insensitive). */
    public function header(string $name, mixed $default = null): mixed
    {
        $upper = strtoupper($name);
        $key = 'HTTP_' . str_replace('-', '_', $upper);
        $value = $this->server[$key] ?? null;
        if ($value === null && in_array($upper, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $value = $this->server[$upper] ?? null;
        }
        return $value ?? $default;
    }

    /** Token from an "Authorization: Bearer x" header, or null. */
    public function bearerToken(): ?string
    {
        $auth = (string) ($this->server['HTTP_AUTHORIZATION']
            ?? $this->server['REDIRECT_HTTP_AUTHORIZATION']
            ?? '');
        if (stripos($auth, 'bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
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
        $trusted = static::trustedProxies();
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

    /** Configured trusted proxy list (runtime override, else config). */
    public static function trustedProxies(): array
    {
        return static::$trustedProxies
            ?? array_values(array_filter((array) config('app.trusted_proxies', [])));
    }

    /**
     * Is this direct peer one of the trusted proxies? Session::start() uses
     * the same trust list as ip() when deciding on Secure cookies.
     */
    public static function isTrustedPeer(?string $peer = null): bool
    {
        $peer ??= (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($peer === '') {
            return false;
        }
        $trusted = static::trustedProxies();
        return in_array('*', $trusted, true) || in_array($peer, $trusted, true);
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

    /**
     * Does the caller want JSON back?
     * Accept: …json…, Content-Type: application/json, or a /api path.
     * Error rendering (419/401/403/422/500) keys off this.
     */
    public function expectsJson(): bool
    {
        $accept = strtolower((string) ($this->server['HTTP_ACCEPT'] ?? ''));
        $type = strtolower((string) ($this->server['CONTENT_TYPE'] ?? ''));
        return str_contains($accept, '/json')
            || str_contains($type, '/json')
            || str_starts_with($this->uri(), '/api');
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

                $failure = $this->validateRule($field, $value, $ruleName, $ruleParam, $ruleList);
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
    protected function validateRule(string $field, mixed $value, string $rule, ?string $param, array $ruleList = []): ?array
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
                if ($value instanceof UploadedFile && $value->isValid() && in_array($value->safeExtension(), $exts, true)) {
                    return null;
                }
                return ['replace' => ['values' => implode(', ', $exts)]];
            case 'min':
                return $this->validateMin($value, $param, $ruleList);
            case 'max':
                return $this->validateMax($value, $param, $ruleList);
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
            case 'nullable':
                return null; // explicit no-op: empty values are skipped earlier
            case 'date':
                if ($value instanceof \DateTimeInterface) {
                    return null;
                }
                return is_string($value) && $value !== '' && strtotime($value) !== false ? null : [];
            case 'alpha':
                return is_string($value) && preg_match('/\A[\p{L}\s]+\z/u', $value) === 1 ? null : [];
            case 'alpha_num':
                return is_string($value) && preg_match('/\A[\p{L}\p{N}]+\z/u', $value) === 1 ? null : [];
            case 'digits':
                return is_scalar($value) && preg_match('/\A[0-9]{' . (int) $param . '}\z/', (string) $value) === 1
                    ? null : ['replace' => ['digits' => (int) $param]];
            case 'json':
                if (!is_string($value) || $value === '') {
                    return [];
                }
                json_decode($value);
                return json_last_error() === JSON_ERROR_NONE ? null : [];
            case 'regex':
                if (!is_string($value) || $param === null || $param === '') {
                    return [];
                }
                return @preg_match($param, $value) === 1 ? null : [];
            case 'starts_with':
                return is_string($value) && $param !== null && str_starts_with($value, $param)
                    ? null : ['replace' => ['values' => (string) $param]];
            case 'ends_with':
                return is_string($value) && $param !== null && str_ends_with($value, $param)
                    ? null : ['replace' => ['values' => (string) $param]];
            case 'size':
                $n = (float) $param;
                if ($value instanceof UploadedFile) {
                    return $value->sizeKb() == $n ? null : ['replace' => ['size' => $n]];
                }
                $fail = ['replace' => ['size' => $n]];
                if (self::comparesByValue($ruleList, $value)) {
                    return (float) $value == $n ? null : $fail;
                }
                if (is_string($value)) {
                    return self::textLength($value) == (int) $n ? null : $fail;
                }
                if (is_array($value)) {
                    return count($value) == (int) $n ? null : $fail;
                }
                return is_numeric($value) && (float) $value != $n ? $fail : [];
            case 'between':
                [$bLo, $bHi] = array_pad(explode(',', (string) $param), 2, null);
                if ($bLo === null || $bHi === null) {
                    return [];
                }
                $range = ['min' => (float) $bLo, 'max' => (float) $bHi];
                $within = static fn(float $x): ?array => ($x >= (float) $bLo && $x <= (float) $bHi) ? null : $range;
                if (self::comparesByValue($ruleList, $value)) {
                    return $within((float) $value);
                }
                if (is_string($value)) {
                    return $within((float) self::textLength($value));
                }
                if (is_array($value)) {
                    return $within((float) count($value));
                }
                return is_numeric($value) ? $within((float) $value) : [];
            default:
                // A typo'd rule name ('emial') must fail LOUD, not silently pass.
                throw new Exceptions\WiddxException('Unknown validation rule [' . $rule . '] on field [' . $field . '].');
        }
    }

    /**
     * Character count for min/max/size, without requiring ext-mbstring.
     * Shared hosting often ships PHP with no mbstring, and an unguarded
     * mb_strlen() there is a fatal error inside a form validation, not a
     * failed rule. PCRE unicode support is always compiled in on PHP 8.
     */
    protected static function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }
        return static::textLengthPcre($value);
    }

    /** The no-mbstring path, kept separate so the suite can exercise it here. */
    protected static function textLengthPcre(string $value): int
    {
        $chars = preg_match_all('/./su', $value);
        // Invalid UTF-8 bytes: fall back to bytes rather than throwing.
        return $chars === false ? strlen($value) : $chars;
    }

    /**
     * Do min/max/size/between measure this value by NUMBER? Only when the rule list
     * declares the field numeric, or the value is a real int/float (which has no
     * length to count). A digit string under `string` is TEXT: '0001' is four
     * characters and '100' is three — reading them as 1 and 100 rejected valid input.
     */
    protected static function comparesByValue(array $rules, mixed $value): bool
    {
        if (is_string($value)) {
            return $rules !== []
                && array_intersect($rules, ['int', 'integer', 'numeric', 'float', 'double']) !== []
                && is_numeric($value);
        }
        return is_numeric($value);
    }

    /** Validate a min: rule across value types. */
    protected function validateMin(mixed $value, ?string $param, array $rules = []): ?array
    {
        $min = (int) $param;
        if ($value instanceof UploadedFile && $value->sizeKb() < $min) {
            return ['langKey' => 'min.file', 'replace' => ['min' => $min]];
        }
        // A numerically-typed field compares as a NUMBER even when the value arrives
        // as a string, so '25' with int|min:18 passes instead of strlen('25') === 2.
        if (self::comparesByValue($rules, $value)) {
            return (float) $value < $min ? ['langKey' => 'min.numeric', 'replace' => ['min' => $min]] : null;
        }
        if (is_string($value) && self::textLength($value) < $min) {
            return ['langKey' => 'min.string', 'replace' => ['min' => $min]];
        }
        if (is_array($value) && count($value) < $min) {
            return ['langKey' => 'min.array', 'replace' => ['min' => $min]];
        }
        return null;
    }

    /** Validate a max: rule across value types. */
    protected function validateMax(mixed $value, ?string $param, array $rules = []): ?array
    {
        $max = (int) $param;
        if ($value instanceof UploadedFile && $value->sizeKb() > $max) {
            return ['langKey' => 'max.file', 'replace' => ['max' => $max]];
        }
        if (self::comparesByValue($rules, $value)) {
            return (float) $value > $max ? ['langKey' => 'max.numeric', 'replace' => ['max' => $max]] : null;
        }
        if (is_string($value) && self::textLength($value) > $max) {
            return ['langKey' => 'max.string', 'replace' => ['max' => $max]];
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
        $raw = $this->server['HTTP_X_CSRF_TOKEN']
            ?? $this->input('csrf_token')
            ?? $this->input('_token')
            ?? '';
        // csrf_token[]=x arrives as an ARRAY: casting it to string would
        // emit a PHP notice — treat any non-string as simply invalid.
        $token = is_string($raw) ? $raw : '';
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

    /**
     * Resolve a same-site redirect target from an (attacker-controlled) URL.
     * Returns '/' unless it is unmistakably this host — the only place the
     * Referer trust decision lives. back() and the validation-redirect in
     * App::run() must both go through here, or one of them re-opens the bug.
     */
    public static function sameSiteUrl(mixed $url): string
    {
        if (!is_string($url) || trim($url) === '') {
            return '/';
        }
        $url = trim($url);
        $local = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($local === '') {
            return '/';
        }
        // Same-host absolute path. Reject protocol-relative (//) and any
        // backslash — browsers treat \ as /, so /\evil.com becomes //evil.com.
        if ($url[0] === '/') {
            if (str_starts_with($url, '//') || str_contains($url, '\\')) {
                return '/';
            }
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user'])) {
            return '/';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return '/';
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return '/';
        }
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        return $host === $local ? $url : '/';
    }

    /** The safe target for a validation-failed or back() redirect. */
    public static function sameSiteReferer(): string
    {
        return self::sameSiteUrl($_SERVER['HTTP_REFERER'] ?? '');
    }
}
