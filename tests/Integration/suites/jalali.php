<?php
/** تست توابع تقویم جلالی (تبدیل میلادی ↔ شمسی، طول ماه، قالب‌بندی) */
declare(strict_types=1);

use App\Utilities\JalaliHelper;

TestLog::suite('JalaliHelper — تقویم شمسی');

// ---------- تبدیل میلادی به جلالی (بردارهای معلوم) ----------
$toJalali = [
    'امروز (۲۰۲۶-۰۹-۰۶)' => [[2026, 9, 6], [1405, 6, 15]],
    'نوروز ۱۴۰۵'          => [[2026, 3, 21], [1405, 1, 1]],
    'نوروز ۱۴۰۰'          => [[2021, 3, 21], [1400, 1, 1]],
    '۲۲ بهمن ۱۳۵۷'        => [[1979, 2, 11], [1357, 11, 22]],
    'آخر اسفند کبیشه ۱۴۰۳' => [[2025, 3, 20], [1403, 12, 30]],
];
foreach ($toJalali as $label => [$g, $j]) {
    TestLog::assertSame("toJalali: {$label}", $j, JalaliHelper::toJalali(...$g));
}

// ---------- تبدیل جلالی به میلادی ----------
$toGregorian = [
    '۱۵ شهریور ۱۴۰۵' => [[1405, 6, 15], [2026, 9, 6]],
    'نوروز ۱۴۰۵'     => [[1405, 1, 1], [2026, 3, 21]],
    '۲۲ بهمن ۱۳۵۷'   => [[1357, 11, 22], [1979, 2, 11]],
];
foreach ($toGregorian as $label => [$j, $g]) {
    TestLog::assertSame("toGregorian: {$label}", $g, JalaliHelper::toGregorian(...$j));
}

// ---------- رفت و برگشت: تبدیل دوطرفه باید پایدار باشد ----------
TestLog::run('رفت‌وبرگشت ۱۰۰۰ روز متوالی', function () {
    $ts = mktime(12, 0, 0, 1, 1, 2020);
    for ($i = 0; $i < 1000; $i++) {
        $gy = (int) date('Y', $ts);
        $gm = (int) date('m', $ts);
        $gd = (int) date('d', $ts);
        [$jy, $jm, $jd] = JalaliHelper::toJalali($gy, $gm, $gd);
        [$gy2, $gm2, $gd2] = JalaliHelper::toGregorian($jy, $jm, $jd);
        if ($gy2 !== $gy || $gm2 !== $gm || $gd2 !== $gd) {
            TestLog::fail('رفت‌وبرگشت', "تاریخ {$gy}-{$gm}-{$gd} درست برنگشت: {$gy2}-{$gm2}-{$gd2}");
            return;
        }
        $ts += 86400;
    }
    TestLog::ok('رفت‌وبرگشت ۱۰۰۰ روز متوالی');
});

// ---------- طول ماه و کبیشه ----------
TestLog::assertSame('طول ماه: فروردین ۳۱ روز', 31, JalaliHelper::monthLength(1405, 1));
TestLog::assertSame('طول ماه: مهر ۳۰ روز', 30, JalaliHelper::monthLength(1405, 7));
TestLog::assertSame('طول ماه: اسفند ۱۴۰۳ (کبیشه) ۳۰ روز', 30, JalaliHelper::monthLength(1403, 12));
TestLog::assertSame('طول ماه: اسفند ۱۴۰۴ (عادی) ۲۹ روز', 29, JalaliHelper::monthLength(1404, 12));
TestLog::assertSame('isLeap: ۱۴۰۳ کبیشه است', true, JalaliHelper::isLeap(1403));
TestLog::assertSame('isLeap: ۱۴۰۴ کبیشه نیست', false, JalaliHelper::isLeap(1404));

// ---------- قالب‌بندی و روز هفته ----------
$ts = mktime(12, 0, 0, 9, 6, 2026); // یکشنبه ۱۵ شهریور ۱۴۰۵
TestLog::assertSame('format: Y/m/d', '1405/06/15', JalaliHelper::format('Y/m/d', $ts));
TestLog::assertSame('format: نام روز و روز ماه', 'یکشنبه 15', JalaliHelper::format('l j', $ts));
TestLog::assertSame('formatLong با ارقام فارسی', '۱۵ شهریور ۱۴۰۵', JalaliHelper::formatLong($ts));
TestLog::assertSame('faDigits', '۰۱۲۳۴۵۶۷۸۹', JalaliHelper::faDigits('0123456789'));
TestLog::assertSame('weekDayIndex: یکشنبه = ۱', 1, JalaliHelper::weekDayIndex($ts));
