<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function notFound(): self
    {
        return new self('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if (!$headOnly) {
            echo $this->body;
        }
    }
}
