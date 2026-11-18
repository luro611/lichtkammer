<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Mediathek: Suche, Bearbeiten (einzeln und mehrere), Papierkorb (30 Tage), Fotografen.
 * Originale werden nur beim endgültigen Löschen nach Ablauf der Frist (oder auf ausdrücklichen Wunsch) entfernt, nie verändert.
 */
final class Catalog
{
    public const TRASH_DAYS = 30;
    public const PAGE = 48;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Library $library,
        private readonly string $originalsDir,
        private readonly string $mediaDir,
    ) {
    }

    /**
     * @param array{q?:string,filter?:string,photographer?:int,year?:int,gallery?:int,page?:int} $f
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function search(array $f): array
    {
        $where = ['p.deleted_at IS NULL'];
        $args = [];
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . strtr(mb_substr($q, 0, 100), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $where[] = "(p.title LIKE :q ESCAPE '\\' OR p.caption LIKE :q ESCAPE '\\' OR p.place LIKE :q ESCAPE '\\' OR p.original_name LIKE :q ESCAPE '\\'
                OR CAST(p.year AS TEXT) LIKE :q ESCAPE '\\' OR EXISTS (SELECT 1 FROM gallery_photos gp JOIN galleries g ON g.id = gp.gallery_id WHERE gp.photo_id = p.id AND g.title LIKE :q ESCAPE '\\'))";
            $args[':q'] = $like;
        }
        switch ((string) ($f['filter'] ?? '')) {
            case 'ohne_galerie':
                $where[] = 'NOT EXISTS (SELECT 1 FROM gallery_photos gp WHERE gp.photo_id = p.id)';
                break;
            case 'ohne_alt':
                $where[] = "p.alt_text = ''";
                break;
            case 'fehler':
                $where[] = "p.status = 'failed'";
                break;
        }
        if (($f['photographer'] ?? 0) > 0) {
            $where[] = 'p.photographer_id = :ph';
            $args[':ph'] = (int) $f['photographer'];
        }
        if (($f['year'] ?? 0) > 0) {
            $where[] = 'p.year = :yr';
            $args[':yr'] = (int) $f['year'];
        }
        if (($f['gallery'] ?? 0) > 0) {
            $where[] = 'EXISTS (SELECT 1 FROM gallery_photos gp WHERE gp.photo_id = p.id AND gp.gallery_id = :gal)';
            $args[':gal'] = (int) $f['gallery'];
        }
        $w = implode(' AND ', $where);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM photos p WHERE $w", $args);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = min(max(1, (int) ($f['page'] ?? 1)), $pages);
        $rows = $this->db->all(
            'SELECT p.id, p.token, p.original_name, p.title, p.alt_text, p.status, p.error, p.width, p.height, p.year, p.place, p.taken_at, ' . Galleries::THUMB . " AS thumb
             FROM photos p WHERE $w ORDER BY p.id DESC LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE),
            $args,
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Auswahl für den Seiten-Editor: fertige Fotos, neueste zuerst, Suche in Titel, Alt-Text, Ort, Bildunterschrift, Dateiname.
     *
     * @return array{rows:list<array<string,mixed>>,page:int,more:bool}
     */
    public function pick(string $q, int $page): array
    {
        $args = [];
        $w = "p.deleted_at IS NULL AND p.status = 'ready'";
        $q = trim($q);
        if ($q !== '') {
            $w .= " AND (p.title LIKE :q ESCAPE '\\' OR p.alt_text LIKE :q ESCAPE '\\' OR p.place LIKE :q ESCAPE '\\' OR p.caption LIKE :q ESCAPE '\\' OR p.original_name LIKE :q ESCAPE '\\')";
            $args[':q'] = '%' . strtr(mb_substr($q, 0, 100), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        }
        $page = max(1, $page);
        $rows = $this->db->all(
            'SELECT p.id, p.title, p.caption, p.alt_text, p.original_name, ' . Galleries::THUMB . " AS thumb FROM photos p WHERE $w ORDER BY p.id DESC LIMIT 25 OFFSET " . (($page - 1) * 24),
            $args,
        );
        $more = count($rows) > 24;
        return ['rows' => array_slice($rows, 0, 24), 'page' => $page, 'more' => $more];
    }

    /** Fotos, die noch in keiner Galerie liegen und kein Foto der angegebenen Galerie sind (zum Hinzufügen). */
    public function searchNotIn(int $galleryId, string $q, int $page): array
    {
        $r = $this->search(['q' => $q, 'page' => $page]);
        $in = array_map('intval', array_column($this->db->all('SELECT photo_id FROM gallery_photos WHERE gallery_id = ?', [$galleryId]), 'photo_id'));
        foreach ($r['rows'] as &$row) {
            $row['in_gallery'] = in_array((int) $row['id'], $in, true);
        }
        return $r;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $p = $this->db->one('SELECT p.*, ' . Galleries::THUMB . ' AS thumb FROM photos p WHERE p.id = ? AND p.deleted_at IS NULL', [$id]);
        if ($p === null) {
            return null;
        }
        $p['exif'] = json_decode((string) $p['exif_json'], true) ?: [];
        $p['preview'] = $this->db->value("SELECT path FROM photo_variants WHERE photo_id = ? AND format = 'jpeg' ORDER BY ABS(width - 960) LIMIT 1", [$id]);
        return $p;
    }

    /** @return list<int> vorhandene Aufnahmejahre */
    public function years(): array
    {
        return array_map('intval', array_column($this->db->all('SELECT DISTINCT year FROM photos WHERE year IS NOT NULL AND deleted_at IS NULL ORDER BY year DESC'), 'year'));
    }

    /**
     * Felder eines Fotos. Nur Schlüssel in $fields werden gesetzt.
     *
     * @param array<string,string> $fields title, caption, alt_text, place, year, photographer_id
     */
    public function update(int $id, array $fields): void
    {
        $sets = [];
        $args = [];
        foreach (self::clean($fields, $this->db) as $col => $val) {
            $sets[] = "$col = ?";
            $args[] = $val;
        }
        if ($sets === []) {
            return;
        }
        $args[] = $this->clock->now();
        $args[] = $id;
        $n = $this->db->run('UPDATE photos SET ' . implode(', ', $sets) . ', updated_at = ? WHERE id = ? AND deleted_at IS NULL', $args)->rowCount();
        if ($n === 0) {
            throw new MediaException('Das Foto gibt es nicht (mehr).');
        }
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,string|int|null>
     */
    private static function clean(array $fields, Database $db): array
    {
        $out = [];
        foreach (['title' => 200, 'caption' => 1000, 'alt_text' => 500, 'place' => 120] as $col => $max) {
            if (array_key_exists($col, $fields)) {
                $out[$col] = Galleries::clip((string) $fields[$col], $max);
            }
        }
        if (array_key_exists('year', $fields)) {
            $y = trim((string) $fields['year']);
            if ($y === '') {
                $out['year'] = null;
            } elseif (ctype_digit($y) && (int) $y >= 1800 && (int) $y <= 2100) {
                $out['year'] = (int) $y;
            } else {
                throw new MediaException('Das Jahr muss zwischen 1800 und 2100 liegen.');
            }
        }
        if (array_key_exists('photographer_id', $fields)) {
            $p = trim((string) $fields['photographer_id']);
            if ($p === '') {
                $out['photographer_id'] = null;
            } elseif (ctype_digit($p) && $db->value('SELECT 1 FROM photographers WHERE id = ?', [(int) $p]) !== null) {
                $out['photographer_id'] = (int) $p;
            } else {
                throw new MediaException('Diese Person gibt es nicht in der Liste der Fotografen.');
            }
        }
        return $out;
    }

    /**
     * Gleiche Felder für viele Fotos. Alles oder nichts: ein Fehler in den Werten ändert kein Foto.
     *
     * @param list<int> $ids
     * @param array<string,string> $fields nur die ausdrücklich gesetzten Felder
     */
    public function bulkUpdate(array $ids, array $fields): int
    {
        $clean = self::clean($fields, $this->db); // wirft vor jeder Änderung
        if ($clean === [] || $ids === []) {
            return 0;
        }
        return $this->db->tx(function () use ($ids, $fields): int {
            $n = 0;
            foreach ($ids as $id) {
                try {
                    $this->update($id, $fields);
                    $n++;
                } catch (MediaException) {
                    // Foto inzwischen gelöscht: überspringen
                }
            }
            return $n;
        });
    }

    /** @param list<int> $ids */
    public function countExisting(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $n += $this->db->value('SELECT 1 FROM photos WHERE id = ? AND deleted_at IS NULL', [$id]) !== null ? 1 : 0;
        }
        return $n;
    }

    // --- Papierkorb ---

    /**
     * Legt Fotos in den Papierkorb und entfernt ihre Varianten sofort (nicht mehr öffentlich abrufbar).
     * Original, Texte und Galerie-Zuordnung bleiben, damit „Wiederherstellen“ alles an den alten Platz zurückbringt.
     *
     * @param list<int> $ids
     */
    public function trash(array $ids): int
    {
        $n = 0;
        $now = $this->clock->now();
        foreach ($ids as $id) {
            $done = $this->db->run('UPDATE photos SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$now, $now, $id])->rowCount();
            if ($done > 0) {
                $this->removeVariants($id);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Holt Fotos zurück und erzeugt die Varianten neu.
     *
     * @param list<int> $ids
     * @return array{restored:int,failed:int}
     */
    public function restore(array $ids): array
    {
        $ok = 0;
        $bad = 0;
        foreach ($ids as $id) {
            // Zweige aus der Medienverwaltung kommen nur als Ganzes zurück (Medien → Papierkorb)
            $done = $this->db->run('UPDATE photos SET deleted_at = NULL, updated_at = ? WHERE id = ? AND deleted_at IS NOT NULL AND trash_root_id IS NULL', [$this->clock->now(), $id])->rowCount();
            if ($done === 0) {
                continue;
            }
            $fid = $this->db->value('SELECT folder_id FROM photos WHERE id = ?', [$id]);
            if ($fid !== null && $this->db->value('SELECT 1 FROM folders WHERE id = ? AND deleted_at IS NULL', [$fid]) === null) {
                $this->db->run('UPDATE photos SET folder_id = NULL WHERE id = ?', [$id]); // Ordner liegt im Papierkorb oder ist weg: in die Wurzel
            }
            $r = $this->library->process($id);
            if ($r['status'] === 'failed') {
                $bad++;
            }
            $ok++;
        }
        return ['restored' => $ok, 'failed' => $bad];
    }

    /** @return list<array<string,mixed>> */
    public function trashed(): array
    {
        $rows = $this->db->all('SELECT id, original_name, title, bytes, deleted_at FROM photos WHERE deleted_at IS NOT NULL AND trash_root_id IS NULL ORDER BY deleted_at DESC, id DESC');
        $now = $this->clock->now();
        foreach ($rows as &$r) {
            $r['days_left'] = max(0, (int) ceil(((int) $r['deleted_at'] + self::TRASH_DAYS * 86400 - $now) / 86400));
        }
        return $rows;
    }

    /**
     * Endgültig löschen: Zeile (mit Varianten und Zuordnungen), Varianten und Original. Nur Fotos, die im Papierkorb liegen.
     *
     * @param list<int> $ids
     */
    public function purge(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $p = $this->db->one('SELECT id, original_path FROM photos WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
            if ($p === null) {
                continue;
            }
            $this->removeVariants($id);
            $this->db->run('UPDATE galleries SET cover_photo_id = NULL WHERE cover_photo_id = ?', [$id]);
            $this->db->run('DELETE FROM photos WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
            $rel = (string) $p['original_path'];
            $orig = $this->originalsDir . '/' . $rel;
            if (!str_contains($rel, '..') && $rel[0] !== '/' && is_file($orig)) { // nie außerhalb des Originale-Ordners löschen
                @unlink($orig);
            }
            $n++;
        }
        return $n;
    }

    /** Löscht, was länger als 30 Tage im Papierkorb liegt. Wird beim Aufruf der Fotoseiten ausgeführt (kein Cron nötig). */
    public function purgeExpired(): int
    {
        $limit = $this->clock->now() - self::TRASH_DAYS * 86400;
        // in Stapeln (kein Zeitlimit-Problem nach langer Pause); der Rest folgt beim nächsten Aufruf
        $ids = array_map('intval', array_column($this->db->all('SELECT id FROM photos WHERE deleted_at IS NOT NULL AND deleted_at <= ? LIMIT 200', [$limit]), 'id'));
        return $this->purge($ids);
    }

    private function removeVariants(int $id): void
    {
        foreach ($this->db->all('SELECT path FROM photo_variants WHERE photo_id = ?', [$id]) as $v) {
            $abs = $this->mediaDir . '/' . $v['path'];
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->db->run('DELETE FROM photo_variants WHERE photo_id = ?', [$id]);
    }

    // --- Fotografen ---

    /** @return list<array<string,mixed>> */
    public function photographers(): array
    {
        return $this->db->all('SELECT ph.id, ph.name, (SELECT COUNT(*) FROM photos p WHERE p.photographer_id = ph.id AND p.deleted_at IS NULL) AS photo_count FROM photographers ph ORDER BY ph.sort_order, ph.name COLLATE NOCASE');
    }

    public function addPhotographer(string $name): int
    {
        $name = Galleries::clip($name, 80);
        if ($name === '') {
            throw new MediaException('Bitte einen Namen eingeben.');
        }
        if ($this->db->value('SELECT 1 FROM photographers WHERE name = ? COLLATE NOCASE', [$name]) !== null) {
            throw new MediaException('Diese Person steht schon in der Liste.');
        }
        $this->db->run('INSERT INTO photographers(name, sort_order) VALUES(?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM photographers))', [$name]);
        return $this->db->lastId();
    }

    public function renamePhotographer(int $id, string $name): void
    {
        $name = Galleries::clip($name, 80);
        if ($name === '') {
            throw new MediaException('Bitte einen Namen eingeben.');
        }
        if ($this->db->value('SELECT 1 FROM photographers WHERE name = ? COLLATE NOCASE AND id <> ?', [$name, $id]) !== null) {
            throw new MediaException('Diese Person steht schon in der Liste.');
        }
        $this->db->run('UPDATE photographers SET name = ? WHERE id = ?', [$name, $id]);
    }

    /** Entfernt die Person aus der Liste; ihre Fotos bleiben, ohne Fotografen. */
    public function deletePhotographer(int $id): void
    {
        $this->db->run('DELETE FROM photographers WHERE id = ?', [$id]);
    }
}
