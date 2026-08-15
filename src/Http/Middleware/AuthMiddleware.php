<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Utilities\JwtHelper;
use App\Config\AppConfig;

final class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        $path = $request->getPathInfo();
        $publicPaths = [
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/refresh',
            '/api/auth/logout',
        ];

        if (in_array($path, $publicPaths, true)) {
            return $next($request);
        }

        $authHeader = $request->getHeader('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return (new Response())
                ->setStatusCode(401)
                ->setJson([
                    'success' => false,
                    'message' => 'Authorization header missing or invalid',
                ]);
        }

        $token = substr($authHeader, 7);
        try {
            $payload = JwtHelper::verify($token);
            $request->setAttribute('user_id', $payload['sub'] ?? null);
            $request->setAttribute('user_role', $payload['role'] ?? null);
            $request->setAttribute('building_id', $payload['building_id'] ?? null);
        } catch (\Exception $e) {
            return (new Response())
                ->setStatusCode(401)
                ->setJson([
                    'success' => false,
                    'message' => 'Invalid or expired token: ' . $e->getMessage(),
                ]);
        }

        return $next($request);
    }
}
