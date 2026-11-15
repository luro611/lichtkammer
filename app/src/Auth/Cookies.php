<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;

/**
 * Cookie-Regeln nach Konfiguration (LK_BASE_URL), nicht nach der einzelnen Anfrage und nicht nach LK_ENV:
 * https:// → __Host-Präfix und Secure; http:// (nur lokale Entwicklung) → ohne beides.
 */
final class Cookies
{
    public const SESSION = 'lk_sid';
    public const PRE = 'lk_pre';
    public const ENROLL = 'lk_enr';

    public function __construct(private readonly Config $config)
    {
    }

    public function secure(): bool
    {
        return str_starts_with($this->config->baseUrl(), 'https://');
    }

    public function name(string $base): string
    {
        return ($this->secure() ? '__Host-' : '') . $base;
    }

    public function set(string $base, string $value, ?int $maxAge = null): string
    {
        $c = $this->name($base) . '=' . $value . '; Path=/; HttpOnly; SameSite=Strict';
        if ($this->secure()) {
            $c .= '; Secure';
        }
        if ($maxAge !== null) {
            $c .= '; Max-Age=' . $maxAge;
        }
        return $c;
    }

    public function clear(string $base): string
    {
        return $this->set($base, '', 0) . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }

    /** Host für die Passkey-Relying-Party-ID (Schritt 6): aus der Konfiguration, nie aus HTTP_HOST. */
    public function rpId(): string
    {
        return (string) parse_url($this->config->baseUrl(), PHP_URL_HOST);
    }
}
