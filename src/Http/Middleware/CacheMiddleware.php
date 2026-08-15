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

        $cacheKey = 'cache:http:' . md5($uri . ':' . serialize($_GET));
        $cached = CacheHelper::get($cacheKey);
        if ($cached !== null) {
            $data = json_decode($cached, true);
            return (new Response())
                ->setStatusCode($data['status'] ?? 200)
                ->setJson($data['body'] ?? $data);
        }

        $result = $next($request);
        if ($result instanceof Response && $result->getStatusCode() === 200) {
            // Cache only successful GET responses for 5 minutes
            CacheHelper::set($cacheKey, json_encode([
                'status' => 200,
                'body' => $result,
            ]), 300);
        }
        return $result;
    }
}
