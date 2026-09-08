<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\CacheHelper;

/**
 * محدودسازی نرخ درخواست‌ها (بر اساس IP و مسیر).
 *
 * سه لایه:
 *  ۱. سقف سراسری هر IP — محافظ در برابر اسکن/حملهٔ عمومی
 *  ۲. سقف هر «الگوی مسیر» (شناسه‌های عددی نرمال می‌شوند تا با تغییر آیدی
 *     نتوان سطل‌ها را دور زد)
 *  ۳. نقاط حساس با سقف سخت‌گیرانه‌تر و پنجرهٔ بلندتر:
 *     - ارسال کد پیامک (ضد بمباران پیامکی و هزینهٔ بی‌رویه)
 *     - تأیید کد یک‌بارمصرف (ضد حدس رمز)
 *     - ورود/ثبت‌نام و تغییر رمز
 *     - ثبت/تأیید پرداخت
 *     برای ارسال پیامک، علاوه بر IP بر اساس شمارهٔ مقصد هم محدود می‌شود.
 *
 * اگر Redis در دسترس نباشد، محدودسازی بی‌صدا غیرفعال می‌شود (fail-open)
 * تا دسترس‌پذیری سرویس حفظ شود.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /** سقف سراسری هر IP: [تعداد، پنجره به ثانیه] */
    private const GLOBAL_LIMIT = [240, 60];

    /** سقف پیش‌فرض هر الگوی مسیر: [تعداد، پنجره به ثانیه] */
    private const ROUTE_LIMIT = [60, 60];

    /** نقاط حساس — کلید = «متد مسیرِ دقیق»: [تعداد، پنجره به ثانیه] */
    private const SENSITIVE = [
        'POST /api/auth/send-otp' => [5, 900],       // ۵ ارسال کد در ۱۵ دقیقه
        'POST /api/auth/verify-otp' => [10, 900],    // ۱۰ تلاش تأیید در ۱۵ دقیقه
        'POST /api/auth/check-phone' => [20, 900],
        'POST /api/auth/login' => [10, 900],
        'POST /api/auth/register' => [10, 900],
        'POST /api/auth/set-password' => [10, 900],
        'PUT /api/auth/password' => [10, 900],
    ];

    /** سقف ثبت/تأیید/رد پرداخت برای هر IP: [تعداد، پنجره به ثانیه] */
    private const PAYMENT_LIMIT = [30, 60];

    public function handle(Request $request, callable $next): mixed
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $method = strtoupper($request->getMethod());
        $path = $this->normalizePath($request->getPathInfo());

        // ۱) سقف سراسری هر IP
        $denied = $this->check("rl:global:{$ip}", self::GLOBAL_LIMIT[0], self::GLOBAL_LIMIT[1]);
        if ($denied) {
            return $denied;
        }

        // ۲) نقاط حساس (مسیر دقیق، بدون نرمال‌سازی شناسه‌ها)
        $exact = "{$method} {$path}";
        if (isset(self::SENSITIVE[$exact])) {
            [$limit, $window] = self::SENSITIVE[$exact];
            $denied = $this->check("rl:sensitive:{$ip}:" . md5($exact), $limit, $window);
            if ($denied) {
                return $denied;
            }
            // برای ارسال پیامک، بر اساس شمارهٔ مقصد هم محدود کن (ضد بمباران یک شماره)
            if ($exact === 'POST /api/auth/send-otp') {
                $phone = $this->extractPhone($request);
                if ($phone !== null) {
                    $denied = $this->check('rl:sensitive:phone:' . md5($phone), $limit, $window);
                    if ($denied) {
                        return $denied;
                    }
                }
            }
        } elseif (str_starts_with($path, '/api/payments') && $method === 'POST') {
            // ۳) ثبت/تأیید/رد پرداخت
            $denied = $this->check("rl:payment:{$ip}", self::PAYMENT_LIMIT[0], self::PAYMENT_LIMIT[1]);
            if ($denied) {
                return $denied;
            }
        }

        // ۴) سقف هر الگوی مسیر
        $denied = $this->check("rl:route:{$ip}:" . md5("{$method} {$path}"), self::ROUTE_LIMIT[0], self::ROUTE_LIMIT[1]);
        if ($denied) {
            return $denied;
        }

        return $next($request);
    }

    /** نرمال‌سازی مسیر: حذف پیشوند زیرپوشه و یکسان‌سازی شناسه‌های عددی */
    private function normalizePath(string $path): string
    {
        $path = explode('?', $path)[0];
        if (str_starts_with($path, '/b/')) {
            $path = substr($path, 2);
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        // شناسه‌های عددی و نام فایل‌ها در مسیر دانلود → الگوی ثابت
        $path = (string) preg_replace('#/\d+#', '/{id}', $path);
        return rtrim($path, '/') ?: '/';
    }

    /** بررسی یک سطل؛ در صورت عبور از سقف، پاسخ 429 برمی‌گرداند */
    private function check(string $key, int $limit, int $window): ?Response
    {
        $current = CacheHelper::get($key, 0);
        if (!is_numeric($current)) {
            $current = 0;
        }
        if ($current >= $limit) {
            return (new Response())
                ->setStatusCode(429)
                ->setJson([
                    'success' => false,
                    'message' => 'درخواست‌های شما بیش از حد مجاز است؛ چند لحظه دیگر دوباره تلاش کنید.',
                    'retry_after' => $window,
                ]);
        }
        CacheHelper::set($key, (int) $current + 1, $window);
        return null;
    }

    /** شمارهٔ موبایل داخل بدنهٔ درخواست (برای محدودسازی پیامک بر اساس مقصد) */
    private function extractPhone(Request $request): ?string
    {
        $body = $request->getJsonBody();
        $phone = $body['phone'] ?? $body['mobile'] ?? null;
        if (!is_string($phone)) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone);
        return $digits !== '' ? $digits : null;
    }
}
