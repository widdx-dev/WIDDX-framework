<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Job for sending queued emails.
 */
class SendMailJob extends Job
{
    public function handle(array $data): void
    {
        $mail = Mail::connection();
        $mail->send($data['to'], $data['subject'], $data['template'], $data['data']);
    }
}
