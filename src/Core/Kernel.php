<?php

declare(strict_types=1);

namespace App\Core;

use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\RateLimitMiddleware;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\CacheMiddleware;

final class Kernel
{
    private Router $router;
    private Container $container;
    private array $middleware = [
        RateLimitMiddleware::class,
        CorsMiddleware::class,
        AuthMiddleware::class,
        CacheMiddleware::class,
    ];

    public function __construct()
    {
        $this->router = new Router();
        $this->container = new Container();
        $this->registerServices();
    }

    private function registerServices(): void
    {
        $this->container->set('request', fn() => new Request());
        $this->container->set('response', fn() => new Response());
        $this->container->set('router', fn() => $this->router);
    }

    public function handle(?Request $request = null): Response
    {
        $request = $request ?? new Request();
        $response = new Response();

        try {
            // Apply global middleware pipeline
            $pipeline = new MiddlewarePipeline($this->middleware);
            $handler = function (Request $req) use ($request, $response) {
                $match = $this->router->match($req);
                if (!$match) {
                    $response->setStatusCode(404)->setJson([
                        'success' => false,
                        'message' => 'Resource not found',
                    ]);
                    return $response;
                }

                $controllerClass = $match['controller'];
                $method = $match['method'];

                // Set named params as attributes
                foreach ($match['named'] as $key => $value) {
                    $req->setAttribute($key, $value);
                }

                if (!class_exists($controllerClass)) {
                    $response->setStatusCode(500)->setJson([
                        'success' => false,
                        'message' => 'Controller not found: ' . $controllerClass,
                    ]);
                    return $response;
                }

                $controller = new $controllerClass();
                if (!method_exists($controller, $method)) {
                    $response->setStatusCode(500)->setJson([
                        'success' => false,
                        'message' => 'Method not found: ' . $method,
                    ]);
                    return $response;
                }

                $result = $controller->$method($req, ...array_values($match['named']));
                if ($result instanceof Response) {
                    return $result;
                }

                return $response->setJson([
                    'success' => true,
                    'data' => $result,
                ]);
            };

            $pipelineResult = $pipeline->process($request, $handler);
            if ($pipelineResult instanceof Response) {
                return $pipelineResult;
            }
            return $pipelineResult ?? $response;
        } catch (\Throwable $e) {
            $status = $e instanceof \App\Exceptions\AppException ? 400 : 500;
            $response->setStatusCode($status)->setJson([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $e->getCode() ?: $status,
            ]);
            return $response;
        }
    }
}
