<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * CSRF-Schutz vor der Anmeldung (es gibt noch keine Sitzung). Der Zufallswert r liegt im Cookie
 * lk_pre; das Formularfeld trägt exp|HMAC(csrf, r|exp). Geprüft wird mit dem r aus dem Cookie:
 * ein fremdes Token passt nicht zu meinem Cookie (Login-CSRF).
 */
final class PreCsrf
{
    public const TTL = 3600;

    public function __construct(private readonly Keys $keys, private readonly Clock $clock)
    {
    }

    public static function validR(string $r): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $r) === 1;
    }

    public function newR(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function token(string $r): string
    {
        $exp = $this->clock->now() + self::TTL;
        return $exp . '|' . $this->keys->hmac('csrf', $r . '|' . $exp);
    }

    public function check(string $r, string $token): bool
    {
        if (!self::validR($r) || !str_contains($token, '|')) {
            return false;
        }
        [$exp, $mac] = explode('|', $token, 2);
        if (!ctype_digit($exp) || (int) $exp < $this->clock->now()) {
            return false;
        }
        return hash_equals($this->keys->hmac('csrf', $r . '|' . $exp), $mac);
    }
}
