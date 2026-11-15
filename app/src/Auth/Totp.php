<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/** TOTP nach RFC 6238: HMAC-SHA1, 6 Stellen, 30 s, Fenster ±1 Schritt. */
final class Totp
{
    public const PERIOD = 30;

    public static function newSecret(): string
    {
        return Base32::encode(random_bytes(20));
    }

    public static function step(int $time): int
    {
        return intdiv($time, self::PERIOD);
    }

    public static function code(string $secretBase32, int $step, int $digits = 6, string $algo = 'sha1'): string
    {
        $key = Base32::decode($secretBase32);
        $h = hash_hmac($algo, pack('J', $step), $key, true);
        $o = ord($h[strlen($h) - 1]) & 0x0F;
        $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Prüft den Code gegen die Schritte now-1..now+1. Alle drei werden ohne vorzeitigen Abbruch
     * berechnet und mit hash_equals verglichen. Zählt nur ein Schritt, der größer als $lastStep ist.
     *
     * @return int|null der höchste passende Schritt, sonst null
     */
    public static function verify(string $secretBase32, string $input, int $time, int $lastStep): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $input) !== 1) {
            return null;
        }
        $now = self::step($time);
        $best = null;
        for ($s = $now - 1; $s <= $now + 1; $s++) {
            $match = hash_equals(self::code($secretBase32, $s), $input);
            if ($match && $s > $lastStep && ($best === null || $s > $best)) {
                $best = $s;
            }
        }
        return $best;
    }

    public static function uri(string $secretBase32, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?secret=' . $secretBase32 . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}
