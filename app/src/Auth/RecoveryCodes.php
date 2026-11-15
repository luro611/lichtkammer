<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/** Wiederherstellungscodes: zehn Stück, 10 Zeichen (Crockford-Base32), gespeichert als HMAC. */
final class RecoveryCodes
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const COUNT = 10;

    public function __construct(private readonly Database $db, private readonly Keys $keys, private readonly Clock $clock)
    {
    }

    /** Eingabe vereinheitlichen: groß, ohne Trennzeichen, O→0, I/L→1. */
    public static function normalize(string $input): string
    {
        $s = strtoupper($input);
        $s = preg_replace('/[^0-9A-Z]/', '', $s) ?? '';
        return strtr($s, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private function hash(string $normalized): string
    {
        return $this->keys->hmac('recovery', $normalized);
    }

    /** @return list<string> neue Codes im Klartext ("XXXXX-XXXXX"); ersetzt alle bisherigen des Kontos */
    public function regenerate(int $userId): array
    {
        return $this->db->tx(function () use ($userId): array {
            $this->db->run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
            $out = [];
            for ($i = 0; $i < self::COUNT; $i++) {
                $code = '';
                for ($j = 0; $j < 10; $j++) {
                    $code .= self::ALPHABET[random_int(0, 31)];
                }
                $this->db->run('INSERT INTO recovery_codes(user_id, code_hash) VALUES(?,?)', [$userId, $this->hash($code)]);
                $out[] = substr($code, 0, 5) . '-' . substr($code, 5);
            }
            return $out;
        });
    }

    /**
     * Prüft gegen alle unbenutzten Codes des Kontos (immer alle, mit hash_equals) und verbraucht
     * den Treffer atomar. Genau ein gleichzeitiger Aufruf gewinnt.
     */
    public function consume(int $userId, string $input): bool
    {
        $norm = self::normalize($input);
        if (strlen($norm) !== 10) {
            // gleiche Arbeit wie bei einem echten Versuch, aber nie ein Treffer
            $norm = str_repeat('0', 10);
            $valid = false;
        } else {
            $valid = true;
        }
        $candidate = $this->hash($norm);
        return $this->db->tx(function () use ($userId, $candidate, $valid): bool {
            $found = null;
            foreach ($this->db->all('SELECT id, code_hash FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]) as $row) {
                if (hash_equals((string) $row['code_hash'], $candidate) && $valid) {
                    $found = (int) $row['id'];
                }
            }
            if ($found === null) {
                return false;
            }
            return $this->db->run('UPDATE recovery_codes SET used_at = ? WHERE id = ? AND used_at IS NULL', [$this->clock->now(), $found])->rowCount() === 1;
        });
    }

    public function remaining(int $userId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }
}
