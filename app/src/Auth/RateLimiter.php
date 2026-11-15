<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Zähler gegen Raten. Alle Schlüssel sind HMACs (auch für unbekannte Namen: gleiche Behandlung).
 * Versuche werden VOR der teuren Prüfung gebucht (gate) und bei Erfolg wieder entfernt (release).
 * Während einer Sperre werden keine Versuche gebucht: eine laufende Sperre verlängert sich nicht.
 */
final class RateLimiter
{
    public const WINDOW = 900;          // 15 Minuten
    public const DELAY_FROM = 3;        // ab dem 3. Fehlversuch zunehmende Wartezeit
    public const DELAY_MAX = 30;
    public const LOCK_AT = 10;          // Name+Adresse und zweite Stufe
    public const LOCK_AT_IP = 30;       // Adresse allein (mehrere Namen, Mobilfunk-NAT)
    public const DELAY_ONLY_USER = 100; // Name allein: nur Wartezeit, nie Sperre

    public function __construct(private readonly Database $db, private readonly Keys $keys, private readonly Clock $clock)
    {
    }

    public function key(string $kind, string $value): string
    {
        return $this->keys->hmac('ratelimit', $kind . '|' . $value);
    }

    /**
     * Prüft alle Regeln und bucht den Versuch atomar.
     *
     * @param list<array{kind:string,key:string,lock:?int,delay:bool}> $rules
     *        lock = Schwelle für 15 Minuten Sperre (null: keine Sperre), delay = Wartezeit ab dem 3. Fehlversuch
     * @param bool $globalBrake globale Bremse beachten und mitzählen
     * @return array{ok:bool,retry:int,ids:list<int>,reason:string,locked_now:list<string>}
     */
    public function gate(array $rules, bool $globalBrake, int $brakeLimit, int $brakeWindow): array
    {
        return $this->db->tx(function () use ($rules, $globalBrake, $brakeLimit, $brakeWindow): array {
            $now = $this->clock->now();
            $this->db->run('DELETE FROM login_attempts WHERE at < ?', [$now - self::WINDOW]);

            foreach ($rules as $r) {
                $row = $this->db->one('SELECT COUNT(*) AS n, MAX(at) AS last FROM login_attempts WHERE kind = ? AND key_hash = ? AND at >= ?', [$r['kind'], $r['key'], $now - self::WINDOW]);
                $n = (int) ($row['n'] ?? 0);
                $last = (int) ($row['last'] ?? 0);
                if ($r['lock'] !== null && $n >= $r['lock']) {
                    return ['ok' => false, 'retry' => max(1, $last + self::WINDOW - $now), 'ids' => [], 'reason' => 'locked', 'locked_now' => []];
                }
                $delayFrom = $r['kind'] === 'user' ? self::DELAY_ONLY_USER : self::DELAY_FROM;
                if ($r['delay'] && $n >= $delayFrom) {
                    $wait = $r['kind'] === 'user' ? self::DELAY_MAX : min(self::DELAY_MAX, 2 ** ($n - $delayFrom));
                    if ($now < $last + $wait) {
                        return ['ok' => false, 'retry' => $last + $wait - $now, 'ids' => [], 'reason' => 'delay', 'locked_now' => []];
                    }
                }
            }
            if ($globalBrake) {
                $recent = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND at > ?', ['global', $now - $brakeWindow]);
                if ($recent >= $brakeLimit) {
                    return ['ok' => false, 'retry' => $brakeWindow, 'ids' => [], 'reason' => 'global', 'locked_now' => []];
                }
            }
            $ids = [];
            $lockedNow = [];
            foreach ($rules as $r) {
                $this->db->run('INSERT INTO login_attempts(kind, key_hash, at) VALUES(?,?,?)', [$r['kind'], $r['key'], $now]);
                $ids[] = $this->db->lastId();
                if ($r['lock'] !== null) {
                    $n = (int) $this->db->value('SELECT COUNT(*) FROM login_attempts WHERE kind = ? AND key_hash = ? AND at >= ?', [$r['kind'], $r['key'], $now - self::WINDOW]);
                    if ($n === $r['lock']) {
                        $lockedNow[] = $r['kind']; // dieser Versuch ist der, ab dem gesperrt wird (wenn er scheitert)
                    }
                }
            }
            if ($globalBrake) {
                $this->db->run('INSERT INTO login_attempts(kind, key_hash, at) VALUES(?,?,?)', ['global', 'g', $now]);
            }
            return ['ok' => true, 'retry' => 0, 'ids' => $ids, 'reason' => '', 'locked_now' => $lockedNow];
        });
    }

    /** Erfolgreicher Vorgang: die gebuchten Versuche wieder entfernen (die globale Bremse bleibt gezählt). */
    public function release(array $ids): void
    {
        foreach ($ids as $id) {
            $this->db->run('DELETE FROM login_attempts WHERE id = ?', [$id]);
        }
    }

    /** Alte Zähler und zusammengefasste Fehlversuche aufräumen. */
    public function purge(): void
    {
        $now = $this->clock->now();
        $this->db->run('DELETE FROM login_attempts WHERE at < ?', [$now - self::WINDOW]);
        $this->db->run('DELETE FROM login_failures WHERE minute < ?', [intdiv($now - 30 * 86400, 60)]);
    }

    /** Fehlversuch eines unbekannten Absenders zusammenfassen (nicht Teil der Protokollkette). */
    public function noteUnknownFailure(string $ipKey): void
    {
        $minute = intdiv($this->clock->now(), 60);
        $this->db->run(
            'INSERT INTO login_failures(ip_hash, minute, count) VALUES(?,?,1) ON CONFLICT(ip_hash, minute) DO UPDATE SET count = count + 1',
            [$this->key('ipfail', $ipKey), $minute],
        );
    }
}
