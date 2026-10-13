<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Mailable base class.
 *
 * Extend this to create emails:
 *
 *   class WelcomeEmail extends Mailable
 *   {
 *       public function __construct(protected string $name) {}
 *
 *       public function build(): array
 *       {
 *           return [
 *               'template' => 'emails.welcome',
 *               'data' => ['name' => $this->name],
 *           ];
 *       }
 *   }
 *
 * Send:
 *   Mail::to('user@example.com')->deliver(new WelcomeEmail('Ali'));
 */
abstract class Mailable
{
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected array $attachments = [];
    protected string $subject = '';
    protected string $replyTo = '';

    /** Build the email content */
    abstract public function build(): array;

    /** Send to a recipient */
    public function to(string|array $email): static
    {
        $this->to = is_array($email) ? $email : [$email];
        return $this;
    }

    /** CC recipients */
    public function cc(string|array $recipients): static
    {
        $this->cc = is_array($recipients) ? $recipients : [$recipients];
        return $this;
    }

    /** BCC recipients */
    public function bcc(string|array $recipients): static
    {
        $this->bcc = is_array($recipients) ? $recipients : [$recipients];
        return $this;
    }

    /** Attach a file by path */
    public function attach(string $path, ?string $name = null): static
    {
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path)];
        return $this;
    }

    /** Set reply-to address */
    public function replyTo(string $email): static
    {
        $this->replyTo = $email;
        return $this;
    }

    /** Set subject */
    public function subject(string $subject): static
    {
        $this->subject = $subject;
        return $this;
    }

    /** Set sender (from) */
    public function from(string $email, string $name = ''): static
    {
        // Headers are applied through the driver using config defaults;
        // store on the instance so drivers can honour them.
        $this->fromEmail = $email;
        $this->fromName = $name;
        return $this;
    }

    protected string $fromEmail = '';
    protected string $fromName = '';

    /** Build the message headers from current state */
    public function headers(): array
    {
        $headers = [];
        if ($this->fromEmail !== '') {
            $headers['From'] = $this->fromName !== ''
                ? "{$this->fromName} <{$this->fromEmail}>"
                : $this->fromEmail;
        }
        if ($this->replyTo !== '') {
            $headers['Reply-To'] = $this->replyTo;
        }
        if (!empty($this->cc)) {
            $headers['Cc'] = implode(', ', $this->cc);
        }
        if (!empty($this->bcc)) {
            $headers['Bcc'] = implode(', ', $this->bcc);
        }
        return $headers;
    }

    /** Recipients this mailable will be sent to */
    public function recipients(): array
    {
        return $this->to;
    }

    /** Send the email to all recipients */
    public function send(): bool
    {
        if (empty($this->to)) {
            throw new Exceptions\MailException('No recipient set. Use to() before send().');
        }

        $mail = Mail::connection();
        $content = $this->build();
        $template = $content['template'] ?? 'emails.default';
        $data = $content['data'] ?? [];

        $body = view($template, $data);
        $headers = $this->headers();
        $result = true;

        foreach ($this->to as $recipient) {
            $sent = $mail->raw($recipient, $this->subject, $body, $headers);

            // Attach files if supported by the driver
            if ($sent && !empty($this->attachments) && method_exists($mail, 'attach')) {
                foreach ($this->attachments as $file) {
                    $mail->attach($file['path'], $file['name']);
                }
            }

            $result = $sent && $result;
        }

        return $result;
    }

    /** Queue the email for the first recipient */
    public function queue(): string
    {
        $content = $this->build();
        $recipient = $this->to[0] ?? throw new Exceptions\MailException('No recipient set. Use to() before queue().');

        return Mail::queue(
            $recipient,
            $this->subject,
            $content['template'] ?? 'emails.default',
            $content['data'] ?? []
        );
    }
}