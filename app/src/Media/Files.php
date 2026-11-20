<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;

/**
 * Dateien, die keine Fotos sind (PDF, SVG, Bild als Datei; Entwurf 16). Typ nur am Inhalt. Das Original liegt privat unter
 * <Datenordner>/files/<xx>/<token>.<ext> (Zufallsname, Endung aus dem erkannten Typ, nie aus dem Hochladenamen).
 * Nur auf Schalter „öffentlich“ entsteht eine Kopie unter media/dateien/. Namen sind Daten, nie Pfade.
 */
final class Files
{
    public const MAX_SVG = 1048576;
    public const MAX_IMAGE = 5242880;
    private const EXT = ['pdf' => 'pdf', 'svg' => 'svg', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    private const MAX_PIXELS = 100_000_000;
    public const HTACCESS = <<<'HT'
# Kein PHP; feste Typen und Schutzköpfe (Entwurf 16). Wird von Lichtkammer angelegt.
RemoveHandler .php .phtml
RemoveType .php
<FilesMatch "\.(php|phtml|phar)$">
  Require all denied
</FilesMatch>
AddType application/pdf .pdf
AddType image/svg+xml .svg
<IfModule mod_headers.c>
Header set Cache-Control "public, max-age=86400"
Header set X-Content-Type-Options "nosniff"
<FilesMatch "\.svg$">
Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
</FilesMatch>
<FilesMatch "\.pdf$">
Header set Content-Disposition "inline"
</FilesMatch>
</IfModule>

HT;

    /** @param (callable():bool)|null $svgProbe Selbsttest: sendet der Webserver den Schutzkopf für SVG? (nur Tests ersetzen ihn) */
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly string $dataDir,
        private readonly string $mediaDir,
        private readonly int $maxPdf = 50 * 1048576,
        private readonly string $baseUrl = '',
        private $svgProbe = null,
    ) {
    }

    public function maxBytes(): int
    {
        return max($this->maxPdf, self::MAX_SVG, self::MAX_IMAGE);
    }

    private function root(): string
    {
        return $this->dataDir . '/files';
    }

    /**
     * Prüft am Inhalt, bereinigt (SVG) und legt die Datei ab.
     *
     * @return array{duplicate:bool,file_id:int,in_trash:bool,kind:string,notes:list<string>,folder:string}
     * @throws MediaException
     */
    public function ingest(int $userId, string $file, string $originalName, int $folderId, string $fingerprint = ''): array
    {
        $size = is_file($file) ? (int) filesize($file) : 0;
        if ($size <= 0) {
            throw new MediaException('Die Datei ist leer.');
        }
        if ($size > $this->maxBytes()) {
            throw new MediaException('Die Datei ist zu groß.');
        }
        [$kind, $mime] = $this->detect($file);
        $limit = ['pdf' => $this->maxPdf, 'svg' => self::MAX_SVG, 'image' => self::MAX_IMAGE][$kind];
        if ($size > $limit) {
            throw new MediaException('Die Datei ist zu groß (' . ['pdf' => 'PDF', 'svg' => 'SVG', 'image' => 'Bild als Datei'][$kind] . ': höchstens ' . (int) round($limit / 1048576) . ' MB).');
        }
        $notes = [];
        $tmp = null;
        $source = $file;
        if ($kind === 'svg') {
            $raw = (string) file_get_contents($file);
            $r = SvgCheck::sanitize($raw);
            $notes = $r['notes'];
            $tmp = $file . '.clean';
            if (@file_put_contents($tmp, $r['svg']) === false) {
                throw new MediaException('Die Datei konnte nicht verarbeitet werden.');
            }
            $source = $tmp;
        } elseif ($kind === 'pdf') {
            PdfCheck::check($file);
        } else {
            $info = @getimagesize($file);
            if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
                throw new MediaException('Das Bild ist beschädigt oder zu groß (mehr als 100 Megapixel).');
            }
        }
        try {
            $sha = hash_file('sha256', $source);
            if ($sha === false) {
                throw new MediaException('Die Datei konnte nicht gelesen werden.');
            }
            $dup = $this->db->one('SELECT id, folder_id, deleted_at FROM files WHERE sha256 = ?', [$sha]);
            if ($dup !== null) {
                return ['duplicate' => true, 'file_id' => (int) $dup['id'], 'in_trash' => $dup['deleted_at'] !== null, 'kind' => $kind, 'notes' => $notes, 'folder' => $this->folderName((int) $dup['folder_id'])];
            }
            $token = bin2hex(random_bytes(12));
            $ext = $kind === 'image' ? self::EXT[$mime] : self::EXT[$kind];
            $rel = substr($token, 0, 2) . '/' . $token . '.' . $ext;
            $abs = $this->root() . '/' . $rel;
            Library::prepareDenyDir($this->root(), dirname($abs));
            if (!$this->copyExclusive($source, $abs)) {
                throw new MediaException('Die Datei konnte nicht gespeichert werden. Bitte Schreibrechte und Speicherplatz prüfen.');
            }
            $now = $this->clock->now();
            try {
                $this->db->run(
                    'INSERT INTO files(token, folder_id, name, kind, mime, bytes, sha256, path, fingerprint, uploaded_by, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$token, max(0, $folderId), Names::fileName($originalName), $kind, $kind === 'pdf' ? 'application/pdf' : ($kind === 'svg' ? 'image/svg+xml' : $mime), (int) filesize($source), $sha, $rel, $fingerprint, $userId, $now, $now],
                );
            } catch (\PDOException $e) {
                @unlink($abs);
                $dup = $this->db->one('SELECT id, folder_id, deleted_at FROM files WHERE sha256 = ?', [$sha]);
                if ($dup !== null) { // zwei gleiche Uploads gleichzeitig
                    return ['duplicate' => true, 'file_id' => (int) $dup['id'], 'in_trash' => $dup['deleted_at'] !== null, 'kind' => $kind, 'notes' => $notes, 'folder' => $this->folderName((int) $dup['folder_id'])];
                }
                throw $e;
            }
            return ['duplicate' => false, 'file_id' => $this->db->lastId(), 'in_trash' => false, 'kind' => $kind, 'notes' => $notes, 'folder' => ''];
        } finally {
            if ($tmp !== null) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Typ nur aus dem Inhalt. Endung, gemeldeter Typ und Name zählen nicht.
     *
     * @return array{0:string,1:string} [Art pdf|svg|image, Typ]
     * @throws MediaException
     */
    public function detect(string $file): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);
        $mime = is_string($mime) ? $mime : '';
        if ($mime === 'application/pdf') {
            return ['pdf', 'application/pdf'];
        }
        if (isset(self::EXT[$mime]) && $mime !== 'pdf' && str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return ['image', $mime];
        }
        $head = (string) file_get_contents($file, false, null, 0, 4096);
        if ((str_starts_with($mime, 'text/') || $mime === 'image/svg+xml' || $mime === 'application/xml') && preg_match('/<svg[\s>]/i', $head) === 1) {
            return ['svg', 'image/svg+xml'];
        }
        throw new MediaException('Dieses Dateiformat ist nicht erlaubt. Erlaubt sind PDF, SVG sowie PNG, JPEG und WebP.');
    }

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

    private function folderName(int $id): string
    {
        return $id < 1 ? 'Medien' : (string) ($this->db->value('SELECT name FROM folders WHERE id = ?', [$id]) ?? 'Medien');
    }

    // --- Lesen ---

    /** @return array<string,mixed>|null */
    public function get(int $id, bool $includeTrash = false): ?array
    {
        return $this->db->one('SELECT * FROM files WHERE id = ?' . ($includeTrash ? '' : ' AND deleted_at IS NULL'), [$id]);
    }

    /** Absoluter Pfad der privaten Datei, nur aus der Datenbank (nie aus Eingaben). */
    public function absolute(array $file): ?string
    {
        $rel = (string) $file['path'];
        if (preg_match('#^[0-9a-f]{2}/[0-9a-f]{24}\.(pdf|svg|png|jpg|webp)$#', $rel) !== 1) {
            return null;
        }
        $abs = $this->root() . '/' . $rel;
        return is_file($abs) ? $abs : null;
    }

    private const PUBLIC_NAME = '/^[0-9a-f]{8}-[a-z0-9-]*\.(pdf|svg|png|jpg|webp)$/D';
    private const LEGACY_PATH = '#^dokumente/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$#D';

    /**
     * Öffentliche Adresse für Seiten (Entwurf 44): nur für öffentliche Dateien und nur, wenn der Name aus der Datenbank
     * streng zum Muster passt (Modifikator D: kein Zeilenumbruch am Ende). Sonst null. Prüft nicht, ob die Kopie auf der Platte liegt.
     *
     * @param array<string,mixed> $file
     */
    public static function publicUrlOrNull(array $file): ?string
    {
        $rel = self::publicRelOrNull($file);
        return $rel === null ? null : '/media/' . $rel;
    }

    /** Pfad der öffentlichen Kopie unter media/ („dateien/<name>“ oder „dokumente/<name>“), nach Muster geprüft. @param array<string,mixed> $file */
    public static function publicRelOrNull(array $file): ?string
    {
        if ((int) ($file['public'] ?? 0) !== 1 || ($file['deleted_at'] ?? null) !== null) {
            return null;
        }
        $legacy = (string) ($file['legacy_public_path'] ?? '');
        if ($legacy !== '') {
            return preg_match(self::LEGACY_PATH, $legacy) === 1 ? $legacy : null;
        }
        $name = (string) ($file['public_name'] ?? '');
        return preg_match(self::PUBLIC_NAME, $name) === 1 ? 'dateien/' . $name : null;
    }

    /** „1,2 MB“, „340 KB“, „900 Bytes“: feste Formatierung aus der Größe der Datenbank. */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return str_replace('.', ',', number_format($bytes / 1048576, 1, '.', '')) . ' MB';
        }
        return $bytes >= 1024 ? (int) round($bytes / 1024) . ' KB' : max(0, $bytes) . ' Bytes';
    }

    /** Kurzname der Art für die Anzeige („PDF“, „SVG“, „PNG“ …), aus der Endung der öffentlichen Adresse. */
    public static function kindLabel(string $url): string
    {
        return ['pdf' => 'PDF', 'svg' => 'SVG', 'png' => 'PNG', 'jpg' => 'JPEG', 'webp' => 'WebP'][strtolower(pathinfo($url, PATHINFO_EXTENSION))] ?? 'Datei';
    }

    public function publicUrl(array $file): string
    {
        return (int) $file['public'] === 1 ? ($file['legacy_public_path'] !== '' ? '/media/' . $file['legacy_public_path'] : '/media/dateien/' . $file['public_name']) : '';
    }

    // --- Ändern ---

    /** Nur der Anzeigename; die öffentliche Adresse bleibt stabil (Links in Seiten). @throws MediaException */
    public function rename(int $id, string $name): void
    {
        if (str_contains($name, '/')) {
            throw new MediaException('Ein Name darf keinen Schrägstrich enthalten.');
        }
        $n = $this->db->run('UPDATE files SET name = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [Names::clean($name, Names::FILE_FALLBACK)['name'], $this->clock->now(), $id])->rowCount();
        if ($n === 0) {
            throw new MediaException('Die Datei gibt es nicht (mehr).');
        }
    }

    /**
     * Öffentlich schalten (Kopie unter media/dateien/) oder zurück auf privat (Kopie wird sofort gelöscht).
     * SVG nur, wenn der Selbsttest den Schutzkopf des Webservers bestätigt (E13).
     *
     * @throws MediaException
     */
    public function setPublic(int $id, bool $public): void
    {
        $f = $this->get($id);
        if ($f === null) {
            throw new MediaException('Die Datei gibt es nicht (mehr).');
        }
        if ($f['legacy_public_path'] !== '') {
            throw new MediaException('Diese Datei ist von der alten Seite übernommen und bleibt öffentlich, damit alte Adressen weiter funktionieren.');
        }
        if (!$public) {
            $this->removeCopy($f);
            $this->db->run("UPDATE files SET public = 0, public_name = '', updated_at = ? WHERE id = ?", [$this->clock->now(), $id]);
            return;
        }
        if ((int) $f['public'] === 1) {
            return;
        }
        if ($f['kind'] === 'svg' && !$this->svgHeadersWork()) {
            throw new MediaException('SVG-Dateien lassen sich nur öffentlich schalten, wenn der Webserver den Schutzkopf mitschickt (mod_headers). Der Selbsttest dafür ist fehlgeschlagen. Die Datei bleibt privat; als Logo oder Bild in einer Seite ist sie trotzdem nutzbar.');
        }
        $src = $this->absolute($f);
        if ($src === null) {
            throw new MediaException('Die Datei fehlt im Speicher.');
        }
        $slug = Galleries::slugify(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string) $f['name']) ?? '');
        $slug = $slug === 'galerie' ? 'datei' : substr($slug, 0, 60);
        $name = substr((string) $f['token'], 0, 8) . '-' . trim($slug, '-') . '.' . pathinfo((string) $f['path'], PATHINFO_EXTENSION);
        $dir = $this->mediaDir . '/dateien';
        $this->prepareCopyDir($dir);
        $tmp = $dir . '/.tmp' . bin2hex(random_bytes(4));
        if (!@copy($src, $tmp) || !@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            throw new MediaException('Die öffentliche Kopie konnte nicht angelegt werden. Bitte Schreibrechte prüfen.');
        }
        @chmod($dir . '/' . $name, 0644);
        $this->db->run('UPDATE files SET public = 1, public_name = ?, updated_at = ? WHERE id = ?', [$name, $this->clock->now(), $id]);
    }

    private function prepareCopyDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new MediaException('Der Ordner für öffentliche Dateien konnte nicht angelegt werden.');
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht) || @file_get_contents($ht) !== self::HTACCESS) {
            @file_put_contents($ht, self::HTACCESS);
            @chmod($ht, 0644);
        }
    }

    private function removeCopy(array $f): void
    {
        $name = (string) $f['public_name'];
        if ($name !== '' && $f['legacy_public_path'] === '' && preg_match('/^[0-9a-f]{8}-[a-z0-9-]*\.(pdf|svg|png|jpg|webp)$/D', $name) === 1) {
            @unlink($this->mediaDir . '/dateien/' . $name);
        }
    }

    // --- Öffentliche Kopien nachziehen (Entwurf 44, Stufe 1b) ---

    /**
     * Prüft alle öffentlichen Dateien: Name streng nach Muster, Kopie vorhanden? Nur Lesen.
     *
     * @param list<int>|null $onlyIds nur diese Dateien
     * @return list<array{row:array<string,mixed>,rel:?string,state:string}> state: ok | bad_name | missing
     */
    private function scanPublic(?array $onlyIds = null): array
    {
        $sql = "SELECT * FROM files WHERE public = 1 AND deleted_at IS NULL";
        $args = [];
        if ($onlyIds !== null) {
            if ($onlyIds === []) {
                return [];
            }
            $sql .= ' AND id IN (' . implode(',', array_fill(0, count($onlyIds), '?')) . ')';
            $args = array_map('intval', $onlyIds);
        }
        $out = [];
        foreach ($this->db->all($sql . ' ORDER BY id', $args) as $row) {
            $rel = self::publicRelOrNull($row);
            $out[] = ['row' => $row, 'rel' => $rel, 'state' => $rel === null ? 'bad_name' : (is_file($this->mediaDir . '/' . $rel) ? 'ok' : 'missing')];
        }
        return $out;
    }

    /**
     * Zustand der öffentlichen Kopien (nur Lesen, für die Systemprüfung).
     *
     * @return array{total:int,missing:int,no_original:int,bad_name:int}
     */
    public function publicCopyStatus(): array
    {
        $st = ['total' => 0, 'missing' => 0, 'no_original' => 0, 'bad_name' => 0];
        foreach ($this->scanPublic() as $x) {
            $st['total']++;
            if ($x['state'] === 'bad_name') {
                $st['bad_name']++;
            } elseif ($x['state'] === 'missing') {
                $st['missing']++;
                $st['no_original'] += $this->absolute($x['row']) === null ? 1 : 0;
            }
        }
        return $st;
    }

    /**
     * Fehlende öffentliche Kopien aus den privaten Originalen nachziehen (nach einer Wiederherstellung auf einem neuen Server fehlen sie,
     * weil die Sicherung nur die privaten Originale enthält). Ändert NUR Dateien, nie Zeilen: Fehlt das Original, bleibt die Zeile wie sie ist
     * und wird nur gezählt (Prüfung 45, B1). Namen nur nach Muster (nie ein Pfad aus der Datenbank ohne Prüfung), SVG nur, wenn der Webserver den
     * Schutzkopf bestätigt (E13), geschrieben wird atomar über eine Temporärdatei.
     *
     * @param list<int>|null $onlyIds nur diese Dateien
     * @return array{repaired:int,no_original:int,bad_name:int,svg_skipped:int,failed:int}
     */
    public function repairPublicCopies(int $limit = 100, ?array $onlyIds = null): array
    {
        $r = ['repaired' => 0, 'no_original' => 0, 'bad_name' => 0, 'svg_skipped' => 0, 'failed' => 0];
        $svgOk = null;
        foreach ($this->scanPublic($onlyIds) as $x) {
            if ($x['state'] === 'bad_name') {
                $r['bad_name']++;
                continue;
            }
            if ($x['state'] === 'ok') {
                continue;
            }
            if ($r['repaired'] >= $limit) {
                break;
            }
            $row = $x['row'];
            $src = $this->absolute($row);
            if ($src === null) {
                $r['no_original']++;
                continue;
            }
            if ($row['kind'] === 'svg') {
                $svgOk ??= $this->svgHeadersWork();
                if (!$svgOk) {
                    $r['svg_skipped']++;
                    continue;
                }
            }
            $target = $this->mediaDir . '/' . $x['rel'];
            $dir = dirname($target);
            try {
                if (str_starts_with((string) $x['rel'], 'dateien/')) {
                    $this->prepareCopyDir($dir); // legt auch die .htaccess an (kein PHP, feste Typen, SVG-Schutzkopf)
                } elseif (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new MediaException('Ordner fehlt');
                }
                // media/dokumente hat keine eigene .htaccess; es gilt die allgemeine in public/media (kein PHP), und das Muster lässt nur .pdf zu.
                $tmp = $dir . '/.tmp' . bin2hex(random_bytes(4));
                if (!@copy($src, $tmp) || (is_file($target) ? !@unlink($tmp) : !@rename($tmp, $target))) {
                    @unlink($tmp);
                    throw new MediaException('Kopie fehlgeschlagen');
                }
                @chmod($target, 0644);
                $r['repaired']++;
            } catch (MediaException) {
                $r['failed']++;
            }
        }
        return $r;
    }

    /** Selbsttest: liefert der Webserver für SVG in media/dateien/ den Schutzkopf? Ergebnis eine Stunde lang gemerkt. */
    public function svgHeadersWork(): bool
    {
        $m = (string) ($this->db->value("SELECT v FROM meta WHERE k = 'svg_probe'") ?? '');
        if (preg_match('/^(\d+):([01])$/', $m, $x) === 1 && (int) $x[1] > $this->clock->now() - 3600) {
            return $x[2] === '1';
        }
        $ok = false;
        try {
            $ok = $this->svgProbe !== null ? (bool) ($this->svgProbe)() : $this->realProbe();
        } catch (\Throwable) {
            $ok = false;
        }
        $this->db->run("INSERT INTO meta(k, v) VALUES('svg_probe', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [$this->clock->now() . ':' . ($ok ? '1' : '0')]);
        return $ok;
    }

    private function realProbe(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }
        $dir = $this->mediaDir . '/dateien';
        $this->prepareCopyDir($dir);
        file_put_contents($dir . '/_probe.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $h = @get_headers(rtrim($this->baseUrl, '/') . '/media/dateien/_probe.svg', true, $ctx);
        if (!is_array($h)) {
            return false;
        }
        $csp = '';
        foreach ($h as $k => $v) {
            if (strtolower((string) $k) === 'content-security-policy') {
                $csp = is_array($v) ? implode(' ', $v) : (string) $v;
            }
        }
        return str_contains($csp, 'sandbox');
    }

    /**
     * „Bestand übernehmen“: PDFs aus media/dokumente/ (WordPress-Import) als öffentliche Dateien eintragen, ohne sie zu verschieben.
     * Sie durchlaufen dieselben Prüfungen wie ein Upload; durchgefallene werden ausgelassen. Die Adresse /media/dokumente/<name> bleibt.
     *
     * @return array{adopted:int,skipped:int,known:int}
     */
    public function adoptLegacy(int $userId, int $folderId): array
    {
        $adopted = 0;
        $skipped = 0;
        $known = 0;
        foreach (glob($this->mediaDir . '/dokumente/*.pdf') ?: [] as $abs) {
            $name = basename($abs);
            $legacy = 'dokumente/' . $name;
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$/', $name) !== 1 || !is_file($abs) || is_link($abs)) {
                $skipped++;
                continue;
            }
            if ($this->db->value('SELECT 1 FROM files WHERE legacy_public_path = ?', [$legacy]) !== null) {
                $known++;
                continue;
            }
            try {
                [$kind] = $this->detect($abs);
                if ($kind !== 'pdf') {
                    throw new MediaException('kein PDF');
                }
                PdfCheck::check($abs);
                $r = $this->ingest($userId, $abs, $name, $folderId);
            } catch (MediaException) {
                $skipped++;
                continue;
            }
            if ($r['duplicate']) {
                $skipped++;
                continue;
            }
            $this->db->run("UPDATE files SET public = 1, public_name = ?, legacy_public_path = ?, updated_at = ? WHERE id = ?", [$name, $legacy, $this->clock->now(), $r['file_id']]);
            $adopted++;
        }
        return ['adopted' => $adopted, 'skipped' => $skipped, 'known' => $known];
    }

    // --- Papierkorb (einzelne Dateien) ---

    /** @param list<int> $ids */
    public function trash(array $ids): int
    {
        $n = 0;
        $now = $this->clock->now();
        foreach ($ids as $id) {
            $f = $this->get($id);
            if ($f === null) {
                continue;
            }
            if ($f['legacy_public_path'] === '') {
                $this->removeCopy($f); // nicht mehr öffentlich abrufbar
            }
            $n += $this->db->run(
                'UPDATE files SET deleted_at = ?, updated_at = ?' . ($f['legacy_public_path'] === '' ? ", public = 0, public_name = ''" : '') . ' WHERE id = ? AND deleted_at IS NULL',
                [$now, $now, $id],
            )->rowCount();
        }
        return $n;
    }

    /**
     * Öffentliche Kopien von Dateien im Papierkorb entfernen (auch Dateien, die mit ihrem Ordner gelöscht wurden: Folders::trash
     * ändert nur Datenbankzeilen). Wiederholbar, in Stapeln; übernommene Altdateien (legacy) bleiben bewusst erreichbar.
     *
     * @return int Anzahl bereinigter Dateien
     */
    public function unpublishTrashed(int $limit = 200): int
    {
        $n = 0;
        foreach ($this->db->all("SELECT * FROM files WHERE deleted_at IS NOT NULL AND public = 1 AND legacy_public_path = '' LIMIT " . $limit) as $f) {
            $this->removeCopy($f);
            $n += $this->db->run("UPDATE files SET public = 0, public_name = '' WHERE id = ?", [$f['id']])->rowCount();
        }
        return $n;
    }

    /** Einzeln gelöschte Dateien zurückholen (Zweige nur über den Ordner). @param list<int> $ids */
    public function restore(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $f = $this->get($id, true);
            if ($f === null || $f['deleted_at'] === null || $f['trash_root_id'] !== null) {
                continue;
            }
            $fid = (int) $f['folder_id'];
            if ($fid > 0 && $this->db->value('SELECT 1 FROM folders WHERE id = ? AND deleted_at IS NULL', [$fid]) === null) {
                $fid = 0;
            }
            $n += $this->db->run('UPDATE files SET deleted_at = NULL, folder_id = ?, updated_at = ? WHERE id = ?', [$fid, $this->clock->now(), $id])->rowCount();
        }
        return $n;
    }

    /** Endgültig löschen: Zeile, Original, öffentliche Kopie. Nur Dateien im Papierkorb. @param list<int> $ids */
    public function purge(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $f = $this->get($id, true);
            if ($f === null || $f['deleted_at'] === null) {
                continue;
            }
            // Erst die Zeile (nur, solange sie im Papierkorb liegt), dann die Dateien: holt jemand die Datei im selben Moment
            // zurück, bleibt das Original erhalten (Prüfung 19).
            if ($this->db->run('DELETE FROM files WHERE id = ? AND deleted_at IS NOT NULL', [$id])->rowCount() === 0) {
                continue;
            }
            $this->removeCopy($f);
            $abs = $this->absolute($f);
            if ($abs !== null) {
                @unlink($abs);
            }
            if ($f['legacy_public_path'] !== '' && preg_match('#^dokumente/[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.pdf$#', (string) $f['legacy_public_path']) === 1) {
                @unlink($this->mediaDir . '/' . $f['legacy_public_path']); // erst jetzt verschwindet die alte öffentliche Adresse
            }
            $n++;
        }
        return $n;
    }

    /** @return list<array<string,mixed>> einzeln gelöschte Dateien */
    public function trashedSingles(): array
    {
        $rows = $this->db->all('SELECT id, name, kind, bytes, deleted_at FROM files WHERE deleted_at IS NOT NULL AND trash_root_id IS NULL ORDER BY deleted_at DESC, id DESC');
        $now = $this->clock->now();
        foreach ($rows as &$r) {
            $r['days_left'] = max(0, (int) ceil(((int) $r['deleted_at'] + Catalog::TRASH_DAYS * 86400 - $now) / 86400));
        }
        return $rows;
    }
}
