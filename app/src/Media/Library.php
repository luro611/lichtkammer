<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Aufnahme und Verarbeitung von Fotos.
 *  - Originale landen unter originals/ mit Zufallsnamen und werden nie überschrieben oder verändert.
 *  - Varianten liegen unter media/<xx>/<token>-<breite>.<format>, ohne Metadaten (kein GPS).
 *  - Verarbeitung ist idempotent: ein zweiter Lauf lässt unveränderte Dateien in Ruhe.
 */
final class Library
{
    public const PIPELINE_VERSION = 1;
    public const WIDTHS = [480, 960, 1600, 2400];
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heic'];
    private const MAX_PIXELS = 100_000_000;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $originalsDir,
        private readonly string $mediaDir,
        private readonly ?ImageEngine $engine,
        private readonly int $maxBytes = 100 * 1024 * 1024,
    ) {
    }

    public function engine(): ?ImageEngine
    {
        return $this->engine;
    }

    /** Breiten einer Bildvariante: feste Stufen bis zur Originalbreite, dazu die Originalbreite selbst (höchstens 2400). */
    public static function plan(int $srcWidth): array
    {
        $w = array_values(array_filter(self::WIDTHS, static fn (int $x): bool => $x <= $srcWidth));
        $full = min($srcWidth, max(self::WIDTHS));
        if ($srcWidth > 0 && !in_array($full, $w, true)) {
            $w[] = $full;
        }
        sort($w);
        return $w;
    }

    /**
     * Prüft die Datei am Inhalt, legt das Original ab und erzeugt die Zeile (Status „pending“).
     *
     * @return array{duplicate:bool,photo_id:int,in_trash:bool}
     * @throws MediaException
     */
    public function ingest(int $userId, string $file, string $originalName, ?int $galleryId): array
    {
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size <= 0) {
            throw new MediaException('Die Datei ist leer.');
        }
        if ($size > $this->maxBytes) {
            throw new MediaException('Die Datei ist zu groß (höchstens ' . (int) round($this->maxBytes / 1048576) . ' MB).');
        }
        if ($this->engine === null) {
            throw new MediaException('Auf diesem Server ist keine Bildbibliothek (Imagick oder GD) verfügbar.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        if (!is_string($mime) || !isset(self::TYPES[$mime])) {
            throw new MediaException('Dieses Dateiformat ist nicht erlaubt. Erlaubt sind JPEG, PNG, WebP und HEIC.');
        }
        if (!$this->engine->canRead($mime)) {
            throw new MediaException($mime === 'image/heic' || $mime === 'image/heif'
                ? 'HEIC-Bilder kann dieser Server nicht lesen. Bitte als JPEG exportieren.'
                : 'Dieses Bildformat kann dieser Server nicht lesen.');
        }
        if (!str_starts_with($mime, 'image/hei')) { // HEIC: Größe liest die Bibliothek später
            $info = @getimagesize($file);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                throw new MediaException('Die Datei ist kein lesbares Bild oder beschädigt.');
            }
            if ($info[0] * $info[1] > self::MAX_PIXELS) {
                throw new MediaException('Das Bild ist zu groß (mehr als 100 Megapixel).');
            }
        }
        $sha = hash_file('sha256', $file);
        if ($sha === false) {
            throw new MediaException('Die Datei konnte nicht gelesen werden.');
        }
        $dup = $this->db->one('SELECT id, deleted_at FROM photos WHERE sha256 = ?', [$sha]);
        if ($dup !== null) {
            return ['duplicate' => true, 'photo_id' => (int) $dup['id'], 'in_trash' => $dup['deleted_at'] !== null];
        }

        $token = bin2hex(random_bytes(12));
        $rel = substr($token, 0, 2) . '/' . $token . '.' . self::TYPES[$mime];
        $abs = $this->originalsDir . '/' . $rel;
        $this->prepareOriginalsDir(dirname($abs));
        if (!$this->copyExclusive($file, $abs)) {
            throw new MediaException('Das Original konnte nicht gespeichert werden. Bitte Schreibrechte und Speicherplatz prüfen.');
        }
        $now = $this->clock->now();
        try {
            // Foto und Galerie-Verknüpfung zusammen: schlägt das zweite fehl, bleibt keine Zeile ohne Original zurück.
            $photoId = $this->db->tx(function () use ($token, $originalName, $rel, $sha, $size, $mime, $userId, $now, $galleryId): int {
                $this->db->run(
                    'INSERT INTO photos(token, original_name, original_path, sha256, bytes, mime, status, uploaded_by, created_at, updated_at)
                     VALUES(?,?,?,?,?,?,\'pending\',?,?,?)',
                    [$token, self::cleanName($originalName), $rel, $sha, $size, $mime, $userId, $now, $now],
                );
                $id = $this->db->lastId();
                if ($galleryId !== null) { // seit Schema 3: Verknüpfung mit eigener Position, ans Ende
                    $this->db->run(
                        'INSERT OR IGNORE INTO gallery_photos(gallery_id, photo_id, position, added_at)
                         VALUES(?, ?, (SELECT COALESCE(MAX(position),0)+1 FROM gallery_photos WHERE gallery_id = ?), ?)',
                        [$galleryId, $id, $galleryId, $now],
                    );
                }
                return $id;
            });
        } catch (\PDOException $e) {
            @unlink($abs);
            $dup = $this->db->one('SELECT id, deleted_at FROM photos WHERE sha256 = ?', [$sha]);
            if ($dup !== null) { // zwei gleiche Uploads gleichzeitig
                return ['duplicate' => true, 'photo_id' => (int) $dup['id'], 'in_trash' => $dup['deleted_at'] !== null];
            }
            throw $e;
        }
        return ['duplicate' => false, 'photo_id' => $photoId, 'in_trash' => false];
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f]+/u', '', $name) ?? '';
        return mb_substr(trim($name), 0, 200);
    }

    private function prepareOriginalsDir(string $dir): void
    {
        $root = $this->originalsDir;
        $old = umask(0077);
        try {
            if (!is_dir($root)) {
                @mkdir($root, 0700, true);
            }
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
        } finally {
            umask($old);
        }
        if (is_dir($root) && !is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Require all denied\n");
            @file_put_contents($root . '/index.html', '');
        }
    }

    /** Kopiert nur, wenn das Ziel noch nicht existiert ('x'-Modus): ein Original wird nie überschrieben. */
    private function copyExclusive(string $from, string $to): bool
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            return false;
        }
        $ok = stream_copy_to_stream($in, $out) === filesize($from);
        fclose($in);
        fclose($out);
        if (!$ok) {
            @unlink($to);
            return false;
        }
        @chmod($to, 0600);
        return true;
    }

    /**
     * Erzeugt Varianten, Platzhalter und EXIF-Daten. Wiederholbar: Fertiges bleibt unberührt.
     *
     * @return array{status:string,created:int,removed:int,error:string}  status: ready | unchanged | failed
     */
    public function process(int $photoId, bool $force = false): array
    {
        $photo = $this->db->one('SELECT * FROM photos WHERE id = ?', [$photoId]);
        if ($photo === null || $photo['deleted_at'] !== null) {
            throw new MediaException('Das Foto gibt es nicht (mehr).');
        }
        if ($this->engine === null) {
            return $this->fail($photoId, 'Auf diesem Server ist keine Bildbibliothek (Imagick oder GD) verfügbar.');
        }
        $formats = $this->engine->formats();
        if (!in_array('jpeg', $formats, true)) {
            return $this->fail($photoId, 'Die Bildbibliothek kann kein JPEG schreiben.');
        }
        $existing = [];
        foreach ($this->db->all('SELECT width, format, path FROM photo_variants WHERE photo_id = ?', [$photoId]) as $v) {
            $existing[$v['width'] . ':' . $v['format']] = $v['path'];
        }
        if (!$force && $photo['status'] === 'ready' && (int) $photo['pipeline_version'] === self::PIPELINE_VERSION && (int) $photo['width'] > 0) {
            $complete = true;
            foreach (self::plan((int) $photo['width']) as $w) {
                foreach ($formats as $f) {
                    $p = $existing[$w . ':' . $f] ?? null;
                    if ($p === null || !is_file($this->mediaDir . '/' . $p)) {
                        $complete = false;
                    }
                }
            }
            if ($complete) {
                return ['status' => 'unchanged', 'created' => 0, 'removed' => 0, 'error' => ''];
            }
        }
        @set_time_limit(120);
        $src = null;
        try {
            $orig = $this->originalsDir . '/' . $photo['original_path'];
            if (!is_file($orig)) {
                throw new MediaException('Das Original fehlt im Speicher.');
            }
            $src = $this->engine->open($orig, (int) $photo['rotation']);
            $w0 = $src->width();
            $h0 = $src->height();
            if ($w0 < 1 || $h0 < 1) {
                throw new MediaException('Das Bild hat keine gültige Größe.');
            }
            if ($w0 * $h0 > self::MAX_PIXELS) { // fängt auch HEIC ab, dessen Größe ingest() nicht vorab kennt
                throw new MediaException('Das Bild ist zu groß (mehr als 100 Megapixel).');
            }
            $created = 0;
            $wanted = [];
            foreach (self::plan($w0) as $w) {
                $h = (int) max(1, round($h0 * $w / $w0));
                foreach ($formats as $f) {
                    $rel = substr((string) $photo['token'], 0, 2) . '/' . $photo['token'] . '-' . $w . '.' . ($f === 'jpeg' ? 'jpg' : $f);
                    $wanted[$w . ':' . $f] = true;
                    $abs = $this->mediaDir . '/' . $rel;
                    if (!$force && isset($existing[$w . ':' . $f]) && is_file($abs) && filesize($abs) > 0) {
                        continue;
                    }
                    $bytes = $src->encode($w, $f);
                    $this->writeAtomic($abs, $bytes);
                    $this->db->run(
                        'INSERT INTO photo_variants(photo_id, width, height, format, path, bytes) VALUES(?,?,?,?,?,?)
                         ON CONFLICT(photo_id, width, format) DO UPDATE SET height=excluded.height, path=excluded.path, bytes=excluded.bytes',
                        [$photoId, $w, $h, $f, $rel, strlen($bytes)],
                    );
                    $created++;
                }
            }
            $removed = 0;
            foreach ($existing as $key => $rel) { // veraltete Varianten kontrolliert entfernen
                if (!isset($wanted[$key])) {
                    [$w, $f] = explode(':', $key);
                    @unlink($this->mediaDir . '/' . $rel);
                    $this->db->run('DELETE FROM photo_variants WHERE photo_id = ? AND width = ? AND format = ?', [$photoId, (int) $w, $f]);
                    $removed++;
                }
            }
            $blur = $photo['blur_data_uri'] !== '' && !$force ? (string) $photo['blur_data_uri'] : 'data:image/jpeg;base64,' . base64_encode($src->placeholder());
            $exif = ExifReader::read($orig, (string) $photo['mime']);
            $taken = $exif['taken_at'];
            $this->db->run(
                'UPDATE photos SET width=?, height=?, status=\'ready\', error=\'\', pipeline_version=?, blur_data_uri=?, exif_json=?, has_gps=?,
                        taken_at=COALESCE(?, taken_at), year=COALESCE(year, ?), updated_at=? WHERE id=?',
                [$w0, $h0, self::PIPELINE_VERSION, $blur, json_encode($exif['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $exif['has_gps'] ? 1 : 0, $taken, $taken !== null ? (int) substr($taken, 0, 4) : null, $this->clock->now(), $photoId],
            );
            return ['status' => 'ready', 'created' => $created, 'removed' => $removed, 'error' => ''];
        } catch (MediaException $e) {
            return $this->fail($photoId, $e->getMessage());
        } catch (\Throwable $t) {
            error_log('Lichtkammer: Bildverarbeitung: ' . $t::class);
            return $this->fail($photoId, 'Das Bild konnte nicht verarbeitet werden.');
        } finally {
            $src?->close();
        }
    }

    /** @return array{status:string,created:int,removed:int,error:string} */
    private function fail(int $photoId, string $message): array
    {
        $this->db->run("UPDATE photos SET status='failed', error=?, updated_at=? WHERE id=?", [$message, $this->clock->now(), $photoId]);
        return ['status' => 'failed', 'created' => 0, 'removed' => 0, 'error' => $message];
    }

    private function writeAtomic(string $abs, string $bytes): void
    {
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MediaException('Der Ordner für Bildvarianten ist nicht beschreibbar.');
        }
        $tmp = $abs . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $abs)) {
            @unlink($tmp);
            throw new MediaException('Eine Bildvariante konnte nicht gespeichert werden. Bitte Speicherplatz prüfen.');
        }
        @chmod($abs, 0644);
    }
}
