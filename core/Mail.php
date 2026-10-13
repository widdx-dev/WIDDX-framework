<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\MailInterface;

/**
 * Mail manager — facade for sending emails.
 *
 * Usage:
 *   Mail::send('user@example.com', 'Subject', 'emails.welcome', ['name' => 'Ali']);
 *   Mail::to('user@example.com')->subject('Hi')->send($mailable);
 *   Mail::raw('user@example.com', 'Subject', 'Body');
 *   Mail::queue('user@example.com', 'Subject', 'template', ['key' => 'value']);
 */
class Mail
{
    protected static ?MailInterface $instance = null;

    /**
     * Get mail instance (respects container binding if registered).
     */
    public static function connection(): MailInterface
    {
        if (static::$instance === null) {
            // Prefer the container binding so drivers are swappable at runtime
            $container = Container::getInstance();
            if ($container->has(MailInterface::class)) {
                return static::$instance = $container->get(MailInterface::class);
            }
            $driver = (string) (config('mail.default') ?? 'log');
            static::$instance = $driver === 'smtp' ? new SmtpMail() : new LogMail();
        }
        return static::$instance;
    }

    /** Set custom mail instance */
    public static function setConnection(MailInterface $mail): void
    {
        static::$instance = $mail;
    }

    /**
     * Create a mailable builder.
     * Usage: Mail::to('user@example.com')->subject('Hi')->deliver($mailable);
     */
    public static function to(string|array $email): PendingMailable
    {
        return (new PendingMailable())->to($email);
    }

    /**
     * Send a templated email.
     *
     * Usage: Mail::send('to@example.com', 'Subject', 'emails.welcome', ['name' => 'Ali']);
     */
    public static function send(string $to, string $subject, string $template, array $data = []): bool
    {
        return static::connection()->send($to, $subject, $template, $data);
    }

    /**
     * Send a mailable instance.
     * Usage: Mail::deliver($mailable);
     */
    public static function deliver(Mailable $mailable): bool
    {
        return $mailable->send();
    }

    /** Send raw email with given body */
    public static function raw(string $to, string $subject, string $body, array $headers = []): bool
    {
        return static::connection()->raw($to, $subject, $body, $headers);
    }

    /**
     * Queue a templated email.
     * Usage: Mail::queue('to@example.com', 'Subject', 'emails.welcome', ['name' => 'Ali']);
     */
    public static function queue(string $to, string $subject, string $template, array $data = []): string
    {
        return static::connection()->queue($to, $subject, $template, $data);
    }

    /**
     * Queue a mailable instance for later delivery.
     */
    public static function queueMailable(Mailable $mailable): string
    {
        return $mailable->queue();
    }

    /** Send HTML content */
    public static function html(string $to, string $subject, string $html): bool
    {
        return static::raw($to, $subject, $html, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** Send plain text content */
    public static function plain(string $to, string $subject, string $text): bool
    {
        return static::raw($to, $subject, $text, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}