<?php
declare(strict_types=1);

namespace Lichtkammer;

final class Request
{
    /** @param array<string,mixed> $server */
    public function __construct(private readonly array $server, private readonly Config $config)
    {
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

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ($this->config->trustProxy()) {
            $proto = strtolower(trim(explode(',', (string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            return $proto === 'https';
        }
        return false;
    }
}
