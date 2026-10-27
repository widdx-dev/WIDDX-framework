<?php

declare(strict_types=1);

namespace Widdx;

use Widdx\Contracts\MailInterface;

/**
 * SMTP mail driver — a real SMTP client over sockets.
 *
 * Supports plain SMTP, implicit SSL (port 465) and STARTTLS (port 587),
 * with AUTH LOGIN when credentials are configured. When
 * mail.mailers.smtp.host is empty it falls back to PHP's mail() so the
 * driver still works on shared hosting.
 *
 * Config keys:
 *   mail.mailers.smtp.host / port / username / password
 *   mail.mailers.smtp.encryption  '' | 'tls' (STARTTLS) | 'ssl' (implicit)
 *   mail.mailers.smtp.timeout     connection timeout seconds (default 30)
 *   mail.mailers.smtp.verify_peer TLS certificate verification (default true)
 */
class SmtpMail implements MailInterface
{
    protected string $from;
    protected string $fromName;
    protected array $headers = [];

    protected string $host;
    protected int $port;
    protected ?string $username;
    protected ?string $password;
    protected string $encryption;
    protected int $timeout;
    protected bool $verifyPeer;
    /** @var array<int, array{path:string,name:string}> */
    protected array $attachments = [];

    public function __construct()
    {
        $this->from = (string) config('mail.from', 'noreply@localhost');
        $this->fromName = (string) config('mail.from_name', 'Widdx App');
        $smtp = (array) config('mail.mailers.smtp', []);
        $this->host = (string) ($smtp['host'] ?? '');
        $this->port = (int) ($smtp['port'] ?? 587);
        $this->username = (($smtp['username'] ?? '') !== '') ? (string) $smtp['username'] : null;
        $this->password = (($smtp['password'] ?? '') !== '') ? (string) $smtp['password'] : null;
        $this->encryption = strtolower((string) ($smtp['encryption'] ?? 'tls'));
        $this->timeout = (int) ($smtp['timeout'] ?? 30);
        $this->verifyPeer = (bool) ($smtp['verify_peer'] ?? true);
    }

    /** Set the "Reply-To" address */
    public function replyTo(string $email, string $name = ''): static
    {
        $this->headers['Reply-To'] = $name ? "{$name} <{$email}>" : $email;
        return $this;
    }

    /** Attach a file (sent as a multipart/mixed attachment). */
    public function attach(string $path, ?string $name = null): static
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new Exceptions\MailException("Attachment not readable: {$path}");
        }
        $this->attachments[] = ['path' => $path, 'name' => $name ?? basename($path)];
        return $this;
    }

    /** Add Bcc recipients (envelope-only; stripped from message data). */
    public function bcc(array $recipients): static
    {
        $this->headers['Bcc'] = trim(($this->headers['Bcc'] ?? '') . ',' . implode(',', $recipients), ',');
        return $this;
    }

    /** Add Cc recipients. */
    public function cc(array $recipients): static
    {
        $this->headers['Cc'] = trim(($this->headers['Cc'] ?? '') . ',' . implode(',', $recipients), ',');
        return $this;
    }

    /** Render a view template and send it. */
    public function send(string $to, string $subject, string $template, array $data = []): bool
    {
        $body = (string) view($template, $data);
        return $this->raw($to, $subject, $body);
    }

    /** Queue the mail for sending through the queue worker. */
    public function queue(string $to, string $subject, string $template, array $data = []): string
    {
        return Queue::push(SendMailJob::class, [
            'to' => $to,
            'subject' => $subject,
            'template' => $template,
            'data' => $data,
        ]);
    }

    public function raw(string $to, string $subject, string $body, array $headers = []): bool
    {
        try {
            // Validate values that PHP mail() and SMTP both use as headers/envelope.
            // The actual message body is intentionally not restricted by this guard.
            $to = $this->sanitize($to, 'recipient');
            $subject = $this->sanitize($subject, 'subject');

            // Header names are case-insensitive. Normalize standard names before
            // processing Cc/Bcc, so e.g. "bcc" cannot bypass the Bcc stripping.
            $allHeaders = $this->normalizeHeaders(array_merge(
                ['From' => "{$this->fromName} <{$this->from}>"],
                $this->headers,
                $headers
            ));
            unset($allHeaders['Subject']); // The $subject argument is authoritative.

            // Envelope recipients: To + Cc + Bcc (Bcc is stripped from DATA below).
            $recipients = $this->extractEmails($to);
            foreach (['Cc', 'Bcc'] as $key) {
                if (isset($allHeaders[$key])) {
                    $recipients = array_merge($recipients, $this->extractEmails($allHeaders[$key]));
                }
            }
            $recipients = array_values(array_unique($recipients));

            // Keep To/Cc inside message DATA; never expose Bcc in the message itself.
            if (!isset($allHeaders['To'])) {
                $allHeaders['To'] = $to;
            }
            unset($allHeaders['Bcc']);

            // Attachments turn the payload into a multipart/mixed attachment.
            $dataBody = $body;
            if ($this->attachments !== []) {
                $boundary = 'widdx-' . bin2hex(random_bytes(8));
                $allHeaders['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
                $dataBody = $this->buildMultipart($boundary, $body);
            }

            $message = $this->buildMessage($subject, $allHeaders, $dataBody);

            // No host configured — fall back to PHP's mail() function.
            if ($this->host === '') {
                $headerLines = [];
                foreach ($allHeaders as $name => $value) {
                    $safeName = $this->normalizeHeaderName((string) $name);
                    $headerLines[] = $safeName . ': ' . $this->sanitize((string) $value, "header '{$safeName}'");
                }
                return @mail($to, $subject, $dataBody, implode("\r\n", $headerLines));
            }

            $this->smtpSend($recipients, $message);
            return true;
        } finally {
            // Clear one-shot attachments even if header validation fails before transport.
            $this->attachments = [];
        }
    }

    /**
     * Validate and canonicalize standard header names. Reject control characters,
     * whitespace, colon and non-ASCII before a name can reach the message builder.
     */
    protected function normalizeHeaderName(string $name): string
    {
        if (preg_match('/\A[\x21-\x39\x3B-\x7E]+\z/', $name) !== 1) {
            throw new Exceptions\MailException('Refusing invalid email header name.');
        }

        return match (strtolower($name)) {
            'from' => 'From',
            'to' => 'To',
            'cc' => 'Cc',
            'bcc' => 'Bcc',
            'reply-to' => 'Reply-To',
            'content-type' => 'Content-Type',
            'content-transfer-encoding' => 'Content-Transfer-Encoding',
            'content-disposition' => 'Content-Disposition',
            'mime-version' => 'MIME-Version',
            'subject' => 'Subject',
            default => $name,
        };
    }

    /** Normalize case-insensitive header names and reject CR/LF in values. */
    protected function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $rawName => $rawValue) {
            $name = $this->normalizeHeaderName((string) $rawName);
            if (is_array($rawValue) || (is_object($rawValue) && !$rawValue instanceof \Stringable)) {
                throw new Exceptions\MailException("Refusing header '{$name}': value must be scalar or stringable.");
            }
            $value = $this->sanitize((string) $rawValue, "header '{$name}'");

            // Preserve multiple Cc/Bcc entries that differ only in header casing.
            if (in_array($name, ['Cc', 'Bcc'], true) && isset($normalized[$name]) && $value !== '') {
                $normalized[$name] = trim($normalized[$name] . ', ' . $value, ', ');
            } else {
                $normalized[$name] = $value;
            }
        }
        return $normalized;
    }

    /** RFC 2046 multipart/mixed body: text part + base64 attachments. */
    protected function buildMultipart(string $boundary, string $body): string
    {
        $parts = [];
        $parts[] = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n" . $body;
        foreach ($this->attachments as $attachment) {
            $data = @file_get_contents($attachment['path']);
            if ($data === false) {
                throw new Exceptions\MailException('Cannot read attachment: ' . $attachment['path']);
            }
            $name = $this->safeAttachmentName($attachment['name']);
            $parts[] = "--{$boundary}\r\nContent-Type: " . static::mimeFor($name) . "; name=\"{$name}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                . chunk_split(base64_encode($data), 76, "\r\n");
        }
        $parts[] = "--{$boundary}--";
        return implode("\r\n", $parts);
    }

    /** Keep attachment parameter values safe inside quoted MIME header fields. */
    protected function safeAttachmentName(string $name): string
    {
        $name = $this->sanitize($name, 'attachment name');
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name)) ?? '';
        $name = trim($name, '.');
        if ($name === '' || $name === '..') {
            return 'attachment';
        }
        return substr($name, 0, 150);
    }

    /** Best-effort content type by extension. */
    protected static function mimeFor(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return match ($ext) {
            'txt', 'md', 'log' => 'text/plain',
            'html', 'htm' => 'text/html',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'zip' => 'application/zip',
            'xls', 'xlsx' => 'application/vnd.ms-excel',
            default => 'application/octet-stream',
        };
    }

    /** Build the RFC-822 message (headers + blank line + body). */
    protected function buildMessage(string $subject, array $headers, string $body): string
    {
        $lines = [];
        $now = gmdate('D, d M Y H:i:s') . ' +0000';
        $lines[] = 'Date: ' . $now;
        $lines[] = 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . gethostname() . '>';
        foreach ($headers as $name => $value) {
            $name = $this->normalizeHeaderName((string) $name);
            $lines[] = $name . ': ' . $this->sanitize((string) $value, "header '{$name}'");
        }
        if ($subject !== '') {
            $subject = $this->sanitize($subject, 'subject');
            $encoded = $this->encodeHeaderLine('Subject', $subject);
            $lines[] = $encoded;
        }
        return implode("\r\n", $lines) . "\r\n\r\n" . $body;
    }

    /**
     * Header injection guard: header VALUES must not contain CR/LF.
     * (Multi-line RFC2047 subjects are assembled separately, never via \n in values.)
     */
    protected function sanitize(string $value, string $what): string
    {
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            throw new Exceptions\MailException("Refusing {$what}: contains CR/LF (possible header injection).");
        }
        return trim($value);
    }

    /** Encode non-ASCII header values (RFC 2047) when mbstring is available. */
    protected function encodeHeaderLine(string $name, string $value): string
    {
        if (function_exists('mb_encode_mimeheader') && preg_match('/[^\x20-\x7E]/', $value)) {
            $value = mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
            return $name . ': ' . $value;
        }
        return $name . ': ' . $value;
    }

    /** Pull bare email addresses out of a header value or address list. */
    protected function extractEmails(string $value): array
    {
        preg_match_all('/<\s*([^>]+?)\s*>|([^,;\s<>]+@[^,;\s<>]+)/', $value, $matches);
        $emails = [];
        foreach ($matches[0] as $i => $match) {
            $candidate = trim($matches[1][$i] !== '' ? $matches[1][$i] : $matches[2][$i]);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $candidate;
            }
        }
        return $emails;
    }

    /** Full SMTP conversation. Throws MailException on any failure. */
    protected function smtpSend(array $recipients, string $message): void
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
            ],
        ]);

        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            throw new Exceptions\MailException("SMTP connection to {$this->host}:{$this->port} failed: {$errstr} ({$errno}).");
        }
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($this->read($socket), [220], 'greeting');

            $hello = gethostname() ?: 'localhost';
            $this->write($socket, 'EHLO ' . $hello);
            $ehlo = $this->read($socket);
            $this->expect($ehlo, [250], 'EHLO');

            if ($this->encryption === 'tls') {
                $this->write($socket, 'STARTTLS');
                $this->expect($this->read($socket), [220], 'STARTTLS');
                $ok = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if (!$ok) {
                    throw new Exceptions\MailException('SMTP STARTTLS handshake failed.');
                }
                $this->write($socket, 'EHLO ' . $hello);
                $this->expect($this->read($socket), [250], 'EHLO (after STARTTLS)');
            }

            if ($this->username !== null) {
                $this->write($socket, 'AUTH LOGIN');
                $this->expect($this->read($socket), [334], 'AUTH LOGIN');
                $this->write($socket, base64_encode($this->username));
                $this->expect($this->read($socket), [334], 'AUTH username');
                $this->write($socket, base64_encode((string) $this->password));
                $this->expect($this->read($socket), [235], 'AUTH password');
            }

            $this->write($socket, 'MAIL FROM:<' . $this->from . '>');
            $this->expect($this->read($socket), [250], 'MAIL FROM');

            foreach ($recipients as $recipient) {
                $this->write($socket, 'RCPT TO:<' . $recipient . '>');
                $this->expect($this->read($socket), [250, 251], 'RCPT TO <' . $recipient . '>');
            }

            $this->write($socket, 'DATA');
            $this->expect($this->read($socket), [354], 'DATA');

            // Dot-stuffing: lines starting with '.' must be doubled.
            $stuffed = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", $message));
            $this->write($socket, $stuffed . "\r\n.");
            $this->expect($this->read($socket), [250], 'queueing message');

            $this->write($socket, 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    protected function write($socket, string $line): void
    {
        if (fwrite($socket, $line . "\r\n") === false) {
            throw new Exceptions\MailException('SMTP write failed — connection lost.');
        }
    }

    /** Read one (possibly multi-line) SMTP reply. */
    protected function read($socket): string
    {
        $response = '';
        while (!feof($socket)) {
            $chunk = fgets($socket, 1024);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
            // A line like "250 " (code + space) ends the reply; "250-" continues.
            if (preg_match('/^\d{3} /', $chunk)) {
                break;
            }
        }
        return trim($response);
    }

    /** Assert the reply code is one of the expected ones. */
    protected function expect(string $response, array $codes, string $stage): void
    {
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new Exceptions\MailException("SMTP error at {$stage}: " . str_replace("\r\n", ' / ', $response));
        }
    }
}
