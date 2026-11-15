<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Request
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $post
     * @param array<string,string> $cookies
     */
    public function __construct(
        private readonly array $server,
        private readonly Config $config,
        private readonly array $post = [],
        private readonly array $cookies = [],
        private readonly ?string $body = null,
    ) {
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Pfad ohne Query, immer mit führendem Schrägstrich, ohne doppelte Schrägstriche. */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = rawurldecode(strtok($uri, '?#') ?: '/');
        $path = preg_replace('#/+#', '/', '/' . $path) ?? '/';
        return $path;
    }

    /** Query-Parameter (nur Strings). */
    public function query(string $key): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '');
        $q = parse_url($uri, PHP_URL_QUERY);
        if (!is_string($q)) {
            return '';
        }
        parse_str($q, $out);
        return is_string($out[$key] ?? null) ? $out[$key] : '';
    }

    /** Formularwert als String (Arrays und fehlende Werte werden zu ''). */
    public function input(string $key): string
    {
        $v = $this->post[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    /** Liste aus name[]-Feldern (nur Strings, höchstens 500). @return list<string> */
    public function inputList(string $key): array
    {
        $v = $this->post[$key] ?? [];
        if (!is_array($v)) {
            return [];
        }
        return array_slice(array_values(array_filter($v, 'is_string')), 0, 500);
    }

    /** Kopfzeile, z. B. header('X-CSRF-Token'). */
    public function header(string $name): string
    {
        $v = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '';
        return is_string($v) ? $v : '';
    }

    /** Roher Anfragekörper (für Upload-Stücke); höchstens $max Byte. */
    public function body(int $max): string
    {
        if ($this->body !== null) {
            return substr($this->body, 0, $max + 1);
        }
        return (string) file_get_contents('php://input', false, null, 0, $max + 1);
    }

    public function cookie(string $name): string
    {
        $v = $this->cookies[$name] ?? '';
        return is_string($v) ? $v : '';
    }

    /** Maßgeblich ist nur REMOTE_ADDR (in Docker setzt mod_remoteip sie für vertraute Proxys). */
    public function remoteAddr(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ($this->config->trustProxy()) {
            // Mehrere Proxys hängen rechts an: der LETZTE Eintrag stammt vom eigenen Proxy, nicht vom Client.
            $parts = explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''));
            return strtolower(trim((string) end($parts))) === 'https';
        }
        return false;
    }
}
