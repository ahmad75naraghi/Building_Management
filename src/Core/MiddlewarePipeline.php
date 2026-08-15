<?php

declare(strict_types=1);

namespace App\Core;

final class MiddlewarePipeline
{
    /**
     * @param array<class-string> $middlewareClasses
     */
    public function __construct(private array $middlewareClasses = [])
    {
    }

    public function process(Request $request, callable $next): mixed
    {
        $pipeline = $this->createPipeline($next);
        return $pipeline($request);
    }

    private function createPipeline(callable $next): callable
    {
        return array_reduce(
            array_reverse($this->middlewareClasses),
            function (callable $carry, string $middlewareClass) {
                return function (Request $request) use ($carry, $middlewareClass) {
                    $middleware = new $middlewareClass();
                    return $middleware->handle($request, $carry);
                };
            },
            $next
        );
    }
}
