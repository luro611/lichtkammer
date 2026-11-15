<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/** Kontoverwaltung durch Administratoren: Einladung, Rücksetz-Link, Sperren, Rolle. Konten werden nie gelöscht. */
final class Accounts
{
    public function __construct(
        private readonly Database $db,
        private readonly Keys $keys,
        private readonly SessionStore $sessions,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT id, username, display_name, role, totp_confirmed_at, disabled_at, created_at FROM users ORDER BY id');
    }

    /** @return string Einladungs-Token (Klartext, wird nur einmal angezeigt) */
    public function createInvite(int $adminId, string $role, string $ipPrefix): string
    {
        if (!in_array($role, ['admin', 'editor'], true)) {
            throw new \InvalidArgumentException('Unbekannte Rolle');
        }
        $raw = bin2hex(random_bytes(32));
        $now = $this->clock->now();
        $this->db->tx(function () use ($raw, $role, $adminId, $now, $ipPrefix): void {
            $this->db->run(
                'INSERT INTO invites(token_hash, purpose, role, expires_at, created_by, created_at) VALUES(?,?,?,?,?,?)',
                [$this->keys->hmac('ids', $raw), 'new_account', $role, $now + Enrollment::INVITE_TTL, $adminId, $now],
            );
            $this->audit->log('invite_created', $adminId, 'role=' . $role, $ipPrefix);
        });
        return $raw;
    }

    /**
     * Rücksetz-Link für ein anderes Konto: TOTP, Wiederherstellungscodes und Sitzungen werden sofort ungültig.
     *
     * @return string|null Token (Klartext) oder null, wenn nicht erlaubt
     */
    public function createReenroll(int $adminId, int $targetId, string $ipPrefix): ?string
    {
        if ($adminId === $targetId) {
            return null; // eigenes Konto: Passwort ändern bzw. Notfallweg
        }
        $raw = bin2hex(random_bytes(32));
        $ok = $this->db->tx(function () use ($raw, $adminId, $targetId, $ipPrefix): bool {
            $t = $this->db->one('SELECT id, role FROM users WHERE id = ?', [$targetId]);
            if ($t === null) {
                return false;
            }
            $now = $this->clock->now();
            $this->db->run('UPDATE users SET totp_secret_enc = NULL, totp_confirmed_at = NULL WHERE id = ?', [$targetId]);
            $this->db->run('DELETE FROM recovery_codes WHERE user_id = ?', [$targetId]);
            $this->db->run("UPDATE invites SET used_at = ? WHERE user_id = ? AND purpose = 'reenroll' AND used_at IS NULL", [$now, $targetId]);
            $this->sessions->destroyForUser($targetId);
            $this->db->run(
                'INSERT INTO invites(token_hash, purpose, user_id, role, expires_at, created_by, created_at) VALUES(?,?,?,?,?,?,?)',
                [$this->keys->hmac('ids', $raw), 'reenroll', $targetId, $t['role'], $now + Enrollment::INVITE_TTL, $adminId, $now],
            );
            $this->audit->log('account_reset', $targetId, 'by=' . $adminId, $ipPrefix);
            return true;
        });
        return $ok ? $raw : null;
    }

    /** Sperrt/entsperrt. Nicht sich selbst, nicht den letzten aktiven Administrator (Prüfung in derselben Transaktion). */
    public function setDisabled(int $adminId, int $targetId, bool $disabled, string $ipPrefix): ?string
    {
        if ($adminId === $targetId) {
            return 'Du kannst dich nicht selbst sperren.';
        }
        return $this->db->tx(function () use ($adminId, $targetId, $disabled, $ipPrefix): ?string {
            $t = $this->db->one('SELECT id, role, disabled_at FROM users WHERE id = ?', [$targetId]);
            if ($t === null) {
                return 'Konto nicht gefunden.';
            }
            if ($disabled && $t['role'] === 'admin' && $t['disabled_at'] === null && $this->otherActiveAdmins($targetId) < 1) {
                return 'Das ist der letzte aktive Administrator.';
            }
            $this->db->run('UPDATE users SET disabled_at = ? WHERE id = ?', [$disabled ? $this->clock->now() : null, $targetId]);
            if ($disabled) {
                $this->sessions->destroyForUser($targetId);
                $this->voidInvitesBy($targetId);
            }
            $this->audit->log($disabled ? 'account_disabled' : 'account_enabled', $targetId, 'by=' . $adminId, $ipPrefix);
            return null;
        });
    }

    public function setRole(int $adminId, int $targetId, string $role, string $ipPrefix): ?string
    {
        if (!in_array($role, ['admin', 'editor'], true)) {
            return 'Unbekannte Rolle.';
        }
        return $this->db->tx(function () use ($adminId, $targetId, $role, $ipPrefix): ?string {
            $t = $this->db->one('SELECT id, role, disabled_at FROM users WHERE id = ?', [$targetId]);
            if ($t === null) {
                return 'Konto nicht gefunden.';
            }
            if ($t['role'] === 'admin' && $role !== 'admin' && $t['disabled_at'] === null && $this->otherActiveAdmins($targetId) < 1) {
                return 'Das ist der letzte aktive Administrator.';
            }
            $this->db->run('UPDATE users SET role = ? WHERE id = ?', [$role, $targetId]);
            if ($role !== 'admin') {
                $this->voidInvitesBy($targetId);
            }
            $this->audit->log('role_changed', $targetId, 'role=' . $role . ',by=' . $adminId, $ipPrefix);
            return null;
        });
    }

    /** Andere Administratoren, die sich auch anmelden können (aktiv und mit bestätigtem TOTP). */
    private function otherActiveAdmins(int $exceptId): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND disabled_at IS NULL AND totp_confirmed_at IS NOT NULL AND id <> ?", [$exceptId]);
    }

    /** Offene Einladungen eines gesperrten oder herabgestuften Administrators gelten nicht mehr. */
    private function voidInvitesBy(int $userId): void
    {
        $this->db->run("UPDATE invites SET used_at = ? WHERE created_by = ? AND purpose = 'new_account' AND used_at IS NULL", [$this->clock->now(), $userId]);
    }

    /** @return int|null Zahl der beendeten Sitzungen, null wenn es das Konto nicht gibt */
    public function endSessions(int $adminId, int $targetId, string $ipPrefix): ?int
    {
        return $this->db->tx(function () use ($adminId, $targetId, $ipPrefix): ?int {
            if ($this->db->value('SELECT 1 FROM users WHERE id = ?', [$targetId]) === null) {
                return null;
            }
            $n = $this->sessions->destroyForUser($targetId);
            $this->audit->log('sessions_ended', $targetId, 'by=' . $adminId . ',n=' . $n, $ipPrefix);
            return $n;
        });
    }
}
