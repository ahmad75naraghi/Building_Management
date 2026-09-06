<?php
/** تست لاگ‌گیر مرکزی */
declare(strict_types=1);

use App\Core\Logger;

TestLog::suite('Logger — لاگ‌گیر مرکزی');

TestLog::run('سطوح', function () {
    Logger::reset();
    Logger::info('t', 'یک پیام');
    Logger::error('t', 'یک خطا');
    TestLog::assertSame('دو رکورد ثبت شد', 2, count(Logger::records()));
    TestLog::assertSame('فیلتر بر اساس سطح', 1, count(Logger::records(Logger::ERROR)));
});

TestLog::run('پنهان‌سازی اطلاعات حساس', function () {
    Logger::reset();
    Logger::warning('t', 'ورود', ['phone' => '09120000000', 'password' => 'hunter2', 'token' => 'abc', 'code' => '123456']);
    $c = Logger::records()[0]['context'];
    TestLog::assertSame('رمز پنهان شد', '[redacted]', $c['password']);
    TestLog::assertSame('توکن پنهان شد', '[redacted]', $c['token']);
    TestLog::assertSame('کد OTP پنهان شد', '[redacted]', $c['code']);
    TestLog::assertSame('شماره باقی ماند', '09120000000', $c['phone']);
});

TestLog::run('پنهان‌سازی تودرتو', function () {
    Logger::reset();
    Logger::info('t', 'x', ['payload' => ['user' => ['name' => 'علی', 'password_hash' => 'zzz']]]);
    $c = Logger::records()[0]['context'];
    TestLog::assertSame('کلید حساس تودرتو', '[redacted]', $c['payload']['user']['password_hash']);
});

TestLog::run('ثبت استثنا با ردپا', function () {
    Logger::reset();
    try {
        throw new RuntimeException('خرابی آزمایشی');
    } catch (Throwable $e) {
        Logger::exception('t', $e, ['building_id' => 3]);
    }
    $r = Logger::records()[0];
    TestLog::assertSame('سطح error', Logger::ERROR, $r['level']);
    TestLog::assertSame('کلاس استثنا', 'RuntimeException', $r['context']['exception']['class']);
    TestLog::assertTrue('ردپا ثبت شد', !empty($r['context']['exception']['trace']));
    TestLog::assertSame('زمینه حفظ شد', 3, $r['context']['building_id']);
});

TestLog::run('guard خطا را می‌بلعد', function () {
    Logger::reset();
    $v = Logger::guard('t', 'کار جانبی', fn() => throw new LogicException('نه'), 'fallback');
    TestLog::assertSame('مقدار جایگزین برگشت', 'fallback', $v);
    TestLog::assertSame('خطا لاگ شد', 1, count(Logger::records(Logger::ERROR)));

    $ok = Logger::guard('t', 'کار سالم', fn() => 42, 0);
    TestLog::assertSame('مقدار موفق برگشت', 42, $ok);
});

TestLog::run('خروجی قابل سریال‌سازی JSON است', function () {
    Logger::reset();
    Logger::info('t', 'شیء', ['obj' => new stdClass(), 'res' => STDERR, 'deep' => 1]);
    $json = json_encode(Logger::records()[0], JSON_UNESCAPED_UNICODE);
    TestLog::assertTrue('JSON ساخته شد', is_string($json) && $json !== '');
});

TestLog::run('پیام‌های خیلی بلند بریده می‌شوند', function () {
    Logger::reset();
    Logger::info('t', str_repeat('ا', 5000));
    TestLog::assertTrue('طول محدود شد', mb_strlen(Logger::records()[0]['message']) <= 2001);
});
