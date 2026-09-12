<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * توابع تقویم جلالی (شمسی) — تبدیل میلادی ↔ جلالی و قالب‌بندی تاریخ.
 *
 * الگوریتم استاندارد ۳۳-ساله (همان الگوریتم معروف jdf) بدون هیچ وابستگی خارجی.
 * منبع حقیقت برای همه بخش‌ها: اسکریپت‌های سمت سرور (مثل یادآوری‌ها) و
 * فرانت‌اند (تقویم) هر دو از همین کلاس استفاده می‌کنند.
 */
final class JalaliHelper
{
    /** نام ماه‌های جلالی (ایندکس ۱ تا ۱۲) */
    public const MONTH_NAMES = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد',
        4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر',
        10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    /** نام روزهای هفته از شنبه (ایندکس ۰ تا ۶) */
    public const WEEKDAY_NAMES = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];

    /**
     * تبدیل میلادی به جلالی.
     *
     * @return array{0:int,1:int,2:int} [سال، ماه، روز] جلالی
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    /**
     * تبدیل جلالی به میلادی.
     *
     * @return array{0:int,1:int,2:int} [سال، ماه، روز] میلادی
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4)
            + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $salA = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28,
            31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        for (; $gm < 13 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }
        return [$gy, $gm, $gd];
    }

    /** تعداد روزهای ماه جلالی (اسفند در سال کبیشه ۳۰ روز است) */
    public static function monthLength(int $jy, int $jm): int
    {
        if ($jm < 1 || $jm > 12) {
            return 0;
        }
        if ($jm <= 6) {
            return 31;
        }
        if ($jm <= 11) {
            return 30;
        }
        // اسفند: روز بعد از ۲۹ اسفند را بررسی می‌کنیم؛ اگر هنوز اسفند بود، سال کبیشه است
        [$gy, $gm, $gd] = self::toGregorian($jy, 12, 29);
        $ts = mktime(12, 0, 0, $gm, $gd, $gy) + 86400;
        [$cjy, $cjm] = self::toJalali((int) date('Y', $ts), (int) date('m', $ts), (int) date('d', $ts));
        return ($cjy === $jy && $cjm === 12) ? 30 : 29;
    }

    /** آیا سال جلالی کبیشه است؟ */
    public static function isLeap(int $jy): bool
    {
        return self::monthLength($jy, 12) === 30;
    }

    /** تبدیل timestamp به [سال، ماه، روز] جلالی */
    public static function fromTimestamp(int $timestamp): array
    {
        return self::toJalali((int) date('Y', $timestamp), (int) date('m', $timestamp), (int) date('d', $timestamp));
    }

    /**
     * شاخص روز هفته شنبه‌محور: شنبه=۰ ... جمعه=۶
     */
    public static function weekDayIndex(int $timestamp): int
    {
        return ((int) date('w', $timestamp) + 1) % 7;
    }

    /** نام روز هفته برای یک timestamp */
    public static function weekDayName(int $timestamp): string
    {
        return self::WEEKDAY_NAMES[self::weekDayIndex($timestamp)];
    }

    /**
     * قالب‌بندی تاریخ جلالی برای یک timestamp.
     *
     * توکن‌ها: Y (سال ۴ رقمی)، m (ماه با صفر)، d (روز با صفر)،
     * j (روز بدون صفر)، F (نام ماه)، l (نام روز هفته)،
     * سایر کاراکترها همان‌طور باقی می‌مانند.
     */
    public static function format(string $format, int $timestamp): string
    {
        [$jy, $jm, $jd] = self::fromTimestamp($timestamp);
        $out = '';
        $len = strlen($format);
        for ($i = 0; $i < $len; $i++) {
            $out .= match ($format[$i]) {
                'Y' => (string) $jy,
                'm' => sprintf('%02d', $jm),
                'd' => sprintf('%02d', $jd),
                'j' => (string) $jd,
                'F' => self::MONTH_NAMES[$jm],
                'l' => self::weekDayName($timestamp),
                default => $format[$i],
            };
        }
        return $out;
    }

    /** تبدیل ارقام لاتین یک رشته به فارسی (برای متن‌های کاربرپسند مثل پیامک و اعلان) */
    public static function faDigits(string $value): string
    {
        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    /** «۱۵ شهریور ۱۴۰۵» (با ارقام فارسی) */
    public static function formatLong(int $timestamp): string
    {
        [$jy, $jm, $jd] = self::fromTimestamp($timestamp);
        return self::faDigits(sprintf('%d %s %d', $jd, self::MONTH_NAMES[$jm], $jy));
    }

    /** «شنبه ۱۵ شهریور ۱۴۰۵، ساعت ۱۸:۰۰» (با ارقام فارسی) */
    public static function formatDateTimeLong(int $timestamp, bool $withTime = true): string
    {
        [$jy, $jm, $jd] = self::fromTimestamp($timestamp);
        $date = sprintf('%s %d %s %d', self::weekDayName($timestamp), $jd, self::MONTH_NAMES[$jm], $jy);
        $text = $withTime ? $date . '، ساعت ' . date('H:i', $timestamp) : $date;
        return self::faDigits($text);
    }
}
