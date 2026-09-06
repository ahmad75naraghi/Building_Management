<?php
/** تست نرمال‌سازی شماره موبایل */
declare(strict_types=1);

use App\Utilities\PhoneHelper;

TestLog::suite('PhoneHelper — شماره موبایل');

$normalize = [
    'شماره استاندارد'        => ['09123456789', '09123456789'],
    'ارقام فارسی'            => ['۰۹۱۲۳۴۵۶۷۸۹', '09123456789'],
    'ارقام عربی'             => ['٠٩١٢٣٤٥٦٧٨٩', '09123456789'],
    'با +98'                 => ['+989123456789', '09123456789'],
    'با 0098'                => ['00989123456789', '09123456789'],
    'با 98 بدون علامت'       => ['989123456789', '09123456789'],
    'بدون صفر ابتدایی'       => ['9123456789', '09123456789'],
    'با فاصله و خط تیره'     => ['0912 345-6789', '09123456789'],
    'مقدار تهی'              => [null, ''],
];
foreach ($normalize as $label => [$in, $out]) {
    TestLog::assertSame("normalize: {$label}", $out, PhoneHelper::normalize($in));
}

$valid = ['09123456789', '۰۹۱۲۳۴۵۶۷۸۹', '+989123456789', '9123456789'];
foreach ($valid as $v) {
    TestLog::assertTrue("isValid معتبر: {$v}", PhoneHelper::isValid($v));
}

$invalid = ['', null, '0912345678', '091234567890', '08123456789', 'abcdefghijk', '12345'];
foreach ($invalid as $v) {
    TestLog::assertTrue('isValid نامعتبر: ' . var_export($v, true), !PhoneHelper::isValid($v));
}

TestLog::assertSame('toEnglishDigits کد فارسی', '123456', PhoneHelper::toEnglishDigits('۱۲۳۴۵۶'));
TestLog::assertSame('toEnglishDigits حروف را نگه می‌دارد', 'کد 12', PhoneHelper::toEnglishDigits('کد ۱۲'));
