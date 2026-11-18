<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Database;

/** Inhalt eines Ordners für die Medienansicht: Fotos und Dateien zusammen, sortiert, in Seiten zu je 200. */
final class Browser
{
    public const PAGE = 200;
    private const SORT = ['name' => 'name COLLATE NOCASE', 'datum' => 'at', 'groesse' => 'bytes', 'art' => 'type, mime'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     *   rows: type photo|file, id, name, bytes, at, mime, status, folder_id, thumb, pub, kind, alt
     */
    public function contents(int $folder, string $q = '', string $sort = 'name', bool $desc = false, int $page = 1): array
    {
        $q = trim($q);
        $args = [];
        $pw = 'p.deleted_at IS NULL';
        $fw = 'f.deleted_at IS NULL';
        if ($q !== '') { // Suche gilt für die ganze Medienverwaltung
            $like = '%' . strtr(mb_substr($q, 0, 100), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
            $pw .= " AND (p.original_name LIKE :q ESCAPE '\\' OR p.title LIKE :q ESCAPE '\\')";
            $fw .= " AND f.name LIKE :q ESCAPE '\\'";
            $args[':q'] = $like;
        } elseif ($folder === 0) {
            $pw .= ' AND p.folder_id IS NULL';
            $fw .= ' AND f.folder_id = 0';
        } else {
            $pw .= ' AND p.folder_id = :fo';
            $fw .= ' AND f.folder_id = :fo';
            $args[':fo'] = $folder;
        }
        $union = "SELECT 'photo' AS type, p.id AS id, p.original_name AS name, p.bytes AS bytes, p.created_at AS at, p.mime AS mime, p.status AS status, COALESCE(p.folder_id, 0) AS folder_id,
                    " . Galleries::THUMB . " AS thumb, 0 AS pub, 'photo' AS kind, p.alt_text AS alt FROM photos p WHERE $pw
                  UNION ALL
                  SELECT 'file', f.id, f.name, f.bytes, f.created_at, f.mime, 'ready', f.folder_id, NULL, f.public, f.kind, '' FROM files f WHERE $fw";
        $total = (int) $this->db->value("SELECT COUNT(*) FROM ($union)", $args);
        $pages = max(1, (int) ceil($total / self::PAGE));
        $page = min(max(1, $page), $pages);
        $order = (self::SORT[$sort] ?? self::SORT['name']) . ($desc ? ' DESC' : ' ASC') . ', id';
        $rows = $this->db->all("SELECT * FROM ($union) ORDER BY $order LIMIT " . self::PAGE . ' OFFSET ' . (($page - 1) * self::PAGE), $args);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Fotos im Papierkorb, die nach Wiederherstellen eines Zweigs noch auf Varianten warten. */
    public function pendingPhotos(int $limit = 5): array
    {
        return array_map(static fn (array $r): int => (int) $r['id'], $this->db->all("SELECT id FROM photos WHERE deleted_at IS NULL AND status = 'pending' ORDER BY id LIMIT " . $limit));
    }

    public function pendingCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM photos WHERE deleted_at IS NULL AND status = 'pending'");
    }

    /** Belegter Platz (Fotos-Originale und Dateien). @return array{photos:int,files:int} */
    public function usage(): array
    {
        return [
            'photos' => (int) $this->db->value('SELECT COALESCE(SUM(bytes),0) FROM photos'),
            'files' => (int) $this->db->value('SELECT COALESCE(SUM(bytes),0) FROM files'),
        ];
    }
}
