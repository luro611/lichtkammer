<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Geheimschlüssel LK_APP_KEY (32 Byte, Base64). Der Rohschlüssel wird nie direkt benutzt:
 * Teilschlüssel per HKDF mit festen Kontexten. Verschlüsselt wird mit XChaCha20-Poly1305
 * und Zusatzdaten, Format "v1:<kid>:<base64url(nonce|cipher)>".
 */
final class Keys
{
    public const CTX = ['totp', 'recovery', 'audit', 'ratelimit', 'csrf', 'ids'];

    /** @var array<string,string> */
    private array $rings = []; // kid => Rohschlüssel
    private string $currentKid;

    /** @throws ConfigError */
    public function __construct(string $keyB64, string $previousB64 = '')
    {
        if (!extension_loaded('sodium')) {
            throw new ConfigError('Die PHP-Erweiterung „sodium“ fehlt.');
        }
        $raw = self::decode($keyB64, 'LK_APP_KEY');
        $this->currentKid = self::kidOf($raw);
        $this->rings[$this->currentKid] = $raw;
        if ($previousB64 !== '') {
            $prev = self::decode($previousB64, 'LK_APP_KEY_PREVIOUS');
            $this->rings[self::kidOf($prev)] ??= $prev;
        }
    }

    /** @throws ConfigError */
    private static function decode(string $b64, string $name): string
    {
        if ($b64 === '') {
            throw new ConfigError($name . ' fehlt. Erzeugen mit: openssl rand -base64 32');
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new ConfigError($name . ' muss genau 32 Byte in Base64 sein. Erzeugen mit: openssl rand -base64 32');
        }
        if ($raw === str_repeat($raw[0], 32)) {
            throw new ConfigError($name . ' ist ein Platzhalter (lauter gleiche Bytes). Bitte einen echten Schlüssel erzeugen.');
        }
        return $raw;
    }

    private static function kidOf(string $raw): string
    {
        return substr(bin2hex(hash_hkdf('sha256', $raw, 16, 'lichtkammer:kid')), 0, 8);
    }

    public function kid(): string
    {
        return $this->currentKid;
    }

    /** Teilschlüssel für einen festen Kontext (mit dem aktuellen oder, wenn angegeben, einem anderen Schlüsselring). */
    public function derive(string $context, ?string $kid = null): string
    {
        if (!in_array($context, self::CTX, true)) {
            throw new \InvalidArgumentException('Unbekannter Schlüsselkontext');
        }
        return hash_hkdf('sha256', $this->rings[$kid ?? $this->currentKid], 32, 'lichtkammer:' . $context);
    }

    /** @return list<string> Kennungen aller bekannten Schlüssel (aktueller zuerst). */
    public function kids(): array
    {
        $kids = array_keys($this->rings);
        usort($kids, fn (string $a, string $b): int => ($b === $this->currentKid) <=> ($a === $this->currentKid));
        return $kids;
    }

    public function hmac(string $context, string $data, ?string $kid = null): string
    {
        return hash_hmac('sha256', $data, $this->derive($context, $kid));
    }

    public function encrypt(string $plain, string $aad): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = $this->derive('totp');
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $aad, $nonce, $key);
        sodium_memzero($key);
        return 'v1:' . $this->currentKid . ':' . rtrim(strtr(base64_encode($nonce . $cipher), '+/', '-_'), '=');
    }

    /** @return array{plain:string,kid:string}|null null bei falschem Schlüssel, falschen Zusatzdaten oder kaputtem Wert */
    public function decrypt(string $token, string $aad): ?array
    {
        $parts = explode(':', $token, 3);
        if (count($parts) !== 3 || $parts[0] !== 'v1' || !isset($this->rings[$parts[1]])) {
            return null;
        }
        $bin = base64_decode(strtr($parts[2], '-_', '+/'), true);
        $nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($bin === false || strlen($bin) < $nlen + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bin, $nlen), $aad, substr($bin, 0, $nlen), $this->derive('totp', $parts[1]));
        return $plain === false ? null : ['plain' => $plain, 'kid' => $parts[1]];
    }

    /** Kennung des Schlüssels, mit dem ein Wert verschlüsselt wurde (ohne zu entschlüsseln). */
    public static function kidOfToken(string $token): ?string
    {
        $parts = explode(':', $token, 3);
        return count($parts) === 3 && $parts[0] === 'v1' ? $parts[1] : null;
    }
}
