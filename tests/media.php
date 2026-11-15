<?php
declare(strict_types=1);

// Schritt 3: Datenmodell, Upload in Stücken, Bildpipeline (Imagick und GD), EXIF/GPS, Dubletten, Abweisungen.

use Lichtkammer\Auth\Schema;
use Lichtkammer\Media\Capabilities;
use Lichtkammer\Media\ExifReader;
use Lichtkammer\Media\GdEngine;
use Lichtkammer\Media\ImagickEngine;
use Lichtkammer\Media\Library;
use Lichtkammer\Media\MediaException;

require_once __DIR__ . '/support.php';

/** JPEG-Segment mit EXIF: Marke, Ausrichtung, Datum, Blende, ISO und GPS (Breitengrad). */
function lk_exif_app1(int $orientation): string
{
    $ifd0Off = 8;
    $ifd0Len = 2 + 4 * 12 + 4;
    $exOff = $ifd0Off + $ifd0Len;
    $exLen = 2 + 3 * 12 + 4;
    $gpsOff = $exOff + $exLen;
    $gpsLen = 2 + 3 * 12 + 4;
    $dataOff = $gpsOff + $gpsLen;
    $data = '';
    $add = static function (string $bytes) use (&$data, $dataOff): int {
        $o = $dataOff + strlen($data);
        $data .= $bytes;
        return $o;
    };
    $make = $add("TestCam\0");
    $dt = $add("2024:06:15 10:30:00\0");
    $fn = $add(pack('VV', 28, 10));
    $lat = $add(pack('VVVVVV', 52, 1, 30, 1, 0, 1));
    $ifd0 = pack('v', 4)
        . pack('vvVV', 0x010F, 2, 8, $make)
        . pack('vvV', 0x0112, 3, 1) . pack('vv', $orientation, 0)
        . pack('vvVV', 0x8769, 4, 1, $exOff)
        . pack('vvVV', 0x8825, 4, 1, $gpsOff)
        . pack('V', 0);
    $ex = pack('v', 3)
        . pack('vvVV', 0x829D, 5, 1, $fn)
        . pack('vvV', 0x8827, 3, 1) . pack('vv', 400, 0)
        . pack('vvVV', 0x9003, 2, 20, $dt)
        . pack('V', 0);
    $gps = pack('v', 3)
        . pack('vvV', 0x0000, 1, 4) . pack('C4', 2, 3, 0, 0)
        . pack('vvV', 0x0001, 2, 2) . "N\0\0\0"
        . pack('vvVV', 0x0002, 5, 3, $lat)
        . pack('V', 0);
    $tiff = "II*\0" . pack('V', 8) . $ifd0 . $ex . $gps . $data;
    $payload = "Exif\0\0" . $tiff;
    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}

/** Testbild als JPEG-Bytes, linke Hälfte rot, rechte blau; optional mit EXIF. */
function lk_jpeg(int $w, int $h, ?int $orientation = null): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, (int) imagecolorallocate($im, 220, 30, 30));
    imagefilledrectangle($im, intdiv($w, 2) + 1, 0, $w, $h, (int) imagecolorallocate($im, 30, 30, 220));
    ob_start();
    imagejpeg($im, null, 90);
    $jpg = (string) ob_get_clean();
    return $orientation === null ? $jpg : substr($jpg, 0, 2) . lk_exif_app1($orientation) . substr($jpg, 2);
}

/** Lädt Bytes über die echten Wege hoch (start, Stücke, Ende). @return array<string,mixed> */
function lk_upload(TestBrowser $b, string $name, string $bytes, int $chunk = 5000): array
{
    $csrf = TestBrowser::csrf($b->get('/admin/fotos'));
    $start = json_decode($b->post('/admin/upload/start', ['_csrf' => $csrf, 'name' => $name, 'size' => (string) strlen($bytes)])->body, true);
    if (!isset($start['id'])) {
        return ['stage' => 'start'] + $start;
    }
    for ($off = 0; $off < strlen($bytes); $off += $chunk) {
        $r = $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=' . $off, [], ['HTTP_X_CSRF_TOKEN' => $csrf], substr($bytes, $off, $chunk));
        if ($r->status !== 200) {
            return ['stage' => 'chunk', 'status' => $r->status] + (array) json_decode($r->body, true);
        }
    }
    $fin = $b->request('POST', '/admin/upload/' . $start['id'] . '/finish', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
    $out = (array) json_decode($fin->body, true);
    $out['_status'] = $fin->status;
    $out['_csrf'] = $csrf;
    return $out;
}

$f = lk_new_tmp($tmp, 'media');

// --- Bildbibliotheken ---
$imagick = ImagickEngine::available() ? new ImagickEngine() : null;
$gd = GdEngine::available() ? new GdEngine() : null;
check('Medien: Imagick vorhanden (Testumgebung)', $imagick !== null);
check('Medien: GD vorhanden (Testumgebung)', $gd !== null);
check('Medien: Imagick schreibt JPEG und WebP', $imagick !== null && in_array('jpeg', $imagick->formats(), true) && in_array('webp', $imagick->formats(), true));
check('Medien: GD schreibt JPEG und WebP', $gd !== null && in_array('jpeg', $gd->formats(), true) && in_array('webp', $gd->formats(), true));
check('Medien: Capabilities wählt Imagick vor GD', Capabilities::engine() instanceof ImagickEngine);
check('Medien: Imagick liest JPEG/PNG/WebP, nicht SVG', $imagick->canRead('image/jpeg') && $imagick->canRead('image/png') && $imagick->canRead('image/webp') && !$imagick->canRead('image/svg+xml'));

// --- Breitenplan ---
check('Plan: 1920 px → 480, 960, 1600 und die Originalbreite', Library::plan(1920) === [480, 960, 1600, 1920]);
check('Plan: 6000 px → vier Stufen bis 2400', Library::plan(6000) === [480, 960, 1600, 2400]);
check('Plan: genau 960 px → 480, 960', Library::plan(960) === [480, 960]);
check('Plan: kleines Bild (300 px) → nur Originalbreite, nie vergrößern', Library::plan(300) === [300]);
check('Plan: 2400 px genau → keine doppelte Stufe', Library::plan(2400) === [480, 960, 1600, 2400]);

// --- EXIF-Leser ---
$exifFile = $f . '/exif.jpg';
file_put_contents($exifFile, lk_jpeg(1200, 800, 6));
$ex = ExifReader::read($exifFile, 'image/jpeg');
check('EXIF: Kamera gelesen', ($ex['fields']['camera'] ?? '') === 'TestCam');
check('EXIF: Blende und ISO', ($ex['fields']['aperture'] ?? '') === 'f/2.8' && ($ex['fields']['iso'] ?? '') === '400');
check('EXIF: Aufnahmedatum', $ex['taken_at'] === '2024-06-15 10:30:00');
check('EXIF: GPS erkannt, aber nicht in den Feldern', $ex['has_gps'] === true && !preg_match('/gps|lat|52/i', json_encode($ex['fields'])));
check('EXIF: Bild ohne EXIF liefert leere Felder', ExifReader::read($f . '/nonexistent.jpg', 'image/jpeg')['fields'] === []);

// --- Datenmodell ---
$m = lk_make($f . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
check('Schema: Version 4', Schema::version($db) === 4 && count(Schema::migrations()) === 4);
foreach (['galleries', 'photos', 'photo_variants', 'uploads'] as $t) {
    check("Schema: Tabelle $t", $db->value("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$t]) !== null);
}
check('Schema: Anmeldetabellen unverändert da', (int) $db->value('SELECT COUNT(*) FROM users') === 1);

// --- Fotoseite ---
$page = $b->get('/admin/fotos');
check('Fotos: Seite für Angemeldete', $page->status === 200 && str_contains($page->body, 'id="uploader"') && str_contains($page->body, '/assets/js/upload.js'));
check('Fotos: ohne Inline-Skript/-Stil (CSP)', !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\sonclick=~i', $page->body));
check('Fotos: Navigation verweist darauf', str_contains($page->body, 'href="/admin/fotos"'));
$anon = new TestBrowser($m['app'], $m['config']);
check('Fotos: ohne Anmeldung Weiterleitung', $anon->get('/admin/fotos')->status === 303);
check('Upload: ohne Anmeldung keine Annahme', $anon->post('/admin/upload/start', ['name' => 'a.jpg', 'size' => '10'])->status === 303);
$noCsrf = $b->request('POST', '/admin/upload/start', ['name' => 'a.jpg', 'size' => '10']);
check('Upload: ohne CSRF-Token abgelehnt', $noCsrf->status === 403);
check('Upload: Fotos-Skript ohne innerHTML/eval/Fremdquellen', !preg_match('~eval\(|innerHTML|https?://~', (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/upload.js')));

// --- Hochladen in Stücken, Verarbeiten ---
$orig = lk_jpeg(1200, 800, 6); // gespeichert quer, soll hochkant (800 × 1200) erscheinen
$sha = hash('sha256', $orig);
$r = lk_upload($b, 'Urlaub 1.jpg', $orig, 7000);
check('Upload: abgeschlossen, kein Duplikat', ($r['_status'] ?? 0) === 200 && $r['duplicate'] === false && $r['photo_id'] > 0);
$pid = (int) $r['photo_id'];
$row = $db->one('SELECT * FROM photos WHERE id = ?', [$pid]);
check('Upload: Zeile wartet auf Verarbeitung', $row['status'] === 'pending' && $row['sha256'] === $sha && $row['original_name'] === 'Urlaub 1.jpg');
$origFile = $m['config']->originalsDir() . '/' . $row['original_path'];
check('Original: gespeichert, byte-gleich', is_file($origFile) && hash_file('sha256', $origFile) === $sha);
check('Original: außerhalb des Webroots', !str_starts_with(realpath($origFile), realpath($m['config']->publicDir)));
check('Original: Name ist zufällig, nicht der Dateiname', !str_contains($origFile, 'Urlaub') && preg_match('~/[0-9a-f]{2}/[0-9a-f]{24}\.jpg$~', $origFile) === 1);
check('Original: Ordner gesperrt (.htaccess)', is_file($m['config']->originalsDir() . '/.htaccess'));
check('Upload: Teilstück weg, Zeile in uploads weg', glob($svc->dataDir . '/uploads/*.part') === [] && (int) $db->value('SELECT COUNT(*) FROM uploads') === 0);
check('Upload: im Protokoll vermerkt', (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='photo_uploaded'") === 1 && $svc->audit->verify()['ok']);

$pr = json_decode($b->request('POST', "/admin/fotos/$pid/verarbeiten", [], ['HTTP_X_CSRF_TOKEN' => $r['_csrf']])->body, true);
check('Verarbeitung: fertig, Varianten erzeugt', $pr['status'] === 'ready' && $pr['created'] === 4);
$row = $db->one('SELECT * FROM photos WHERE id = ?', [$pid]);
check('Verarbeitung: Ausrichtung angewendet (hochkant)', (int) $row['width'] === 800 && (int) $row['height'] === 1200);
check('Verarbeitung: Aufnahme-Metadaten gespeichert', $row['taken_at'] === '2024-06-15 10:30:00' && (int) $row['year'] === 2024 && (int) $row['has_gps'] === 1 && str_contains($row['exif_json'], 'TestCam'));
check('Verarbeitung: GPS-Koordinaten stehen nicht in der Datenbank', !str_contains($row['exif_json'], '52') && !preg_match('/gps/i', $row['exif_json']));
check('Verarbeitung: Platzhalter als kleine Daten-Adresse', str_starts_with($row['blur_data_uri'], 'data:image/jpeg;base64,') && strlen($row['blur_data_uri']) < 3000);
$variants = $db->all('SELECT * FROM photo_variants WHERE photo_id = ? ORDER BY width, format', [$pid]);
$widths = array_values(array_unique(array_map(static fn (array $v): int => (int) $v['width'], $variants)));
check('Varianten: Stufen 480 und 800 (Originalbreite)', $widths === [480, 800]);
$fmts = array_values(array_unique(array_map(static fn (array $v): string => $v['format'], $variants)));
check('Varianten: JPEG und WebP je Stufe', in_array('jpeg', $fmts, true) && in_array('webp', $fmts, true) && count($variants) === count($widths) * count($fmts));
$allClean = true;
$allThere = true;
$dimsOk = true;
foreach ($variants as $v) {
    $file = $m['config']->mediaDir() . '/' . $v['path'];
    if (!is_file($file) || filesize($file) !== (int) $v['bytes']) {
        $allThere = false;
        continue;
    }
    $bytes = (string) file_get_contents($file);
    if (str_contains($bytes, 'Exif') || str_contains($bytes, 'TestCam') || str_contains($bytes, 'GPS')) {
        $allClean = false;
    }
    if ($v['format'] === 'jpeg') {
        $e2 = @exif_read_data($file);
        if (is_array($e2) && (isset($e2['GPSLatitude']) || isset($e2['Make']))) {
            $allClean = false;
        }
        $sz = getimagesize($file);
        if ($sz[0] !== (int) $v['width'] || $sz[1] !== (int) $v['height']) {
            $dimsOk = false;
        }
    }
}
check('Varianten: alle Dateien da, Größe stimmt', $allThere);
check('Varianten: ohne EXIF und ohne GPS', $allClean);
check('Varianten: Breite/Höhe passen zur Datenbank (hochkant)', $dimsOk);
check('Varianten: Dateinamen aus Zufallskennung, nicht aus dem Dateinamen', preg_match('~^[0-9a-f]{2}/[0-9a-f]{24}-\d+\.(jpg|webp|avif)$~', $variants[0]['path']) === 1);
check('Varianten: keine Dateien außer den erwarteten (keine .tmp-Reste)', glob($m['config']->mediaDir() . '/*/*.tmp*') === []);

// zweiter Lauf: nichts passiert
$mtimes = [];
foreach ($variants as $v) {
    $mtimes[$v['path']] = [filemtime($m['config']->mediaDir() . '/' . $v['path']), filesize($m['config']->mediaDir() . '/' . $v['path'])];
}
$again = json_decode($b->request('POST', "/admin/fotos/$pid/verarbeiten", [], ['HTTP_X_CSRF_TOKEN' => $r['_csrf']])->body, true);
$same = true;
foreach ($variants as $v) {
    $p = $m['config']->mediaDir() . '/' . $v['path'];
    $same = $same && $mtimes[$v['path']] === [filemtime($p), filesize($p)];
}
check('Idempotent: zweiter Lauf meldet „unchanged“, nichts erzeugt', $again['status'] === 'unchanged' && $again['created'] === 0);
check('Idempotent: Variantendateien unverändert', $same);
// eine Variante fehlt → nur sie wird ersetzt
$victim = $variants[0];
unlink($m['config']->mediaDir() . '/' . $victim['path']);
$fix = $m['app']->services()->library->process($pid);
check('Idempotent: fehlende Variante wird einzeln ersetzt', $fix['status'] === 'ready' && $fix['created'] === 1 && is_file($m['config']->mediaDir() . '/' . $victim['path']));
// Originalschutz bei erzwungenem Neuerzeugen
$forced = $svc->library->process($pid, true);
check('Originalschutz: Original nach erzwungenem Lauf unverändert', $forced['status'] === 'ready' && hash_file('sha256', $origFile) === $sha && filesize($origFile) === strlen($orig));
// veraltete Variante wird entfernt
$db->run("INSERT INTO photo_variants(photo_id,width,height,format,path,bytes) VALUES(?,?,?,?,?,?)", [$pid, 1111, 1666, 'jpeg', substr($row['token'], 0, 2) . '/' . $row['token'] . '-1111.jpg', 5]);
file_put_contents($m['config']->mediaDir() . '/' . substr($row['token'], 0, 2) . '/' . $row['token'] . '-1111.jpg', 'alt');
$clean = $svc->library->process($pid, true);
check('Aufräumen: veraltete Variante entfernt (Datei und Zeile)', $clean['removed'] === 1 && !is_file($m['config']->mediaDir() . '/' . substr($row['token'], 0, 2) . '/' . $row['token'] . '-1111.jpg')
    && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE width = 1111') === 0);

// Übersicht zeigt das Foto mit Vorschau
$list = $b->get('/admin/fotos');
check('Fotos: Übersicht zeigt Vorschaubild und „Alt-Text fehlt“', str_contains($list->body, '/media/') && str_contains($list->body, 'Urlaub 1.jpg') && str_contains($list->body, 'Alt-Text fehlt'));

// --- Dubletten ---
$dup = lk_upload($b, 'Kopie.jpg', $orig);
check('Dublette: erkannt und gemeldet', $dup['duplicate'] === true && $dup['photo_id'] === $pid && $dup['in_trash'] === false);
check('Dublette: kein zweites Original, keine zweite Zeile', (int) $db->value('SELECT COUNT(*) FROM photos') === 1 && count(glob($m['config']->originalsDir() . '/*/*.jpg')) === 1);
$db->run('UPDATE photos SET deleted_at = ? WHERE id = ?', [TEST_START, $pid]);
$dup2 = lk_upload($b, 'Kopie2.jpg', $orig);
check('Dublette: auch im Papierkorb erkannt', $dup2['duplicate'] === true && $dup2['in_trash'] === true);
$db->run('UPDATE photos SET deleted_at = NULL WHERE id = ?', [$pid]);

// --- Abweisungen am Inhalt ---
$php = lk_upload($b, 'harmlos.jpg', "<?php echo 'x'; ?>" . str_repeat('A', 200));
check('Abweisung: PHP-Code mit .jpg-Endung', isset($php['error']) && str_contains($php['error'], 'nicht erlaubt'));
$svg = lk_upload($b, 'bild.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
check('Abweisung: SVG', isset($svg['error']) && str_contains($svg['error'], 'nicht erlaubt'));
$txt = lk_upload($b, 'a.txt', "nur Text\n");
check('Abweisung: Textdatei', isset($txt['error']));
$gif = lk_upload($b, 'a.gif', base64_decode('R0lGODlhAQABAAAAACw=') . str_repeat("\0", 40));
check('Abweisung: GIF (nicht erlaubt)', isset($gif['error']));
$broken = lk_upload($b, 'kaputt.jpg', substr(lk_jpeg(300, 200), 0, 120));
check('Abweisung: abgeschnittenes JPEG', isset($broken['error']));
check('Abweisung: nichts davon liegt in photos/originals', (int) $db->value('SELECT COUNT(*) FROM photos') === 1 && count(glob($m['config']->originalsDir() . '/*/*')) === 1);
$empty = $b->post('/admin/upload/start', ['_csrf' => $r['_csrf'], 'name' => 'leer.jpg', 'size' => '0']);
check('Abweisung: leere Datei schon beim Start', $empty->status === 400);

// --- Stückelung: falscher Versatz, fremder Nutzer, zu viel Daten ---
$start = json_decode($b->post('/admin/upload/start', ['_csrf' => $r['_csrf'], 'name' => 'x.jpg', 'size' => '100'])->body, true);
$hdr = ['HTTP_X_CSRF_TOKEN' => $r['_csrf']];
$c1 = $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=0', [], $hdr, str_repeat('a', 40));
$c2 = $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=0', [], $hdr, str_repeat('a', 40));
check('Stücke: erstes Stück angenommen', $c1->status === 200 && json_decode($c1->body, true)['received'] === 40);
check('Stücke: falscher Versatz → 409 mit richtigem Stand', $c2->status === 409 && json_decode($c2->body, true)['received'] === 40);
$c3 = $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=40', [], $hdr, str_repeat('a', 70));
check('Stücke: mehr als angekündigt abgelehnt', $c3->status === 400);
$fin = $b->request('POST', '/admin/upload/' . $start['id'] . '/finish', [], $hdr);
check('Stücke: unvollständig → Fehler, Teilstück aufgeräumt', $fin->status === 400 && glob($svc->dataDir . '/uploads/*.part') === []);
$ghost = $b->request('POST', '/admin/upload/' . str_repeat('0', 32) . '/chunk?offset=0', [], $hdr, 'abc');
check('Stücke: unbekannte Kennung → 404', $ghost->status === 404);
$trav = $b->request('POST', '/admin/upload/..%2F..%2Fx/chunk?offset=0', [], $hdr, 'abc');
check('Stücke: Kennung mit Pfadanteilen → nicht gefunden', in_array($trav->status, [404], true));
$st2 = $svc->uploads->start(1, 'y.jpg', 50, null);
$foreign = false;
try {
    $svc->uploads->chunk(999, $st2, 0, 'abc');
} catch (MediaException $e) {
    $foreign = $e->getCode() === 404;
}
check('Stücke: fremder Nutzer kommt nicht an den Upload', $foreign);

// --- Größenbegrenzung ---
$m2 = lk_make($f . '/big', ['LK_MAX_UPLOAD_MB' => '1']);
$s2 = lk_setup($m2);
$big = lk_upload($s2['b'], 'gross.jpg', str_repeat('x', 1_200_000));
check('Größe: zu große Datei schon beim Start abgewiesen', isset($big['error']) && str_contains($big['error'], 'zu groß'));

// --- GD als Ersatz: gleiche Datei, GD-Pfad, ebenfalls ohne GPS und mit Ausrichtung ---
$m3 = lk_make($f . '/gd');
$s3 = lk_setup($m3);
$svc3 = $m3['app']->services();
$gdLib = new Library($svc3->db, $svc3->clock, $m3['config']->originalsDir(), $m3['config']->mediaDir(), $gd);
$tmpJpg = $f . '/gd-in.jpg';
file_put_contents($tmpJpg, lk_jpeg(2000, 1000, 1));
$ing = $gdLib->ingest(1, $tmpJpg, 'gd.jpg', null);
$res = $gdLib->process($ing['photo_id']);
$gdRow = $svc3->db->one('SELECT * FROM photos WHERE id = ?', [$ing['photo_id']]);
$gdVars = $svc3->db->all('SELECT * FROM photo_variants WHERE photo_id = ?', [$ing['photo_id']]);
check('GD: verarbeitet', $res['status'] === 'ready' && (int) $gdRow['width'] === 2000 && (int) $gdRow['height'] === 1000);
check('GD: Stufen 480, 960, 1600, 2000', array_values(array_unique(array_map(static fn (array $v): int => (int) $v['width'], $gdVars))) === Library::plan(2000) && Library::plan(2000) === [480, 960, 1600, 2000]);
$gdClean = true;
foreach ($gdVars as $v) {
    $bytes = (string) file_get_contents($m3['config']->mediaDir() . '/' . $v['path']);
    $gdClean = $gdClean && !str_contains($bytes, 'Exif') && !str_contains($bytes, 'TestCam');
}
check('GD: Varianten ohne EXIF/GPS', $gdClean);
// GD mit Hochkant-EXIF
file_put_contents($tmpJpg, lk_jpeg(1200, 800, 6));
$ing2 = $gdLib->ingest(1, $tmpJpg, 'gd2.jpg', null);
$gdLib->process($ing2['photo_id']);
$gd2 = $svc3->db->one('SELECT width, height FROM photos WHERE id = ?', [$ing2['photo_id']]);
check('GD: Ausrichtung angewendet (hochkant)', (int) $gd2['width'] === 800 && (int) $gd2['height'] === 1200);

// --- Fehlerhaftes Bild bei der Verarbeitung: Foto „failed“, andere laufen weiter ---
$lib3 = $svc3->library;
$ing3 = $lib3->ingest(1, $tmpJpg, 'zerstoert.jpg', null); // gleiche Bytes wie gd2 → Dublette
check('Dublette über Bibliothek: erkannt', $ing3['duplicate'] === true);
file_put_contents($f . '/good.jpg', lk_jpeg(700, 500, 3));
$good = $lib3->ingest(1, $f . '/good.jpg', 'gut.jpg', null);
$badFile = $f . '/bad.jpg';
file_put_contents($badFile, lk_jpeg(500, 300));
$bad = $lib3->ingest(1, $badFile, 'bad.jpg', null);
$badRow = $svc3->db->one('SELECT original_path FROM photos WHERE id = ?', [$bad['photo_id']]);
file_put_contents($m3['config']->originalsDir() . '/' . $badRow['original_path'], str_repeat("\0", 64)); // Original nachträglich beschädigt
$badRes = $lib3->process($bad['photo_id']);
$goodRes = $lib3->process($good['photo_id']);
check('Fehler: beschädigtes Bild → „failed“ mit deutscher Meldung', $badRes['status'] === 'failed' && $badRes['error'] !== '' && !str_contains($badRes['error'], '/'));
check('Fehler: das nächste Bild wird trotzdem verarbeitet', $goodRes['status'] === 'ready');
check('Fehler: Status steht in der Datenbank', $svc3->db->value('SELECT status FROM photos WHERE id = ?', [$bad['photo_id']]) === 'failed');
$missing = false;
try {
    $lib3->process(99999);
} catch (MediaException) {
    $missing = true;
}
check('Fehler: unbekanntes Foto → verständliche Ausnahme', $missing);
$orig404 = $svc3->db->one('SELECT id FROM photos WHERE id = ?', [$good['photo_id']]);
check('Fehler: Foto in der Übersicht mit Meldung', str_contains($s3['b']->get('/admin/fotos')->body, 'error-text'));
