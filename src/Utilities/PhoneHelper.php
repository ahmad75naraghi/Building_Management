<?php

declare(strict_types=1);

namespace App\Utilities;

/**
 * نرمال‌سازی و اعتبارسنجی شماره موبایل ایرانی.
 * شماره موبایل، نام‌کاربری (username) هر کاربر است.
 */
final class PhoneHelper
{
    /**
     * تبدیل ارقام فارسی/عربی به انگلیسی، حذف فاصله و خط‌تیره،
     * و یکدست‌سازی پیش‌شماره کشور به فرمت 09xxxxxxxxx.
     */
    public static function normalize(?string $phone): string
    {
        $phone = (string) ($phone ?? '');
        // ارقام فارسی و عربی به انگلیسی
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $en = ['0','1','2','3','4','5','6','7','8','9'];
        $phone = str_replace($fa, $en, $phone);
        $phone = str_replace($ar, $en, $phone);
        // حذف همه‌چیز به‌جز رقم و +
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        // حذف + ابتدایی
        $phone = ltrim($phone, '+');
        // 0098... -> 98...
        if (str_starts_with($phone, '0098')) {
            $phone = substr($phone, 2);
        }
        // 98... (۱۲ رقم) -> 09...
        if (str_starts_with($phone, '98') && strlen($phone) === 12) {
            $phone = '0' . substr($phone, 2);
        }
        // 9... (۱۰ رقم، بدون صفر) -> 09...
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }
        return $phone;
    }

    /**
     * آیا شماره یک موبایل ایرانی معتبر است؟ (09xxxxxxxxx)
     */
    public static function isValid(?string $phone): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalize($phone));
    }
}
