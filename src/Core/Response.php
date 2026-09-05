<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private ?string $content = null;

    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function setJson(array $data): self
    {
        $this->content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $this->setHeader('Content-Type', 'application/json; charset=utf-8');
        return $this;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        // هدرهای امنیتی پیش‌فرض (برای پاسخ‌های API)
        $this->setHeaderIfMissing('X-Frame-Options', 'DENY');
        $this->setHeaderIfMissing('X-Content-Type-Options', 'nosniff');
        $this->setHeaderIfMissing('Referrer-Policy', 'strict-origin-when-cross-origin');

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        if ($this->content !== null) {
            echo $this->content;
        }
    }

    private function setHeaderIfMissing(string $name, string $value): void
    {
        foreach (array_keys($this->headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return;
            }
        }
        $this->headers[$name] = $value;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }
}
