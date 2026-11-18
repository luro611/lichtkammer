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
        private readonly Files $files,
        private readonly Folders $folders,
    ) {
    }

    /**
     * Beginnt einen Upload oder setzt einen unfertigen fort (gleicher Nutzer, Ordner, Name, Größe und Fingerabdruck; Prüfung 17, H2).
     * intent: photo (Foto, kommt bei einem Ordner mit Entwurfsgalerie auch dorthin), photo_plain (Foto, nie automatisch in eine Galerie) oder file (PDF, SVG, Bild als Datei).
     *
     * @return array{id:string,received:int}
     * @throws MediaException
     */
    public function start(int $userId, string $name, int $size, ?int $galleryId, ?int $folderId = null, string $intent = 'photo', string $fingerprint = ''): array
    {
        $intent = in_array($intent, ['photo', 'photo_plain', 'file'], true) ? $intent : 'photo';
        $limit = $intent === 'file' ? $this->files->maxBytes() : $this->maxBytes;
        if ($size < 1) {
            throw new MediaException('Die Datei ist leer.');
        }
        if ($size > $limit) {
            throw new MediaException('Die Datei ist zu groß (höchstens ' . (int) round($limit / 1048576) . ' MB).');
        }
        $folderId = $folderId !== null && $folderId > 0 ? $folderId : 0;
        if (!$this->folders->isUsable($folderId)) {
            throw new MediaException('Der Zielordner gibt es nicht (mehr) oder er liegt im Papierkorb.');
        }
        if ($galleryId !== null && $this->db->value('SELECT 1 FROM galleries WHERE id = ?', [$galleryId]) === null) {
            $galleryId = null;
        }
        $fingerprint = preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1 ? $fingerprint : '';
        $this->cleanup();
        $clean = Library::cleanName($name);
        if ($fingerprint !== '') {
            $old = $this->db->one(
                'SELECT * FROM uploads WHERE user_id = ? AND folder_id = ? AND original_name = ? AND size = ? AND fingerprint = ? AND intent = ?',
                [$userId, $folderId, $clean, $size, $fingerprint, $intent],
            );
            if ($old !== null) {
                $part = $this->dir() . '/' . $old['id'] . '.part';
                if (is_file($part) && (int) filesize($part) === (int) $old['received']) {
                    $this->db->run('UPDATE uploads SET gallery_id = ?, updated_at = ? WHERE id = ?', [$galleryId, $this->clock->now(), $old['id']]);
                    return ['id' => (string) $old['id'], 'received' => (int) $old['received']];
                }
                @unlink($part); // Stand passt nicht zur Datei: von vorn
                $this->db->run('DELETE FROM uploads WHERE id = ?', [$old['id']]);
            }
        }
        $id = bin2hex(random_bytes(16));
        $part = $this->dir() . '/' . $id . '.part';
        if (@file_put_contents($part, '') === false) {
            throw new MediaException('Der Upload-Ordner ist nicht beschreibbar.');
        }
        @chmod($part, 0600);
        $now = $this->clock->now();
        $this->db->run('INSERT INTO uploads(id, user_id, original_name, size, received, gallery_id, created_at, updated_at, folder_id, intent, fingerprint) VALUES(?,?,?,?,0,?,?,?,?,?,?)',
            [$id, $userId, $clean, $size, $galleryId, $now, $now, $folderId, $intent, $fingerprint]);
        return ['id' => $id, 'received' => 0];
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

    /**
     * @return array{duplicate:bool,photo_id:int,file_id:int,in_trash:bool,name:string,intent:string,kind:string,notes:list<string>,folder:string}
     * @throws MediaException
     */
    public function finish(int $userId, string $id): array
    {
        $up = $this->find($id, $userId);
        $part = $this->dir() . '/' . $id . '.part';
        try {
            if ((int) $up['received'] !== (int) $up['size'] || !is_file($part) || filesize($part) !== (int) $up['size']) {
                throw new MediaException('Der Upload ist unvollständig. Bitte noch einmal versuchen.');
            }
            $name = (string) $up['original_name'];
            $folder = (int) $up['folder_id'];
            if (!$this->folders->isUsable($folder)) { // der Ordner kam seit dem Start in den Papierkorb
                throw new MediaException('Der Zielordner gibt es nicht (mehr) oder er liegt im Papierkorb.');
            }
            $base = ['name' => $name, 'intent' => (string) $up['intent'], 'kind' => 'photo', 'notes' => [], 'folder' => '', 'file_id' => 0, 'photo_id' => 0];
            if ($up['intent'] === 'file') {
                $r = $this->files->ingest($userId, $part, $name, $folder, (string) $up['fingerprint']);
                return ['duplicate' => $r['duplicate'], 'in_trash' => $r['in_trash'], 'file_id' => $r['file_id'], 'kind' => $r['kind'], 'notes' => $r['notes'], 'folder' => $r['folder']] + $base;
            }
            $gallery = $up['gallery_id'] === null ? null : (int) $up['gallery_id'];
            if ($gallery === null && $up['intent'] === 'photo' && $folder > 0) { // Ordner mit Entwurfsgalerie: neue Fotos kommen dazu (E3b, N4)
                $fg = $this->folders->get($folder)['gallery_id'] ?? null;
                if ($fg !== null && $this->db->value("SELECT 1 FROM galleries WHERE id = ? AND status = 'draft'", [$fg]) !== null) {
                    $gallery = (int) $fg;
                }
            }
            $r = $this->library->ingest($userId, $part, $name, $gallery, $folder > 0 ? $folder : null, (string) $up['fingerprint']);
            $where = '';
            if ($r['duplicate']) {
                $pf = $this->db->value('SELECT folder_id FROM photos WHERE id = ?', [$r['photo_id']]);
                $where = $this->folders->nameOf($pf === null ? null : (int) $pf);
                if ($gallery !== null && !$r['in_trash']) { // Dublette: bleibt am alten Platz, kommt aber in die neue Galerie
                    $this->db->run(
                        'INSERT OR IGNORE INTO gallery_photos(gallery_id, photo_id, position, added_at) VALUES(?, ?, (SELECT COALESCE(MAX(position),0)+1 FROM gallery_photos WHERE gallery_id = ?), ?)',
                        [$gallery, $r['photo_id'], $gallery, $this->clock->now()],
                    );
                }
            }
            return ['duplicate' => $r['duplicate'], 'photo_id' => $r['photo_id'], 'in_trash' => $r['in_trash'], 'folder' => $where] + $base;
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
