<?php
/** تست تنظیمات امن، محدودیت نرخ و محافظت CSRF */
declare(strict_types=1);

use App\Config\AppConfig;
use App\Services\RateLimiter;

TestLog::suite('امنیت — تنظیمات، محدودیت نرخ، CSRF');

// ------------------------------------------------------------ تنظیمات و اسرار

TestLog::run('هیچ سرّی در کد باقی نمانده', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 3) . '/config/app.php');
    TestLog::assertTrue('رمز قدیمی پایگاه‌داده حذف شد', !str_contains($src, 'GrKbG-nOwEL5'));
    TestLog::assertTrue('کلید JWT قدیمی حذف شد', !str_contains($src, 'km1DP3O0u83MDK69H84'));
    TestLog::assertTrue('ثابت JWT_SECRET دیگر وجود ندارد', !preg_match('/const\s+JWT_SECRET/', $src));
    TestLog::assertTrue('ثابت APP_ENV دیگر وجود ندارد', !preg_match('/const\s+APP_ENV/', $src));
});

TestLog::run('محیط پیش‌فرض امن است', function () {
    putenv('APP_ENV');
    TestLog::assertSame('بدون تنظیم، production فرض می‌شود', 'production', AppConfig::environment());
    TestLog::assertSame('isProduction برقرار است', true, AppConfig::isProduction());

    putenv('APP_ENV=development');
    TestLog::assertSame('مقدار محیطی خوانده می‌شود', 'development', AppConfig::environment());

    putenv('APP_ENV=چرند');
    TestLog::assertSame('مقدار نامعتبر به production برمی‌گردد', 'production', AppConfig::environment());
    putenv('APP_ENV=development');
});

TestLog::run('کلید JWT در تولید اجباری است', function () {
    putenv('APP_ENV=production');
    putenv('JWT_SECRET');
    TestLog::assertThrows('نبود JWT_SECRET خطا می‌دهد', fn() => AppConfig::jwtSecret(), 'JWT_SECRET');

    putenv('JWT_SECRET=کوتاه');
    TestLog::assertThrows('کلید کوتاه رد می‌شود', fn() => AppConfig::jwtSecret(), 'کوتاه');

    $good = str_repeat('a', 48);
    putenv('JWT_SECRET=' . $good);
    TestLog::assertSame('کلید معتبر پذیرفته می‌شود', $good, AppConfig::jwtSecret());
    putenv('APP_ENV=development');
});

TestLog::run('در توسعه کلید موقت ساخته می‌شود', function () {
    putenv('APP_ENV=development');
    putenv('JWT_SECRET');
    $s1 = AppConfig::jwtSecret();
    TestLog::assertTrue('کلید ساخته شد', strlen($s1) >= 32);
    TestLog::assertSame('بین دو فراخوانی ثابت می‌ماند', $s1, AppConfig::jwtSecret());
});

TestLog::run('رمز پایگاه‌داده در تولید اجباری است', function () {
    putenv('APP_ENV=production');
    putenv('JWT_SECRET=' . str_repeat('a', 48));
    putenv('DB_PASSWORD');
    TestLog::assertThrows('نبود DB_PASSWORD خطا می‌دهد',
        fn() => AppConfig::getDatabaseConfig(), 'DB_PASSWORD');
    putenv('APP_ENV=development');
});

TestLog::run('بازرسی آمادگی تولید مشکلات را گزارش می‌کند', function () {
    putenv('JWT_SECRET'); putenv('DB_PASSWORD'); putenv('APP_URL'); putenv('OTP_DEBUG=1');
    $p = AppConfig::validateForProduction();
    $joined = implode(' | ', $p);
    TestLog::assertTrue('نبود JWT_SECRET گزارش شد', str_contains($joined, 'JWT_SECRET'));
    TestLog::assertTrue('نبود DB_PASSWORD گزارش شد', str_contains($joined, 'DB_PASSWORD'));
    TestLog::assertTrue('نبود APP_URL گزارش شد', str_contains($joined, 'APP_URL'));
    TestLog::assertTrue('فعال‌بودن OTP_DEBUG گزارش شد', str_contains($joined, 'OTP_DEBUG'));

    putenv('JWT_SECRET=' . str_repeat('a', 48));
    putenv('DB_PASSWORD=secret');
    putenv('APP_URL=https://example.com');
    putenv('OTP_DEBUG');
    TestLog::assertSame('با تنظیمات کامل مشکلی نیست', [], AppConfig::validateForProduction());
});

TestLog::run('فایل .env.example موجود و کامل است', function () {
    $p = dirname(__DIR__, 3) . '/.env.example';
    TestLog::assertTrue('فایل وجود دارد', is_file($p));
    $src = (string) file_get_contents($p);
    foreach (['APP_ENV', 'JWT_SECRET', 'DB_PASSWORD', 'DB_DSN', 'APP_URL'] as $k) {
        TestLog::assertTrue("کلید {$k} مستند شده", str_contains($src, $k));
    }
    TestLog::assertTrue('هیچ رمز واقعی در نمونه نیست', !str_contains($src, 'GrKbG-nOwEL5'));
});

TestLog::run('.env در گیت‌ایگنور هست', function () {
    $gi = (string) file_get_contents(dirname(__DIR__, 3) . '/.gitignore');
    TestLog::assertTrue('.env نادیده گرفته می‌شود', preg_match('/^\.env$/m', $gi) === 1);
    TestLog::assertTrue('.env.example استثنا شده', str_contains($gi, '!.env.example'));
});

// ------------------------------------------------------------ محدودیت نرخ

TestLog::run('شمارش و مسدودسازی تلاش‌ها', function () {
    RateLimiter::resetTableCache();
    $l = new RateLimiter();
    $key = 'test:' . uniqid('', true);

    TestLog::assertSame('در ابتدا صفر', 0, $l->attempts($key, 900));
    TestLog::assertSame('در ابتدا مسدود نیست', false, $l->tooManyAttempts($key, 5, 900));

    for ($i = 0; $i < 5; $i++) {
        $l->hit($key, 900);
    }
    TestLog::assertSame('پنج تلاش شمرده شد', 5, $l->attempts($key, 900));
    TestLog::assertSame('حالا مسدود است', true, $l->tooManyAttempts($key, 5, 900));
    TestLog::assertTrue('زمان انتظار مثبت است', $l->availableIn($key, 900) > 0);
});

TestLog::run('ورود موفق سابقه را پاک می‌کند', function () {
    $l = new RateLimiter();
    $key = 'test:' . uniqid('', true);
    $l->hit($key, 900);
    $l->hit($key, 900);
    TestLog::assertSame('دو تلاش', 2, $l->attempts($key, 900));
    $l->clear($key);
    TestLog::assertSame('پس از پاک‌سازی صفر', 0, $l->attempts($key, 900));
});

TestLog::run('تلاش‌های قدیمی‌تر از بازه شمرده نمی‌شوند', function () {
    $l = new RateLimiter();
    $key = 'test:' . uniqid('', true);
    $l->hit($key, 900);
    // بازه یک ثانیه‌ای: رکورد لحظه پیش خارج از پنجره قرار می‌گیرد
    TestLog::assertSame('خارج از بازه شمرده نشد', 0, $l->attempts($key, 0));
});

TestLog::run('کلیدهای مختلف روی هم اثر ندارند', function () {
    $l = new RateLimiter();
    $a = 'test:a' . uniqid('', true);
    $b = 'test:b' . uniqid('', true);
    for ($i = 0; $i < 5; $i++) { $l->hit($a, 900); }
    TestLog::assertSame('کلید الف مسدود', true, $l->tooManyAttempts($a, 5, 900));
    TestLog::assertSame('کلید ب آزاد', false, $l->tooManyAttempts($b, 5, 900));
});

TestLog::run('کلیدها به‌صورت هش ذخیره می‌شوند', function () {
    $l = new RateLimiter();
    $phone = '09121234567';
    $l->hit(RateLimiter::loginKey($phone, '1.2.3.4'), 900);

    $rows = test_db()->query("SELECT limit_key FROM rate_limits")->fetchAll(PDO::FETCH_COLUMN);
    $all = implode(',', $rows);
    TestLog::assertTrue('شماره خام ذخیره نشده', !str_contains($all, $phone));
    TestLog::assertTrue('IP خام ذخیره نشده', !str_contains($all, '1.2.3.4'));
    TestLog::assertTrue('طول هش SHA-256 است', strlen((string) ($rows[0] ?? '')) === 64);
});

TestLog::run('کلید ورود شامل شماره و IP است', function () {
    $k1 = RateLimiter::loginKey('09121234567', '1.1.1.1');
    $k2 = RateLimiter::loginKey('09121234567', '2.2.2.2');
    $k3 = RateLimiter::loginKey('09129999999', '1.1.1.1');
    TestLog::assertTrue('IP متفاوت کلید متفاوت', $k1 !== $k2);
    TestLog::assertTrue('شماره متفاوت کلید متفاوت', $k1 !== $k3);
});

TestLog::run('مقادیر پیش‌فرض منطقی‌اند', function () {
    TestLog::assertSame('حداکثر تلاش', 5, RateLimiter::LOGIN_MAX_ATTEMPTS);
    TestLog::assertSame('بازه ۱۵ دقیقه', 900, RateLimiter::LOGIN_DECAY_SECONDS);
});

// ------------------------------------------------------------ CSRF

TestLog::run('توابع CSRF تعریف شده‌اند', function () {
    require_once dirname(__DIR__, 3) . '/includes/api_helper.php';
    foreach (['csrf_token', 'csrf_field', 'csrf_verify'] as $fn) {
        TestLog::assertTrue("تابع {$fn} موجود است", function_exists($fn));
    }
});

TestLog::run('توکن ساخته و پایدار می‌ماند', function () {
    $_SESSION = [];
    $t1 = csrf_token();
    TestLog::assertTrue('توکن به‌قدر کافی بلند است', strlen($t1) >= 32);
    TestLog::assertSame('در همان جلسه ثابت می‌ماند', $t1, csrf_token());
});

TestLog::run('اعتبارسنجی توکن', function () {
    $_SESSION = [];
    $t = csrf_token();
    TestLog::assertSame('توکن درست پذیرفته می‌شود', true, csrf_verify($t));
    TestLog::assertSame('توکن غلط رد می‌شود', false, csrf_verify('اشتباه'));
    TestLog::assertSame('توکن خالی رد می‌شود', false, csrf_verify(''));
    TestLog::assertSame('مقدار غیررشته‌ای رد می‌شود', false, csrf_verify(null));

    $_SESSION = [];
    TestLog::assertSame('بدون جلسه هیچ توکنی معتبر نیست', false, csrf_verify($t));
});

TestLog::run('فیلد فرم درست تولید می‌شود', function () {
    $_SESSION = [];
    $html = csrf_field();
    TestLog::assertTrue('ورودی مخفی است', str_contains($html, 'type="hidden"'));
    TestLog::assertTrue('نام درست است', str_contains($html, 'name="_csrf"'));
    TestLog::assertTrue('مقدار توکن را دارد', str_contains($html, csrf_token()));
});

TestLog::run('همه فرم‌های POST توکن CSRF دارند', function () {
    $root = dirname(__DIR__, 3);
    $bad = [];
    foreach (glob($root . '/*.php') ?: [] as $f) {
        $src = (string) file_get_contents($f);
        $forms = preg_match_all('/<form[^>]*method=["\']post["\']/i', $src);
        if (!$forms) { continue; }
        $tokens = substr_count($src, 'csrf_field()');
        if ($tokens < $forms) {
            $bad[] = basename($f) . " ({$forms} فرم، {$tokens} توکن)";
        }
    }
    TestLog::assertSame('هیچ فرم بدون توکنی نیست', [], $bad);
});

TestLog::run('بررسی SSL دیگر به‌صورت ثابت خاموش نیست', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/api_helper.php');
    TestLog::assertTrue('VERIFYPEER صفرِ ثابت ندارد',
        !preg_match('/CURLOPT_SSL_VERIFYPEER\s*,\s*(0|false)\s*\)/i', $src));
    TestLog::assertTrue('تابع متمرکز تنظیم SSL وجود دارد',
        str_contains($src, 'function api_apply_ssl_options'));
});
