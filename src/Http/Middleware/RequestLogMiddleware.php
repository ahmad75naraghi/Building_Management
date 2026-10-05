<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Logger;
use App\Core\Request;

/**
 * لاگ ساختاریافتهٔ درخواست‌ها: روش، مسیر، وضعیت، زمان پردازش و کاربر.
 * برای مشاهده/دیباگ و داشبوردهای عملیاتی — با ذخیره‌سازی سبک و بدون
 * وابستگی به زیرساخت اضافه (از همان لاگ‌گیر مرکزی استفاده می‌کند).
 */
final class RequestLogMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $start = microtime(true);
        $response = $next($request);

        try {
            $path = $request->getPathInfo();
            // مسیرهای پرتردد و کم‌ارزش برای لاگ عملیاتی حذف می‌شوند
            if (!in_array($path, ['/api/notifications/unread-count'], true)) {
                Logger::info('http', 'درخواست API', [
                    'method' => $request->getMethod(),
                    'path' => $path,
                    'status' => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                    'user_id' => $request->getAttribute('user_id'),
                    'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                ]);
            }
        } catch (\Throwable $e) {
            // لاگ درخواست هرگز نباید پاسخ را خراب کند
        }

        return $response;
    }
}
