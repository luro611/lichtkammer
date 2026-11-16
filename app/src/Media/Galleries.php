<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Galerien und ihre Zuordnung zu Fotos (Tabelle gallery_photos: ein Foto kann in mehreren Galerien liegen,
 * jede Galerie hat ihre eigene Reihenfolge). Fotos im Papierkorb zählen nirgends mit, ihre Verknüpfungen bleiben aber bestehen.
 */
final class Galleries
{
    public const THUMB = "(SELECT path FROM photo_variants v WHERE v.photo_id = p.id AND v.format = 'jpeg' ORDER BY v.width LIMIT 1)";

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public static function slugify(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = strtr($t, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (function_exists('transliterator_transliterate')) {
            $t = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $t);
        }
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        $t = trim(substr($t, 0, 60), '-');
        return $t === '' ? 'galerie' : $t;
    }

    private function uniqueSlug(string $base, ?int $exceptId = null): string
    {
        $slug = $base;
        for ($i = 2; $this->db->value('SELECT 1 FROM galleries WHERE slug = ? AND id IS NOT ?', [$slug, $exceptId]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db->all(
            'SELECT g.*, (SELECT COUNT(*) FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = g.id AND p.deleted_at IS NULL) AS photo_count
             FROM galleries g ORDER BY g.sort_order, g.id',
        );
        foreach ($rows as &$r) {
            $r['cover'] = $this->coverPath((int) $r['id']);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $g = $this->db->one('SELECT * FROM galleries WHERE id = ?', [$id]);
        if ($g !== null) {
            $g['cover_effective'] = $this->effectiveCover($id);
        }
        return $g;
    }

    /** Titelbild: das gewählte, wenn es noch in der Galerie liegt, sonst das erste Foto. */
    public function effectiveCover(int $id): ?int
    {
        $v = $this->db->value(
            'SELECT p.id FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id
             WHERE gp.gallery_id = ? AND p.deleted_at IS NULL
             ORDER BY (p.id = (SELECT cover_photo_id FROM galleries WHERE id = ?)) DESC, gp.position, p.id LIMIT 1',
            [$id, $id],
        );
        return $v === null ? null : (int) $v;
    }

    private function coverPath(int $id): ?string
    {
        $pid = $this->effectiveCover($id);
        if ($pid === null) {
            return null;
        }
        $v = $this->db->value("SELECT path FROM photo_variants WHERE photo_id = ? AND format = 'jpeg' ORDER BY width LIMIT 1", [$pid]);
        return $v === null ? null : (string) $v;
    }

    public function create(string $title, string $description = ''): int
    {
        $title = self::clip($title, 200);
        if ($title === '') {
            throw new MediaException('Bitte einen Titel für die Galerie eingeben.');
        }
        $now = $this->clock->now();
        return $this->db->tx(function () use ($title, $description, $now): int {
            $this->db->run(
                'INSERT INTO galleries(slug, title, description, sort_order, created_at, updated_at)
                 VALUES(?,?,?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM galleries), ?, ?)',
                [$this->uniqueSlug(self::slugify($title)), $title, self::clip($description, 5000), $now, $now],
            );
            return $this->db->lastId();
        });
    }

    public function update(int $id, string $title, string $description, bool $showExif, ?string $slug = null): void
    {
        $g = $this->must($id);
        $title = self::clip($title, 200);
        if ($title === '') {
            throw new MediaException('Der Titel darf nicht leer sein.');
        }
        $newSlug = (string) $g['slug'];
        if ($slug !== null && trim($slug) !== '' && trim($slug) !== $g['slug']) {
            $newSlug = $this->uniqueSlug(self::slugify($slug), $id);
        }
        $this->db->run(
            'UPDATE galleries SET title=?, description=?, show_exif=?, slug=?, updated_at=? WHERE id=?',
            [$title, self::clip($description, 5000), $showExif ? 1 : 0, $newSlug, $this->clock->now(), $id],
        );
    }

    /** Löscht nur die Zusammenstellung; Fotos bleiben in der Mediathek. */
    public function delete(int $id): void
    {
        $this->must($id);
        $this->db->run('DELETE FROM galleries WHERE id = ?', [$id]); // gallery_photos wird per ON DELETE CASCADE geräumt
    }

    /**
     * Fotos, die eine Veröffentlichung verhindern: ohne Alt-Text oder noch nicht fertig verarbeitet.
     *
     * @return list<array<string,mixed>>
     */
    public function blockers(int $id): array
    {
        return $this->db->all(
            "SELECT p.id, p.original_name, p.title, p.status, p.alt_text FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id
             WHERE gp.gallery_id = ? AND p.deleted_at IS NULL AND (p.alt_text = '' OR p.status <> 'ready') ORDER BY gp.position, p.id",
            [$id],
        );
    }

    /**
     * @return array{ok:bool,reason:string,blockers:list<array<string,mixed>>}
     */
    public function setStatus(int $id, string $status): array
    {
        $this->must($id);
        if (!in_array($status, ['draft', 'published'], true)) {
            throw new MediaException('Unbekannter Status.');
        }
        if ($status === 'published') {
            $n = (int) $this->db->value('SELECT COUNT(*) FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? AND p.deleted_at IS NULL', [$id]);
            if ($n === 0) {
                return ['ok' => false, 'reason' => 'Die Galerie hat noch keine Fotos.', 'blockers' => []];
            }
            $bl = $this->blockers($id);
            if ($bl !== []) {
                return ['ok' => false, 'reason' => 'Vor dem Veröffentlichen brauchen alle Fotos einen Alt-Text und müssen fertig verarbeitet sein.', 'blockers' => $bl];
            }
        }
        $this->db->run('UPDATE galleries SET status=?, updated_at=? WHERE id=?', [$status, $this->clock->now(), $id]);
        return ['ok' => true, 'reason' => '', 'blockers' => []];
    }

    /** Fotos einer Galerie in ihrer Reihenfolge (ohne Papierkorb). @return list<array<string,mixed>> */
    public function photos(int $id): array
    {
        return $this->db->all(
            'SELECT p.id, p.token, p.original_name, p.title, p.alt_text, p.status, p.error, p.width, p.height, p.taken_at, p.on_home, gp.position, ' . self::THUMB . ' AS thumb
             FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? AND p.deleted_at IS NULL ORDER BY gp.position, p.id',
            [$id],
        );
    }

    /** @param list<int> $photoIds */
    public function addPhotos(int $id, array $photoIds): int
    {
        $this->must($id);
        $now = $this->clock->now();
        return $this->db->tx(function () use ($id, $photoIds, $now): int {
            $added = 0;
            foreach ($photoIds as $pid) {
                if ($this->db->value('SELECT 1 FROM photos WHERE id = ? AND deleted_at IS NULL', [$pid]) === null) {
                    continue;
                }
                $st = $this->db->run(
                    'INSERT OR IGNORE INTO gallery_photos(gallery_id, photo_id, position, added_at)
                     VALUES(?, ?, (SELECT COALESCE(MAX(position),0)+1 FROM gallery_photos WHERE gallery_id = ?), ?)',
                    [$id, $pid, $id, $now],
                );
                $added += $st->rowCount();
            }
            return $added;
        });
    }

    /** @param list<int> $photoIds */
    public function removePhotos(int $id, array $photoIds): int
    {
        $this->must($id);
        return $this->db->tx(function () use ($id, $photoIds): int {
            $n = 0;
            foreach ($photoIds as $pid) {
                $n += $this->db->run('DELETE FROM gallery_photos WHERE gallery_id = ? AND photo_id = ?', [$id, $pid])->rowCount();
                $this->db->run('UPDATE galleries SET cover_photo_id = NULL WHERE id = ? AND cover_photo_id = ?', [$id, $pid]);
            }
            return $n;
        });
    }

    /** Neue Reihenfolge der Fotos; was in $order fehlt, bleibt in alter Reihenfolge dahinter. @param list<int> $order */
    public function reorderPhotos(int $id, array $order): void
    {
        $this->must($id);
        $this->db->tx(function () use ($id, $order): void {
            $current = array_map('intval', array_column($this->db->all('SELECT photo_id FROM gallery_photos WHERE gallery_id = ? ORDER BY position, photo_id', [$id]), 'photo_id'));
            $final = [];
            foreach ($order as $pid) {
                if (in_array($pid, $current, true) && !in_array($pid, $final, true)) {
                    $final[] = $pid;
                }
            }
            foreach ($current as $pid) {
                if (!in_array($pid, $final, true)) {
                    $final[] = $pid;
                }
            }
            $this->writePositions($id, $final);
        });
    }

    /** Ein Foto um eine Stelle verschieben (Ersatz für Ziehen, ohne Skript und per Tastatur). */
    public function movePhoto(int $id, int $photoId, string $direction): void
    {
        $rows = $this->db->all('SELECT gp.photo_id, (p.deleted_at IS NULL) AS visible FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? ORDER BY gp.position, gp.photo_id', [$id]);
        $ids = array_map(static fn (array $r): int => (int) $r['photo_id'], $rows);
        // Nachbar ist das nächste sichtbare Foto; Fotos im Papierkorb behalten ihren Platz.
        $visible = [];
        foreach ($rows as $k => $r) {
            if ((int) $r['visible'] === 1) {
                $visible[] = $k;
            }
        }
        $a = array_search($photoId, array_map(static fn (int $k): int => $ids[$k], $visible), true);
        if ($a === false) {
            return;
        }
        $b = $direction === 'hoch' ? $a - 1 : $a + 1;
        if ($b < 0 || $b >= count($visible)) {
            return;
        }
        [$i, $j] = [$visible[$a], $visible[$b]];
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->db->tx(fn () => $this->writePositions($id, $ids));
    }

    /** Einmaliges Sortieren: date_asc, date_desc oder name. Fotos ohne Aufnahmedatum stehen bei Datum am Ende. */
    public function sortBy(int $id, string $mode): void
    {
        $this->must($id);
        $order = match ($mode) {
            'date_asc' => '(p.taken_at IS NULL), p.taken_at ASC, p.original_name COLLATE NOCASE, p.id',
            'date_desc' => '(p.taken_at IS NULL), p.taken_at DESC, p.original_name COLLATE NOCASE, p.id',
            'name' => 'p.original_name COLLATE NOCASE, p.id',
            default => throw new MediaException('Unbekannte Sortierung.'),
        };
        $this->db->tx(function () use ($id, $order): void {
            $ids = array_map(
                static fn (array $r): int => (int) $r['id'],
                $this->db->all("SELECT p.id FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? ORDER BY $order", [$id]),
            );
            $this->writePositions($id, $ids);
        });
    }

    /** @param list<int> $ids */
    private function writePositions(int $galleryId, array $ids): void
    {
        foreach ($ids as $pos => $pid) {
            $this->db->run('UPDATE gallery_photos SET position = ? WHERE gallery_id = ? AND photo_id = ?', [$pos + 1, $galleryId, $pid]);
        }
    }

    public function setCover(int $id, int $photoId): void
    {
        $this->must($id);
        if ($this->db->value('SELECT 1 FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE gp.gallery_id = ? AND gp.photo_id = ? AND p.deleted_at IS NULL', [$id, $photoId]) === null) {
            throw new MediaException('Das Foto gehört nicht zu dieser Galerie.');
        }
        $this->db->run('UPDATE galleries SET cover_photo_id = ?, updated_at = ? WHERE id = ?', [$photoId, $this->clock->now(), $id]);
    }

    /** Schalter „Startseite“ eines Fotos (Block „Startseiten-Fotos“). */
    public function setHome(int $photoId, bool $on): void
    {
        $n = $this->db->run('UPDATE photos SET on_home = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$on ? 1 : 0, $this->clock->now(), $photoId])->rowCount();
        if ($n === 0) {
            throw new MediaException('Das Foto gibt es nicht (mehr).');
        }
    }

    /** @param list<int> $order Galerien in neuer Reihenfolge */
    public function reorderGalleries(array $order): void
    {
        $this->db->tx(function () use ($order): void {
            $current = array_map('intval', array_column($this->db->all('SELECT id FROM galleries ORDER BY sort_order, id'), 'id'));
            $final = [];
            foreach (array_merge($order, $current) as $gid) {
                if (in_array($gid, $current, true) && !in_array($gid, $final, true)) {
                    $final[] = $gid;
                }
            }
            foreach ($final as $pos => $gid) {
                $this->db->run('UPDATE galleries SET sort_order = ? WHERE id = ?', [$pos + 1, $gid]);
            }
        });
    }

    public function moveGallery(int $id, string $direction): void
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all('SELECT id FROM galleries ORDER BY sort_order, id'));
        $i = array_search($id, $ids, true);
        $j = $direction === 'hoch' ? (int) $i - 1 : (int) $i + 1;
        if ($i === false || $j < 0 || $j >= count($ids)) {
            return;
        }
        [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
        $this->reorderGalleries($ids);
    }

    /** Galerien eines Fotos. @return list<array<string,mixed>> */
    public function ofPhoto(int $photoId): array
    {
        return $this->db->all('SELECT g.id, g.title, g.status FROM gallery_photos gp JOIN galleries g ON g.id = gp.gallery_id WHERE gp.photo_id = ? ORDER BY g.sort_order, g.id', [$photoId]);
    }

    private function must(int $id): array
    {
        $g = $this->db->one('SELECT * FROM galleries WHERE id = ?', [$id]);
        if ($g === null) {
            throw new MediaException('Die Galerie gibt es nicht (mehr).');
        }
        return $g;
    }

    public static function clip(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]+/u', '', trim($s)) ?? '';
        return mb_substr($s, 0, $max);
    }
}
