<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Pending mailable — a mailable that carries settings, not content.
 *
 * Used by Mail::to() as a fluent builder:
 *
 *   Mail::to('user@example.com')
 *       ->subject('Welcome')
 *       ->deliver(new WelcomeEmail('Ali'));
 *
 * Content comes from the Mailable instance passed to deliver()/queue().
 */
class PendingMailable
{
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected array $attachments = [];
    protected string $subject = '';
    protected string $fromEmail = '';
    protected string $fromName = '';
    protected string $replyTo = '';

    /** Send to a recipient */
    public function to(string|array $email): self
    {
        $this->to = is_array($email) ? $email : [$email];
        return $this;
    }

    /** CC recipients */
    public function cc(string|array $recipients): self
    {
        $this->cc = is_array($recipients) ? $recipients : [$recipients];
        return $this;
    }

    /** BCC recipients */
    public function bcc(string|array $recipients): self
    {
        $this->bcc = is_array($recipients) ? $recipients : [$recipients];
        return $this;
    }

    /** Attach a file */
    public function attach(string $path, ?string $name = null): self
    {
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path)];
        return $this;
    }

    /** Set reply-to address */
    public function replyTo(string $email): self
    {
        $this->replyTo = $email;
        return $this;
    }

    /** Set sender */
    public function from(string $email, string $name = ''): self
    {
        $this->fromEmail = $email;
        $this->fromName = $name;
        return $this;
    }

    /** Set subject */
    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /** Deliver the actual mailable using these settings */
    public function deliver(Mailable $mailable): bool
    {
        $mailable->to($this->to)
            ->subject($this->subject);

        if (!empty($this->cc)) {
            $mailable->cc($this->cc);
        }
        if (!empty($this->bcc)) {
            $mailable->bcc($this->bcc);
        }
        if ($this->replyTo !== '') {
            $mailable->replyTo($this->replyTo);
        }
        if ($this->fromEmail !== '') {
            $mailable->from($this->fromEmail, $this->fromName);
        }
        foreach ($this->attachments as $file) {
            $mailable->attach($file['path'], $file['name']);
        }

        return $mailable->send();
    }

    /** Queue the actual mailable using these settings */
    public function queue(Mailable $mailable): string
    {
        $mailable->to($this->to)->subject($this->subject);
        return $mailable->queue();
    }

    /** Apply settings and return the mailable (for direct access) */
    public function applyTo(Mailable $mailable): Mailable
    {
        $mailable->to($this->to)->subject($this->subject);
        if (!empty($this->cc)) {
            $mailable->cc($this->cc);
        }
        if (!empty($this->bcc)) {
            $mailable->bcc($this->bcc);
        }
        if ($this->replyTo !== '') {
            $mailable->replyTo($this->replyTo);
        }
        return $mailable;
    }
}