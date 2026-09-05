<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\CacheHelper;

final class CacheMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $method = $request->getMethod();
        $uri = $request->getUri();

        // Only cache GET requests
        if ($method !== 'GET') {
            return $next($request);
        }

        // کلید کش باید شامل کاربر باشد — در غیر این صورت پاسخ کاربر A
        // (اعلانات/تیکت‌های خصوصی) به کاربر B داده می‌شود (نشت داده)
        $userId = (string) ($request->getAttribute('user_id') ?? 'guest');
        $cacheKey = 'cache:http:' . md5($userId . ':' . $uri . ':' . serialize($_GET));
        $cached = CacheHelper::get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            $response = new Response();
            $response->setStatusCode((int) ($cached['status'] ?? 200));
            $response->setContent((string) ($cached['body'] ?? ''));
            $response->setHeader('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }

        $result = $next($request);
        if ($result instanceof Response && $result->getStatusCode() === 200) {
            // فقط محتوای JSON واقعی کش شود (نه آبجکت Response)
            $content = $result->getContent();
            if ($content !== null) {
                CacheHelper::set($cacheKey, json_encode([
                    'status' => 200,
                    'body' => $content,
                ], JSON_UNESCAPED_UNICODE), 300);
            }
        }
        return $result;
    }
}
