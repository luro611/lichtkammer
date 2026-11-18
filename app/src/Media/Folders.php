<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Ordner der Medienverwaltung (Entwurf 16): nur Datenbankzeilen. Die Wurzel („Medien“) ist parent_id = 0 und hat keine Zeile.
 * Ein Name wird nie zum Pfad auf der Festplatte. Alles, was mehrere Zeilen ändert, läuft in einer Transaktion.
 */
final class Folders
{
    public const MAX_DEPTH = 8;
    public const MAX_FOLDERS = 5000;
    public const BATCH = 100;

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    // --- Lesen ---

    /** @return array<string,mixed>|null nur nicht gelöschte Ordner */
    public function get(int $id): ?array
    {
        return $id < 1 ? null : $this->db->one('SELECT * FROM folders WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** Alle lebenden Ordner (für den Baum), natürlich nach Name sortiert. @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = $this->db->all('SELECT id, parent_id, name, gallery_id FROM folders WHERE deleted_at IS NULL');
        usort($rows, static fn (array $a, array $b): int => Names::natural((string) $a['name'], (string) $b['name']));
        return $rows;
    }

    /** @return list<array<string,mixed>> Unterordner mit Anzahl (Fotos, Dateien, Ordner) */
    public function children(int $parent): array
    {
        $rows = $this->db->all('SELECT * FROM folders WHERE parent_id = ? AND deleted_at IS NULL', [$parent]);
        usort($rows, static fn (array $a, array $b): int => Names::natural((string) $a['name'], (string) $b['name']));
        foreach ($rows as &$r) {
            $r += $this->counts((int) $r['id']);
        }
        return $rows;
    }

    /** @return array{photos:int,files:int,folders:int} */
    public function counts(int $id): array
    {
        return [
            'photos' => (int) $this->db->value($id === 0 ? 'SELECT COUNT(*) FROM photos WHERE folder_id IS NULL AND deleted_at IS NULL' : 'SELECT COUNT(*) FROM photos WHERE folder_id = ? AND deleted_at IS NULL', $id === 0 ? [] : [$id]),
            'files' => (int) $this->db->value('SELECT COUNT(*) FROM files WHERE folder_id = ? AND deleted_at IS NULL', [$id]),
            'folders' => (int) $this->db->value('SELECT COUNT(*) FROM folders WHERE parent_id = ? AND deleted_at IS NULL', [$id]),
        ];
    }

    /** Brotkrumen von der Wurzel bis zum Ordner (ohne Wurzel). @return list<array{id:int,name:string}> */
    public function breadcrumb(int $id): array
    {
        $out = [];
        for ($i = 0; $id > 0 && $i <= self::MAX_DEPTH + 2; $i++) {
            $f = $this->db->one('SELECT id, parent_id, name FROM folders WHERE id = ?', [$id]);
            if ($f === null) {
                break;
            }
            array_unshift($out, ['id' => (int) $f['id'], 'name' => (string) $f['name']]);
            $id = (int) $f['parent_id'];
        }
        return $out;
    }

    /** Tiefe eines Ordners: Wurzel = 0, Ordner der obersten Ebene = 1. */
    public function depth(int $id): int
    {
        return $id < 1 ? 0 : count($this->breadcrumb($id));
    }

    /** Ist der Ordner (oder einer seiner Vorfahren) im Papierkorb oder gar nicht vorhanden? */
    public function isUsable(int $id): bool
    {
        if ($id === 0) {
            return true;
        }
        for ($i = 0; $id > 0 && $i <= self::MAX_DEPTH + 2; $i++) {
            $f = $this->db->one('SELECT parent_id, deleted_at FROM folders WHERE id = ?', [$id]);
            if ($f === null || $f['deleted_at'] !== null) {
                return false;
            }
            $id = (int) $f['parent_id'];
        }
        return true;
    }

    /** Ordner und alle Nachkommen (nicht gelöscht, wenn $live). @return list<int> */
    public function subtree(int $id, bool $live = true): array
    {
        $rows = $this->db->all(
            'WITH RECURSIVE t(id) AS (SELECT id FROM folders WHERE id = ? UNION ALL SELECT f.id FROM folders f JOIN t ON f.parent_id = t.id)
             SELECT f.id FROM folders f JOIN t ON t.id = f.id' . ($live ? ' WHERE f.deleted_at IS NULL' : ''),
            [$id],
        );
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** Größte Tiefe unterhalb eines Ordners (0 = keine Unterordner). */
    private function heightBelow(int $id): int
    {
        $max = 0;
        $level = [$id];
        for ($d = 1; $level !== [] && $d <= self::MAX_DEPTH + 2; $d++) {
            $in = implode(',', array_fill(0, count($level), '?'));
            $next = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all("SELECT id FROM folders WHERE parent_id IN ($in) AND deleted_at IS NULL", $level));
            if ($next !== []) {
                $max = $d;
            }
            $level = $next;
        }
        return $max;
    }

    // --- Anlegen, Umbenennen ---

    /** Neuer Ordner. Gibt es den Namen schon, ist das ein Fehler (anders als ensure). @throws MediaException */
    public function create(int $parent, string $name): int
    {
        if (str_contains($name, '/')) { // wie beim Umbenennen: ein Name enthält nie einen Schrägstrich
            throw new MediaException('Ein Name darf keinen Schrägstrich enthalten.');
        }
        $c = Names::clean($name);
        return $this->db->tx(function () use ($parent, $c): int {
            $this->mustBeUsable($parent); // innerhalb der Transaktion: kein Anlegen unter einem Ordner, der gerade in den Papierkorb kam
            if ($this->db->value('SELECT 1 FROM folders WHERE parent_id = ? AND name_key = ? AND deleted_at IS NULL', [$parent, $c['key']]) !== null) {
                throw new MediaException('Diesen Namen gibt es hier schon.');
            }
            $this->checkLimits($parent, 1);
            return $this->insert($parent, $c['name'], $c['key']);
        });
    }

    /**
     * Legt den Pfad an und führt mit vorhandenen Ordnern gleichen Namens zusammen. Gibt die Kennung des letzten Ordners
     * und die neu angelegten Kennungen zurück.
     *
     * @param list<string> $segments schon geprüft (Names::segments)
     * @return array{id:int,created:list<int>}
     * @throws MediaException
     */
    public function ensurePath(int $parent, array $segments): array
    {
        return $this->db->tx(function () use ($parent, $segments): array {
            $this->mustBeUsable($parent);
            $created = [];
            $cur = $parent;
            foreach ($segments as $seg) {
                $c = Names::clean($seg);
                $existing = $this->db->value('SELECT id FROM folders WHERE parent_id = ? AND name_key = ? AND deleted_at IS NULL', [$cur, $c['key']]);
                if ($existing !== null) {
                    $cur = (int) $existing;
                    continue;
                }
                $this->checkLimits($cur, 1);
                $cur = $this->insert($cur, $c['name'], $c['key']);
                $created[] = $cur;
            }
            return ['id' => $cur, 'created' => $created];
        });
    }

    private function insert(int $parent, string $name, string $key): int
    {
        $now = $this->clock->now();
        $this->db->run('INSERT INTO folders(parent_id, name, name_key, created_at, updated_at) VALUES(?,?,?,?,?)', [$parent, $name, $key, $now, $now]);
        return $this->db->lastId();
    }

    /** Grenzen vor dem Anlegen von $n neuen Ordnern unter $parent (Tiefe, Gesamtzahl). @throws MediaException */
    private function checkLimits(int $parent, int $n): void
    {
        if ($this->depth($parent) + 1 > self::MAX_DEPTH) {
            throw new MediaException('Die Ordner sind zu tief verschachtelt (höchstens ' . self::MAX_DEPTH . ' Ebenen).');
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM folders WHERE deleted_at IS NULL') + $n > self::MAX_FOLDERS) {
            throw new MediaException('Es gibt schon die höchste Zahl an Ordnern (' . self::MAX_FOLDERS . ').');
        }
    }

    /** @throws MediaException */
    private function mustBeUsable(int $parent): void
    {
        if (!$this->isUsable($parent)) {
            throw new MediaException('Der Zielordner gibt es nicht (mehr) oder er liegt im Papierkorb.');
        }
    }

    /** @throws MediaException */
    public function rename(int $id, string $name): void
    {
        if (str_contains($name, '/')) {
            throw new MediaException('Ein Name darf keinen Schrägstrich enthalten.');
        }
        $c = Names::clean($name);
        $this->db->tx(function () use ($id, $c): void {
            $f = $this->get($id);
            if ($f === null) {
                throw new MediaException('Den Ordner gibt es nicht (mehr).');
            }
            if ($this->db->value('SELECT 1 FROM folders WHERE parent_id = ? AND name_key = ? AND deleted_at IS NULL AND id <> ?', [$f['parent_id'], $c['key'], $id]) !== null) {
                throw new MediaException('Diesen Namen gibt es hier schon.');
            }
            $this->db->run('UPDATE folders SET name = ?, name_key = ?, updated_at = ? WHERE id = ?', [$c['name'], $c['key'], $this->clock->now(), $id]);
        });
    }

    // --- Verschieben ---

    /**
     * Verschiebt Ordner, Fotos und Dateien in einen Zielordner (0 = Wurzel). Alles oder nichts.
     *
     * @param list<int> $folderIds @param list<int> $photoIds @param list<int> $fileIds
     * @return int Anzahl verschobener Einträge
     * @throws MediaException
     */
    public function move(array $folderIds, array $photoIds, array $fileIds, int $target): int
    {
        return $this->db->tx(function () use ($folderIds, $photoIds, $fileIds, $target): int {
            $this->mustBeUsable($target);
            $now = $this->clock->now();
            $n = 0;
            $targetDepth = $this->depth($target);
            $names = [];
            foreach ($folderIds as $id) {
                $f = $this->get($id);
                if ($f === null) {
                    continue;
                }
                if ($target > 0 && in_array($target, $this->subtree($id), true)) { // Zyklus: in sich selbst oder einen Nachkommen
                    throw new MediaException('Ein Ordner lässt sich nicht in sich selbst oder in einen seiner Unterordner verschieben.');
                }
                if ($targetDepth + 1 + $this->heightBelow($id) > self::MAX_DEPTH) {
                    throw new MediaException('Dann wären die Ordner zu tief verschachtelt (höchstens ' . self::MAX_DEPTH . ' Ebenen).');
                }
                $key = (string) $f['name_key'];
                if (isset($names[$key]) || ((int) $f['parent_id'] !== $target
                    && $this->db->value('SELECT 1 FROM folders WHERE parent_id = ? AND name_key = ? AND deleted_at IS NULL', [$target, $key]) !== null)) {
                    throw new MediaException('Im Zielordner gibt es schon einen Ordner mit dem Namen „' . $f['name'] . '“. Bitte erst umbenennen.');
                }
                $names[$key] = true;
            }
            foreach ($folderIds as $id) {
                $n += $this->db->run('UPDATE folders SET parent_id = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$target, $now, $id])->rowCount();
            }
            foreach ($photoIds as $id) {
                $n += $this->db->run('UPDATE photos SET folder_id = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$target === 0 ? null : $target, $now, $id])->rowCount();
            }
            foreach ($fileIds as $id) {
                $n += $this->db->run('UPDATE files SET folder_id = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [$target, $now, $id])->rowCount();
            }
            return $n;
        });
    }

    // --- Papierkorb ---

    /**
     * Legt einen Ordner mit allem darunter in den Papierkorb. In allen Zeilen steht die Kennung des Wurzelordners (trash_root_id),
     * damit Wiederherstellen genau diesen Zweig zurückholt. Nur Datenbankänderungen: schnell, in einer Transaktion.
     * Die Bildvarianten entfernt anschließend finishTrash() in Stapeln.
     *
     * @return array{folders:int,photos:int,files:int,galleries:list<int>} galleries: Galerien, in denen betroffene Fotos liegen
     * @throws MediaException
     */
    public function trash(int $id): array
    {
        return $this->db->tx(function () use ($id): array {
            if ($this->get($id) === null) {
                throw new MediaException('Den Ordner gibt es nicht (mehr).');
            }
            $now = $this->clock->now();
            $ids = $this->subtree($id);
            $in = implode(',', array_map('intval', $ids)); // nur Zahlen aus der Datenbank
            $folders = $this->db->run("UPDATE folders SET deleted_at = ?, trash_root_id = ?, updated_at = ? WHERE id IN ($in) AND deleted_at IS NULL", [$now, $id, $now])->rowCount();
            $galleries = array_map(static fn (array $r): int => (int) $r['gallery_id'], $this->db->all("SELECT DISTINCT gp.gallery_id FROM gallery_photos gp JOIN photos p ON p.id = gp.photo_id WHERE p.folder_id IN ($in) AND p.deleted_at IS NULL"));
            $photos = $this->db->run("UPDATE photos SET deleted_at = ?, trash_root_id = ?, updated_at = ? WHERE folder_id IN ($in) AND deleted_at IS NULL", [$now, $id, $now])->rowCount();
            $files = $this->db->run("UPDATE files SET deleted_at = ?, trash_root_id = ?, updated_at = ? WHERE folder_id IN ($in) AND deleted_at IS NULL", [$now, $id, $now])->rowCount();
            return ['folders' => $folders, 'photos' => $photos, 'files' => $files, 'galleries' => $galleries];
        });
    }

    /** Wurzeln im Papierkorb mit Zahlen. @return list<array<string,mixed>> */
    public function trashedRoots(): array
    {
        $rows = $this->db->all('SELECT id, name, deleted_at FROM folders WHERE deleted_at IS NOT NULL AND trash_root_id = id ORDER BY deleted_at DESC, id DESC');
        $now = $this->clock->now();
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            $r['photos'] = (int) $this->db->value('SELECT COUNT(*) FROM photos WHERE trash_root_id = ?', [$id]);
            $r['files'] = (int) $this->db->value('SELECT COUNT(*) FROM files WHERE trash_root_id = ?', [$id]);
            $r['folders'] = (int) $this->db->value('SELECT COUNT(*) FROM folders WHERE trash_root_id = ? AND id <> ?', [$id, $id]);
            $r['days_left'] = max(0, (int) ceil(((int) $r['deleted_at'] + Catalog::TRASH_DAYS * 86400 - $now) / 86400));
        }
        return $rows;
    }

    /**
     * Holt einen Zweig zurück. Liegt der frühere Elternordner selbst im Papierkorb oder fehlt er, kommt der Zweig in die Wurzel.
     * Gleiche Namen werden zusammengeführt, nie überschrieben und nie als Fehler abgewiesen. Fotos werden auf „pending“ gesetzt
     * (ihre Varianten erzeugt der vorhandene Verarbeitungsweg nachträglich).
     *
     * @return array{folders:int,photos:int,files:int,to_root:bool}
     * @throws MediaException
     */
    public function restore(int $root): array
    {
        return $this->db->tx(function () use ($root): array {
            $f = $this->db->one('SELECT * FROM folders WHERE id = ? AND trash_root_id = id AND deleted_at IS NOT NULL', [$root]);
            if ($f === null) {
                throw new MediaException('Diesen Zweig gibt es im Papierkorb nicht (mehr).');
            }
            $parent = (int) $f['parent_id'];
            $toRoot = false;
            if ($parent !== 0 && !$this->isUsable($parent)) {
                $parent = 0;
                $toRoot = true;
            }
            $stat = ['folders' => 0, 'photos' => 0, 'files' => 0];
            $this->restoreInto($f, $parent, $root, $stat);
            return $stat + ['to_root' => $toRoot];
        });
    }

    /** @param array<string,mixed> $f @param array{folders:int,photos:int,files:int} $stat */
    private function restoreInto(array $f, int $parent, int $root, array &$stat): void
    {
        $id = (int) $f['id'];
        $now = $this->clock->now();
        $existing = $this->db->value('SELECT id FROM folders WHERE parent_id = ? AND name_key = ? AND deleted_at IS NULL AND id <> ?', [$parent, $f['name_key'], $id]);
        $target = $id;
        if ($existing !== null) { // zusammenführen: Inhalt zum vorhandenen Ordner, der alte Eintrag entfällt
            $target = (int) $existing;
        } else {
            $this->db->run('UPDATE folders SET parent_id = ?, deleted_at = NULL, trash_root_id = NULL, updated_at = ? WHERE id = ?', [$parent, $now, $id]);
            $stat['folders']++;
        }
        $stat['photos'] += $this->db->run("UPDATE photos SET folder_id = ?, deleted_at = NULL, trash_root_id = NULL, status = 'pending', updated_at = ? WHERE folder_id = ? AND trash_root_id = ?", [$target, $now, $id, $root])->rowCount();
        $stat['files'] += $this->db->run('UPDATE files SET folder_id = ?, deleted_at = NULL, trash_root_id = NULL, updated_at = ? WHERE folder_id = ? AND trash_root_id = ?', [$target, $now, $id, $root])->rowCount();
        foreach ($this->db->all('SELECT * FROM folders WHERE parent_id = ? AND trash_root_id = ?', [$id, $root]) as $child) {
            $this->restoreInto($child, $target, $root, $stat);
        }
        if ($existing !== null) {
            $this->db->run('DELETE FROM folders WHERE id = ?', [$id]);
        }
    }

    /**
     * Entfernt die Bildvarianten von Fotos im Papierkorb eines Zweigs in Stapeln (nicht mehr öffentlich abrufbar).
     * Wiederholbar; gibt zurück, wie viele noch offen sind.
     */
    public function finishTrash(string $mediaDir, int $limit = self::BATCH): int
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->db->all(
            'SELECT DISTINCT p.id FROM photos p JOIN photo_variants v ON v.photo_id = p.id WHERE p.deleted_at IS NOT NULL LIMIT ' . ($limit + 1),
        ));
        foreach (array_slice($ids, 0, $limit) as $pid) {
            foreach ($this->db->all('SELECT path FROM photo_variants WHERE photo_id = ?', [$pid]) as $v) {
                $abs = $mediaDir . '/' . $v['path'];
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
            $this->db->run('DELETE FROM photo_variants WHERE photo_id = ?', [$pid]);
        }
        return max(0, count($ids) - $limit);
    }

    /**
     * Wie finishTrash, aber wiederholt in Stapeln, bis nichts mehr offen ist oder die Zeit um ist. Gelöschte Fotos sollen nicht
     * länger als nötig über ihre Bildadressen abrufbar sein (nur ein Stapel je Aufruf ließ bei großen Zweigen den Rest stehen).
     *
     * @return int noch offene Fotos
     */
    public function finishTrashWithin(string $mediaDir, float $seconds): int
    {
        $deadline = microtime(true) + $seconds;
        do {
            $left = $this->finishTrash($mediaDir);
        } while ($left > 0 && microtime(true) < $deadline);
        return $left;
    }

    /**
     * Löscht Ordnerzeilen endgültig. Fotos und Dateien müssen vorher endgültig entfernt sein. Alles, was noch auf den Ordner
     * zeigt (einzeln gelöschte oder durch ein Rennen lebende Fotos, Dateien, Unterordner), kommt in die Wurzel.
     *
     * @param list<int> $ids Kennungen von Zeilen im Papierkorb
     */
    public function purgeRows(array $ids): int
    {
        return $this->db->tx(function () use ($ids): int {
            $n = 0;
            foreach ($ids as $id) {
                $f = $this->db->one('SELECT id FROM folders WHERE id = ? AND deleted_at IS NOT NULL', [$id]);
                if ($f === null) {
                    continue;
                }
                // Lebender Inhalt unter einem Ordner im Papierkorb entsteht nur durch ein Rennen (Upload oder Anlegen läuft, während
                // der Ordner gelöscht wird). Er wird nie mitgelöscht und blockiert auch nichts: er kommt in die Wurzel.
                foreach ($this->db->all('SELECT id, name, name_key FROM folders WHERE parent_id = ? AND deleted_at IS NULL', [$id]) as $kid) {
                    $name = (string) $kid['name'];
                    $key = (string) $kid['name_key'];
                    for ($i = 2; $this->db->value('SELECT 1 FROM folders WHERE parent_id = 0 AND name_key = ? AND deleted_at IS NULL', [$key]) !== null; $i++) {
                        $n2 = Names::clean($kid['name'] . ' (gerettet ' . $i . ')');
                        $name = $n2['name'];
                        $key = $n2['key'];
                    }
                    $this->db->run('UPDATE folders SET parent_id = 0, name = ?, name_key = ?, updated_at = ? WHERE id = ?', [$name, $key, $this->clock->now(), $kid['id']]);
                }
                $this->db->run('UPDATE photos SET folder_id = NULL WHERE folder_id = ?', [$id]);
                $this->db->run('UPDATE files SET folder_id = 0 WHERE folder_id = ?', [$id]);
                $this->db->run('DELETE FROM folders WHERE id = ?', [$id]);
                $n++;
            }
            return $n;
        });
    }

    /** Verknüpfte Galerie als Merkhilfe (3.4). */
    public function setGallery(int $id, ?int $galleryId): void
    {
        $this->db->run('UPDATE folders SET gallery_id = ?, updated_at = ? WHERE id = ?', [$galleryId, $this->clock->now(), $id]);
    }

    /** Ordner, in dem die Kennung des Fotos liegt (Name, nur Anzeige). */
    public function nameOf(?int $id): string
    {
        return $id === null || $id < 1 ? 'Medien' : (string) ($this->db->value('SELECT name FROM folders WHERE id = ?', [$id]) ?? 'Medien');
    }
}
