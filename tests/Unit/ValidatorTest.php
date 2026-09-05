<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Utilities\Validator;
use PHPUnit\Framework\TestCase;

/**
 * تست‌های واحد برای Validator — بدون نیاز به دیتابیس.
 *
 * اجرا: composer test
 */
final class ValidatorTest extends TestCase
{
    public function testRequiredFieldMissing(): void
    {
        $errors = Validator::validate([], ['title' => 'required']);
        $this->assertArrayHasKey('title', $errors);
    }

    public function testRequiredFieldPresent(): void
    {
        $errors = Validator::validate(['title' => 'شارژ شهریور'], ['title' => 'required']);
        $this->assertArrayNotHasKey('title', $errors);
    }

    public function testEmailValidation(): void
    {
        $errors = Validator::validate(
            ['email' => 'not-an-email'],
            ['email' => 'required|email']
        );
        $this->assertArrayHasKey('email', $errors);
    }

    public function testValidEmailPasses(): void
    {
        $errors = Validator::validate(
            ['email' => 'user@example.com'],
            ['email' => 'required|email']
        );
        $this->assertArrayNotHasKey('email', $errors);
    }

    public function testMinLengthValidation(): void
    {
        $errors = Validator::validate(
            ['password' => '123'],
            ['password' => 'min:8']
        );
        $this->assertArrayHasKey('password', $errors);
    }

    public function testEmptyValueWithRequiredRule(): void
    {
        $errors = Validator::validate(['title' => ''], ['title' => 'required']);
        $this->assertArrayHasKey('title', $errors);
    }
}
