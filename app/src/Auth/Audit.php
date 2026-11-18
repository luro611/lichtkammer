<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

/**
 * Protokoll, nur zum Anhängen (SQLite-Trigger) mit HMAC-Kette. event und detail stammen nur aus einem festen
 * Vokabular bzw. sind Kennungen; nie Eingaben, Kopfzeilen, Passwörter, Codes oder Token.
 */
final class Audit
{
    public const EVENTS = [
        'login_ok', 'login_failed', 'login_locked', 'totp_failed', 'twofa_locked', 'recovery_used', 'logout',
        'password_changed', 'totp_changed', 'recovery_regenerated', 'account_created', 'account_disabled', 'account_enabled',
        'account_reset', 'invite_created', 'invite_used', 'setup_done', 'emergency_reset', 'global_brake',
        'sessions_ended', 'key_rotated', 'new_environment', 'role_changed', 'account_under_attack', 'photo_uploaded',
        'gallery_saved', 'gallery_deleted', 'gallery_published', 'photos_saved', 'photos_trashed', 'photos_restored', 'photos_purged',
        'page_saved', 'page_deleted', 'settings_saved', 'site_published', 'site_rolled_back', 'import_run', 'import_finished', 'import_purged',
        'device_trusted', 'device_trust_used', 'device_trust_rejected',
        'folder_created', 'folder_renamed', 'media_moved', 'media_trashed', 'media_restored', 'media_purged', 'file_uploaded', 'file_renamed', 'file_public_changed', 'folder_gallery_created', 'media_upload_run', 'backup_restored', 'originals_restored',
    ];

    public function __construct(private readonly Database $db, private readonly Keys $keys, private readonly Clock $clock)
    {
    }

    public function log(string $event, ?int $userId = null, string $detail = '', string $ipPrefix = ''): void
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unbekanntes Protokollereignis');
        }
        if (preg_match('/^[a-z0-9_:.=,\/-]{0,120}$/', $detail) !== 1) {
            throw new \InvalidArgumentException('Ungültige Protokolldetails');
        }
        if (preg_match('~^(unbekannt|[0-9a-f:.]{2,45}/\d{1,3})?$~', $ipPrefix) !== 1) {
            throw new \InvalidArgumentException('Ungültiges Adresspräfix');
        }
        $this->db->tx(function () use ($event, $userId, $detail, $ipPrefix): void {
            $prev = (string) ($this->db->value('SELECT chain FROM audit_log ORDER BY id DESC LIMIT 1') ?? 'genesis');
            $at = $this->clock->now();
            $chain = $this->chain($this->keys->kid(), $prev, $at, $userId, $event, $detail, $ipPrefix);
            $this->db->run(
                'INSERT INTO audit_log(at, user_id, event, detail, ip_prefix, chain) VALUES(?,?,?,?,?,?)',
                [$at, $userId, $event, $detail, $ipPrefix, $chain],
            );
        });
    }

    private function chain(string $kid, string $prev, int $at, ?int $userId, string $event, string $detail, string $ip): string
    {
        return $this->keys->hmac('audit', $prev . '|' . $at . '|' . ($userId ?? '') . '|' . $event . '|' . $detail . '|' . $ip, $kid);
    }

    /**
     * Prüft die Kette. Nach einer Schlüsselrotation werden ältere Einträge mit dem alten Schlüssel geprüft.
     *
     * @return array{ok:bool,count:int,last_at:?int,broken_at:?int}
     */
    public function verify(): array
    {
        $prev = 'genesis';
        $count = 0;
        $lastAt = null;
        $active = $this->keys->kids();
        foreach ($this->db->pdo->query('SELECT id, at, user_id, event, detail, ip_prefix, chain FROM audit_log ORDER BY id') as $row) {
            $uid = $row['user_id'] === null ? null : (int) $row['user_id'];
            $match = null;
            foreach ($active as $kid) {
                if (hash_equals($this->chain($kid, $prev, (int) $row['at'], $uid, $row['event'], $row['detail'], $row['ip_prefix']), $row['chain'])) {
                    $match = $kid;
                    break;
                }
            }
            if ($match === null) {
                return ['ok' => false, 'count' => $count, 'last_at' => $lastAt, 'broken_at' => (int) $row['id']];
            }
            // den Schlüssel, der gepasst hat, als nächstes zuerst versuchen
            $active = array_values(array_unique(array_merge([$match], $active)));
            $prev = $row['chain'];
            $count++;
            $lastAt = (int) $row['at'];
        }
        return ['ok' => true, 'count' => $count, 'last_at' => $lastAt, 'broken_at' => null];
    }
}
