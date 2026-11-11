<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Response object.
 */
class Response
{
    public function __construct(
        protected mixed $content = '',
        protected int $status = 200,
        protected array $headers = []
    ) {}

    public static function make(mixed $content = '', int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        // Pretty-print only in debug: production payloads stay compact.
        $flags = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            | (App::getConfig('app.debug', false) ? JSON_PRETTY_PRINT : 0);
        try {
            $content = json_encode($data, $flags);
        } catch (\JsonException $e) {
            // Never send a silently-empty body — surface the encoding failure.
            $content = json_encode(['error' => 'JSON encoding failed: ' . $e->getMessage()]) ?: '[]';
            if ($status === 200) {
                $status = 500;
            }
        }
        return new self($content, $status, $headers);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    /**
     * Set a header, replacing any earlier value of the same name.
     * Note: Set-Cookie lines are always appended at send() time (never
     * replaced), so the session cookie queued by PHP survives every response.
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Append a header value WITHOUT replacing earlier ones — for headers that
     * may repeat in a single response, most importantly Set-Cookie:
     *   Response::make('ok')
     *       ->addHeader('Set-Cookie', 'session=abc; Path=/; HttpOnly')
     *       ->addHeader('Set-Cookie', 'prefs=dark; Path=/');
     * Name matching is case-insensitive so "set-cookie" appends to "Set-Cookie".
     */
    public function addHeader(string $name, string $value): self
    {
        foreach ($this->headers as $key => $existing) {
            if (strcasecmp((string) $key, $name) === 0) {
                $values = is_array($existing) ? $existing : [$existing];
                $values[] = $value;
                $this->headers[$key] = array_values($values);
                return $this;
            }
        }
        $this->headers[$name] = [$value];
        return $this;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** Get all response headers (a value is a list when addHeader() repeated it) */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** Get response content */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /** 2.4: drop the body (HEAD requests must send headers only). */
    public function withoutBody(): self
    {
        $this->content = '';
        return $this;
    }

    /** Set multiple headers at once */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /** Send response to browser */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                // A value may be a list (see addHeader): several Set-Cookie lines
                // must each reach the client, not overwrite one another.
                $values = is_array($value) ? array_values($value) : [$value];
                // Set-Cookie is strictly append-only: header(replace: true) would
                // also wipe cookies already queued by the session extension.
                $appendOnly = strcasecmp((string) $name, 'Set-Cookie') === 0;
                $replace = !$appendOnly;
                foreach ($values as $single) {
                    header($name . ': ' . (string) $single, $replace);
                    $replace = false;
                }
            }
        }
        echo (string) $this->content;
    }

    public function __toString(): string
    {
        return (string) $this->content;
    }
}
