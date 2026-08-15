<?php

declare(strict_types=1);

namespace App\Core;

final class Container
{
    private array $services = [];
    private array $instances = [];

    public function set(string $id, callable|string $value): void
    {
        $this->services[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->services[$id])) {
            throw new \RuntimeException("Service not found: {$id}");
        }

        $value = $this->services[$id];
        if (is_callable($value)) {
            $value = $value($this);
        }

        $this->instances[$id] = $value;
        return $value;
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}
