<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;
use Lichtkammer\Request;

/** Anmeldung in zwei Stufen (Passwort, dann TOTP oder Wiederherstellungscode), Abmeldung, Passwortänderung. */
final class Auth
{
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

    /** Scheinprüfung mit einem in meta gespeicherten Hash: ein Argon2-Lauf wie bei bekanntem Namen. */
    private function verifyDummy(string $password): void
    {
        $stored = $this->db->value("SELECT v FROM meta WHERE k = 'dummy_hash'");
        $used = $this->passwords->verifyDummy($password, is_string($stored) ? $stored : null);
        if ($used !== $stored) {
            $this->db->run("INSERT INTO meta(k, v) VALUES('dummy_hash', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [$used]);
        }
    }

    /** Wie lange ein Gerät nach erfolgreichem TOTP-Code ohne zweiten Faktor angemeldet werden darf (fest, wird nicht verlängert). */
    public const DEVICE_TTL = 86400;
    public const MAX_DEVICES = 10;

    private function deviceHash(string $raw): string
    {
        return $this->keys->hmac('ids', 'device:' . $raw);
    }

    /** Legt ein vertrautes Gerät an und gibt das Rohtoken fürs Cookie zurück (in der Datenbank steht nur der keyed Hash). */
    private function trustDevice(int $userId, Request $req, string $ipPrefix): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->db->tx(function () use ($raw, $userId, $req, $now): void {
            $this->db->run('DELETE FROM trusted_devices WHERE expires_at < ?', [$now]);
            $this->db->run(
                'DELETE FROM trusted_devices WHERE user_id = ? AND id_hash NOT IN (SELECT id_hash FROM trusted_devices WHERE user_id = ? ORDER BY created_at DESC, rowid DESC LIMIT ?)',
                [$userId, $userId, self::MAX_DEVICES - 1],
            );
            $this->db->run(
                'INSERT INTO trusted_devices(id_hash, user_id, created_at, expires_at, ua_hash) VALUES(?,?,?,?,?)',
                [$this->deviceHash($raw), $userId, $now, $now + self::DEVICE_TTL, hash('sha256', $req->userAgent())],
            );
        });
        $this->audit->log('device_trusted', $userId, 'hours=' . intdiv(self::DEVICE_TTL, 3600), $ipPrefix);
        return $raw;
    }

    private function isTrusted(int $userId, string $raw, Request $req): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
            return false;
        }
        $row = $this->db->one('SELECT user_id, expires_at, ua_hash FROM trusted_devices WHERE id_hash = ?', [$this->deviceHash($raw)]);
        return $row !== null && (int) $row['user_id'] === $userId && (int) $row['expires_at'] > $this->clock->now()
            && hash_equals((string) $row['ua_hash'], hash('sha256', $req->userAgent()));
    }

    /** Beendet das Vertrauen für dieses eine Gerät (beim Abmelden). */
    public function forgetDevice(string $raw): void
    {
        if ($raw !== '') {
            $this->db->run('DELETE FROM trusted_devices WHERE id_hash = ?', [$this->deviceHash($raw)]);
        }
    }

    /**
     * Stufe 1. Gleiche Antwort, gleicher Zeitaufwand und gleiche Zähler für unbekannte, gesperrte,
     * deaktivierte und TOTP-lose Konten.
     *
     * Mit gültigem Gerätetoken (Cookie lk_dev) entfällt die zweite Stufe: die Sitzung ist gleich voll ('trusted' = true).
     *
     * @return array{status:string,sid:?string,retry:int,trusted?:bool} status: ok | fail | blocked
     */
    public function login(Request $req, string $name, string $password, string $deviceToken = ''): array
    {
        $this->sessions->purge();
        $this->limiter->purge();
        $n = Passwords::normalizeUsername($name);
        $ip = ClientIp::forms($req->remoteAddr());
        $gate = $this->limiter->gate([
            ['kind' => 'userip', 'key' => $this->limiter->key('userip', $n . '|' . $ip['key']), 'lock' => RateLimiter::LOCK_AT, 'delay' => true],
            ['kind' => 'ip', 'key' => $this->limiter->key('ip', $ip['key']), 'lock' => RateLimiter::LOCK_AT_IP, 'delay' => false],
            ['kind' => 'user', 'key' => $this->limiter->key('user', $n), 'lock' => null, 'delay' => false], // nur zählen und melden (A2)
        ], true, $this->config->globalBrakeLimit(), $this->config->globalBrakeWindow());
        if (!$gate['ok']) {
            $this->noteBrake($gate['reason'], $ip['prefix']);
            return ['status' => 'blocked', 'sid' => null, 'retry' => $gate['retry']];
        }

        $user = $this->db->one('SELECT * FROM users WHERE username = ?', [$n]);
        if ($user !== null) {
            $passwordOk = $this->passwords->verify($password, (string) $user['password_hash']);
        } else {
            $this->verifyDummy($password);
            $passwordOk = false;
        }
        $usable = $user !== null && $passwordOk && $user['disabled_at'] === null && $user['totp_confirmed_at'] !== null;

        if (!$usable) {
            if ($user !== null) {
                $why = !$passwordOk ? 'password' : ($user['disabled_at'] !== null ? 'disabled' : 'no_totp');
                $this->audit->log('login_failed', (int) $user['id'], 'reason=' . $why, $ip['prefix']);
                if (in_array('userip', $gate['locked_now'], true)) {
                    $this->audit->log('login_locked', (int) $user['id'], 'stage=password', $ip['prefix']);
                }
                if ($gate['alarm']) {
                    $this->audit->log('account_under_attack', (int) $user['id'], 'attempts=' . RateLimiter::ALARM_USER, $ip['prefix']);
                }
            } else {
                $this->limiter->noteUnknownFailure($ip['key']);
            }
            return ['status' => 'fail', 'sid' => null, 'retry' => 0];
        }

        $this->limiter->release($gate['ids']);
        if ($this->passwords->needsRehash((string) $user['password_hash'])) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$this->passwords->hash($password), $user['id']]);
        }
        if ($this->isTrusted((int) $user['id'], $deviceToken, $req)) {
            $s = $this->sessions->create((int) $user['id'], 'full', $ip['prefix'], $req->userAgent());
            $this->audit->log('device_trust_used', (int) $user['id'], '', $ip['prefix']);
            $this->noteLogin((int) $user['id'], $ip['prefix']);
            return ['status' => 'ok', 'sid' => $s['id'], 'retry' => 0, 'trusted' => true];
        }
        $s = $this->sessions->create((int) $user['id'], 'password_ok', $ip['prefix'], $req->userAgent());
        return ['status' => 'ok', 'sid' => $s['id'], 'retry' => 0, 'trusted' => false];
    }

    /**
     * Stufe 2 mit TOTP-Code (6 Ziffern) oder Wiederherstellungscode.
     *
     * Mit $trust und gültigem TOTP-Code (nicht Wiederherstellungscode) entsteht ein Gerätetoken für 24 Stunden ('device').
     *
     * @return array{status:string,sid:?string,retry:int,recovery:bool,device?:string} status: ok | fail | blocked | expired
     */
    public function secondFactor(Request $req, string $sid, string $input, bool $trust = false): array
    {
        $s = $this->sessions->find($sid);
        if ($s === null || $s['stage'] !== 'password_ok') {
            return ['status' => 'expired', 'sid' => null, 'retry' => 0, 'recovery' => false];
        }
        $userId = (int) $s['user_id'];
        $ip = ClientIp::forms($req->remoteAddr());
        $gate = $this->limiter->gate([
            ['kind' => 'user2fa', 'key' => $this->limiter->key('user2fa', (string) $userId), 'lock' => RateLimiter::LOCK_AT, 'delay' => false],
        ], false, 0, 0);
        if (!$gate['ok']) {
            return ['status' => 'blocked', 'sid' => null, 'retry' => $gate['retry'], 'recovery' => false];
        }

        $isTotp = preg_match('/^\s*\d{3}\s?\d{3}\s*$/', $input) === 1;
        $ok = false;
        $usedRecovery = false;
        $reencrypt = null;
        if ($isTotp) {
            $user = $this->db->one('SELECT totp_secret_enc, totp_last_step FROM users WHERE id = ?', [$userId]);
            $dec = $user !== null && $user['totp_secret_enc'] !== null
                ? $this->keys->decrypt((string) $user['totp_secret_enc'], 'users:totp:' . $userId)
                : null;
            if ($dec !== null) {
                $step = Totp::verify($dec['plain'], $input, $this->clock->now(), (int) $user['totp_last_step']);
                if ($step !== null) {
                    $ok = $this->db->tx(fn (): bool => $this->db->run(
                        'UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?',
                        [$step, $userId, $step],
                    )->rowCount() === 1);
                    if ($ok && $dec['kid'] !== $this->keys->kid()) {
                        $reencrypt = $dec['plain'];
                    }
                }
            }
        } else {
            $ok = $this->recovery->consume($userId, $input);
            $usedRecovery = $ok;
        }

        if (!$ok) {
            $this->audit->log('totp_failed', $userId, $isTotp ? 'kind=totp' : 'kind=recovery', $ip['prefix']);
            if (in_array('user2fa', $gate['locked_now'], true)) {
                $this->audit->log('twofa_locked', $userId, '', $ip['prefix']);
            }
            $gone = $this->sessions->noteTotpFailure($sid);
            return ['status' => $gone ? 'expired' : 'fail', 'sid' => null, 'retry' => 0, 'recovery' => false];
        }

        $this->limiter->release($gate['ids']);
        if ($reencrypt !== null) {
            $this->db->run('UPDATE users SET totp_secret_enc = ? WHERE id = ?', [$this->keys->encrypt($reencrypt, 'users:totp:' . $userId), $userId]);
        }
        // Sitzungswechsel: die password_ok-Zeile verschwindet, eine neue (ID und CSRF neu) entsteht.
        $new = $this->db->tx(function () use ($sid, $userId, $ip, $req): array {
            $this->sessions->destroy($sid);
            return $this->sessions->create($userId, 'full', $ip['prefix'], $req->userAgent());
        });
        if ($usedRecovery) {
            $this->audit->log('recovery_used', $userId, '', $ip['prefix']);
        }
        $this->noteLogin($userId, $ip['prefix']);
        $res = ['status' => 'ok', 'sid' => $new['id'], 'retry' => 0, 'recovery' => $usedRecovery];
        if ($trust && $isTotp) {
            $res['device'] = $this->trustDevice($userId, $req, $ip['prefix']);
        }
        return $res;
    }

    private function noteLogin(int $userId, string $ipPrefix): void
    {
        $seen = $this->db->value("SELECT 1 FROM audit_log WHERE user_id = ? AND event = 'login_ok' AND ip_prefix = ? LIMIT 1", [$userId, $ipPrefix]);
        $any = $this->db->value("SELECT 1 FROM audit_log WHERE user_id = ? AND event = 'login_ok' LIMIT 1", [$userId]);
        $this->audit->log('login_ok', $userId, '', $ipPrefix);
        if ($any !== null && $seen === null) {
            $this->audit->log('new_environment', $userId, '', $ipPrefix);
        }
        $this->noteKeyRotation();
    }

    /** Merkt sich die Schlüsselkennung; ändert sie sich, entsteht die Nahtstelle „key_rotated“ im Protokoll. */
    private function noteKeyRotation(): void
    {
        $kid = $this->keys->kid();
        $old = $this->db->value("SELECT v FROM meta WHERE k = 'key_kid'");
        if ($old === $kid) {
            return;
        }
        $this->db->run("INSERT INTO meta(k,v) VALUES('key_kid', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [$kid]);
        if ($old !== null) {
            $this->audit->log('key_rotated', null, 'kid=' . $kid);
        }
    }

    public function logout(string $sid, ?int $userId, string $ipPrefix): void
    {
        $this->sessions->destroy($sid);
        if ($userId !== null) {
            $this->audit->log('logout', $userId, '', $ipPrefix);
        }
    }

    private function gate2fa(int $userId): array
    {
        return $this->limiter->gate([
            ['kind' => 'user2fa', 'key' => $this->limiter->key('user2fa', (string) $userId), 'lock' => RateLimiter::LOCK_AT, 'delay' => false],
        ], true, $this->config->globalBrakeLimit(), $this->config->globalBrakeWindow());
    }

    /**
     * Prüft Passwort (und optional TOTP) für sensible Aktionen. Zählt auf dem Zähler der zweiten Stufe.
     *
     * @return string|null Fehlermeldung oder null
     */
    private function reauthenticate(int $userId, string $password, ?string $totp): ?string
    {
        $gate = $this->gate2fa($userId);
        if (!$gate['ok']) {
            return 'Bitte später noch einmal versuchen.';
        }
        $user = $this->db->one('SELECT password_hash, totp_secret_enc, totp_last_step FROM users WHERE id = ?', [$userId]);
        if ($user === null || !$this->passwords->verify($password, (string) $user['password_hash'])) {
            return 'Das aktuelle Passwort stimmt nicht.';
        }
        if ($totp !== null) {
            $dec = $this->keys->decrypt((string) $user['totp_secret_enc'], 'users:totp:' . $userId);
            $step = $dec === null ? null : Totp::verify($dec['plain'], $totp, $this->clock->now(), (int) $user['totp_last_step']);
            $used = $step !== null && $this->db->tx(fn (): bool => $this->db->run(
                'UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?',
                [$step, $userId, $step],
            )->rowCount() === 1);
            if (!$used) {
                return 'Der Code stimmt nicht.';
            }
        }
        $this->limiter->release($gate['ids']);
        return null;
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public function changePassword(int $userId, string $sid, string $current, string $totp, string $new, string $ipPrefix): ?string
    {
        $username = (string) $this->db->value('SELECT username FROM users WHERE id = ?', [$userId]);
        $problem = $this->passwords->validate($new, $username);
        if ($problem !== null) {
            return $problem;
        }
        $err = $this->reauthenticate($userId, $current, $totp);
        if ($err !== null) {
            return $err;
        }
        $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [$this->passwords->hash($new), $userId]);
        $ended = $this->sessions->destroyForUser($userId, $sid);
        $this->audit->log('password_changed', $userId, 'sessions_ended=' . $ended, $ipPrefix);
        return null;
    }

    /** @return array{error:?string,codes:list<string>} */
    public function regenerateCodes(int $userId, string $password, string $ipPrefix): array
    {
        $err = $this->reauthenticate($userId, $password, null);
        if ($err !== null) {
            return ['error' => $err, 'codes' => []];
        }
        $codes = $this->recovery->regenerate($userId);
        $this->audit->log('recovery_regenerated', $userId, '', $ipPrefix);
        return ['error' => null, 'codes' => $codes];
    }

    /** Die globale Bremse höchstens einmal je Minute ins Protokoll schreiben (kein Füllen durch Angreifer). */
    private function noteBrake(string $reason, string $ipPrefix): void
    {
        if ($reason !== 'global') {
            return;
        }
        $last = (int) ($this->db->value("SELECT v FROM meta WHERE k = 'brake_logged_at'") ?? 0);
        $now = $this->clock->now();
        if ($now - $last >= 60) {
            $this->db->run("INSERT INTO meta(k,v) VALUES('brake_logged_at', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [(string) $now]);
            $this->audit->log('global_brake', null, 'limit=' . $this->config->globalBrakeLimit(), $ipPrefix);
        }
    }
}
