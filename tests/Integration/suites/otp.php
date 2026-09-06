<?php
/** تست سرویس کد یک‌بارمصرف */
declare(strict_types=1);

use App\Core\Logger;
use App\Exceptions\ValidationException;
use App\Services\OtpService;

TestLog::suite('OtpService — کد یک‌بارمصرف');

$db = test_db();
$otp = new OtpService();

/** خواندن آخرین کد از دیتابیس ممکن نیست (هش شده)؛ پس از حالت دیباگ استفاده می‌کنیم */
putenv('OTP_DEBUG=1');

/** عقب‌بردن زمان ساخت آخرین کد برای دور زدن بازه انتظار */
$agePhone = function (string $phone, int $seconds) use ($db) {
    $db->prepare("UPDATE otp_codes SET created_at = ? WHERE phone = ?")
       ->execute([date('Y-m-d H:i:s', time() - $seconds), $phone]);
};

TestLog::run('ارسال کد به شماره معتبر', function () use ($otp) {
    $r = $otp->sendCode('09121111111');
    TestLog::assertTrue('ارسال شد', $r['sent'] === true);
    TestLog::assertSame('کد ۶ رقمی', 6, strlen((string) $r['debug_code']));
    TestLog::assertTrue('فقط رقم', ctype_digit((string) $r['debug_code']));
    TestLog::assertSame('retry_after برابر cooldown', OtpService::RESEND_COOLDOWN, $r['retry_after']);
});

TestLog::run('کد به صورت هش ذخیره می‌شود', function () use ($db) {
    $hash = $db->query("SELECT code_hash FROM otp_codes ORDER BY id DESC LIMIT 1")->fetchColumn();
    TestLog::assertTrue('هش bcrypt است', is_string($hash) && str_starts_with($hash, '$2y$'));
    TestLog::assertTrue('کد خام ذخیره نشده', !ctype_digit((string) $hash));
});

TestLog::run('شماره نامعتبر رد می‌شود', function () use ($otp) {
    TestLog::assertThrows('sendCode با شماره غلط', fn() => $otp->sendCode('12345'), 'معتبر نیست');
});

TestLog::run('شماره با ارقام فارسی پذیرفته می‌شود', function () use ($otp, $db) {
    $r = $otp->sendCode('۰۹۱۲۲۲۲۲۲۲۲');
    TestLog::assertTrue('ارسال شد', $r['sent']);
    $phone = $db->query("SELECT phone FROM otp_codes ORDER BY id DESC LIMIT 1")->fetchColumn();
    TestLog::assertSame('نرمال‌سازی شده ذخیره شد', '09122222222', $phone);
});

TestLog::run('بازه انتظار ارسال مجدد', function () use ($otp) {
    $r = $otp->sendCode('09121111111');
    TestLog::assertSame('ارسال دوم رد شد', false, $r['sent']);
    TestLog::assertTrue('retry_after مثبت و ≤ cooldown',
        $r['retry_after'] > 0 && $r['retry_after'] <= OtpService::RESEND_COOLDOWN, "retry={$r['retry_after']}");
    TestLog::assertSame('کد دیباگ فاش نمی‌شود', null, $r['debug_code']);
});

TestLog::run('پس از پایان بازه انتظار دوباره ارسال می‌شود', function () use ($otp, $agePhone) {
    $agePhone('09121111111', OtpService::RESEND_COOLDOWN + 5);
    $r = $otp->sendCode('09121111111');
    TestLog::assertTrue('ارسال مجدد موفق', $r['sent']);
});

TestLog::run('تأیید کد درست', function () use ($otp, $agePhone) {
    $agePhone('09123333333', 999);
    $r = $otp->sendCode('09123333333');
    TestLog::assertTrue('کد تأیید شد', $otp->verifyCode('09123333333', (string) $r['debug_code']));
});

TestLog::run('کد یک‌بارمصرف است', function () use ($otp, $agePhone) {
    $agePhone('09124444444', 999);
    $r = $otp->sendCode('09124444444');
    $otp->verifyCode('09124444444', (string) $r['debug_code']);
    TestLog::assertThrows('استفاده دوباره رد می‌شود',
        fn() => $otp->verifyCode('09124444444', (string) $r['debug_code']), 'منقضی');
});

TestLog::run('کد با ارقام فارسی هم تأیید می‌شود', function () use ($otp, $agePhone) {
    $agePhone('09125555555', 999);
    $r = $otp->sendCode('09125555555');
    $fa = strtr((string) $r['debug_code'], ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
    TestLog::assertTrue('تأیید با ارقام فارسی', $otp->verifyCode('09125555555', $fa));
});

TestLog::run('کد خالی رد می‌شود', function () use ($otp) {
    TestLog::assertThrows('verifyCode با کد خالی', fn() => $otp->verifyCode('09121111111', '  '), 'وارد کنید');
});

TestLog::run('کد منقضی‌شده رد می‌شود', function () use ($otp, $db, $agePhone) {
    $agePhone('09126666666', 999);
    $r = $otp->sendCode('09126666666');
    $db->prepare("UPDATE otp_codes SET expires_at = ? WHERE phone = ?")
       ->execute([date('Y-m-d H:i:s', time() - 10), '09126666666']);
    TestLog::assertThrows('کد منقضی', fn() => $otp->verifyCode('09126666666', (string) $r['debug_code']), 'منقضی');
});

TestLog::run('سقف تلاش ناموفق', function () use ($otp, $agePhone) {
    $phone = '09127777777';
    $agePhone($phone, 999);
    $otp->sendCode($phone);
    for ($i = 1; $i <= OtpService::MAX_ATTEMPTS; $i++) {
        try { $otp->verifyCode($phone, '000000'); } catch (ValidationException $e) { /* انتظار می‌رود */ }
    }
    TestLog::assertThrows('پس از سقف، کد باطل می‌شود',
        fn() => $otp->verifyCode($phone, '000000'), 'تلاش‌های ناموفق');
    TestLog::assertThrows('کد باطل‌شده دیگر در دسترس نیست',
        fn() => $otp->verifyCode($phone, '000000'), 'منقضی');
});

TestLog::run('ارسال کد جدید، کد قبلی را باطل می‌کند', function () use ($otp, $agePhone) {
    $phone = '09128888888';
    $agePhone($phone, 999);
    $first = $otp->sendCode($phone)['debug_code'];
    $agePhone($phone, OtpService::RESEND_COOLDOWN + 5);
    $second = $otp->sendCode($phone)['debug_code'];
    TestLog::assertThrows('کد قدیمی دیگر کار نمی‌کند',
        fn() => $otp->verifyCode($phone, (string) $first));
    TestLog::assertTrue('کد جدید کار می‌کند', $otp->verifyCode($phone, (string) $second));
});

TestLog::run('سقف ارسال در ساعت', function () use ($otp, $db) {
    $phone = '09129999999';
    for ($i = 0; $i < OtpService::MAX_PER_HOUR; $i++) {
        $otp->sendCode($phone);
        $db->prepare("UPDATE otp_codes SET created_at = ? WHERE phone = ? AND created_at > ?")
           ->execute([date('Y-m-d H:i:s', time() - 120), $phone, date('Y-m-d H:i:s', time() - 60)]);
    }
    TestLog::assertThrows('درخواست هفتم رد می‌شود', fn() => $otp->sendCode($phone), 'بیش از حد');
});

TestLog::run('کدهای قدیمی‌تر از یک ساعت در سقف حساب نمی‌شوند', function () use ($otp, $db) {
    $phone = '09121212121';
    for ($i = 0; $i < OtpService::MAX_PER_HOUR; $i++) {
        $otp->sendCode($phone);
        $db->prepare("UPDATE otp_codes SET created_at = ? WHERE phone = ?")
           ->execute([date('Y-m-d H:i:s', time() - 7200), $phone]);
    }
    $r = $otp->sendCode($phone);
    TestLog::assertTrue('هنوز می‌توان ارسال کرد', $r['sent']);
});

TestLog::run('بدون OTP_DEBUG کد فاش نمی‌شود', function () use ($otp, $agePhone) {
    putenv('OTP_DEBUG');
    $agePhone('09123131313', 999);
    $r = $otp->sendCode('09123131313');
    TestLog::assertSame('debug_code تهی است', null, $r['debug_code']);
    putenv('OTP_DEBUG=1');
});

TestLog::run('رویدادها لاگ می‌شوند', function () {
    TestLog::assertTrue('حداقل یک رکورد OtpService',
        count(array_filter(Logger::records(), fn($r) => $r['channel'] === 'OtpService')) > 0);
    foreach (Logger::records() as $r) {
        if ($r['channel'] !== 'OtpService') { continue; }
        if (isset($r['context']['code'])) {
            TestLog::fail('کد OTP نباید در لاگ باشد', json_encode($r['context'], JSON_UNESCAPED_UNICODE));
            return;
        }
    }
    TestLog::ok('هیچ کد خامی در لاگ نیست');
});

putenv('OTP_DEBUG');
