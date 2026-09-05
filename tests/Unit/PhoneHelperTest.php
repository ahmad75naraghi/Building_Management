<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Utilities\PhoneHelper;
use PHPUnit\Framework\TestCase;

/**
 * تست‌های واحد نرمال‌سازی شماره موبایل (نام‌کاربری سیستم) — بدون نیاز به دیتابیس.
 */
final class PhoneHelperTest extends TestCase
{
    public function testValidIranianMobile(): void
    {
        $this->assertTrue(PhoneHelper::isValid('09123456789'));
    }

    public function testPersianDigitsNormalized(): void
    {
        $this->assertSame('09123456789', PhoneHelper::normalize('۰۹۱۲۳۴۵۶۷۸۹'));
        $this->assertTrue(PhoneHelper::isValid('۰۹۱۲۳۴۵۶۷۸۹'));
    }

    public function testCountryCodeNormalized(): void
    {
        $this->assertSame('09123456789', PhoneHelper::normalize('+989123456789'));
        $this->assertSame('09123456789', PhoneHelper::normalize('00989123456789'));
        $this->assertSame('09123456789', PhoneHelper::normalize('989123456789'));
    }

    public function testSeparatorsRemoved(): void
    {
        $this->assertSame('09123456789', PhoneHelper::normalize('0912 345 6789'));
        $this->assertSame('09123456789', PhoneHelper::normalize('0912-345-6789'));
    }

    public function testInvalidPhones(): void
    {
        $this->assertFalse(PhoneHelper::isValid(''));
        $this->assertFalse(PhoneHelper::isValid('12345'));
        $this->assertFalse(PhoneHelper::isValid('02122334455'));
        $this->assertFalse(PhoneHelper::isValid('user@example.com'));
    }
}
