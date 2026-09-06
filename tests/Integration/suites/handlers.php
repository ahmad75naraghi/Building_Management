<?php
/** تست گیرنده‌های سراسری خطا و نوشتن روی فایل */
declare(strict_types=1);

use App\Core\Logger;

TestLog::suite('Logger — گیرنده‌های سراسری و فایل');

TestLog::run('هشدارهای PHP خودکار لاگ می‌شوند', function () {
    Logger::install();
    Logger::reset();
    @file_get_contents('/definitely/not/a/real/path/xyz');
    $php = array_filter(Logger::records(), fn($r) => $r['channel'] === 'php');
    TestLog::assertTrue('خطای سرکوب‌شده با @ لاگ نمی‌شود', count($php) === 0,
        'رکوردها: ' . count($php));

    Logger::reset();
    $arr = [];
    $x = @$arr['missing']; // سرکوب‌شده
    TestLog::assertSame('دسترسی به کلید ناموجود سرکوب شد', 0, count(Logger::records()));
});

TestLog::run('هشدار بدون سرکوب ثبت می‌شود', function () {
    Logger::install();
    Logger::reset();
    $arr = [];
    $x = $arr['missing'] ?? null; // بدون هشدار
    trigger_error('هشدار آزمایشی', E_USER_WARNING);
    $recs = array_values(array_filter(Logger::records(), fn($r) => $r['channel'] === 'php'));
    TestLog::assertTrue('هشدار ثبت شد', count($recs) >= 1);
    if ($recs) {
        TestLog::assertSame('سطح warning', Logger::WARNING, $recs[0]['level']);
        TestLog::assertTrue('محل خطا ثبت شد', !empty($recs[0]['context']['at']));
    }
});

TestLog::run('نوشتن روی فایل و خواندن دوباره', function () {
    $dir = sys_get_temp_dir() . '/bm-log-test-' . random_int(1000, 9999);
    putenv('APP_LOG_DIR=' . $dir);
    Logger::useMemory(false);
    Logger::reset();

    Logger::error('FileTest', 'خطای نمونه برای فایل', ['building_id' => 5, 'password' => 'x']);

    $file = $dir . '/app-' . date('Y-m-d') . '.log';
    TestLog::assertTrue('فایل لاگ ساخته شد', is_file($file), $file);

    $lines = array_filter(explode("\n", (string) file_get_contents($file)));
    $last = json_decode((string) end($lines), true);
    TestLog::assertTrue('هر خط JSON معتبر است', is_array($last));
    TestLog::assertSame('کانال', 'FileTest', $last['channel'] ?? null);
    TestLog::assertSame('سطح', 'error', $last['level'] ?? null);
    TestLog::assertSame('زمینه حفظ شد', 5, $last['context']['building_id'] ?? null);
    TestLog::assertSame('رمز در فایل هم پنهان است', '[redacted]', $last['context']['password'] ?? null);
    TestLog::assertTrue('متن فارسی خوانا ذخیره شد',
        str_contains((string) file_get_contents($file), 'خطای نمونه'));

    @unlink($file);
    @rmdir($dir);
    putenv('APP_LOG_DIR');
    Logger::useMemory(true);
});

TestLog::run('مسیر غیرقابل‌نوشتن باعث خطا نمی‌شود', function () {
    putenv('APP_LOG_DIR=/proc/definitely-not-writable');
    Logger::useMemory(false);
    Logger::reset();
    Logger::error('FileTest', 'باید بی‌سروصدا به error_log برگردد');
    TestLog::ok('بدون پرتاب استثنا اجرا شد');
    putenv('APP_LOG_DIR');
    Logger::useMemory(true);
});
