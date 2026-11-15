<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Response
{
    /**
     * @param array<string,string> $headers
     * @param list<string> $cookies fertige Set-Cookie-Werte
     */
    public function __construct(
        public readonly string $body,
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function notFound(): self
    {
        return new self('Nicht gefunden', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $headers + $this->headers, $this->cookies);
    }

    /** Standard-Kopfzeilen ergänzen; bereits gesetzte Kopfzeilen der Antwort bleiben. @param array<string,string> $defaults */
    public function withDefaults(array $defaults): self
    {
        return new self($this->body, $this->status, $this->headers + $defaults, $this->cookies);
    }

    public function withCookie(string $setCookie): self
    {
        return new self($this->body, $this->status, $this->headers, [...$this->cookies, $setCookie]);
    }

    public function send(bool $headOnly = false): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            header('Set-Cookie: ' . $cookie, false);
        }
        if (!$headOnly) {
            echo $this->body;
        }
    }
}
