<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Eigene Sitzungsverwaltung in SQLite (nicht PHP-Dateisitzungen). Cookie enthält 32 Zufallsbytes,
 * in der Datenbank steht nur der SHA-256-Hash.
 */
final class SessionStore
{
    public const PASSWORD_OK_TTL = 300;
    public const TOUCH_AFTER = 60;
    public const MAX_TOTP_FAILURES = 5;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly int $idle,
        private readonly int $absolute,
    ) {
    }

    public static function hashId(string $raw): string
    {
        return hash('sha256', $raw);
    }

    /** @return array{id:string,csrf:string} neue Sitzungs-ID (roh, fürs Cookie) und CSRF-Token */
    public function create(int $userId, string $stage, string $ipPrefix, string $userAgent): array
    {
        $raw = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->db->run(
            'INSERT INTO sessions(id_hash, user_id, stage, csrf_token, created_at, last_seen_at, ip_prefix, ua_hash) VALUES(?,?,?,?,?,?,?,?)',
            [self::hashId($raw), $userId, $stage, $csrf, $now, $now, $ipPrefix, hash('sha256', $userAgent)],
        );
        return ['id' => $raw, 'csrf' => $csrf];
    }

    /**
     * Sitzung samt Konto holen. Abgelaufene, deaktivierte und unvollständige Konten liefern null
     * (abgelaufene Zeilen werden dabei gelöscht).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $raw): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
            return null;
        }
        $hash = self::hashId($raw);
        $row = $this->db->one(
            'SELECT s.*, u.username, u.display_name, u.role, u.disabled_at, u.totp_confirmed_at
             FROM sessions s JOIN users u ON u.id = s.user_id WHERE s.id_hash = ?',
            [$hash],
        );
        if ($row === null) {
            return null;
        }
        $now = $this->clock->now();
        $expired = $row['stage'] === 'password_ok'
            ? $now - (int) $row['created_at'] > self::PASSWORD_OK_TTL
            : ($now - (int) $row['last_seen_at'] > $this->idle || $now - (int) $row['created_at'] > $this->absolute);
        if ($expired || $row['disabled_at'] !== null || $row['totp_confirmed_at'] === null) {
            $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [$hash]);
            return null;
        }
        if ($row['stage'] === 'full' && $now - (int) $row['last_seen_at'] > self::TOUCH_AFTER) {
            $this->db->run('UPDATE sessions SET last_seen_at = ? WHERE id_hash = ?', [$now, $hash]);
        }
        return $row;
    }

    public function destroy(string $raw): void
    {
        $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [self::hashId($raw)]);
    }

    public function destroyForUser(int $userId, ?string $exceptRaw = null): int
    {
        if ($exceptRaw === null) {
            return $this->db->run('DELETE FROM sessions WHERE user_id = ?', [$userId])->rowCount();
        }
        return $this->db->run('DELETE FROM sessions WHERE user_id = ? AND id_hash <> ?', [$userId, self::hashId($exceptRaw)])->rowCount();
    }

    /** Zählt einen falschen Code in einer password_ok-Sitzung; ab 5 wird sie gelöscht. */
    public function noteTotpFailure(string $raw): bool
    {
        $hash = self::hashId($raw);
        $this->db->run('UPDATE sessions SET totp_failures = totp_failures + 1 WHERE id_hash = ?', [$hash]);
        $n = (int) ($this->db->value('SELECT totp_failures FROM sessions WHERE id_hash = ?', [$hash]) ?? 0);
        if ($n >= self::MAX_TOTP_FAILURES) {
            $this->db->run('DELETE FROM sessions WHERE id_hash = ?', [$hash]);
            return true;
        }
        return false;
    }

    /** Abgelaufene Sitzungen und Einrichtungs-Zwischenstände löschen (kein Cron: bei jeder Anmeldung). */
    public function purge(): void
    {
        $now = $this->clock->now();
        $this->db->run(
            "DELETE FROM sessions WHERE (stage = 'password_ok' AND created_at < ?) OR (stage = 'full' AND (last_seen_at < ? OR created_at < ?))",
            [$now - self::PASSWORD_OK_TTL, $now - $this->idle, $now - $this->absolute],
        );
        $this->db->run('DELETE FROM pending_enrollments WHERE expires_at < ?', [$now]);
    }
}
