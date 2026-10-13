<?php

declare(strict_types=1);

namespace Widdx\Contracts;

/**
 * Mail contract — any mail driver must implement this.
 *
 * Example drivers: SmtpMail, MailgunMail, LogMail (for testing)
 */
interface MailInterface
{
    /** Send a raw text email */
    public function raw(string $to, string $subject, string $body, array $headers = []): bool;

    /** Send a templated email */
    public function send(string $to, string $subject, string $template, array $data = []): bool;

    /** Queue an email for later sending */
    public function queue(string $to, string $subject, string $template, array $data = []): string;

    /** Attach a file */
    public function attach(string $path, ?string $name = null): static;

    /** Set CC recipients */
    public function cc(array $recipients): static;

    /** Set BCC recipients */
    public function bcc(array $recipients): static;

    /** Set reply-to address */
    public function replyTo(string $email): static;
}
