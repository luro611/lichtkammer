<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Upload in Stücken: start → chunk (der Reihe nach) → finish. Das Teilstück liegt im Datenordner
 * (nicht öffentlich). Erst finish prüft die Datei am Inhalt und legt das Original ab.
 */
final class Uploads
{
    public const MAX_CHUNK = 8 * 1024 * 1024;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly Library $library,
        private readonly string $dataDir,
        private readonly int $maxBytes,
    ) {
    }

    /** @throws MediaException */
    public function start(int $userId, string $name, int $size, ?int $galleryId): string
    {
        if ($size < 1) {
            throw new MediaException('Die Datei ist leer.');
        }
        if ($size > $this->maxBytes) {
            throw new MediaException('Die Datei ist zu groß (höchstens ' . (int) round($this->maxBytes / 1048576) . ' MB).');
        }
        if ($galleryId !== null && $this->db->value('SELECT 1 FROM galleries WHERE id = ?', [$galleryId]) === null) {
            $galleryId = null;
        }
        $this->cleanup();
        $id = bin2hex(random_bytes(16));
        $dir = $this->dir();
        $part = $dir . '/' . $id . '.part';
        if (@file_put_contents($part, '') === false) {
            throw new MediaException('Der Upload-Ordner ist nicht beschreibbar.');
        }
        @chmod($part, 0600);
        $now = $this->clock->now();
        $this->db->run('INSERT INTO uploads(id, user_id, original_name, size, received, gallery_id, created_at, updated_at) VALUES(?,?,?,?,0,?,?,?)',
            [$id, $userId, Library::cleanName($name), $size, $galleryId, $now, $now]);
        return $id;
    }

    /**
     * Hängt das nächste Stück an. Der Versatz muss genau dem bisher Empfangenen entsprechen.
     *
     * @return array{received:int}
     * @throws MediaException  code 409 bei falschem Versatz (Antwort enthält den richtigen Stand)
     */
    public function chunk(int $userId, string $id, int $offset, string $bytes): array
    {
        $up = $this->find($id, $userId);
        $len = strlen($bytes);
        if ($len < 1 || $len > self::MAX_CHUNK) {
            throw new MediaException('Das Teilstück hat eine ungültige Größe.');
        }
        if ($offset !== (int) $up['received']) {
            throw new MediaException((string) $up['received'], 409);
        }
        if ($offset + $len > (int) $up['size']) {
            throw new MediaException('Es kamen mehr Daten an als angekündigt.');
        }
        $part = $this->dir() . '/' . $id . '.part';
        $fh = @fopen($part, 'cb');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            throw new MediaException('Das Teilstück konnte nicht gespeichert werden.');
        }
        try {
            fseek($fh, $offset);
            if (fwrite($fh, $bytes) !== $len) {
                throw new MediaException('Das Teilstück konnte nicht gespeichert werden. Bitte Speicherplatz prüfen.');
            }
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->db->run('UPDATE uploads SET received = ?, updated_at = ? WHERE id = ? AND received = ?', [$offset + $len, $this->clock->now(), $id, $offset]);
        return ['received' => $offset + $len];
    }

    /** @return array{duplicate:bool,photo_id:int,in_trash:bool,name:string} @throws MediaException */
    public function finish(int $userId, string $id): array
    {
        $up = $this->find($id, $userId);
        $part = $this->dir() . '/' . $id . '.part';
        try {
            if ((int) $up['received'] !== (int) $up['size'] || !is_file($part) || filesize($part) !== (int) $up['size']) {
                throw new MediaException('Der Upload ist unvollständig. Bitte noch einmal versuchen.');
            }
            $r = $this->library->ingest($userId, $part, (string) $up['original_name'], $up['gallery_id'] === null ? null : (int) $up['gallery_id']);
            return $r + ['name' => (string) $up['original_name']];
        } finally {
            @unlink($part);
            $this->db->run('DELETE FROM uploads WHERE id = ?', [$id]);
        }
    }

    /** @return array<string,mixed> @throws MediaException */
    private function find(string $id, int $userId): array
    {
        $row = preg_match('/^[0-9a-f]{32}$/', $id) === 1 ? $this->db->one('SELECT * FROM uploads WHERE id = ? AND user_id = ?', [$id, $userId]) : null;
        if ($row === null) {
            throw new MediaException('Der Upload ist unbekannt oder abgelaufen.', 404);
        }
        return $row;
    }

    private function dir(): string
    {
        $dir = $this->dataDir . '/uploads';
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
        return $dir;
    }

    /** Liegengebliebene Teilstücke nach 24 Stunden entfernen. */
    private function cleanup(): void
    {
        $cut = $this->clock->now() - 86400;
        foreach ($this->db->all('SELECT id FROM uploads WHERE updated_at < ?', [$cut]) as $row) {
            @unlink($this->dir() . '/' . $row['id'] . '.part');
            $this->db->run('DELETE FROM uploads WHERE id = ?', [$row['id']]);
        }
    }
}
