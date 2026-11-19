<?php
declare(strict_types=1);

// Bildvarianten für alle Fotos neu erzeugen (Admin → Bildvarianten, Media/VariantRebuild): Stapel, Fortsetzen, Überspringen,
// fehlende Originale, Rechte, CSRF, keine Metadaten in Varianten, Zeitgrenze, Warnband, Protokoll.

use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Media\VariantRebuild;

require_once __DIR__ . '/support.php';

$vs = lk_new_tmp($tmp, 'varianten');
$V = lk_make($vs, ['LK_ORIGINALS_DIR' => $vs . '/orig', 'LK_DATA_DIR' => $vs . '/data']);
$vsu = lk_setup($V);
$vsvc = $V['app']->services();
$vdb = $vsvc->db;
$vb = lk_login($V, 'lutz', TEST_PASSWORD, $vsu['secret']);
$media = $V['config']->mediaDir();
$origDir = $V['config']->originalsDir();

// Fünf Fotos mit EXIF und GPS im Original, über die echten Wege hochgeladen und verarbeitet.
$vcsrf = TestBrowser::csrf($vb->get('/admin/fotos'));
$ids = [];
foreach ([700, 701, 702, 703, 704] as $w) {
    $r = lk_upload($vb, "gps-$w.jpg", lk_jpeg($w, 300, 1));
    $vb->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], ['HTTP_X_CSRF_TOKEN' => $vcsrf]);
    $ids[] = (int) $r['photo_id'];
}
check('Varianten: Vorbereitung, fünf Fotos fertig, Original mit GPS', (int) $vdb->value("SELECT COUNT(*) FROM photos WHERE status = 'ready' AND has_gps = 1") === 5);

$hashOrig = static function () use ($vdb, $origDir): array {
    $o = [];
    foreach ($vdb->all('SELECT original_path FROM photos') as $r) {
        $f = $origDir . '/' . $r['original_path'];
        $o[$r['original_path']] = is_file($f) ? hash_file('sha256', $f) . ':' . filemtime($f) : 'fehlt';
    }
    return $o;
};
$origBefore = $hashOrig();
$tick = static function (float $by): callable { // Uhr, die bei jedem Aufruf um $by Sekunden weiterläuft
    $t = 0.0;
    return static function () use (&$t, $by): float {
        $t += $by;
        return $t;
    };
};
$mk = static fn (float $budget = VariantRebuild::BUDGET, ?callable $timer = null): VariantRebuild => new VariantRebuild($vdb, $V['clock'], $vsvc->library, $origDir, $budget, $timer);
$clearMedia = static function () use ($media): void {
    exec('rm -rf ' . escapeshellarg($media) . '/*');
};

// ---------------------------------------------------------------- vollständig: alles wird übersprungen, nichts angefasst
$files = glob($media . '/*/*.{jpg,webp,avif}', GLOB_BRACE) ?: [];
foreach ($files as $f) {
    touch($f, 1_000_000);
}
clearstatcache();
$r = $mk()->step();
check('Überspringen: vollständige Fotos werden nicht neu erzeugt (5 übersprungen, 0 erzeugt)', $r['done'] && $r['skipped'] === 5 && $r['created'] === 0 && $r['failed'] === 0 && $r['total'] === 5);
clearstatcache();
$untouched = true;
foreach ($files as $f) {
    $untouched = $untouched && filemtime($f) === 1_000_000;
}
check('Überspringen: vorhandene Dateien unverändert (Änderungszeit gleich)', $files !== [] && $untouched);
check('Überspringen: nach dem Ende ist kein Durchgang mehr offen, Ergebnis bleibt lesbar', !$mk()->running() && ($mk()->runStats()['skipped'] ?? -1) === 5);

// ---------------------------------------------------------------- Stand nach Wiederherstellung: Dateien weg, Zeilen da
$clearMedia();
$st = $mk()->status();
check('Status: ohne Dateien haben 0 von 5 Fotos Bildvarianten', $st['total'] === 5 && $st['ok'] === 0 && $st['problems'] === []);
$home = $vb->get('/admin');
check('Warnband: „5 von 5 Foto(s) haben keine Bildvarianten“ mit Verweis', $home->status === 200 && str_contains($home->body, '5 von 5 Foto(s) haben keine Bildvarianten') && str_contains($home->body, '/admin/varianten'));
$sys = $vb->get('/admin/system');
check('Systemprüfung: Zeile Bildvarianten „Bitte ansehen“ mit Anzahl', preg_match('~Bildvarianten</td>\s*<td><span class="badge cover">Bitte ansehen</span></td>\s*<td>0 von 5 Fotos~', $sys->body) === 1);
$page = $vb->get('/admin/varianten');
check('Seite: zeigt „0 von 5 Fotos haben Bildvarianten“ und beide Knöpfe', $page->status === 200 && str_contains($page->body, '0 von 5 Fotos haben Bildvarianten') && str_contains($page->body, 'Fehlende Bildvarianten erzeugen') && str_contains($page->body, 'erzwingen'));
check('Navigation: Verweis „Bildvarianten“ für Administratoren', str_contains($page->body, 'href="/admin/varianten"'));

// ---------------------------------------------------------------- Zeitgrenze und Stapel (feste Uhr)
$v = $mk(0.0, $tick(10.0));
$steps = 0;
$handled = [];
do {
    $r = $v->step();
    $steps++;
    $handled[] = $r['handled'];
    if (!$r['done']) {
        check("Zeitgrenze: Schritt $steps läuft nach dem ersten Foto nicht weiter, Stand liegt in der Datenbank", $r['handled'] === 1 && $v->running() && $mk()->running());
    }
} while (!$r['done'] && $steps < 20);
check('Zeitgrenze: mit Budget 0 genau ein Foto je Aufruf (5 Aufrufe, je 1), Durchgang endet', $steps === 5 && $handled === [1, 1, 1, 1, 1] && $r['done'] && !$mk()->running());
check('Stapel: nach den Schritten haben alle 5 Fotos Bildvarianten, 5 erzeugt', $mk()->status()['ok'] === 5 && ($mk()->runStats()['created'] ?? 0) === 5);

// Budget, das für zwei Fotos reicht: höchstens ein Teil je Aufruf, Summe stimmt
$clearMedia();
$v = $mk(25.0, $tick(4.0));
$steps = 0;
$sum = 0;
$maxHandled = 0;
do {
    $r = $v->step();
    $steps++;
    $sum += $r['handled'];
    $maxHandled = max($maxHandled, $r['handled']);
} while (!$r['done'] && $steps < 20);
check('Stapel: mit kleinem Budget mehrere Aufrufe, jeder bearbeitet höchstens einen Teil, am Ende alles da', $steps > 1 && $steps < 5 && $maxHandled < 5 && $sum === 5 && $mk()->status()['ok'] === 5);

// ---------------------------------------------------------------- Fortsetzen nach Abbruch (neues Objekt = neu geladene Seite)
$clearMedia();
$a = $mk(0.0, $tick(10.0));
$r1 = $a->step();
check('Fortsetzen: nach dem ersten Foto läuft der Durchgang, 1 von 5 geprüft', !$r1['done'] && $r1['position'] === 1 && $mk()->running() && $mk()->status()['position'] === 1);
$cursor = $vdb->value("SELECT v FROM meta WHERE k = 'variants_cursor'");
$done = false;
$b2 = $mk(0.0, $tick(10.0)); // frisches Objekt, nichts im Speicher
$n = 0;
while (!$done && $n++ < 10) {
    $r = $b2->step();
    $done = $r['done'];
}
check('Fortsetzen: neues Objekt macht an derselben Stelle weiter (kein Neubeginn), erzeugt in Summe 5', $cursor !== null && (int) $cursor > 0 && $done && ($mk()->runStats()['created'] ?? 0) === 5 && ($mk()->runStats()['steps'] ?? 0) === 5 && $mk()->status()['ok'] === 5);

// ---------------------------------------------------------------- leere und beschädigte Dateien zählen als fehlend
$one = $vdb->one('SELECT path FROM photo_variants WHERE photo_id = ? LIMIT 1', [$ids[2]]);
file_put_contents($media . '/' . $one['path'], '');
check('Größenprüfung: leere Variante gilt als fehlend', $mk()->status()['ok'] === 4);
$r = $mk()->step();
check('Größenprüfung: nur das betroffene Foto wird neu erzeugt (1 erzeugt, 4 übersprungen)', $r['created'] === 1 && $r['skipped'] === 4 && filesize($media . '/' . $one['path']) > 0 && $mk()->status()['ok'] === 5);

// ---------------------------------------------------------------- Erzwingen: alles neu, Originale unberührt, keine Metadaten
foreach (glob($media . '/*/*.{jpg,webp,avif}', GLOB_BRACE) ?: [] as $f) {
    touch($f, 1_000_000);
}
clearstatcache();
$vf = $mk();
$vf->start(true);
$r = $vf->step();
clearstatcache();
$changed = 0;
$meta = 0;
$nfiles = 0;
foreach (glob($media . '/*/*.{jpg,webp,avif}', GLOB_BRACE) ?: [] as $f) {
    $nfiles++;
    $changed += filemtime($f) !== 1_000_000 ? 1 : 0;
    $bytes = (string) file_get_contents($f);
    $meta += (str_contains($bytes, 'Exif') || str_contains($bytes, 'EXIF') || str_contains($bytes, 'TestCam') || str_contains($bytes, 'XMP')) ? 1 : 0;
    if (str_ends_with($f, '.jpg')) {
        $x = @exif_read_data($f);
        $meta += is_array($x) && (isset($x['GPSLatitude']) || isset($x['GPSLongitude']) || isset($x['Make'])) ? 1 : 0;
    }
}
check('Erzwingen: alle Fotos neu erzeugt (5), alle Dateien neu geschrieben', $r['done'] && $r['force'] === true && $r['created'] === 5 && $nfiles > 0 && $changed === $nfiles);
check('Keine Metadaten: in keiner Variante EXIF, GPS, Kamera oder XMP', $nfiles > 0 && $meta === 0);
check('Keine Metadaten: das Original hat GPS (Vergleich), die Datenbank merkt es nur als Zahl', (int) $vdb->value('SELECT COUNT(*) FROM photos WHERE has_gps = 1') === 5);
check('Originale: nach den Läufen unverändert (Prüfsumme und Änderungszeit)', $hashOrig() === $origBefore);

// ---------------------------------------------------------------- fehlendes, falsches und verlinktes Original: Foto wird übersprungen
$clearMedia();
$relMissing = (string) $vdb->value('SELECT original_path FROM photos WHERE id = ?', [$ids[0]]);
$relLink = (string) $vdb->value('SELECT original_path FROM photos WHERE id = ?', [$ids[1]]);
$relBad = (string) $vdb->value('SELECT original_path FROM photos WHERE id = ?', [$ids[2]]);
$secret = $vs . '/geheim.jpg';
file_put_contents($secret, lk_jpeg(640, 300));
$secretHash = hash_file('sha256', $secret);
rename($origDir . '/' . $relMissing, $vs . '/weg-' . basename($relMissing));
$linkBytes = (string) file_get_contents($origDir . '/' . $relLink);
unlink($origDir . '/' . $relLink);
symlink($secret, $origDir . '/' . $relLink);
$vdb->run('UPDATE photos SET original_path = ? WHERE id = ?', ['../../etc/passwd', $ids[2]]);
$r = $mk()->step();
check('Fehlendes Original: Durchgang bricht nicht ab, die zwei übrigen Fotos werden erzeugt', $r['done'] && $r['created'] === 2 && $r['noorig'] === 3 && $r['failed'] === 0);
$st = $mk()->status();
$names = array_column($st['problems'], 'id');
sort($names);
check('Fehlerliste: nennt das fehlende, das verlinkte und das falsch benannte Original', $names === [$ids[0], $ids[1], $ids[2]] && $st['ok'] === 2 && str_contains($st['problems'][0]['text'], 'Original fehlt'));
check('Fehlendes Original: Foto ist als fehlgeschlagen markiert, es entstehen keine Varianten dafür', (string) $vdb->value('SELECT status FROM photos WHERE id = ?', [$ids[0]]) === 'failed' && glob($media . '/' . substr((string) $vdb->value('SELECT token FROM photos WHERE id = ?', [$ids[0]]), 0, 2) . '/' . $vdb->value('SELECT token FROM photos WHERE id = ?', [$ids[0]]) . '-*') === []);
check('Verlinktes Original: wird nicht gefolgt (keine Variante aus der Zieldatei), Ziel unverändert', glob($media . '/' . substr((string) $vdb->value('SELECT token FROM photos WHERE id = ?', [$ids[1]]), 0, 2) . '/' . $vdb->value('SELECT token FROM photos WHERE id = ?', [$ids[1]]) . '-*') === [] && hash_file('sha256', $secret) === $secretHash && is_link($origDir . '/' . $relLink));
$page = $vb->get('/admin/varianten');
check('Seite: Fehlerliste mit den drei Fotos sichtbar, „3 mit Problem“ im letzten Durchgang', str_contains($page->body, 'Probleme') && str_contains($page->body, 'Das Original fehlt im Speicher') && str_contains($page->body, '2 von 5 Fotos haben Bildvarianten'));
// aufräumen für die nächsten Prüfungen
unlink($origDir . '/' . $relLink);
file_put_contents($origDir . '/' . $relLink, $linkBytes);
rename($vs . '/weg-' . basename($relMissing), $origDir . '/' . $relMissing);
$vdb->run('UPDATE photos SET original_path = ? WHERE id = ?', [$relBad, $ids[2]]);
$relFour = (string) $vdb->value('SELECT original_path FROM photos WHERE id = ?', [$ids[3]]);
$r = $mk()->step();
check('Nachträglich da: nach dem Zurücklegen der Originale werden die drei Fotos nachgeholt', $r['done'] && $r['created'] === 3 && $mk()->status()['ok'] === 5 && $mk()->status()['problems'] === []);

// Erzwingen mit fehlendem Original lässt vorhandene Varianten und den Status in Ruhe
$vdb->run('UPDATE photos SET original_path = ? WHERE id = ?', ['ab/' . str_repeat('0', 24) . '.jpg', $ids[3]]);
$fv = $mk();
$fv->start(true);
$r = $fv->step();
check('Erzwingen ohne Original: vorhandene Varianten bleiben, Status bleibt „ready“', $r['noorig'] === 1 && $r['created'] === 4 && (string) $vdb->value('SELECT status FROM photos WHERE id = ?', [$ids[3]]) === 'ready' && $mk()->status()['ok'] === 5);
$vdb->run('UPDATE photos SET original_path = ? WHERE id = ?', [$relFour, $ids[3]]);

// ---------------------------------------------------------------- Rechte und CSRF
$anon = new TestBrowser($V['app'], $V['config']);
foreach (['/admin/varianten'] as $path) {
    check("Rechte: GET $path ohne Anmeldung → Umleitung", $anon->get($path)->status === 303);
}
foreach (['/admin/varianten/schritt', '/admin/varianten/weiter'] as $path) {
    check("Rechte: POST $path ohne Anmeldung → Umleitung", $anon->post($path, [])->status === 303);
}
$vdb->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'redaktion', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ed' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$edSess = $vsvc->sessions->create((int) $vdb->value("SELECT id FROM users WHERE username = 'redaktion'"), 'full', ClientIp::forms('203.0.113.9')['prefix'], 'TestBrowser/1');
$ed = new TestBrowser($V['app'], $V['config'], '203.0.113.9');
$ed->jar[$vsvc->cookies->name('lk_sid')] = $edSess['id'];
$edCsrf = TestBrowser::csrf($ed->get('/admin'));
check('Rechte: Redaktion sieht die Seite nicht (403)', $ed->get('/admin/varianten')->status === 403);
check('Rechte: Redaktion kann keinen Schritt auslösen (403, nichts geändert)', $ed->post('/admin/varianten/schritt', ['_csrf' => $edCsrf, 'neu' => '1', 'erzwingen' => '1'])->status === 403 && $ed->post('/admin/varianten/weiter', ['_csrf' => $edCsrf, 'neu' => '1'])->status === 403 && $vdb->value("SELECT v FROM meta WHERE k = 'variants_cursor'") === null);
check('Rechte: Redaktion sieht das Warnband nicht', !str_contains($ed->get('/admin')->body, 'Bildvarianten (/admin/varianten)'));
$vdb->run('DELETE FROM sessions WHERE user_id = (SELECT id FROM users WHERE username = \'redaktion\')');
$vdb->run("DELETE FROM users WHERE username = 'redaktion'");
$clearMedia();
$auditN = (int) $vdb->value('SELECT COUNT(*) FROM audit_log');
check('CSRF: POST schritt ohne Token → 403, nichts geändert', $vb->post('/admin/varianten/schritt', ['neu' => '1'])->status === 403 && $vb->post('/admin/varianten/weiter', [])->status === 403 && $vb->post('/admin/varianten/schritt', ['_csrf' => 'falsch'])->status === 403 && $mk()->status()['ok'] === 0 && $vdb->value("SELECT v FROM meta WHERE k = 'variants_cursor'") === null && (int) $vdb->value('SELECT COUNT(*) FROM audit_log') === $auditN);
check('CSRF: GET löst nichts aus', $vb->get('/admin/varianten/schritt')->status === 404 || $vb->get('/admin/varianten/schritt')->status === 405);

// ---------------------------------------------------------------- Ablauf über HTTP (wie das Skript auf der Seite) und Protokoll
$vcsrf = TestBrowser::csrf($vb->get('/admin/varianten'));
$H = ['HTTP_X_CSRF_TOKEN' => $vcsrf];
$r = $vb->request('POST', '/admin/varianten/schritt?neu=1', [], $H);
$j = (array) json_decode($r->body, true);
check('HTTP: Schritt antwortet mit JSON (nur Zahlen), Fortschritt und Ende', $r->status === 200 && str_contains($r->headers['Content-Type'] ?? '', 'json') && ($j['done'] ?? null) === true && ($j['created'] ?? 0) === 5 && ($j['total'] ?? 0) === 5 && ($j['position'] ?? 0) === 5 && !isset($j['run']));
$page = $vb->get('/admin/varianten');
check('HTTP: Seite zeigt danach „5 von 5 Fotos haben Bildvarianten“ und den Hinweis zum Veröffentlichen', str_contains($page->body, '5 von 5 Fotos haben Bildvarianten') && str_contains($page->body, '/admin/veroeffentlichen') && !str_contains($vb->get('/admin')->body, 'haben keine Bildvarianten'));
$start = (string) $vdb->value("SELECT detail FROM audit_log WHERE event = 'variants_start' ORDER BY id DESC LIMIT 1");
$end = (string) $vdb->value("SELECT detail FROM audit_log WHERE event = 'variants_done' ORDER BY id DESC LIMIT 1");
check('Protokoll: Beginn und Ende mit Zahlen, ohne Namen oder Pfade', $start === 'force=0,photos=5' && $end === 'force=0,photos=5,created=5,skipped=0,failed=0,noorig=0' && (int) $vdb->value("SELECT COUNT(*) FROM audit_log WHERE event LIKE 'variants_%' AND (detail LIKE '%.jpg%' OR detail LIKE '%/%')") === 0);
// ohne Skript: ein Klick = ein Stück, erzwingen mit CSRF aus dem Formular
$nr = $vb->post('/admin/varianten/weiter', ['_csrf' => $vcsrf, 'neu' => '1', 'erzwingen' => '1']);
check('Ohne Skript: „Weiter“ liefert die Seite mit dem Ergebnis des Durchgangs', $nr->status === 200 && str_contains($nr->body, 'Dieser Durchgang: 5 Foto(s) neu erzeugt') && str_contains($nr->body, 'Fertig.'));
check('Protokoll: Erzwingen als force=1 festgehalten', (string) $vdb->value("SELECT detail FROM audit_log WHERE event = 'variants_start' ORDER BY id DESC LIMIT 1") === 'force=1,photos=5' && $vsvc->audit->verify()['ok']);
check('Skript: Datei public/assets/js/varianten.js ist da und wird von der Seite geladen', is_file(__DIR__ . '/../public/assets/js/varianten.js') && str_contains($page->body, '/assets/js/varianten.js'));

// ---------------------------------------------------------------- Warnband-Zwischenspeicher und Papierkorb
$cnt = $mk();
check('Warnband: Zahl aus dem Zwischenspeicher, nach einem Schritt neu berechnet', $cnt->missingCount() === ['missing' => 0, 'total' => 5]);
$vdb->run("UPDATE photos SET deleted_at = 1 WHERE id = ?", [$ids[0]]);
$clearMedia();
$cnt->invalidate();
check('Papierkorb: Fotos im Papierkorb zählen nicht mit', $cnt->missingCount() === ['missing' => 4, 'total' => 4]);
$vdb->run("UPDATE photos SET status = 'pending' WHERE id = ?", [$ids[1]]);
$cnt->invalidate();
check('Warnband: frisch hochgeladene (pending) Fotos zählen nicht als fehlend', $cnt->missingCount() === ['missing' => 3, 'total' => 4]);
$vdb->run('UPDATE photos SET deleted_at = NULL, status = ? WHERE id IN (?, ?)', ['ready', $ids[0], $ids[1]]);

// Rückweg: das Einspielen einer Sicherung verwirft den Stand der Bildvarianten (die Dateien gehören zum alten Server)
check('Wiederherstellung: Restore löscht variants_*-Merker der Sicherung (Quelltext)', str_contains((string) file_get_contents(__DIR__ . '/../app/src/Backup/Restore.php'), "DELETE FROM meta WHERE k LIKE 'variants"));
