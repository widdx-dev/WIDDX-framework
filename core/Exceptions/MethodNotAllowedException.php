<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/** Thrown when the request method is not allowed for a matched URI (405). */
class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 405, $previous);
    }
}
