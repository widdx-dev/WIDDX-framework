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

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
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

    /** Get all response headers */
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
                header("{$name}: {$value}");
            }
        }
        echo (string) $this->content;
    }

    public function __toString(): string
    {
        return (string) $this->content;
    }
}
