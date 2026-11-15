<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Aus REMOTE_ADDR abgeleitete Adressformen. X-Forwarded-For liest die App nie selbst
 * (das übernimmt in Docker mod_remoteip für vertraute Proxys).
 */
final class ClientIp
{
    /** @return array{key:string,prefix:string} key: IPv4 voll bzw. IPv6 /64 (für Zähler); prefix: IPv4 /24 bzw. IPv6 /48 (Protokoll) */
    public static function forms(string $remote): array
    {
        $bin = @inet_pton($remote);
        if ($bin === false) {
            return ['key' => 'unbekannt', 'prefix' => 'unbekannt'];
        }
        if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
            $bin = substr($bin, 12); // IPv4-in-IPv6
        }
        if (strlen($bin) === 4) {
            $o = array_values(unpack('C4', $bin) ?: []);
            return ['key' => implode('.', $o), 'prefix' => $o[0] . '.' . $o[1] . '.' . $o[2] . '.0/24'];
        }
        $key = substr($bin, 0, 8) . str_repeat("\0", 8);
        $prefix = substr($bin, 0, 6) . str_repeat("\0", 10);
        return ['key' => inet_ntop($key) . '/64', 'prefix' => inet_ntop($prefix) . '/48'];
    }
}
