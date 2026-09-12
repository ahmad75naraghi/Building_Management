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
            $handler = function (Request $req) use ($response) {
                
                $match = $this->router->match($req);
                
                // --- FIX FOR SUBFOLDER ROUTING (Fallback Matcher) ---
                if (!$match) {
                    $path = method_exists($req, 'getPathInfo') ? $req->getPathInfo() : ($_SERVER['REQUEST_URI'] ?? '/');
                    $path = explode('?', $path)[0];
                    
                    // Strip the /b subfolder prefix safely
                    if (str_starts_with($path, '/b/')) {
                        $path = substr($path, 2);
                    }
                    if (!str_starts_with($path, '/')) {
                        $path = '/' . $path;
                    }
                    
                    $httpMethod = method_exists($req, 'getMethod') ? $req->getMethod() : ($_SERVER['REQUEST_METHOD'] ?? 'GET');
                    
                    if (class_exists(\App\Config\Routes::class)) {
                        foreach (\App\Config\Routes::$routes as $route => $handlerDef) {
                            $parts = explode(' ', $route);
                            if (count($parts) === 2 && strtoupper($parts[0]) === strtoupper($httpMethod)) {
                                $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9_-]+)', $parts[1]);
                                if (preg_match("#^" . $pattern . "$#", $path, $matches)) {
                                    array_shift($matches);
                                    preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $parts[1], $paramNames);
                                    $named = [];
                                    foreach ($paramNames[1] as $idx => $name) {
                                        $named[$name] = $matches[$idx] ?? null;
                                    }
                                    $match = [
                                        'controller' => $handlerDef[0],
                                        'method' => $handlerDef[1],
                                        'named' => $named
                                    ];
                                    break;
                                }
                            }
                        }
                    }
                }
                // ----------------------------------------------------

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