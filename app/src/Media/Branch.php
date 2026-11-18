<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/** Endgültiges Löschen eines Zweigs aus dem Papierkorb der Medienverwaltung, in Stapeln (all-inkl: kurze Anfragen). */
final class Branch
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Folders $folders,
        private readonly Catalog $catalog,
        private readonly Files $files,
    ) {
    }

    /**
     * Löscht bis zu $limit Fotos und Dateien des Zweigs und, wenn nichts mehr daran hängt, die Ordnerzeilen.
     *
     * @return array{purged:int,remaining:int}
     */
    public function purgeRoot(int $root, int $limit = 200): array
    {
        if ($this->db->value('SELECT 1 FROM folders WHERE id = ? AND trash_root_id = id AND deleted_at IS NOT NULL', [$root]) === null) {
            return ['purged' => 0, 'remaining' => 0];
        }
        $purged = 0;
        $photos = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all('SELECT id FROM photos WHERE trash_root_id = ? LIMIT ' . $limit, [$root]));
        $purged += $this->catalog->purge($photos);
        $left = $limit - count($photos);
        if ($left > 0) {
            $files = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all('SELECT id FROM files WHERE trash_root_id = ? LIMIT ' . $left, [$root]));
            $purged += $this->files->purge($files);
        }
        $remaining = (int) $this->db->value('SELECT (SELECT COUNT(*) FROM photos WHERE trash_root_id = ?) + (SELECT COUNT(*) FROM files WHERE trash_root_id = ?)', [$root, $root]);
        if ($remaining === 0) {
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all('SELECT id FROM folders WHERE trash_root_id = ?', [$root]));
            $this->folders->purgeRows($ids);
        }
        return ['purged' => $purged, 'remaining' => $remaining];
    }

    /** Zweige und einzelne Dateien, die länger als 30 Tage im Papierkorb liegen (ein Stapel je Aufruf). */
    public function purgeExpired(int $limit = 200): int
    {
        $cut = $this->clock->now() - Catalog::TRASH_DAYS * 86400;
        $n = 0;
        foreach ($this->db->all('SELECT id FROM folders WHERE trash_root_id = id AND deleted_at IS NOT NULL AND deleted_at <= ? LIMIT 20', [$cut]) as $r) {
            $n += $this->purgeRoot((int) $r['id'], max(1, $limit - $n))['purged'];
            if ($n >= $limit) {
                return $n;
            }
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all('SELECT id FROM files WHERE deleted_at IS NOT NULL AND trash_root_id IS NULL AND deleted_at <= ? LIMIT ' . $limit, [$cut]));
        return $n + $this->files->purge($ids);
    }
}
