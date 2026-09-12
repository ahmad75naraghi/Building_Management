<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Utilities\JalaliHelper;
use PHPUnit\Framework\TestCase;

/**
 * تست‌های واحد تبدیل تقویم جلالی — بدون نیاز به دیتابیس.
 */
final class JalaliHelperTest extends TestCase
{
    public function testGregorianToJalaliKnownDates(): void
    {
        $this->assertSame([1405, 1, 1], JalaliHelper::toJalali(2026, 3, 21)); // نوروز ۱۴۰۵
        $this->assertSame([1405, 6, 15], JalaliHelper::toJalali(2026, 9, 6));
        $this->assertSame([1357, 11, 22], JalaliHelper::toJalali(1979, 2, 11)); // ۲۲ بهمن ۱۳۵۷
    }

    public function testJalaliToGregorianKnownDates(): void
    {
        $this->assertSame([2026, 3, 21], JalaliHelper::toGregorian(1405, 1, 1));
        $this->assertSame([2026, 9, 6], JalaliHelper::toGregorian(1405, 6, 15));
    }

    public function testRoundTripIsStable(): void
    {
        $ts = mktime(12, 0, 0, 1, 1, 2020);
        for ($i = 0; $i < 366; $i++) {
            $g = [(int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts)];
            $this->assertSame($g, JalaliHelper::toGregorian(...JalaliHelper::toJalali(...$g)));
            $ts += 86400;
        }
    }

    public function testMonthLength(): void
    {
        $this->assertSame(31, JalaliHelper::monthLength(1405, 1));
        $this->assertSame(31, JalaliHelper::monthLength(1405, 6));
        $this->assertSame(30, JalaliHelper::monthLength(1405, 7));
        $this->assertSame(30, JalaliHelper::monthLength(1405, 11));
        $this->assertSame(30, JalaliHelper::monthLength(1403, 12)); // کبیشه
        $this->assertSame(29, JalaliHelper::monthLength(1404, 12)); // عادی
        $this->assertSame(0, JalaliHelper::monthLength(1405, 13)); // نامعتبر
    }

    public function testLeapYears(): void
    {
        $this->assertTrue(JalaliHelper::isLeap(1403));
        $this->assertFalse(JalaliHelper::isLeap(1404));
    }

    public function testFormatAndPersianDigits(): void
    {
        $ts = mktime(18, 30, 0, 9, 6, 2026); // یکشنبه ۱۵ شهریور ۱۴۰۵
        $this->assertSame('1405/06/15', JalaliHelper::format('Y/m/d', $ts));
        $this->assertSame('یکشنبه 15', JalaliHelper::format('l j', $ts));
        $this->assertSame('۱۵ شهریور ۱۴۰۵', JalaliHelper::formatLong($ts));
        $this->assertStringContainsString('شنبه ۱۵ شهریور ۱۴۰۵، ساعت ۱۸:۳۰', JalaliHelper::formatDateTimeLong($ts));
    }
}
