<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\CacheHelper;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $path = $request->getPathInfo();
        $key = "ratelimit:{$ip}:{$path}";

        $current = CacheHelper::get($key, 0);
        $limit = 60; // requests per minute
        $window = 60;

        if ($current >= $limit) {
            return (new Response())
                ->setStatusCode(429)
                ->setJson([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $window,
                ]);
        }

        CacheHelper::set($key, $current + 1, $window);
        return $next($request);
    }
}
