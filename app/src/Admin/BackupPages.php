<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Auth\RateLimiter;
use Lichtkammer\Backup\Backup;
use Lichtkammer\Request;
use Lichtkammer\Response;

/**
 * Sicherung herunterladen (Entwurf 20, 8b). Nur Administratoren. Jeder Abruf braucht eine frische Bestätigung mit Passwort
 * und Code aus der App (10 Minuten, gebunden an die Sitzung, Cookie lk_bk), ist ratenbegrenzt und steht im Protokoll.
 * Die Seite zeigt nur Zahlen; Pfade und Dateinamen kommen nie aus Eingaben.
 */
trait BackupPages
{
    private const BK_COOKIE = 'lk_bk';
    private const BK_TTL = 600;

    private function backupMac(array $sess, int $until): string
    {
        return $this->s->keys->hmac('csrf', 'backup-ok|' . $sess['id_hash'] . '|' . (int) $sess['user_id'] . '|' . $until);
    }

    /** Bis wann die Bestätigung gilt, oder null. */
    private function backupUntil(Request $req, array $sess): ?int
    {
        $raw = $req->cookie($this->s->cookies->name(self::BK_COOKIE));
        if (preg_match('/^(\d{10})\.([0-9a-f]{64})$/', $raw, $m) !== 1) {
            return null;
        }
        $until = (int) $m[1];
        if ($until <= $this->s->clock->now() || $until > $this->s->clock->now() + self::BK_TTL + 5) {
            return null;
        }
        return hash_equals($this->backupMac($sess, $until), $m[2]) ? $until : null;
    }

    private function backup(): Backup
    {
        return new Backup($this->config, $this->s->db, $this->s->clock);
    }

    /** @param array<string,mixed> $sess */
    private function backupView(Request $req, array $sess, ?string $error = null, int $status = 200): Response
    {
        $until = $this->backupUntil($req, $sess);
        return $this->page('admin/backup', [
            'title' => 'Sicherung', 'error' => $error, 'sum' => $this->backup()->summary(),
            'confirmed' => $until !== null, 'left' => $until !== null ? max(0, $until - $this->s->clock->now()) : 0,
            'now' => $this->s->clock->now(), 'partMb' => intdiv($this->backup()->partBytes(), 1048576),
        ], $status, $sess);
    }

    /** @return Response|null eine Abweisung oder null (weiter) */
    private function backupGate(Request $req, array $sess, string $kind, int $limit): ?Response
    {
        if ($this->backupUntil($req, $sess) === null) {
            return $this->backupView($req, $sess, 'Bitte zuerst mit Passwort und Code bestätigen.', 403);
        }
        $g = $this->s->limiter->gate([['kind' => $kind, 'key' => $this->s->limiter->key($kind, (string) (int) $sess['user_id']), 'lock' => $limit, 'delay' => false]], false, 1, 1);
        if (!$g['ok']) {
            return $this->page('admin/message', ['title' => 'Bitte warten', 'heading' => 'Bitte kurz warten', 'text' => 'Es wurden in kurzer Zeit zu viele Sicherungen abgerufen. In etwa ' . max(1, (int) ceil($g['retry'] / 60)) . ' Minuten geht es weiter.'], 429, $sess)
                ->withHeaders(['Retry-After' => (string) max(1, $g['retry'])]);
        }
        return null;
    }

    public function backupPage(Request $req, array $p, ?array $sess): Response
    {
        return $this->backupView($req, $sess);
    }

    public function backupConfirm(Request $req, array $p, ?array $sess): Response
    {
        $err = $this->s->auth->confirmSensitive((int) $sess['user_id'], $req->input('current_password'), $req->input('code'));
        if ($err !== null) {
            return $this->backupView($req, $sess, $err, 400);
        }
        $until = $this->s->clock->now() + self::BK_TTL;
        $this->auditLog('backup_confirmed', $sess, '', $req);
        return Response::redirect('/admin/sicherung')->withCookie($this->s->cookies->set(self::BK_COOKIE, $until . '.' . $this->backupMac($sess, $until), self::BK_TTL));
    }

    public function backupPackage(Request $req, array $p, ?array $sess): Response
    {
        if (($r = $this->backupGate($req, $sess, 'backupz', 6)) !== null) {
            return $r;
        }
        $b = $this->backup();
        try {
            $pkg = $b->package();
        } catch (\RuntimeException $e) {
            error_log('Lichtkammer: Sicherung: ' . $e->getMessage());
            return $this->backupView($req, $sess, 'Die Sicherung konnte nicht erzeugt werden. Ist im Datenordner noch Platz? Genaueres steht im Fehlerprotokoll des Servers.', 500);
        }
        $this->auditLog('backup_package', $sess, 'bytes=' . $pkg['length'] . ',files=' . $pkg['files'] . ',orig=' . $pkg['originals'], $req);
        return Response::generated($pkg['length'], self::backupHeaders('lichtkammer-sicherung-' . gmdate('Ymd-His', $this->s->clock->now()) . '.tar'), $pkg['emit']);
    }

    public function backupPart(Request $req, array $p, ?array $sess): Response
    {
        $nach = $req->query('nach');
        if ($nach !== '' && (!ctype_digit($nach) || strlen($nach) > 9)) {
            return Response::notFound();
        }
        if (($r = $this->backupGate($req, $sess, 'backupo', 120)) !== null) {
            return $r;
        }
        $part = $this->backup()->originalsPart((int) $nach);
        if ($part === null) {
            return $this->missing($sess);
        }
        $this->auditLog('backup_originals', $sess, 'after=' . (int) $nach . ',n=' . $part['count'] . ',bytes=' . $part['bytes'], $req);
        return Response::generated($part['length'], self::backupHeaders('lichtkammer-originale-ab-' . (int) $nach . '.tar'), $part['emit']);
    }

    public function backupOriginal(Request $req, array $p, ?array $sess): Response
    {
        $id = $this->idParam((string) ($p['id'] ?? ''));
        if ($id === null) {
            return Response::notFound();
        }
        if (($r = $this->backupGate($req, $sess, 'backupo', 120)) !== null) {
            return $r;
        }
        $f = $this->backup()->originalFile($id);
        if ($f === null) {
            return $this->missing($sess);
        }
        $size = $f['size'];
        $from = 0;
        $len = $size;
        $status = 200;
        $headers = self::backupHeaders('original-' . $id . '.' . pathinfo($f['rel'], PATHINFO_EXTENSION)) + ['Accept-Ranges' => 'bytes'];
        $range = trim($req->header('Range'));
        if ($range !== '') {
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
                return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            if ($m[1] === '') {
                $from = $size - min($size, (int) $m[2]);
                $end = $size - 1;
            } else {
                $from = (int) $m[1];
                $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
            }
            if ($from >= $size || $end < $from) {
                return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $len = $end - $from + 1;
            $status = 206;
            $headers['Content-Range'] = 'bytes ' . $from . '-' . $end . '/' . $size;
        }
        if ($from === 0) { // nur den Anfang protokollieren, nicht jedes Stück eines Fortsetzens
            $this->auditLog('backup_originals', $sess, 'photo=' . $id . ',bytes=' . $size, $req);
        }
        return Response::file($f['abs'], $from, $len, $status, $headers);
    }

    /** @return array<string,string> */
    private static function backupHeaders(string $name): array
    {
        return [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
