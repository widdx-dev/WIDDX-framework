<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\MailInterface;

/**
 * Log mail driver — writes emails to log instead of sending.
 *
 * Perfect for development and testing.
 */
class LogMail implements MailInterface
{
    protected array $emails = [];

    public function raw(string $to, string $subject, string $body, array $headers = []): bool
    {
        $this->emails[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'headers' => $headers,
            'time' => date('Y-m-d H:i:s'),
        ];
        // Dev driver: log the FULL body too — reset/verification links in
        // the log are how you click through auth flows locally and in tests.
        logger('info', "Mail logged: {$subject} to {$to}\n{$body}");
        return true;
    }

    public function send(string $to, string $subject, string $template, array $data = []): bool
    {
        $body = view($template, $data);
        return $this->raw($to, $subject, $body);
    }

    public function queue(string $to, string $subject, string $template, array $data = []): string
    {
        return Queue::push(SendMailJob::class, [
            'to' => $to,
            'subject' => $subject,
            'template' => $template,
            'data' => $data,
        ]);
    }

    public function attach(string $path, ?string $name = null): static
    {
        return $this;
    }

    public function cc(array $recipients): static
    {
        return $this;
    }

    public function bcc(array $recipients): static
    {
        return $this;
    }

    public function replyTo(string $email): static
    {
        return $this;
    }

    /** Get all logged emails (for testing) */
    public function logged(): array
    {
        return $this->emails;
    }

    /** Clear logged emails */
    public function clear(): void
    {
        $this->emails = [];
    }
}
