<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $query = [];
    private array $post = [];
    private array $attributes = [];
    private array $cookies = [];
    private array $files = [];
    private array $server = [];
    private string $method = 'GET';
    private string $uri = '/';
    private array $headers = [];
    private ?string $body = null;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $this->query = $_GET;
        $this->post = $_POST;
        $this->cookies = $_COOKIE;
        $this->files = $_FILES;
        $this->server = $_SERVER;
        $this->headers = getallheaders() ?: [];
        $rawBody = file_get_contents('php://input') ?: '';
        $this->body = $rawBody !== '' ? $rawBody : null;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getPathInfo(): string
    {
        return $this->uri;
    }

    public function getQueryParam(string $key, ?string $default = null): ?string
    {
        return $this->query[$key] ?? $default;
    }

    public function getPostParam(string $key, ?string $default = null): ?string
    {
        return $this->post[$key] ?? $default;
    }

    public function getJsonBody(): ?array
    {
        if ($this->body === null) {
            return null;
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }

    public function getHeader(string $name): ?string
    {
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}
