<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Request;
use App\Core\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, callable $next): mixed
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $response->setStatusCode(200)
                ->setHeader('Access-Control-Allow-Origin', '*')
                ->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
                ->setHeader('Access-Control-Max-Age', '86400');
            return $response;
        }

        $response = $next($request);
        if ($response instanceof Response) {
            $response->setHeader('Access-Control-Allow-Origin', '*');
            $response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        } else {
            $newResponse = new Response();
            $newResponse->setHeader('Access-Control-Allow-Origin', '*');
            $newResponse->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $newResponse->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
            $newResponse->setStatusCode(200);
            $newResponse->setContent(is_string($response) ? $response : json_encode($response));
            return $newResponse;
        }
        return $response;
    }
}
