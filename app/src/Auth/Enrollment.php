<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;
use Lichtkammer\Request;

/**
 * Ersteinrichtung, Einladung und Rücksetzung. Gemeinsamer Ablauf:
 * (1) Formular mit Token/Link und Passwort, (2) Zwischenzeile in pending_enrollments (eigenes Cookie,
 * 15 Minuten, TOTP-Geheimnis verschlüsselt), (3) Konto entsteht erst nach gültigem TOTP-Code in EINER Transaktion.
 */
final class Enrollment
{
    public const PENDING_TTL = 900;
    public const INVITE_TTL = 86400;
    public const MAX_FAILURES = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Keys $keys,
        private readonly Passwords $passwords,
        private readonly RateLimiter $limiter,
        private readonly SessionStore $sessions,
        private readonly Audit $audit,
        private readonly RecoveryCodes $recovery,
        private readonly Clock $clock,
    ) {
    }

    // --- Zustand der Ersteinrichtung und Rücksetzroute ---

    public function setupOpen(): bool
    {
        $t = $this->config->setupToken();
        return strlen($t) >= 32
            && $this->db->value("SELECT 1 FROM meta WHERE k = 'setup_done_at'") === null
            && (int) $this->db->value('SELECT COUNT(*) FROM users') === 0;
    }

    /** Kennung von Einladungen und Zwischenzeilen: keyed HMAC, nie reines SHA-256 (Prüfung 08, A6). */
    private function tokenHash(string $token): string
    {
        return $this->keys->hmac('ids', $token);
    }

    /**
     * Markierung „Notfall-Token verbraucht“ in meta: absichtlich ohne Schlüssel, damit sie eine
     * Schlüsselrotation überlebt (sonst gälte ein verbrauchtes LK_RESET_TOKEN danach wieder). Das Token steht ohnehin in .env.
     */
    private static function markerHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function resetOpen(): bool
    {
        $t = $this->config->resetToken();
        return strlen($t) >= 32
            && $this->db->value("SELECT 1 FROM meta WHERE k = ?", ['reset_used:' . self::markerHash($t)]) === null;
    }

    /** Warnungen: ein vergessenes, schon benutztes Token steht noch in .env. @return list<string> */
    public function tokenWarnings(): array
    {
        $w = [];
        if (strlen($this->config->setupToken()) > 0 && $this->db->value("SELECT 1 FROM meta WHERE k = 'setup_done_at'") !== null) {
            $w[] = 'In app/.env steht noch LK_SETUP_TOKEN, obwohl die Einrichtung abgeschlossen ist. Bitte die Zeile löschen.';
        }
        $r = $this->config->resetToken();
        if ($r !== '' && $this->db->value("SELECT 1 FROM meta WHERE k = ?", ['reset_used:' . self::markerHash($r)]) !== null) {
            $w[] = 'In app/.env steht noch ein benutztes LK_RESET_TOKEN. Bitte die Zeile löschen.';
        }
        return $w;
    }

    /** @return array{ok:bool,retry:int,ids:list<int>} */
    public function tokenGate(Request $req): array
    {
        $ip = ClientIp::forms($req->remoteAddr());
        $g = $this->limiter->gate([
            ['kind' => 'tok', 'key' => $this->limiter->key('tok', $ip['key']), 'lock' => RateLimiter::LOCK_AT, 'delay' => true],
        ], true, $this->config->globalBrakeLimit(), $this->config->globalBrakeWindow());
        return ['ok' => $g['ok'], 'retry' => $g['retry'], 'ids' => $g['ids']];
    }

    public function release(array $ids): void
    {
        $this->limiter->release($ids);
    }

    // --- Schritt 2: Zwischenzeile ---

    /**
     * Schritt 1 der Ersteinrichtung: Token, Benutzername und Passwort prüfen.
     *
     * @return array{error:?string,enr:?string}
     */
    public function startSetup(Request $req, string $token, string $username, string $password): array
    {
        $gate = $this->tokenGate($req);
        if (!$gate['ok']) {
            return ['error' => 'Bitte später noch einmal versuchen.', 'enr' => null];
        }
        $expected = $this->config->setupToken();
        if (!$this->setupOpen() || !hash_equals($expected, $token)) {
            return ['error' => 'Das Einrichtungs-Token stimmt nicht.', 'enr' => null];
        }
        $name = Passwords::normalizeUsername($username);
        $err = $this->checkNameAndPassword($name, $password);
        if ($err !== null) {
            $this->limiter->release($gate['ids']);
            return ['error' => $err, 'enr' => null];
        }
        $this->limiter->release($gate['ids']);
        return ['error' => null, 'enr' => $this->createPending('setup', null, $name, 'admin', $password)];
    }

    /** Einladung/Rücksetz-Link prüfen (ohne etwas zu verbrauchen). @return array<string,mixed>|null */
    public function invite(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->db->one(
            'SELECT i.*, u.username AS target_name FROM invites i LEFT JOIN users u ON u.id = i.user_id WHERE i.token_hash = ? AND i.used_at IS NULL AND i.expires_at > ?',
            [$this->tokenHash($token), $this->clock->now()],
        );
        return $row;
    }

    /** @return array{error:?string,enr:?string} */
    public function startInvite(Request $req, string $token, string $username, string $password): array
    {
        $gate = $this->tokenGate($req);
        if (!$gate['ok']) {
            return ['error' => 'Bitte später noch einmal versuchen.', 'enr' => null];
        }
        $inv = $this->invite($token);
        if ($inv === null) {
            return ['error' => 'invalid', 'enr' => null];
        }
        if ($inv['purpose'] === 'reenroll') {
            $name = (string) $inv['target_name'];
            $err = $this->passwords->validate($password, $name);
        } else {
            $name = Passwords::normalizeUsername($username);
            $err = $this->checkNameAndPassword($name, $password);
        }
        if ($err !== null) {
            $this->limiter->release($gate['ids']);
            return ['error' => $err, 'enr' => null];
        }
        $this->limiter->release($gate['ids']);
        return ['error' => null, 'enr' => $this->createPending((string) $inv['purpose'], (int) $inv['id'], $name, (string) $inv['role'], $password)];
    }

    private function checkNameAndPassword(string $name, string $password): ?string
    {
        if (!Passwords::validUsername($name)) {
            return 'Der Benutzername darf nur aus Kleinbuchstaben, Ziffern, Punkt, Binde- und Unterstrich bestehen (3 bis 32 Zeichen).';
        }
        if ($this->db->value('SELECT 1 FROM users WHERE username = ?', [$name]) !== null) {
            return 'Dieser Benutzername ist schon vergeben.';
        }
        return $this->passwords->validate($password, $name);
    }

    private function createPending(string $purpose, ?int $inviteId, string $username, string $role, string $password): string
    {
        $raw = bin2hex(random_bytes(32));
        $idHash = $this->tokenHash($raw);
        $secret = Totp::newSecret();
        $now = $this->clock->now();
        $this->db->run(
            'INSERT INTO pending_enrollments(id_hash, purpose, invite_id, username, role, password_hash, totp_secret_enc, csrf_token, expires_at, created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?)',
            [$idHash, $purpose, $inviteId, $username, $role, $this->passwords->hash($password),
                $this->keys->encrypt($secret, 'pending:' . $idHash), bin2hex(random_bytes(32)), $now + self::PENDING_TTL, $now],
        );
        return $raw;
    }

    /** @return array<string,mixed>|null abgelaufene Zeilen werden gelöscht */
    public function pending(string $raw): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
            return null;
        }
        $row = $this->db->one('SELECT * FROM pending_enrollments WHERE id_hash = ?', [$this->tokenHash($raw)]);
        if ($row === null) {
            return null;
        }
        if ((int) $row['expires_at'] < $this->clock->now()) {
            $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$row['id_hash']]);
            return null;
        }
        return $row;
    }

    /** Geheimnis der Zwischenzeile für die Anzeige (QR und Text). */
    public function pendingSecret(array $pending): ?string
    {
        $dec = $this->keys->decrypt((string) $pending['totp_secret_enc'], 'pending:' . $pending['id_hash']);
        return $dec['plain'] ?? null;
    }

    public function cancel(string $raw): void
    {
        $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$this->tokenHash($raw)]);
    }

    /**
     * Schritt 3: TOTP-Code der Einrichtung prüfen und das Konto in einer Transaktion anlegen.
     *
     * @return array{status:string,userId:?int,codes:list<string>,sid:?string}
     *         status: ok | wrong | gone | taken | invalid
     */
    public function confirm(Request $req, string $raw, string $code): array
    {
        $fail = static fn (string $s): array => ['status' => $s, 'userId' => null, 'codes' => [], 'sid' => null];
        $p = $this->pending($raw);
        if ($p === null) {
            return $fail('gone');
        }
        $idHash = (string) $p['id_hash'];
        $secret = $this->pendingSecret($p);
        $step = $secret === null ? null : Totp::verify($secret, $code, $this->clock->now(), 0);
        if ($step === null) {
            $this->db->run('UPDATE pending_enrollments SET failures = failures + 1 WHERE id_hash = ?', [$idHash]);
            if ((int) $this->db->value('SELECT failures FROM pending_enrollments WHERE id_hash = ?', [$idHash]) >= self::MAX_FAILURES) {
                $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$idHash]);
                return $fail('gone');
            }
            return $fail('wrong');
        }

        $ip = ClientIp::forms($req->remoteAddr());
        $result = $this->db->tx(function () use ($p, $idHash, $secret, $step, $ip): array {
            $now = $this->clock->now();
            if ($this->db->value('SELECT 1 FROM pending_enrollments WHERE id_hash = ?', [$idHash]) === null) {
                return ['status' => 'gone', 'userId' => null];
            }
            $purpose = (string) $p['purpose'];
            $name = (string) $p['username'];
            $role = (string) $p['role'];
            if ($purpose === 'setup') {
                if ((int) $this->db->value('SELECT COUNT(*) FROM users') > 0 || $this->db->value("SELECT 1 FROM meta WHERE k = 'setup_done_at'") !== null) {
                    $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$idHash]);
                    return ['status' => 'gone', 'userId' => null];
                }
            } else {
                $used = $this->db->run(
                    'UPDATE invites SET used_at = ? WHERE id = ? AND used_at IS NULL AND expires_at > ?',
                    [$now, $p['invite_id'], $now],
                )->rowCount();
                if ($used !== 1) {
                    $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$idHash]);
                    return ['status' => 'invalid', 'userId' => null];
                }
            }

            if ($purpose === 'reenroll') {
                $inv = $this->db->one('SELECT user_id FROM invites WHERE id = ?', [$p['invite_id']]);
                $userId = (int) ($inv['user_id'] ?? 0);
                $this->db->run(
                    'UPDATE users SET password_hash = ?, totp_secret_enc = ?, totp_confirmed_at = ?, totp_last_step = ? WHERE id = ?',
                    [$p['password_hash'], $this->keys->encrypt((string) $secret, 'users:totp:' . $userId), $now, $step, $userId],
                );
                $this->sessions->destroyForUser($userId);
                $this->audit->log('totp_changed', $userId, 'via=reenroll', $ip['prefix']);
                $this->audit->log('password_changed', $userId, 'via=reenroll', $ip['prefix']);
            } else {
                if ($this->db->value('SELECT 1 FROM users WHERE username = ?', [$name]) !== null) {
                    $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$idHash]);
                    // Einladung wieder freigeben: der Link soll mit anderem Namen noch einmal gehen.
                    $this->db->run('UPDATE invites SET used_at = NULL WHERE id = ?', [$p['invite_id']]);
                    return ['status' => 'taken', 'userId' => null];
                }
                $this->db->run(
                    'INSERT INTO users(username, role, password_hash, totp_confirmed_at, totp_last_step, created_at, webauthn_handle) VALUES(?,?,?,?,?,?,?)',
                    [$name, $role, $p['password_hash'], $now, $step, $now, bin2hex(random_bytes(32))],
                );
                $userId = $this->db->lastId();
                // Die user_id steht fest, bevor das Geheimnis verschlüsselt wird (Zusatzdaten users:totp:<id>).
                $this->db->run('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [$this->keys->encrypt((string) $secret, 'users:totp:' . $userId), $userId]);
                if ($purpose === 'setup') {
                    $this->db->run("INSERT INTO meta(k,v) VALUES('setup_done_at', ?)", [(string) $now]);
                    $this->audit->log('setup_done', $userId, '', $ip['prefix']);
                } else {
                    $this->audit->log('invite_used', $userId, 'invite=' . (int) $p['invite_id'], $ip['prefix']);
                }
                $this->audit->log('account_created', $userId, 'role=' . $role, $ip['prefix']);
            }
            $this->db->run('DELETE FROM pending_enrollments WHERE id_hash = ?', [$idHash]);
            return ['status' => 'ok', 'userId' => $userId];
        });
        if ($result['status'] !== 'ok') {
            return $fail($result['status']);
        }
        $userId = (int) $result['userId'];
        $codes = $this->recovery->regenerate($userId);
        $s = $this->sessions->create($userId, 'full', $ip['prefix'], $req->userAgent());
        return ['status' => 'ok', 'userId' => $userId, 'codes' => $codes, 'sid' => $s['id']];
    }

    // --- Notfall-Rücksetzung für den Administrator (LK_RESET_TOKEN) ---

    /**
     * @return array{error:?string,link:?string} Der Link wird immer angezeigt; bei unbekanntem Namen ist er
     *         wirkungslos (gleiche Seite, kein Aufzählen).
     */
    public function emergencyReset(Request $req, string $token, string $username): array
    {
        $gate = $this->tokenGate($req);
        if (!$gate['ok']) {
            return ['error' => 'Bitte später noch einmal versuchen.', 'link' => null];
        }
        if (!$this->resetOpen() || !hash_equals($this->config->resetToken(), $token)) {
            return ['error' => 'Das Token stimmt nicht.', 'link' => null];
        }
        $name = Passwords::normalizeUsername($username);
        $user = $this->db->one('SELECT id, role FROM users WHERE username = ?', [$name]);
        $raw = bin2hex(random_bytes(32));
        $ip = ClientIp::forms($req->remoteAddr());
        if ($user !== null) {
            $this->db->tx(function () use ($user, $raw, $token, $ip): void {
                $this->revokeCredentials((int) $user['id']);
                $this->db->run(
                    'INSERT INTO invites(token_hash, purpose, user_id, role, expires_at, created_by, created_at) VALUES(?,?,?,?,?,NULL,?)',
                    [$this->tokenHash($raw), 'reenroll', $user['id'], $user['role'], $this->clock->now() + self::INVITE_TTL, $this->clock->now()],
                );
                $this->db->run('INSERT INTO meta(k,v) VALUES(?, ?)', ['reset_used:' . self::markerHash($token), (string) $this->clock->now()]);
                $this->audit->log('emergency_reset', (int) $user['id'], '', $ip['prefix']);
            });
            $this->limiter->release($gate['ids']);
        }
        return ['error' => null, 'link' => $this->config->baseUrl() . '/admin/invite/' . $raw];
    }

    /** TOTP, Wiederherstellungscodes und Sitzungen eines Kontos sofort ungültig machen (Rücksetzung). */
    public function revokeCredentials(int $userId): void
    {
        $this->db->run('UPDATE users SET totp_secret_enc = NULL, totp_confirmed_at = NULL WHERE id = ?', [$userId]);
        $this->db->run('DELETE FROM recovery_codes WHERE user_id = ?', [$userId]);
        $this->db->run('UPDATE invites SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL', [$this->clock->now(), $userId, 'reenroll']);
        $this->sessions->destroyForUser($userId);
    }
}
