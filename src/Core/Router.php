<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Routes;

final class Router
{
    private array $routes = [];

    public function __construct()
    {
        $this->routes = Routes::$routes;
    }

    public function match(Request $request): ?array
    {
        $method = $request->getMethod();
        $path = $request->getPathInfo();

        foreach ($this->routes as $route => $handler) {
            $pattern = $this->buildPattern($route);
            if (preg_match($pattern, $path, $matches) && $this->matchMethod($route, $method)) {
                array_shift($matches);
                $params = array_values($matches);

                // Named parameters from route like {id}
                $namedParams = $this->extractNamedParams($route, $path);
                $params = array_merge($params, $namedParams);

                return [
                    'controller' => $handler[0],
                    'method' => $handler[1],
                    'params' => $params,
                    'named' => $namedParams,
                ];
            }
        }
        return null;
    }

    private function buildPattern(string $route): string
    {
        $methodPath = explode(' ', $route, 2);
        $path = $methodPath[1] ?? $methodPath[0];
        $path = trim($path, '/');
        $pattern = preg_quote($path, '#');
        $pattern = str_replace('\{', '{', $pattern);
        $pattern = str_replace('\}', '}', $pattern);
        $pattern = preg_replace('#\{([^}]+)\}#', '([^/]+)', $pattern);
        return '#^' . $pattern . '$#';
    }

    private function matchMethod(string $route, string $method): bool
    {
        $routeMethod = explode(' ', $route)[0] ?? 'GET';
        return strtoupper($routeMethod) === strtoupper($method);
    }

    private function extractNamedParams(string $route, string $path): array
    {
        $params = [];
        $routePattern = explode(' ', $route)[1] ?? $route;
        $routePattern = trim($routePattern, '/');
        $path = trim($path, '/');

        $routeParts = explode('/', $routePattern);
        $pathParts = explode('/', $path);

        foreach ($routeParts as $i => $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $key = trim($part, '{}');
                if (isset($pathParts[$i])) {
                    $params[$key] = $pathParts[$i];
                }
            }
        }
        return $params;
    }
}
