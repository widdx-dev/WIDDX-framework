<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/** Thrown by Request::validate() — carries errors per field. */
class ValidationException extends WiddxException
{
    /** @var array<string, string[]> Validation errors by field */
    public array $errors;

    public function __construct(string $message = '', array $errors = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 422, $previous);
        $this->errors = $errors;
    }
}
