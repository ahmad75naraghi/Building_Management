<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AuthException extends AppException
{
    protected $message = 'Authentication failed';
    protected $code = 401;
}
