<?php
declare(strict_types=1);

namespace Lichtkammer\Backup;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Config;

/**
 * Sicherung erzeugen (Entwurf 20, 8b). Liefert genau das Format, das Restore einspielt (docs/betrieb/Wiederherstellung.md).
 *
 * Paket (ein TAR-Strom, ohne Originale): MANIFEST.json, originals.sha256, files/…, lichtkammer.sqlite (aus VACUUM INTO).
 * Originale: in Teilen (je ein TAR-Strom, höchstens etwa LK_BACKUP_PART_MB groß, Fortsetzen über „nach Foto-Nummer“)
 * und einzeln (mit Range). Pfade kommen nie aus Eingaben, nur aus der Datenbank, und werden vor dem Lesen geprüft.
 * Originale enthalten alle Metadaten (auch GPS): nie öffentlich, nur über angemeldete Administratoren.
 */
final class Backup
{
    private const CHUNK = 65536;
    private const TMP_PREFIX = 'sicherung-';

    public function __construct(private readonly Config $config, private readonly Database $db, private readonly Clock $clock)
    {
    }

    /** Größe eines Teils der Originale in Bytes (Zeitgrenze des Webservers). */
    public function partBytes(): int
    {
        return max(10, min(500, $this->config->int('LK_BACKUP_PART_MB', 150))) * 1048576;
    }

    /**
     * Die Originale, die wirklich als Datei vorliegen, in Reihenfolge der Foto-Nummer.
     *
     * @return array{rows:list<array{id:int,rel:string,sha:string,abs:string,size:int}>,absent:int,odd:int}
     */
    public function originals(): array
    {
        $dir = $this->originalsRoot();
        $rows = [];
        $absent = 0;
        $odd = 0;
        foreach ($this->db->all('SELECT id, original_path, sha256 FROM photos ORDER BY id') as $r) {
            $rel = (string) $r['original_path'];
            if (preg_match(Restore::ORIG_REL, $rel) !== 1 || preg_match('/^[0-9a-f]{64}$/', (string) $r['sha256']) !== 1) {
                $odd++;
                continue;
            }
            $abs = $dir . '/' . $rel;
            if ($dir === '' || !is_file($abs) || is_link($abs)) {
                $absent++;
                continue;
            }
            $rows[] = ['id' => (int) $r['id'], 'rel' => $rel, 'sha' => (string) $r['sha256'], 'abs' => $abs, 'size' => (int) filesize($abs)];
        }
        return ['rows' => $rows, 'absent' => $absent, 'odd' => $odd];
    }

    private function originalsRoot(): string
    {
        $r = realpath($this->config->originalsDir());
        return $r === false ? '' : rtrim($r, '/');
    }

    /** @return list<array{rel:string,abs:string,size:int}> private Dateien (files/), nur sichere Namen */
    public function privateFiles(): array
    {
        $src = $this->config->dataDir() . '/files';
        if (!is_dir($src) || is_link($src)) {
            return [];
        }
        $out = [];
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
            $out[] = ['rel' => $rel, 'abs' => $f->getPathname(), 'size' => (int) $f->getSize()];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['rel'], $b['rel']));
        return $out;
    }

    /**
     * Übersicht für die Seite.
     *
     * @return array{dbBytes:int,files:int,fileBytes:int,originals:int,originalBytes:int,absent:int,odd:int,parts:list<array{after:int,count:int,bytes:int}>,lastAt:?int}
     */
    public function summary(): array
    {
        $o = $this->originals();
        $files = $this->privateFiles();
        $live = $this->config->dataDir() . '/lichtkammer.sqlite';
        $last = $this->db->value("SELECT v FROM meta WHERE k = 'last_backup_at'");
        return [
            'dbBytes' => is_file($live) ? (int) filesize($live) : 0,
            'files' => count($files),
            'fileBytes' => array_sum(array_column($files, 'size')),
            'originals' => count($o['rows']),
            'originalBytes' => array_sum(array_column($o['rows'], 'size')),
            'absent' => $o['absent'],
            'odd' => $o['odd'],
            'parts' => $this->parts($o['rows']),
            'lastAt' => $last === null ? null : (int) $last,
        ];
    }

    /**
     * @param list<array{id:int,rel:string,sha:string,abs:string,size:int}> $rows
     * @return list<array{after:int,count:int,bytes:int}>  after = Foto-Nummer, nach der der Teil beginnt
     */
    public function parts(array $rows): array
    {
        $parts = [];
        $after = 0;
        $cur = ['after' => 0, 'count' => 0, 'bytes' => 0];
        foreach ($rows as $r) {
            if ($cur['count'] > 0 && $cur['bytes'] + $r['size'] > $this->partBytes()) {
                $parts[] = $cur;
                $cur = ['after' => $after, 'count' => 0, 'bytes' => 0];
            }
            $cur['count']++;
            $cur['bytes'] += $r['size'];
            $after = $r['id'];
        }
        if ($cur['count'] > 0) {
            $parts[] = $cur;
        }
        return $parts;
    }

    /** Merker „zuletzt abgeholt“ (nur Zeitpunkt). */
    public function noteTaken(): void
    {
        $this->db->run("INSERT INTO meta(k, v) VALUES('last_backup_at', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [(string) $this->clock->now()]);
    }

    // ------------------------------------------------------------------ Paket

    /**
     * Paket vorbereiten: Datenbankkopie erzeugen (VACUUM INTO, Rechte 0600) und die Größe des TAR-Stroms berechnen.
     * Die Kopie wird nach dem Senden gelöscht.
     *
     * @return array{length:int,emit:\Closure,db:int,files:int,originals:int}
     * @throws \RuntimeException wenn die Kopie nicht entstehen kann
     */
    public function package(): array
    {
        $this->sweepTemp();
        $o = $this->originals();
        $files = $this->privateFiles();
        $tmp = $this->config->dataDir() . '/' . self::TMP_PREFIX . bin2hex(random_bytes(8)) . '.tmp';
        $old = umask(0077);
        try {
            $this->db->pdo->exec('VACUUM INTO ' . $this->db->pdo->quote($tmp));
        } catch (\Throwable) {
            @unlink($tmp);
            throw new \RuntimeException('Die Datenbankkopie konnte nicht erzeugt werden.');
        } finally {
            umask($old);
        }
        $dbSize = (int) @filesize($tmp);
        if ($dbSize < 1) {
            @unlink($tmp);
            throw new \RuntimeException('Die Datenbankkopie ist leer.');
        }
        $schema = (int) ($this->db->value("SELECT v FROM meta WHERE k = 'schema_version'") ?? count(Schema::migrations()));
        $now = $this->clock->now();
        $manifest = (string) json_encode(['format' => Restore::FORMAT, 'schema_version' => $schema, 'app_version' => Config::VERSION, 'created_at' => $now], JSON_UNESCAPED_SLASHES) . "\n";
        $list = '';
        foreach ($o['rows'] as $r) {
            $list .= $r['sha'] . '  ' . $r['rel'] . "\n";
        }
        $entries = [['MANIFEST.json', null, $manifest, strlen($manifest)], ['originals.sha256', null, $list, strlen($list)]];
        foreach ($files as $f) {
            $entries[] = ['files/' . $f['rel'], $f['abs'], null, $f['size']];
        }
        $entries[] = ['lichtkammer.sqlite', $tmp, null, $dbSize];
        $length = Tar::endSize();
        foreach ($entries as $e) {
            $length += Tar::entrySize($e[3]);
        }
        $emit = function (callable $out) use ($entries, $now, $tmp): void {
            try {
                foreach ($entries as [$name, $path, $content, $size]) {
                    $this->emitEntry($out, $name, $path, $content, $size, $now);
                }
                $out(Tar::end());
                if (connection_aborted() !== 1) {
                    $this->noteTaken(); // erst nach vollständigem Senden „zuletzt heruntergeladen“ (Prüfung 34, N1)
                }
            } finally {
                @unlink($tmp);
            }
        };
        return ['length' => $length, 'emit' => $emit, 'db' => $dbSize, 'files' => count($files), 'originals' => count($o['rows'])];
    }

    // ------------------------------------------------------------------ Originale

    /**
     * Ein Teil der Originale: Fotos mit Nummer größer als $after, bis die Teilgröße erreicht ist (mindestens eins).
     *
     * @return array{length:int,emit:\Closure,count:int,bytes:int,last:int}|null null, wenn nichts (mehr) da ist
     */
    public function originalsPart(int $after): ?array
    {
        $take = [];
        $bytes = 0;
        foreach ($this->originals()['rows'] as $r) {
            if ($r['id'] <= $after) {
                continue;
            }
            if ($take !== [] && $bytes + $r['size'] > $this->partBytes()) {
                break;
            }
            $take[] = $r;
            $bytes += $r['size'];
        }
        if ($take === []) {
            return null;
        }
        $now = $this->clock->now();
        $length = Tar::endSize();
        foreach ($take as $r) {
            $length += Tar::entrySize($r['size']);
        }
        $emit = function (callable $out) use ($take, $now): void {
            foreach ($take as $r) {
                $this->emitEntry($out, 'originals/' . $r['rel'], $r['abs'], null, $r['size'], $now);
            }
            $out(Tar::end());
        };
        return ['length' => $length, 'emit' => $emit, 'count' => count($take), 'bytes' => $bytes, 'last' => $take[count($take) - 1]['id']];
    }

    /**
     * Ein einzelnes Original für den Einzelabruf (mit Range). Der Pfad kommt nur aus der Datenbank.
     *
     * @return array{abs:string,size:int,rel:string,mime:string}|null
     */
    public function originalFile(int $photoId): ?array
    {
        $r = $this->db->one('SELECT original_path, mime FROM photos WHERE id = ?', [$photoId]);
        if ($r === null || preg_match(Restore::ORIG_REL, (string) $r['original_path']) !== 1) {
            return null;
        }
        $root = $this->originalsRoot();
        $abs = $root . '/' . $r['original_path'];
        if ($root === '' || !is_file($abs) || is_link($abs)) {
            return null;
        }
        $real = realpath($abs);
        if ($real === false || !str_starts_with($real, $root . '/')) {
            return null;
        }
        return ['abs' => $real, 'size' => (int) filesize($real), 'rel' => (string) $r['original_path'], 'mime' => (string) $r['mime']];
    }

    // ------------------------------------------------------------------ Hilfen

    /** @param callable(string):void $out */
    private function emitEntry(callable $out, string $name, ?string $path, ?string $content, int $size, int $mtime): void
    {
        $out(Tar::header($name, $size, $mtime));
        if ($content !== null) {
            $out($content);
        } else {
            $fh = @fopen((string) $path, 'rb');
            $left = $size;
            while ($fh !== false && $left > 0 && !feof($fh)) {
                $chunk = (string) fread($fh, min(self::CHUNK, $left));
                if ($chunk === '') {
                    break;
                }
                $out($chunk);
                $left -= strlen($chunk);
                if (connection_aborted() === 1) {
                    fclose($fh);
                    throw new \RuntimeException('abgebrochen');
                }
            }
            if ($fh !== false) {
                fclose($fh);
            }
            while ($left > 0) { // Datei ist unterwegs kürzer geworden: Archiv bleibt formgerecht, die Prüfsummenliste deckt es auf
                $n = min(self::CHUNK, $left);
                $out(str_repeat("\0", $n));
                $left -= $n;
            }
        }
        $pad = Tar::padding($size);
        if ($pad !== '') {
            $out($pad);
        }
        if (connection_aborted() === 1) {
            throw new \RuntimeException('abgebrochen');
        }
    }

    /** Liegengebliebene Datenbankkopien (Abbruch mitten im Senden) nach einer Stunde entfernen. */
    private function sweepTemp(): void
    {
        foreach (glob($this->config->dataDir() . '/' . self::TMP_PREFIX . '*.tmp') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3600) {
                @unlink($f);
            }
        }
    }
}
