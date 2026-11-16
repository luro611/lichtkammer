<?php
declare(strict_types=1);

namespace Lichtkammer\Import;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Media\Catalog;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\Library;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Site\Blocks;
use Lichtkammer\Site\Pages;
use Lichtkammer\Site\Settings;

/**
 * Umzug von WordPress (Schritt 7). Liest ein Importpaket (manifest.json, bilder/, dateien/; erzeugt von
 * tools/wp-export/wp_export.py) aus dem Importordner und legt Fotos, Galerien, Seiten, Fotografen und
 * Weiterleitungen an. Das Paket gilt als nicht vertrauenswürdig: Dateinamen und Felder werden geprüft,
 * Bilder laufen durch dieselbe Aufnahme (Library::ingest) wie jeder Upload, Blöcke durch Blocks::parse.
 *
 * Wiederholbar: Was schon übernommen wurde, steht in import_map und wird nicht noch einmal angelegt.
 * Galerien und Seiten entstehen als Entwurf; veröffentlicht wird erst nach der Durchsicht.
 */
final class Importer
{
    public const MAX_MANIFEST = 16 * 1024 * 1024;
    public const DOC_DIR = 'dokumente';
    private const EMPTY_NOTE = 'leer';

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Library $library,
        private readonly Galleries $galleries,
        private readonly Catalog $catalog,
        private readonly Pages $pages,
        private readonly Settings $settings,
        private readonly string $importDir,
        private readonly string $mediaDir,
    ) {
    }

    public function dir(): string
    {
        return $this->importDir;
    }

    /** Legt den Importordner an und sperrt ihn für den Webserver (er kann im Webroot liegen). */
    public function prepareDir(): void
    {
        $old = umask(0077);
        try {
            if (!is_dir($this->importDir)) {
                @mkdir($this->importDir, 0700, true);
            }
        } finally {
            umask($old);
        }
        if (is_dir($this->importDir) && !is_file($this->importDir . '/.htaccess')) {
            @file_put_contents($this->importDir . '/.htaccess', "Require all denied\n");
            @file_put_contents($this->importDir . '/index.html', '');
        }
    }

    /**
     * Liest und prüft das Paket.
     *
     * @return array{state:string,error:string,m:?array<string,mixed>}  state: none | invalid | ready
     */
    public function load(): array
    {
        $file = $this->importDir . '/manifest.json';
        if (!is_file($file)) {
            return ['state' => 'none', 'error' => '', 'm' => null];
        }
        $size = (int) filesize($file);
        if ($size < 2 || $size > self::MAX_MANIFEST) {
            return ['state' => 'invalid', 'error' => 'Die Datei manifest.json hat eine unerwartete Größe.', 'm' => null];
        }
        try {
            return ['state' => 'ready', 'error' => '', 'm' => self::parse((string) file_get_contents($file))];
        } catch (MediaException $e) {
            return ['state' => 'invalid', 'error' => $e->getMessage(), 'm' => null];
        }
    }

    /**
     * Prüft das Paket und baut eine bereinigte Fassung auf (nie das Eingegebene weiterreichen).
     *
     * @return array<string,mixed>
     * @throws MediaException
     */
    public static function parse(string $json): array
    {
        $d = json_decode($json, true, 64);
        if (!is_array($d) || ($d['format'] ?? '') !== 'lichtkammer-import') {
            throw new MediaException('Das ist kein Lichtkammer-Importpaket (manifest.json fehlt oder ist beschädigt).');
        }
        if ((int) ($d['version'] ?? 0) !== 1) {
            throw new MediaException('Diese Version des Importpakets wird nicht unterstützt.');
        }
        $str = static fn (mixed $v, int $max): string => is_string($v) ? Galleries::clip($v, $max) : '';
        $int = static fn (mixed $v): int => is_int($v) && $v > 0 && $v < 2_000_000_000 ? $v : 0;
        $list = static fn (mixed $v): array => is_array($v) && array_is_list($v) ? $v : [];
        $out = ['photographers' => [], 'photos' => [], 'galleries' => [], 'pages' => [], 'files' => [], 'redirects' => [],
            'hero_wp' => $int($d['hero_wp'] ?? null), 'source' => $str($d['source'] ?? '', 200), 'created' => $int($d['created'] ?? null)];

        foreach ($list($d['photographers'] ?? null) as $n) {
            if (is_string($n) && trim($n) !== '') {
                $out['photographers'][] = Galleries::clip($n, 80);
            }
        }
        $seen = [];
        foreach ($list($d['photos'] ?? null) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $wp = $int($p['wp'] ?? null);
            $file = is_string($p['file'] ?? null) ? $p['file'] : '';
            $sha = is_string($p['sha256'] ?? null) ? strtolower($p['sha256']) : '';
            if ($wp === 0 || isset($seen[$wp]) || !self::safeRel($file, 'bilder') || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
                throw new MediaException('Das Paket enthält einen ungültigen Eintrag bei den Fotos (Nr. ' . ($wp ?: '?') . ').');
            }
            $seen[$wp] = true;
            $year = $int($p['year'] ?? null);
            $out['photos'][] = [
                'wp' => $wp, 'file' => $file, 'sha256' => $sha, 'name' => $str($p['name'] ?? '', 200),
                'alt' => $str($p['alt'] ?? '', 500), 'title' => $str($p['title'] ?? '', 200), 'caption' => $str($p['caption'] ?? '', 1000),
                'photographer' => is_string($p['photographer'] ?? null) ? Galleries::clip($p['photographer'], 80) : '',
                'place' => $str($p['place'] ?? '', 120), 'year' => $year >= 1800 && $year <= 2100 ? $year : 0, 'used' => ($p['used'] ?? false) === true,
            ];
        }
        foreach ($list($d['galleries'] ?? null) as $g) {
            if (!is_array($g) || $int($g['wp'] ?? null) === 0 || trim($str($g['title'] ?? '', 200)) === '') {
                throw new MediaException('Das Paket enthält eine Galerie ohne Nummer oder Titel.');
            }
            $out['galleries'][] = [
                'wp' => $int($g['wp']), 'title' => $str($g['title'], 200), 'description' => $str($g['description'] ?? '', 5000),
                'photos' => array_values(array_filter(array_map($int, $list($g['photos'] ?? null)))), 'cover' => $int($g['cover'] ?? null),
            ];
        }
        foreach ($list($d['pages'] ?? null) as $pg) {
            if (!is_array($pg) || $int($pg['wp'] ?? null) === 0 || trim($str($pg['title'] ?? '', 120)) === '') {
                throw new MediaException('Das Paket enthält eine Seite ohne Nummer oder Titel.');
            }
            $slug = $str($pg['slug'] ?? '', 120);
            $nav = in_array($pg['nav'] ?? '', ['main', 'footer', 'none'], true) ? $pg['nav'] : 'none';
            $item = ['wp' => $int($pg['wp']), 'slug' => $slug, 'title' => $str($pg['title'], 120), 'nav' => $nav, 'body' => '', 'blocks' => null];
            if (isset($pg['blocks']) && is_array($pg['blocks']) && array_is_list($pg['blocks'])) {
                $item['blocks'] = $pg['blocks']; // wird beim Anlegen durch Blocks::parse geprüft
            } else {
                $item['body'] = is_string($pg['body'] ?? null) ? mb_substr($pg['body'], 0, 50000) : '';
            }
            $out['pages'][] = $item;
        }
        foreach ($list($d['files'] ?? null) as $f) {
            $name = is_array($f) && is_string($f['name'] ?? null) ? $f['name'] : '';
            $sha = is_array($f) && is_string($f['sha256'] ?? null) ? strtolower($f['sha256']) : '';
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$/i', $name) !== 1 || !self::safeRel(is_array($f) && is_string($f['file'] ?? null) ? $f['file'] : '', 'dateien') || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
                throw new MediaException('Das Paket enthält einen ungültigen Eintrag bei den Dateien.');
            }
            $out['files'][] = ['name' => $name, 'file' => $f['file'], 'sha256' => $sha];
        }
        foreach ($list($d['redirects'] ?? null) as $r) {
            if (!is_array($r) || !in_array($r['kind'] ?? '', ['gallery', 'page', 'home', 'galleries', 'file'], true)) {
                continue;
            }
            $from = self::normPath((string) ($r['from'] ?? ''));
            if ($from === null || $from === '/') {
                continue;
            }
            $out['redirects'][] = ['from' => $from, 'kind' => $r['kind'], 'wp' => $int($r['wp'] ?? null), 'slug' => $str($r['slug'] ?? '', 120), 'name' => $str($r['name'] ?? '', 130)];
        }
        return $out;
    }

    /** Alter Pfad für den Vergleich: klein, mit führendem /, ohne / am Ende; nur erlaubte Zeichen. */
    public static function normPath(string $path): ?string
    {
        $p = parse_url($path, PHP_URL_PATH);
        $p = is_string($p) ? rawurldecode($p) : '';
        $p = strtolower($p);
        if ($p === '' || $p[0] !== '/' || preg_match('#^[a-z0-9_/.\-]+$#', $p) !== 1 || str_contains($p, '..') || str_contains($p, '//') || strlen($p) > 300) {
            return null;
        }
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    private static function safeRel(string $rel, string $dir): bool
    {
        return preg_match('#^' . $dir . '/[A-Za-z0-9][A-Za-z0-9._-]{0,200}$#', $rel) === 1;
    }

    /** Sicherer absoluter Pfad einer Paketdatei: muss im Importordner liegen und eine normale Datei sein. */
    private function packFile(string $rel): ?string
    {
        $root = realpath($this->importDir);
        $path = $root === false ? false : realpath($root . '/' . $rel);
        if ($root === false || $path === false || !str_starts_with($path, $root . '/') || !is_file($path) || is_link($root . '/' . $rel)) {
            return null;
        }
        return $path;
    }

    // --- Stand

    /** @return array{photos:int,done:int,failed:int,galleries:int,galleries_done:int,pages:int,pages_done:int,finished:bool} */
    public function counts(array $m): array
    {
        $c = fn (string $kind): int => (int) $this->db->value('SELECT COUNT(*) FROM import_map WHERE kind = ?', [$kind]);
        return [
            'photos' => count($m['photos']), 'done' => $c('photo'),
            'failed' => (int) $this->db->value("SELECT COUNT(*) FROM import_map WHERE kind = 'photo' AND note LIKE 'Fehler:%'"),
            'galleries' => count($m['galleries']), 'galleries_done' => $c('gallery'),
            'pages' => count($m['pages']), 'pages_done' => $c('page'),
            'finished' => $this->db->value("SELECT 1 FROM meta WHERE k = 'import_finished'") !== null,
        ];
    }

    // --- Schritt 1: ein Foto nach dem anderen

    /** Fotos, deren Datei fehlte oder beschädigt war, noch einmal versuchen (z. B. nach neuem Hochladen). */
    public function retryFailed(): void
    {
        $this->db->run("DELETE FROM import_map WHERE kind = 'photo' AND local_id = 0 AND note LIKE 'Fehler:%'");
    }

    /**
     * Nimmt das nächste noch nicht übernommene Foto auf (Aufnahme, Varianten, Felder).
     *
     * @param array<string,mixed> $m geprüftes Paket
     * @return array{done:bool,index:int,total:int,name:string,status:string,message:string}
     */
    public function nextPhoto(int $userId, array $m): array
    {
        $total = count($m['photos']);
        $mapped = array_flip(array_map('intval', array_column($this->db->all("SELECT wp_id FROM import_map WHERE kind = 'photo'"), 'wp_id')));
        $this->ensurePhotographers($m);
        $idx = 0;
        foreach ($m['photos'] as $p) {
            $idx++;
            if (isset($mapped[$p['wp']])) {
                continue;
            }
            return ['done' => false, 'index' => $idx, 'total' => $total, 'name' => $p['name']] + $this->importPhoto($userId, $p);
        }
        return ['done' => true, 'index' => $total, 'total' => $total, 'name' => '', 'status' => 'ok', 'message' => ''];
    }

    /** @return array{status:string,message:string} */
    private function importPhoto(int $userId, array $p): array
    {
        $note = static fn (string $s): string => mb_substr($s, 0, 300);
        $map = fn (int $local, string $n) => $this->db->run('INSERT OR REPLACE INTO import_map(kind, wp_id, local_id, note) VALUES(\'photo\', ?, ?, ?)', [$p['wp'], $local, $note($n)]);
        $path = $this->packFile($p['file']);
        if ($path === null) {
            $map(0, 'Fehler: Datei fehlt im Importordner (' . $p['file'] . ').');
            return ['status' => 'failed', 'message' => 'Datei fehlt im Importordner.'];
        }
        if (!hash_equals($p['sha256'], (string) hash_file('sha256', $path))) {
            $map(0, 'Fehler: Prüfsumme stimmt nicht (Datei beschädigt oder unvollständig hochgeladen).');
            return ['status' => 'failed', 'message' => 'Prüfsumme stimmt nicht. Bitte die Datei neu hochladen.'];
        }
        try {
            $r = $this->library->ingest($userId, $path, $p['name'] !== '' ? $p['name'] : basename($path), null);
        } catch (MediaException $e) {
            $map(0, 'Fehler: ' . $e->getMessage());
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
        $id = $r['photo_id'];
        $status = $r['duplicate'] ? 'duplicate' : 'ok';
        $message = $r['duplicate'] ? 'Gleiches Bild war schon vorhanden.' : '';
        if (!$r['duplicate']) {
            $res = $this->library->process($id);
            if ($res['status'] === 'failed') {
                $map($id, 'Fehler: ' . $res['error']);
                $this->fields($id, $p);
                return ['status' => 'failed', 'message' => $res['error']];
            }
        }
        $this->fields($id, $p);
        $map($id, $r['duplicate'] ? 'Doppelt: gleicher Inhalt wie Foto ' . $id : '');
        return ['status' => $status, 'message' => $message];
    }

    /** Setzt nur leere Felder (bei Dubletten wird nichts überschrieben). */
    private function fields(int $id, array $p): void
    {
        $cur = $this->db->one('SELECT title, caption, alt_text, place, year, photographer_id FROM photos WHERE id = ?', [$id]);
        if ($cur === null) {
            return;
        }
        $set = [];
        foreach (['title' => 'title', 'caption' => 'caption', 'alt' => 'alt_text', 'place' => 'place'] as $from => $col) {
            if (trim((string) $cur[$col]) === '' && $p[$from] !== '') {
                $set[$col] = $p[$from];
            }
        }
        if ($cur['year'] === null && $p['year'] > 0) {
            $set['year'] = (string) $p['year'];
        }
        if ($cur['photographer_id'] === null && $p['photographer'] !== '') {
            $pid = $this->db->value('SELECT id FROM photographers WHERE name = ? COLLATE NOCASE', [$p['photographer']]);
            if ($pid !== null) {
                $set['photographer_id'] = (string) $pid;
            }
        }
        if ($set !== []) {
            try {
                $this->catalog->update($id, $set);
            } catch (MediaException) {
                // ungültiger Wert im Paket (z. B. Jahr): Foto bleibt ohne dieses Feld
            }
        }
    }

    private function ensurePhotographers(array $m): void
    {
        $names = $m['photographers'];
        foreach ($m['photos'] as $p) {
            if ($p['photographer'] !== '') {
                $names[] = $p['photographer'];
            }
        }
        foreach (array_unique($names) as $n) {
            if ($this->db->value('SELECT 1 FROM photographers WHERE name = ? COLLATE NOCASE', [$n]) === null) {
                $this->catalog->addPhotographer($n);
            }
        }
    }

    // --- Schritt 2: Galerien, Seiten, Dateien, Weiterleitungen

    /**
     * @param array<string,mixed> $m
     * @return array{galleries:int,pages:int,files:int,redirects:int,problems:list<string>}
     * @throws MediaException wenn noch Fotos fehlen
     */
    public function finish(int $userId, array $m): array
    {
        $mapped = [];
        foreach ($this->db->all("SELECT wp_id, local_id FROM import_map WHERE kind = 'photo'") as $r) {
            $mapped[(int) $r['wp_id']] = (int) $r['local_id'];
        }
        foreach ($m['photos'] as $p) {
            if (!isset($mapped[$p['wp']])) {
                throw new MediaException('Es sind noch nicht alle Fotos verarbeitet.');
            }
        }
        $problems = [];
        $photoOf = static fn (int $wp): int => $mapped[$wp] ?? 0;
        $out = ['galleries' => 0, 'pages' => 0, 'files' => 0, 'redirects' => 0, 'problems' => []];

        // Galerien: Entwurf, EXIF (Kamera, Objektiv) öffentlich; GPS gibt es nie.
        $gal = [];
        foreach ($this->db->all("SELECT wp_id, local_id FROM import_map WHERE kind = 'gallery'") as $r) {
            $gal[(int) $r['wp_id']] = (int) $r['local_id'];
        }
        foreach ($m['galleries'] as $g) {
            if (isset($gal[$g['wp']]) && $this->db->value('SELECT 1 FROM galleries WHERE id = ?', [$gal[$g['wp']]]) !== null) {
                continue;
            }
            $this->db->tx(function () use ($g, $photoOf, &$gal, &$out, &$problems): void {
                $id = $this->galleries->create($g['title'], $g['description']);
                $this->galleries->update($id, $g['title'], $g['description'], true);
                $ids = array_values(array_unique(array_filter(array_map($photoOf, $g['photos']))));
                $this->galleries->addPhotos($id, $ids);
                if ($g['cover'] > 0 && in_array($photoOf($g['cover']), $ids, true)) {
                    $this->galleries->setCover($id, $photoOf($g['cover']));
                }
                if (count($ids) !== count($g['photos'])) {
                    $problems[] = 'Galerie „' . $g['title'] . '“: ' . (count($g['photos']) - count($ids)) . ' Bild(er) fehlen (nicht übernommen oder doppelt).';
                }
                $this->db->run("INSERT OR REPLACE INTO import_map(kind, wp_id, local_id) VALUES('gallery', ?, ?)", [$g['wp'], $id]);
                $gal[$g['wp']] = $id;
                $out['galleries']++;
            });
        }

        // Seiten, erst leer anlegen (feste Adressen), dann mit Inhalt füllen (Links zwischen Seiten und Galerien).
        // Vermerk 'leer' in import_map = angelegt, Inhalt noch nicht eingesetzt (nach einem Abbruch wird er nachgeholt).
        $pg = [];
        $isNew = [];
        foreach ($this->db->all("SELECT wp_id, local_id, note FROM import_map WHERE kind = 'page'") as $r) {
            $pg[(int) $r['wp_id']] = (int) $r['local_id'];
            if ((string) $r['note'] === self::EMPTY_NOTE) {
                $isNew[(int) $r['wp_id']] = true;
            }
        }
        foreach ($m['pages'] as $p) {
            if (isset($pg[$p['wp']]) && $this->pages->get($pg[$p['wp']]) !== null) {
                continue;
            }
            $id = $this->db->tx(function () use ($p): int {
                $id = $this->pages->save(null, ['title' => $p['title'], 'body' => '', 'status' => 'draft', 'nav' => $p['nav'], 'slug' => $p['slug']]);
                $this->db->run("INSERT OR REPLACE INTO import_map(kind, wp_id, local_id, note) VALUES('page', ?, ?, ?)", [$p['wp'], $id, self::EMPTY_NOTE]);
                return $id;
            });
            $pg[$p['wp']] = $id;
            $isNew[$p['wp']] = true;
        }
        $slugOfPage = [];
        $slugByWpSlug = [];
        foreach ($m['pages'] as $p) {
            $row = $this->pages->get($pg[$p['wp']]);
            $slugOfPage[$p['wp']] = (string) ($row['slug'] ?? '');
            $slugByWpSlug[$p['slug']] = (string) ($row['slug'] ?? '');
        }
        $gslug = [];
        foreach ($gal as $wp => $id) {
            $gslug[$wp] = (string) $this->db->value('SELECT slug FROM galleries WHERE id = ?', [$id]);
        }
        $subst = function (string $s) use ($gslug, $slugByWpSlug): string {
            $s = preg_replace_callback('/lk-gallery:(\d+)/', static fn (array $x): string => isset($gslug[(int) $x[1]]) ? '/galerien/' . $gslug[(int) $x[1]] . '/' : '/galerien/', $s) ?? $s;
            $s = preg_replace_callback('/lk-page:([A-Za-z0-9_-]+)/', static fn (array $x): string => '/' . ($slugByWpSlug[$x[1]] ?? $x[1]) . '/', $s) ?? $s;
            return preg_replace('#lk-file:([A-Za-z0-9._-]+)#', '/media/' . self::DOC_DIR . '/$1', $s) ?? $s;
        };
        // Beschreibungen mit Platzhaltern fertigstellen; nur wenn der Text noch der aus dem Paket ist (auch nach einem
        // Abbruch beim ersten Lauf, ohne spätere Änderungen im Admin zu überschreiben).
        foreach ($m['galleries'] as $g) {
            $id = $gal[$g['wp']] ?? 0;
            if ($id > 0 && str_contains($g['description'], 'lk-')
                && (string) $this->db->value('SELECT description FROM galleries WHERE id = ?', [$id]) === $g['description']) {
                $cur = $this->db->one('SELECT title, show_exif FROM galleries WHERE id = ?', [$id]);
                $this->galleries->update($id, (string) $cur['title'], $subst($g['description']), (int) $cur['show_exif'] === 1);
            }
        }
        foreach ($m['pages'] as $p) {
            if (!isset($isNew[$p['wp']])) {
                continue; // schon früher übernommen: Änderungen im Admin nicht überschreiben
            }
            $id = $pg[$p['wp']];
            try {
                $f = ['title' => $p['title'], 'status' => 'draft', 'nav' => $p['nav'], 'body' => $subst($p['body'])];
                if ($p['blocks'] !== null) {
                    $f['blocks'] = Blocks::encode($this->blocksFor($p['blocks'], $photoOf, $subst));
                }
                $this->pages->save($id, $f);
                $this->db->run("UPDATE import_map SET note = '' WHERE kind = 'page' AND wp_id = ?", [$p['wp']]);
                $out['pages']++;
            } catch (MediaException $e) {
                $this->db->run("UPDATE import_map SET note = 'Fehler: Inhalt nicht übernommen' WHERE kind = 'page' AND wp_id = ?", [$p['wp']]);
                $problems[] = 'Seite „' . $p['title'] . '“: ' . $e->getMessage() . ' Die Seite liegt leer als Entwurf bereit.';
            }
        }

        // Dateien (PDF) in den öffentlichen Medienordner
        foreach ($m['files'] as $f) {
            $src = $this->packFile($f['file']);
            if ($src === null || !hash_equals($f['sha256'], (string) hash_file('sha256', $src))) {
                $problems[] = 'Datei ' . $f['name'] . ' fehlt oder ist beschädigt.';
                continue;
            }
            $dir = $this->mediaDir . '/' . self::DOC_DIR;
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $problems[] = 'Der Ordner für Dokumente ist nicht beschreibbar.';
                break;
            }
            $dst = $dir . '/' . $f['name'];
            if (!is_file($dst) || hash_file('sha256', $dst) !== $f['sha256']) {
                $tmp = $dst . '.tmp' . bin2hex(random_bytes(3));
                if (@copy($src, $tmp) && @rename($tmp, $dst)) {
                    @chmod($dst, 0644);
                } else {
                    @unlink($tmp);
                    $problems[] = 'Datei ' . $f['name'] . ' konnte nicht kopiert werden.';
                    continue;
                }
            }
            $this->db->run("INSERT OR REPLACE INTO import_map(kind, wp_id, local_id, note) VALUES('file', ?, 0, ?)", [abs(crc32($f['name'])), $f['name']]);
            $out['files']++;
        }

        // Weiterleitungen alter Adressen
        $now = $this->clock->now();
        $pageByWpSlug = [];
        foreach ($m['pages'] as $p) {
            $pageByWpSlug[$p['slug']] = $pg[$p['wp']];
        }
        foreach ($m['redirects'] as $r) {
            [$kind, $target, $to] = match ($r['kind']) {
                'gallery' => isset($gal[$r['wp']]) ? ['gallery', $gal[$r['wp']], ''] : [null, null, ''],
                'page' => isset($pageByWpSlug[$r['slug']]) ? ['page', $pageByWpSlug[$r['slug']], ''] : [null, null, ''],
                'home' => ['home', null, ''],
                'galleries' => ['galleries', null, ''],
                'file' => $r['name'] !== '' && $this->db->value("SELECT 1 FROM import_map WHERE kind = 'file' AND note = ?", [$r['name']]) !== null
                    ? ['path', null, '/media/' . self::DOC_DIR . '/' . $r['name']] : [null, null, ''],
                default => [null, null, ''],
            };
            if ($kind === null) {
                continue;
            }
            $this->db->run('INSERT OR REPLACE INTO redirects(from_path, kind, target_id, to_path, created_at) VALUES(?,?,?,?,?)', [$r['from'], $kind, $target, $to, $now]);
            $out['redirects']++;
        }

        // Startbild
        if ($m['hero_wp'] > 0 && $photoOf($m['hero_wp']) > 0) {
            $set = $this->settings->all();
            if ($set['hero_photo_id'] === 0) {
                $this->settings->save($set['site_name'], $set['tagline'], $photoOf($m['hero_wp']));
            }
        }
        $this->db->run("INSERT INTO meta(k, v) VALUES('import_finished', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [(string) $now]);
        $out['problems'] = $problems;
        return $out;
    }

    /**
     * Wandelt Blöcke aus dem Paket (Verweise auf WordPress-Nummern) in Lichtkammer-Blöcke und prüft sie.
     *
     * @param list<mixed> $blocks
     * @return list<array<string,mixed>>
     */
    private function blocksFor(array $blocks, callable $photoOf, callable $subst): array
    {
        $conv = function (array $list) use (&$conv, $photoOf, $subst): array {
            $out = [];
            foreach ($list as $b) {
                if (!is_array($b) || !is_string($b['t'] ?? null)) {
                    continue;
                }
                foreach (['text', 'label', 'href', 'caption', 'by'] as $k) {
                    if (isset($b[$k]) && is_string($b[$k])) {
                        $b[$k] = $subst($b[$k]);
                    }
                }
                if ($b['t'] === 'image') {
                    $pid = $photoOf((int) ($b['wp'] ?? 0));
                    if ($pid === 0) {
                        continue;
                    }
                    $alt = is_string($b['alt'] ?? null) ? $b['alt'] : '';
                    $b = ['t' => 'image', 'photo' => $pid, 'alt' => $alt !== '' ? $alt : $this->altOf($pid), 'caption' => $b['caption'] ?? '', 'href' => $b['href'] ?? ''];
                } elseif ($b['t'] === 'gallery') {
                    $ph = [];
                    foreach (is_array($b['photos'] ?? null) ? $b['photos'] : [] as $x) {
                        $pid = $photoOf((int) ($x['wp'] ?? 0));
                        if ($pid > 0) {
                            $ph[] = ['photo' => $pid, 'alt' => $this->altOf($pid)];
                        }
                    }
                    if ($ph === []) {
                        continue;
                    }
                    $b = ['t' => 'gallery', 'photos' => $ph, 'cols' => (int) ($b['cols'] ?? 3)];
                } elseif ($b['t'] === 'columns') {
                    $b['left'] = $conv(is_array($b['left'] ?? null) ? $b['left'] : []);
                    $b['right'] = $conv(is_array($b['right'] ?? null) ? $b['right'] : []);
                }
                $out[] = $b;
            }
            return $out;
        };
        $json = (string) json_encode(['v' => 1, 'blocks' => $conv($blocks)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return Blocks::parse($json, $this->db);
    }

    private function altOf(int $photoId): string
    {
        return (string) ($this->db->value('SELECT alt_text FROM photos WHERE id = ?', [$photoId]) ?? '');
    }

    // --- Bericht

    /**
     * Was nach dem Import noch zu tun ist.
     *
     * @return array{missing_alt:int,photos:int,no_photographer:int,failed:list<array<string,mixed>>,duplicates:int,unused:int,galleries:list<array<string,mixed>>,pages:list<array<string,mixed>>,redirects:int}
     */
    public function report(): array
    {
        $imp = "photo_id IN (SELECT local_id FROM import_map WHERE kind = 'photo' AND local_id > 0)";
        $photos = (int) $this->db->value("SELECT COUNT(DISTINCT local_id) FROM import_map WHERE kind = 'photo' AND local_id > 0");
        $galleries = [];
        foreach ($this->db->all("SELECT g.id, g.title, g.status, g.slug FROM import_map i JOIN galleries g ON g.id = i.local_id WHERE i.kind = 'gallery' ORDER BY g.sort_order, g.id") as $g) {
            $row = $this->db->one(
                "SELECT COUNT(*) AS n, SUM(CASE WHEN p.alt_text = '' THEN 1 ELSE 0 END) AS noalt,
                        SUM(CASE WHEN p.photographer_id IS NULL THEN 1 ELSE 0 END) AS noph
                 FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? AND p.deleted_at IS NULL",
                [$g['id']],
            );
            $galleries[] = $g + ['n' => (int) ($row['n'] ?? 0), 'noalt' => (int) ($row['noalt'] ?? 0), 'noph' => (int) ($row['noph'] ?? 0)];
        }
        $pages = [];
        foreach ($this->db->all("SELECT pg.id, pg.title, pg.status, pg.slug, pg.blocks, pg.body FROM import_map i JOIN pages pg ON pg.id = i.local_id WHERE i.kind = 'page' ORDER BY pg.sort_order, pg.id") as $pg) {
            $noalt = 0;
            if (trim((string) $pg['blocks']) !== '') {
                try {
                    $noalt = Blocks::missingAlt(Blocks::decode((string) $pg['blocks']));
                } catch (MediaException) {
                    $noalt = 0;
                }
            }
            $pages[] = ['id' => (int) $pg['id'], 'title' => $pg['title'], 'status' => $pg['status'], 'slug' => $pg['slug'], 'noalt' => $noalt,
                'empty' => trim((string) $pg['blocks']) === '' && trim((string) $pg['body']) === ''];
        }
        $failed = [];
        foreach ($this->db->all("SELECT wp_id, local_id, note FROM import_map WHERE kind = 'photo' AND note LIKE 'Fehler:%' ORDER BY wp_id LIMIT 200") as $r) {
            $failed[] = ['wp' => (int) $r['wp_id'], 'photo' => (int) $r['local_id'], 'note' => (string) $r['note']];
        }
        return [
            'photos' => $photos,
            'missing_alt' => (int) $this->db->value("SELECT COUNT(*) FROM photos WHERE alt_text = '' AND deleted_at IS NULL AND id IN (SELECT local_id FROM import_map WHERE kind = 'photo' AND local_id > 0)"),
            'no_photographer' => (int) $this->db->value("SELECT COUNT(*) FROM photos WHERE photographer_id IS NULL AND deleted_at IS NULL AND id IN (SELECT local_id FROM import_map WHERE kind = 'photo' AND local_id > 0)"),
            'failed' => $failed,
            'duplicates' => (int) $this->db->value("SELECT COUNT(*) FROM import_map WHERE kind = 'photo' AND note LIKE 'Doppelt:%'"),
            'unused' => (int) $this->db->value("SELECT COUNT(*) FROM photos p WHERE p.deleted_at IS NULL AND p.id IN (SELECT local_id FROM import_map WHERE kind = 'photo' AND local_id > 0)
                AND NOT EXISTS (SELECT 1 FROM gallery_photos gp WHERE gp.photo_id = p.id)"),
            'galleries' => $galleries,
            'pages' => $pages,
            'redirects' => (int) $this->db->value('SELECT COUNT(*) FROM redirects'),
        ];
    }

    // --- Aufräumen

    /** Löscht die Paketdateien im Importordner (nur die im Paket genannten, dazu manifest.json). Nur nach vollständigem Import. @throws MediaException */
    public function purge(array $m): int
    {
        $c = $this->counts($m);
        if ($c['done'] < $c['photos'] || !$c['finished']) {
            throw new MediaException('Das Paket kann erst gelöscht werden, wenn alle Fotos übernommen und der Abschluss gelaufen ist.');
        }
        $n = 0;
        foreach (array_merge(array_column($m['photos'], 'file'), array_column($m['files'], 'file')) as $rel) {
            $abs = $this->packFile($rel);
            if ($abs !== null && @unlink($abs)) {
                $n++;
            }
        }
        @unlink($this->importDir . '/manifest.json');
        foreach (['bilder', 'dateien'] as $d) {
            @rmdir($this->importDir . '/' . $d);
        }
        $this->db->run("DELETE FROM meta WHERE k = 'import_finished'");
        return $n;
    }
}
