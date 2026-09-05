<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends AppException
{
    protected $message = 'Validation error';
    protected $code = 422;

    public function __construct(string $message = 'Validation error', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
