<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/** Generic HTTP error with a status code. */
class HttpException extends WiddxException
{
    public function __construct(string $message = '', public int $status = 500, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
