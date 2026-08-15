<?php

declare(strict_types=1);

namespace App\Exceptions;

class AppException extends \Exception
{
    protected $message = 'Application error';
    protected $code = 400;
}
