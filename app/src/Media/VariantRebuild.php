<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Backup\Restore;

/**
 * Bildvarianten für alle Fotos neu erzeugen, in kleinen Schritten (nach dem Einspielen einer Sicherung fehlen die Varianten,
 * denn die Sicherung enthält nur Datenbank und Originale).
 *
 * Jeder Aufruf von step() arbeitet höchstens etwa $budget Sekunden (auf all-inkl gilt eine Grenze von 60 Sekunden je Anfrage).
 * Der Stand liegt in der Tabelle meta (Schlüssel variants_*), nie im Browser: Neuladen oder Abbruch setzt an derselben Stelle fort.
 * Die Pipeline ist die bestehende (Library::process, ImagickSource/GdSource mit stripImage: keine Metadaten, kein GPS).
 * Originale werden nur gelesen, nie verändert. Ein fehlendes oder ungültiges Original überspringt das Foto.
 */
final class VariantRebuild
{
    public const BUDGET = 20.0;
    private const CURSOR = 'variants_cursor';
    private const RUN = 'variants_run';
    private const CACHE = 'variants_missing';
    private const CACHE_TTL = 300;

    /** @var callable():float */
    private $timer;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Library $library,
        private readonly string $originalsDir,
        private readonly float $budget = self::BUDGET,
        ?callable $timer = null,
    ) {
        $this->timer = $timer ?? static fn (): float => microtime(true);
    }

    /** Läuft gerade ein Durchgang (angefangen, nicht beendet)? */
    public function running(): bool
    {
        return $this->meta(self::CURSOR) !== null;
    }

    /** Neuen Durchgang beginnen. Gibt die Zahl der Fotos zurück. */
    public function start(bool $force): int
    {
        $n = (int) $this->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL');
        $this->setMeta(self::CURSOR, '0');
        $this->setMeta(self::RUN, json_encode(['force' => $force, 'photos' => $n, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'noorig' => 0, 'steps' => 0, 'at' => $this->clock->now()], JSON_THROW_ON_ERROR));
        $this->invalidate();
        return $n;
    }

    /**
     * Ein Stück arbeiten. Beginnt von selbst einen Durchgang (ohne Erzwingen), wenn keiner läuft.
     *
     * @return array{done:bool,started:bool,force:bool,total:int,position:int,created:int,skipped:int,failed:int,noorig:int,handled:int,cursor:int}
     */
    public function step(): array
    {
        $started = false;
        if (!$this->running()) {
            $this->start(false);
            $started = true;
        }
        $run = $this->run();
        $force = (bool) $run['force'];
        $cursor = (int) $this->meta(self::CURSOR);
        $t0 = ($this->timer)();
        $longest = 0.0;
        $handled = 0;
        $c = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'noorig' => 0];
        $done = false;
        while (true) {
            $row = $this->db->one('SELECT * FROM photos WHERE deleted_at IS NULL AND id > ? ORDER BY id LIMIT 1', [$cursor]);
            if ($row === null) {
                $done = true;
                break;
            }
            // Zeitgrenze: nichts Neues anfangen, wenn das längste bisherige Foto nicht mehr hineinpasst (mindestens ein Foto je Aufruf).
            if ($handled > 0 && (($this->timer)() - $t0) + $longest > $this->budget) {
                break;
            }
            $t1 = ($this->timer)();
            $r = $this->one($row, $force);
            $longest = max($longest, ($this->timer)() - $t1);
            $c[$r['kind']]++;
            $cursor = (int) $row['id'];
            $handled++;
            $this->setMeta(self::CURSOR, (string) $cursor); // nach jedem Foto: ein Abbruch verliert höchstens dieses eine
        }
        foreach ($c as $k => $v) {
            $run[$k] += $v;
        }
        $run['steps']++;
        $this->invalidate();
        $position = (int) $this->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL AND id <= ?', [$cursor]);
        $res = ['done' => $done, 'started' => $started, 'force' => $force, 'total' => (int) $this->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL'),
            'position' => $position, 'handled' => $handled, 'cursor' => $cursor] + $c;
        if ($done) {
            $res += ['run' => $run];
            $this->setMeta('variants_last', json_encode($run, JSON_THROW_ON_ERROR)); // Ergebnis des letzten Durchgangs bleibt sichtbar
            $this->db->run('DELETE FROM meta WHERE k IN (?, ?)', [self::CURSOR, self::RUN]);
        } else {
            $this->setMeta(self::RUN, json_encode($run, JSON_THROW_ON_ERROR));
        }
        return $res;
    }

    /** @return array<string,mixed> */
    private function run(): array
    {
        $raw = $this->meta(self::RUN);
        $a = $raw === null ? null : json_decode($raw, true);
        return is_array($a) ? $a : ['force' => false, 'photos' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'noorig' => 0, 'steps' => 0, 'at' => $this->clock->now()];
    }

    /** Zahlen des laufenden Durchgangs (nach dem Ende: des letzten). @return array<string,mixed>|null */
    public function runStats(): ?array
    {
        $raw = $this->meta(self::RUN) ?? $this->meta('variants_last');
        $a = $raw === null ? null : json_decode($raw, true);
        return is_array($a) ? $a : null;
    }

    /**
     * Stand für die Seite: wie viele Fotos Varianten haben, welche Probleme es gibt.
     *
     * @return array{total:int,ok:int,problems:list<array{id:int,name:string,text:string}>,running:bool,position:int}
     */
    public function status(): array
    {
        $ok = 0;
        $total = 0;
        $problems = [];
        foreach ($this->db->all('SELECT * FROM photos WHERE deleted_at IS NULL ORDER BY id') as $p) {
            $total++;
            $has = $this->library->variantsComplete($p);
            if ($has) {
                $ok++;
            }
            if ($this->originalPath((string) $p['original_path']) === null) {
                $problems[] = ['id' => (int) $p['id'], 'name' => (string) $p['original_name'], 'text' => 'Das Original fehlt im Speicher.' . ($has ? ' Die Bildvarianten sind vorhanden.' : '')];
            } elseif ($p['status'] === 'failed') {
                $problems[] = ['id' => (int) $p['id'], 'name' => (string) $p['original_name'], 'text' => (string) $p['error'] !== '' ? (string) $p['error'] : 'Das Bild konnte nicht verarbeitet werden.'];
            }
        }
        $cursor = $this->meta(self::CURSOR);
        return ['total' => $total, 'ok' => $ok, 'problems' => $problems, 'running' => $cursor !== null,
            'position' => $cursor === null ? 0 : (int) $this->db->value('SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL AND id <= ?', [(int) $cursor])];
    }

    /**
     * Zahl der Fotos ohne vollständige Bildvarianten, für das Warnband. Zwischengespeichert (5 Minuten), damit nicht jede
     * Seite alle Dateien prüft. Frisch hochgeladene Fotos (Status pending) zählen nicht: sie werden gerade verarbeitet.
     *
     * @return array{missing:int,total:int}
     */
    public function missingCount(): array
    {
        $raw = $this->meta(self::CACHE);
        if ($raw !== null && preg_match('/^(\d+):(\d+):(\d+)$/', $raw, $m) === 1 && $this->clock->now() - (int) $m[3] < self::CACHE_TTL) {
            return ['missing' => (int) $m[1], 'total' => (int) $m[2]];
        }
        $missing = 0;
        $total = 0;
        foreach ($this->db->all('SELECT * FROM photos WHERE deleted_at IS NULL') as $p) {
            $total++;
            if ($p['status'] !== 'pending' && !$this->library->variantsComplete($p)) {
                $missing++;
            }
        }
        $this->setMeta(self::CACHE, $missing . ':' . $total . ':' . $this->clock->now());
        return ['missing' => $missing, 'total' => $total];
    }

    /** Zwischenspeicher des Warnbands verwerfen (nach Wiederherstellung und nach jedem Schritt). */
    public function invalidate(): void
    {
        $this->db->run('DELETE FROM meta WHERE k = ?', [self::CACHE]);
    }

    /**
     * Ein Foto behandeln.
     *
     * @param array<string,mixed> $row
     * @return array{kind:string} kind: created | skipped | failed | noorig
     */
    private function one(array $row, bool $force): array
    {
        $id = (int) $row['id'];
        if (!$force && $this->library->variantsComplete($row)) {
            return ['kind' => 'skipped'];
        }
        if ($this->originalPath((string) $row['original_path']) === null) {
            if ($this->library->variantsComplete($row)) { // erzwungen, aber das Original fehlt: vorhandene Varianten nicht anfassen
                return ['kind' => 'noorig'];
            }
            $this->library->markFailed($id, 'Das Original fehlt im Speicher.');
            return ['kind' => 'noorig'];
        }
        try {
            $r = $this->library->process($id, $force);
        } catch (MediaException) {
            return ['kind' => 'failed'];
        }
        return $r['status'] === 'failed' ? ['kind' => 'failed'] : ['kind' => $r['created'] > 0 ? 'created' : 'skipped'];
    }

    /** Absoluter Pfad des Originals, wenn es regelkonform und eine echte Datei innerhalb des Originalordners ist, sonst null. */
    private function originalPath(string $rel): ?string
    {
        $root = realpath($this->originalsDir);
        if ($root === false || preg_match(Restore::ORIG_REL, $rel) !== 1) {
            return null;
        }
        $abs = rtrim($this->originalsDir, '/') . '/' . $rel;
        if (!is_file($abs) || is_link($abs)) {
            return null;
        }
        $real = realpath($abs);
        return $real !== false && str_starts_with($real, $root . '/') ? $real : null;
    }

    private function meta(string $k): ?string
    {
        $v = $this->db->value('SELECT v FROM meta WHERE k = ?', [$k]);
        return $v === null ? null : (string) $v;
    }

    private function setMeta(string $k, string $v): void
    {
        $this->db->run('INSERT INTO meta(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
    }
}
