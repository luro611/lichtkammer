<?php
declare(strict_types=1);

namespace Lichtkammer\Backup;

use Lichtkammer\Auth\Audit;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Keys;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Config;

/**
 * Sicherung einspielen (Entwurf 20, H2/E12), auch auf all-inkl ohne SSH.
 *
 * Eine Sicherung liegt als Ordner vor (Bereitstellungsordner, per FTPS befüllbar, gesperrt wie import/):
 *   MANIFEST.json       {"format":1,"schema_version":N,"app_version":"…","created_at":Unix}
 *   lichtkammer.sqlite  Datenbank (aus VACUUM INTO)
 *   files/…             private Dateien (optional)
 *   originals.sha256    Zeilen "<sha256>  <xx/zufallsname.ext>" (optional)
 *   originals/xx/…      Originale (optional, werden in Schritt 2 verschoben)
 *
 * Schritt 1 (Datenbank, Dateien): nur bei der Ersteinrichtung mit Einrichtungs-Token, solange es keine Konten gibt.
 * Schritt 2 (Originale): danach durch angemeldete Administratoren, in Stücken, mit Prüfsumme je Datei.
 * Nie überschreiben: eine vorhandene Datenbank wird beiseitegelegt, ein vorhandenes Original nie ersetzt.
 */
final class Restore
{
    public const FORMAT = 1;
    private const BUDGET = 25;
    private const ORIG_REL = '~^[0-9a-f]{2}/[0-9a-f]{24}\.[a-z0-9]{2,5}$~';

    public function __construct(private readonly Config $config)
    {
    }

    public function dir(): string
    {
        return $this->config->restoreDir();
    }

    /** Legt den Bereitstellungsordner an und sperrt ihn (wie import/). */
    public function prepareDir(): void
    {
        $d = $this->dir();
        if (!is_dir($d)) {
            $old = umask(0077);
            @mkdir($d, 0700, true);
            umask($old);
        }
        if (is_dir($d) && !is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Require all denied\n");
            @file_put_contents($d . '/index.html', '');
        }
    }

    /**
     * @return array{state:'none'|'invalid'|'ok',error:string,manifest:?array<string,mixed>,originals:int,hasFiles:bool}
     */
    public function inspect(): array
    {
        $d = $this->dir();
        $out = ['state' => 'none', 'error' => '', 'manifest' => null, 'originals' => 0, 'hasFiles' => is_dir($d . '/files')];
        if (!is_file($d . '/MANIFEST.json') && !is_file($d . '/lichtkammer.sqlite')) {
            return $out;
        }
        $out['state'] = 'invalid';
        $raw = @file_get_contents($d . '/MANIFEST.json');
        $m = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($m) || ($m['format'] ?? null) !== self::FORMAT || !is_int($m['schema_version'] ?? null)) {
            $out['error'] = 'MANIFEST.json fehlt oder hat ein unbekanntes Format.';
            return $out;
        }
        if (!is_file($d . '/lichtkammer.sqlite') || is_link($d . '/lichtkammer.sqlite')) {
            $out['error'] = 'lichtkammer.sqlite fehlt im Bereitstellungsordner.';
            return $out;
        }
        if ($m['schema_version'] > count(Schema::migrations())) {
            $out['error'] = 'Die Sicherung stammt von einer neueren Version von Lichtkammer (Schema ' . $m['schema_version'] . '). Bitte zuerst die passende oder neuere Version installieren.';
            return $out;
        }
        try {
            $out['originals'] = count($this->originalList());
        } catch (RestoreException $e) {
            $out['error'] = $e->getMessage();
            return $out;
        }
        $out['manifest'] = $m;
        $out['state'] = 'ok';
        return $out;
    }

    /**
     * Schritt 1: Datenbank und private Dateien einspielen. Nur ohne vorhandene Konten (die Prüfung der Einrichtung
     * mit Token macht der Aufrufer, hier wird sie für die Datenbank noch einmal erzwungen).
     *
     * @return array{users:int,photos:int,schema:int,files:int,originals:int}
     * @throws RestoreException
     */
    public function restoreDatabase(Database $current, Keys $keys, Clock $clock, string $ipPrefix = ''): array
    {
        $info = $this->inspect();
        if ($info['state'] !== 'ok') {
            throw new RestoreException($info['error'] !== '' ? $info['error'] : 'Es liegt keine Sicherung im Bereitstellungsordner.');
        }
        if ((int) $current->value('SELECT COUNT(*) FROM users') !== 0) {
            throw new RestoreException('Es gibt schon Konten. Eine Sicherung wird nur bei der Ersteinrichtung eingespielt.');
        }
        $dataDir = $this->config->dataDir();
        $live = $dataDir . '/lichtkammer.sqlite';
        $work = $dataDir . '/wiederherstellung-' . bin2hex(random_bytes(4)) . '.sqlite';
        $old = umask(0077);
        try {
            if (!@copy($this->dir() . '/lichtkammer.sqlite', $work)) {
                throw new RestoreException('Die Datenbank der Sicherung konnte nicht gelesen werden.');
            }
            @chmod($work, 0600);
            $stats = $this->validate($work, $keys);
        } catch (\Throwable $t) {
            @unlink($work);
            throw $t instanceof RestoreException ? $t : new RestoreException('Die Datenbank der Sicherung ist nicht lesbar oder beschädigt.');
        } finally {
            umask($old);
        }

        // Aktuelle (leere) Datenbank beiseitelegen, nie löschen; dann die geprüfte Kopie an ihre Stelle.
        $aside = $live . '.vor-wiederherstellung-' . $clock->now();
        foreach (['-wal', '-shm'] as $suffix) {
            @unlink($live . $suffix);
        }
        if (is_file($live) && !@rename($live, $aside)) {
            @unlink($work);
            throw new RestoreException('Die vorhandene Datenbank konnte nicht beiseitegelegt werden. Bitte Schreibrechte prüfen.');
        }
        if (!@rename($work, $live)) {
            @rename($aside, $live); // zurück
            @unlink($work);
            throw new RestoreException('Die Datenbank konnte nicht eingespielt werden. Der vorherige Zustand ist wiederhergestellt.');
        }
        $db = Database::open($live, $this->config->sqliteWal()); // migriert bei älterem Schema (mit Kopie vor der Migration)
        $db->tx(static function () use ($db): void {
            // Sitzungen, vertraute Geräte, offene Einladungen und Zwischenstände gehören nicht in einen neuen Server (Prüfung 21, M6).
            foreach (['sessions', 'trusted_devices', 'pending_enrollments', 'login_attempts', 'login_failures'] as $t) {
                $db->run("DELETE FROM $t");
            }
            $db->run('DELETE FROM invites WHERE used_at IS NULL');
        });
        $files = $this->copyFiles();
        (new Audit($db, $keys, $clock))->log('backup_restored', null, 'users=' . $stats['users'] . ',photos=' . $stats['photos'] . ',schema=' . $stats['schema'], $ipPrefix);
        @unlink($this->dir() . '/lichtkammer.sqlite'); // die Datenbank soll nicht länger im Webroot liegen
        return ['users' => $stats['users'], 'photos' => $stats['photos'], 'schema' => $stats['schema'], 'files' => $files, 'originals' => $info['originals']];
    }

    /** @return array{users:int,photos:int,schema:int} @throws RestoreException */
    private function validate(string $file, Keys $keys): array
    {
        $pdo = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $ic = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($ic !== 'ok') {
            throw new RestoreException('Die Datenbank der Sicherung ist beschädigt (Integritätsprüfung).');
        }
        $has = static fn (string $t): bool => $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetchColumn() !== false;
        if (!$has('meta') || !$has('users') || !$has('photos')) {
            throw new RestoreException('Das ist keine Lichtkammer-Datenbank.');
        }
        $schema = (int) $pdo->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn();
        if ($schema < 1 || $schema > count(Schema::migrations())) {
            throw new RestoreException('Die Sicherung hat ein unbekanntes Schema (' . $schema . '). Dazu passt diese Version nicht.');
        }
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($users < 1) {
            throw new RestoreException('Die Sicherung enthält keine Konten. Dann ist eine normale Ersteinrichtung der richtige Weg.');
        }
        // Passt LK_APP_KEY? Ohne den richtigen Schlüssel sind die TOTP-Geheimnisse unlesbar, niemand käme mehr hinein.
        foreach ($pdo->query('SELECT id, totp_secret_enc FROM users WHERE totp_secret_enc IS NOT NULL LIMIT 5') as $u) {
            if ($keys->decrypt((string) $u['totp_secret_enc'], 'users:totp:' . $u['id']) === null) {
                throw new RestoreException('LK_APP_KEY passt nicht zu dieser Sicherung (die zweiten Faktoren lassen sich nicht entschlüsseln). Bitte den Schlüssel einsetzen, mit dem die Sicherung entstand (Passwort-Manager), und es noch einmal versuchen. Es wurde nichts verändert.');
            }
        }
        $photos = (int) $pdo->query('SELECT COUNT(*) FROM photos')->fetchColumn();
        $pdo = null;
        return ['users' => $users, 'photos' => $photos, 'schema' => $schema];
    }

    /** Private Dateien (files/) ohne Überschreiben nach <Datenordner>/files kopieren. */
    private function copyFiles(): int
    {
        $src = $this->dir() . '/files';
        if (!is_dir($src) || is_link($src)) {
            return 0;
        }
        $dst = $this->config->dataDir() . '/files';
        $n = 0;
        $base = strlen(rtrim($src, '/')) + 1;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $rel = substr($f->getPathname(), $base);
            if (preg_match('~^[A-Za-z0-9._/-]+$~', $rel) !== 1 || str_contains($rel, '..') || str_starts_with($rel, '.') || str_contains($rel, '/.')) {
                continue;
            }
            $to = $dst . '/' . $rel;
            if (file_exists($to)) {
                continue;
            }
            $old = umask(0077);
            if (!is_dir(dirname($to))) {
                @mkdir(dirname($to), 0700, true);
            }
            umask($old);
            if (@copy($f->getPathname(), $to)) {
                @chmod($to, 0600);
                $n++;
            }
        }
        return $n;
    }

    // ---------------------------------------------------------------- Schritt 2: Originale

    /** @return array<string,string> relativer Pfad => SHA-256 @throws RestoreException */
    public function originalList(): array
    {
        $file = $this->dir() . '/originals.sha256';
        if (!is_file($file)) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('~^([0-9a-f]{64})  (.+)$~', $line, $m) !== 1 || preg_match(self::ORIG_REL, $m[2]) !== 1) {
                throw new RestoreException('originals.sha256 ist fehlerhaft (nur Zeilen „<SHA-256>  xx/name.ext“ sind erlaubt).');
            }
            $out[$m[2]] = $m[1];
        }
        return $out;
    }

    /** Anzahl der Originale, die noch nicht am Ziel liegen. */
    public function pendingOriginals(): int
    {
        try {
            $list = $this->originalList();
        } catch (RestoreException) {
            return 0;
        }
        $n = 0;
        $dst = rtrim($this->config->originalsDir(), '/');
        foreach (array_keys($list) as $rel) {
            if (!is_file($dst . '/' . $rel) && is_file($this->dir() . '/originals/' . $rel)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Ein Durchgang: Originale aus dem Bereitstellungsordner an ihren Ort bringen. Jede Datei wird vor dem Verschieben
     * gegen die Liste geprüft. Nie überschreiben.
     *
     * @return array{moved:int,remaining:int,bad:int,missing:int}  bad = Prüfsumme falsch oder Konflikt, missing = in der Liste, aber nirgends
     * @throws RestoreException
     */
    public function moveOriginals(?int $budget = null): array
    {
        $list = $this->originalList();
        $dst = rtrim($this->config->originalsDir(), '/');
        $src = $this->dir() . '/originals';
        $deadline = microtime(true) + ($budget ?? self::BUDGET);
        $r = ['moved' => 0, 'remaining' => 0, 'bad' => 0, 'missing' => 0];
        foreach ($list as $rel => $sha) {
            $from = $src . '/' . $rel;
            $to = $dst . '/' . $rel;
            if (is_file($to)) { // schon da
                if (is_file($from) && !is_link($from) && hash_file('sha256', $to) === $sha) {
                    @unlink($from);
                }
                continue;
            }
            if (!is_file($from) || is_link($from)) {
                $r['missing']++;
                continue;
            }
            if (microtime(true) > $deadline) {
                $r['remaining']++;
                continue;
            }
            if (hash_file('sha256', $from) !== $sha) {
                $r['bad']++;
                continue;
            }
            $old = umask(0077);
            if (!is_dir(dirname($to))) {
                @mkdir(dirname($to), 0700, true);
            }
            umask($old);
            if (@rename($from, $to)) {
                $r['moved']++;
                continue;
            }
            $in = @fopen($from, 'rb');
            $out = @fopen($to, 'xb');
            $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) === filesize($from);
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
            if ($ok && hash_file('sha256', $to) === $sha) {
                @chmod($to, 0600);
                @unlink($from);
                $r['moved']++;
            } else {
                @unlink($to);
                $r['bad']++;
            }
        }
        return $r;
    }

    /** Fotos, deren Original weder am Ziel liegt noch in der Liste vorkommt (nach dem Einspielen prüfen). */
    public static function missingOriginals(Database $db, string $originalsDir): int
    {
        $n = 0;
        foreach ($db->all('SELECT original_path FROM photos') as $row) {
            if (!is_file(rtrim($originalsDir, '/') . '/' . $row['original_path'])) {
                $n++;
            }
        }
        return $n;
    }
}
