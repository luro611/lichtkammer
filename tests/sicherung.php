<?php
declare(strict_types=1);

// Schritt 8b: Sicherung herunterladen und wieder einspielen (Entwurf 20), dazu Systemprüfung, neutrale Konfigurationsfehlerseite,
// Datenordner-Warnung und die kleinen Härtungen (COOP/CORP, Fehlerseiten, .user.ini, Docker).

use Lichtkammer\Admin\SystemCheck;
use Lichtkammer\App;
use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Backup\Backup;
use Lichtkammer\Backup\Restore;
use Lichtkammer\Backup\Tar;
use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Response;
use Lichtkammer\SecurityHeaders;

require_once __DIR__ . '/support.php';

$s = lk_new_tmp($tmp, 'sicherung');

/** Inhalt einer erzeugten Antwort (gestreamt) einsammeln. */
$collect = static function (Response $r): string {
    $buf = '';
    if ($r->emit !== null) {
        ($r->emit)(static function (string $c) use (&$buf): void {
            $buf .= $c;
        });
    } elseif ($r->stream !== null) {
        [$path, $from, $len] = $r->stream;
        $buf = (string) file_get_contents($path, false, null, $from, $len);
    } else {
        $buf = $r->body;
    }
    return $buf;
};
$untar = static function (string $bytes, string $dir): bool {
    @mkdir($dir, 0777, true);
    $f = $dir . '.tar';
    file_put_contents($f, $bytes);
    exec('tar -xf ' . escapeshellarg($f) . ' -C ' . escapeshellarg($dir) . ' 2>&1', $out, $rc);
    return $rc === 0;
};

// ---------------------------------------------------------------- Tar-Schreiber
$long = str_repeat('verzeichnis/', 10) . 'datei-mit-langem-namen.txt'; // über 100 Zeichen
$tarBytes = Tar::header($long, 5, TEST_START) . 'hallo' . Tar::padding(5) . Tar::header('kurz.txt', 0, TEST_START) . Tar::padding(0) . Tar::end();
check('Tar: berechnete Größe stimmt', strlen($tarBytes) === Tar::entrySize(5) + Tar::entrySize(0) + Tar::endSize());
check('Tar: langer Name wird mit tar entpackt', $untar($tarBytes, $s . '/tar1') && @file_get_contents($s . '/tar1/' . $long) === 'hallo' && is_file($s . '/tar1/kurz.txt'));
foreach (['../x', '/abs', "a\0b", ''] as $bad) {
    $thrown = false;
    try {
        Tar::header($bad, 1, 0);
    } catch (\InvalidArgumentException) {
        $thrown = true;
    }
    check('Tar: ungültiger Name wird abgelehnt (' . json_encode($bad) . ')', $thrown);
}

// ---------------------------------------------------------------- Quelle: Konto, zwei Fotos, private Datei
$A = lk_make($s . '/a', ['LK_ORIGINALS_DIR' => $s . '/a-orig', 'LK_DATA_DIR' => $s . '/a-data']);
$sa = lk_setup($A);
$svc = $A['app']->services();
$db = $svc->db;
$up1 = lk_upload($sa['b'], 'rot-blau.jpg', lk_jpeg(640, 400));
$up2 = lk_upload($sa['b'], 'rot-blau-2.jpg', lk_jpeg(500, 500));
$id1 = (int) $up1['photo_id'];
$id2 = (int) $up2['photo_id'];
$rel1 = (string) $db->value('SELECT original_path FROM photos WHERE id = ?', [$id1]);
$bytes1 = (string) file_get_contents($s . '/a-orig/' . $rel1);
@mkdir($s . '/a-data/files/ab', 0700, true);
file_put_contents($s . '/a-data/files/ab/datei.pdf', '%PDF-privat');
check('Quelle: zwei Fotos mit Originalen', $id1 > 0 && $id2 > $id1 && is_file($s . '/a-orig/' . $rel1));

$b = new TestBrowser($A['app'], $A['config']);
$b->jar = []; // frisch anmelden
$b = lk_login($A, 'lutz', TEST_PASSWORD, $sa['secret']);
$csrf = static fn (): string => TestBrowser::csrf($b->get('/admin/sicherung'));

// Rechte: nicht angemeldet / Redaktion
$anon = new TestBrowser($A['app'], $A['config']);
foreach (['/admin/sicherung', '/admin/sicherung/originale', '/admin/sicherung/original/' . $id1, '/admin/system'] as $path) {
    check("Sicherung: $path ohne Anmeldung → Umleitung", $anon->get($path)->status === 303);
}
check('Sicherung: Paket ohne Anmeldung abgelehnt', $anon->post('/admin/sicherung/paket', [])->status === 303);
$db->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'redaktion', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ed' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$edSess = $svc->sessions->create((int) $db->value("SELECT id FROM users WHERE username = 'redaktion'"), 'full', ClientIp::forms('203.0.113.9')['prefix'], 'TestBrowser/1');
$ed = new TestBrowser($A['app'], $A['config'], '203.0.113.9');
$ed->jar[$svc->cookies->name('lk_sid')] = $edSess['id'];
foreach (['/admin/sicherung', '/admin/sicherung/originale', '/admin/sicherung/original/' . $id1, '/admin/system'] as $path) {
    check("Sicherung: $path für Redaktion → 403", $ed->get($path)->status === 403);
}
check('Sicherung: Paket für Redaktion → 403', $ed->post('/admin/sicherung/paket', ['_csrf' => TestBrowser::csrf($ed->get('/admin'))])->status === 403);

// Ohne Bestätigung nichts herunterladen
$page = $b->get('/admin/sicherung');
check('Sicherung: Seite für Administratoren, zeigt Zahlen', $page->status === 200 && str_contains($page->body, '2 Original(e)') && str_contains($page->body, 'Bestätigen'));
check('Sicherung: Seite zeigt noch keine Downloadknöpfe', !str_contains($page->body, 'name="_csrf" value="' . TestBrowser::csrf($page) . '"><button class="btn" type="submit">Paket'));
check('Sicherung: Paket ohne CSRF-Token → 403', $b->post('/admin/sicherung/paket', [])->status === 403);
$noconf = $b->post('/admin/sicherung/paket', ['_csrf' => $csrf()]);
check('Sicherung: Paket ohne Bestätigung → 403', $noconf->status === 403 && $noconf->emit === null && str_contains($noconf->body, 'zuerst'));
check('Sicherung: Originale ohne Bestätigung → 403', $b->get('/admin/sicherung/originale')->status === 403 && $b->get('/admin/sicherung/original/' . $id1)->status === 403);
check('Sicherung: nichts im Protokoll ohne Bestätigung', (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event IN ('backup_package','backup_originals','backup_confirmed')") === 0);

// Bestätigung: falsches Passwort / falscher Code / richtig
$A['clock']->advance(31);
$wrong = $b->post('/admin/sicherung/bestaetigen', ['_csrf' => $csrf(), 'current_password' => 'falsch-falsch-falsch', 'code' => lk_totp($sa['secret'], $A['clock'])]);
check('Bestätigung: falsches Passwort abgelehnt, kein Cookie', $wrong->status === 400 && $wrong->cookies === [] && $b->cookie('lk_bk') === '');
$wrong2 = $b->post('/admin/sicherung/bestaetigen', ['_csrf' => $csrf(), 'current_password' => TEST_PASSWORD, 'code' => '000000']);
check('Bestätigung: falscher Code abgelehnt', $wrong2->status === 400 && $b->cookie('lk_bk') === '');
$A['clock']->advance(31);
$okc = $b->post('/admin/sicherung/bestaetigen', ['_csrf' => $csrf(), 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sa['secret'], $A['clock'])]);
check('Bestätigung: Passwort und Code richtig → Cookie, Seite bestätigt', $okc->status === 303 && $b->cookie('lk_bk') !== '' && str_contains($b->get('/admin/sicherung')->body, 'Bestätigt'));
check('Bestätigung: Cookie ist HttpOnly, Strict, Secure und kurz', (function () use ($okc): bool {
    foreach ($okc->cookies as $c) {
        if (str_contains($c, 'lk_bk=')) {
            return str_contains($c, 'HttpOnly') && str_contains($c, 'SameSite=Strict') && str_contains($c, 'Secure') && str_contains($c, 'Max-Age=600');
        }
    }
    return false;
})());
check('Bestätigung: Protokoll nur mit Kennung', (string) $db->value("SELECT detail FROM audit_log WHERE event = 'backup_confirmed'") === '');

// Cookie an die Sitzung gebunden, manipuliert, abgelaufen
$cookieName = $svc->cookies->name('lk_bk');
$other = lk_login($A, 'lutz', TEST_PASSWORD, $sa['secret']);
$other->jar[$cookieName] = $b->jar[$cookieName];
check('Bestätigung: Cookie einer anderen Sitzung gilt nicht', $other->post('/admin/sicherung/paket', ['_csrf' => TestBrowser::csrf($other->get('/admin/sicherung'))])->status === 403);
$saved = $b->jar[$cookieName];
$b->jar[$cookieName] = substr($saved, 0, -1) . (substr($saved, -1) === 'a' ? 'b' : 'a');
check('Bestätigung: veränderter Cookie gilt nicht', $b->get('/admin/sicherung/originale')->status === 403);
$until = (int) explode('.', $saved)[0];
$b->jar[$cookieName] = ($until + 100) . '.' . explode('.', $saved)[1];
check('Bestätigung: Cookie mit verlängerter Zeit gilt nicht', $b->get('/admin/sicherung/originale')->status === 403);
$b->jar[$cookieName] = $saved;

// Paket
$pkgResp = $b->post('/admin/sicherung/paket', ['_csrf' => $csrf()]);
check('Paket: 200 mit Anhang, ohne Zwischenspeicher, nosniff', $pkgResp->status === 200 && str_contains($pkgResp->headers['Content-Disposition'], 'attachment') && str_contains($pkgResp->headers['Content-Disposition'], '.tar') && str_contains($pkgResp->headers['Cache-Control'], 'no-store') && $pkgResp->headers['X-Content-Type-Options'] === 'nosniff');
check('Paket: Merker „zuletzt“ erst nach vollständigem Senden (Prüfung 34, N1)', $db->value("SELECT v FROM meta WHERE k = 'last_backup_at'") === null);
$pkg = $collect($pkgResp);
check('Paket: Content-Length stimmt mit dem Strom überein', (int) $pkgResp->headers['Content-Length'] === strlen($pkg));
check('Paket: Datenbankkopie im Datenordner nach dem Senden gelöscht', glob($s . '/a-data/sicherung-*') === []);
check('Paket: lässt sich entpacken', $untar($pkg, $s . '/bundle'));
$man = json_decode((string) @file_get_contents($s . '/bundle/MANIFEST.json'), true);
check('Paket: MANIFEST.json im Format von Restore', ($man['format'] ?? null) === 1 && ($man['schema_version'] ?? null) === count(\Lichtkammer\Auth\Schema::migrations()) && ($man['app_version'] ?? '') === Config::VERSION && ($man['created_at'] ?? 0) > 0);
$chk = new \PDO('sqlite:' . $s . '/bundle/lichtkammer.sqlite');
check('Paket: Datenbank ist gültiges SQLite mit Konten und beiden Fotos', $chk->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && (int) $chk->query('SELECT COUNT(*) FROM users')->fetchColumn() === 2 && (int) $chk->query('SELECT COUNT(*) FROM photos')->fetchColumn() === 2);
$chk = null;
$list = (string) file_get_contents($s . '/bundle/originals.sha256');
check('Paket: Prüfsummenliste enthält beide Originale im Format von Restore', str_contains($list, hash('sha256', $bytes1) . '  ' . $rel1 . "\n") && substr_count($list, "\n") === 2);
check('Paket: private Dateien unter files/', @file_get_contents($s . '/bundle/files/ab/datei.pdf') === '%PDF-privat');
check('Paket: keine Originale im Paket, keine .env, kein Schlüssel', !is_dir($s . '/bundle/originals') && !str_contains($pkg, lk_key()) && !is_file($s . '/bundle/.env'));
check('Paket: Protokoll nur mit Zahlen, Merker „zuletzt“ gesetzt', preg_match('/^bytes=\d+,files=1,orig=2$/', (string) $db->value("SELECT detail FROM audit_log WHERE event = 'backup_package'")) === 1 && (int) $db->value("SELECT v FROM meta WHERE k = 'last_backup_at'") > 0);
check('Paket: Seite zeigt „Zuletzt heruntergeladen“', str_contains($b->get('/admin/sicherung')->body, 'Zuletzt heruntergeladen'));

// Originale in Teilen
$partResp = $b->get('/admin/sicherung/originale');
$partBytes = $collect($partResp);
check('Originale: ein Teil mit beiden Fotos, Länge stimmt', $partResp->status === 200 && (int) $partResp->headers['Content-Length'] === strlen($partBytes) && $untar($partBytes, $s . '/bundle'));
check('Originale: entpackt an den Pfaden der Datenbank, Inhalt gleich', @file_get_contents($s . '/bundle/originals/' . $rel1) === $bytes1);
$relBoth = array_map(static fn (array $r): string => $r['rel'], (new Backup($A['config'], $db, $A['clock']))->originals()['rows']);
check('Originale: alle Prüfsummen der Liste passen zu den entpackten Dateien', (function () use ($s, $list): bool {
    foreach (explode("\n", trim($list)) as $line) {
        [$sha, $rel] = explode('  ', $line, 2);
        if (hash_file('sha256', $s . '/bundle/originals/' . $rel) !== $sha) {
            return false;
        }
    }
    return true;
})() && count($relBoth) === 2);
$part2 = $b->get('/admin/sicherung/originale?nach=' . $id1);
$p2 = $collect($part2);
check('Originale: „nach Foto-Nummer“ setzt fort (nur das zweite Foto)', $part2->status === 200 && $untar($p2, $s . '/part2') && count(glob($s . '/part2/originals/*/*')) === 1);
check('Originale: nach dem letzten Foto nichts mehr (404)', $b->get('/admin/sicherung/originale?nach=' . $id2)->status === 404);
foreach (['abc', '-1', '1; rm', '../1', str_repeat('9', 12)] as $bad) {
    check('Originale: ungültiges „nach“ (' . $bad . ') → 404', $b->get('/admin/sicherung/originale?nach=' . rawurlencode($bad))->status === 404);
}
check('Originale: Protokoll mit Zahlen', (string) $db->value("SELECT detail FROM audit_log WHERE event = 'backup_originals' ORDER BY id LIMIT 1") === 'after=0,n=2,bytes=' . (strlen($bytes1) + filesize($s . '/a-orig/' . (string) $db->value('SELECT original_path FROM photos WHERE id = ?', [$id2]))));

// Teile planen (Teilgröße mindestens 10 MB: mit künstlichen Größen)
$bk = new Backup($A['config'], $db, $A['clock']);
$mk = static fn (int $id, int $mb): array => ['id' => $id, 'rel' => 'aa/x', 'sha' => str_repeat('a', 64), 'abs' => '/x', 'size' => $mb * 1048576];
$bk10 = new Backup(lk_config($s . '/p10', ['LK_BACKUP_PART_MB' => '10']), $db, $A['clock']);
$parts = $bk10->parts([$mk(1, 6), $mk(2, 6), $mk(3, 6), $mk(4, 30), $mk(5, 1)]);
check('Teile: je 10 MB, ein großes Foto bekommt einen eigenen Teil', count($parts) === 5 && $parts[0] === ['after' => 0, 'count' => 1, 'bytes' => 6291456] && $parts[3] === ['after' => 3, 'count' => 1, 'bytes' => 31457280] && $parts[4]['after'] === 4);
check('Teile: kleine Fotos passen zusammen in einen Teil', $bk10->parts([$mk(1, 3), $mk(2, 3), $mk(3, 3)]) === [['after' => 0, 'count' => 3, 'bytes' => 9437184]]);
check('Teile: leer ohne Originale', $bk10->parts([]) === []);

// Einzelabruf mit Range
$one = $b->get('/admin/sicherung/original/' . $id1);
check('Einzelabruf: Datei, Länge, Range erlaubt, Anhang', $one->status === 200 && $one->stream !== null && (int) $one->headers['Content-Length'] === strlen($bytes1) && $one->headers['Accept-Ranges'] === 'bytes' && str_contains($one->headers['Content-Disposition'], 'attachment') && $collect($one) === $bytes1);
$rg = $b->request('GET', '/admin/sicherung/original/' . $id1, [], ['HTTP_RANGE' => 'bytes=10-19']);
check('Einzelabruf: Range liefert 206 mit Content-Range und genau diese Bytes', $rg->status === 206 && $rg->headers['Content-Range'] === 'bytes 10-19/' . strlen($bytes1) && $collect($rg) === substr($bytes1, 10, 10));
$rs = $b->request('GET', '/admin/sicherung/original/' . $id1, [], ['HTTP_RANGE' => 'bytes=-5']);
check('Einzelabruf: Range „letzte 5“', $rs->status === 206 && $collect($rs) === substr($bytes1, -5));
check('Einzelabruf: Range hinter dem Ende → 416', $b->request('GET', '/admin/sicherung/original/' . $id1, [], ['HTTP_RANGE' => 'bytes=' . strlen($bytes1) . '-'])->status === 416);
check('Einzelabruf: Range mit Unsinn → 416', $b->request('GET', '/admin/sicherung/original/' . $id1, [], ['HTTP_RANGE' => 'bytes=a-b'])->status === 416);
foreach (['abc', '0', '99999', '1%2F..%2F..%2Fetc', '..'] as $bad) {
    check('Einzelabruf: ungültige oder unbekannte Nummer (' . $bad . ') → 404', $b->get('/admin/sicherung/original/' . $bad)->status === 404);
}
// Pfad aus der Datenbank wird geprüft: ein manipulierter Eintrag liefert nichts
$db->run('UPDATE photos SET original_path = ? WHERE id = ?', ['../../etc/passwd', $id2]);
check('Einzelabruf: Pfad außerhalb des Musters wird nie gelesen', $b->get('/admin/sicherung/original/' . $id2)->status === 404);
$db->run("UPDATE photos SET original_path = 'ab/' || '0123456789abcdef01234567' || '.jpg' WHERE id = ?", [$id2]);
@mkdir($s . '/a-orig/ab', 0700, true);
symlink('/etc/hostname', $s . '/a-orig/ab/0123456789abcdef01234567.jpg');
check('Einzelabruf: Symlink als Original wird nicht ausgeliefert', $b->get('/admin/sicherung/original/' . $id2)->status === 404);
$sum = (new Backup($A['config'], $db, $A['clock']))->summary();
check('Übersicht: Foto mit fehlender Datei wird gezählt, nicht gesichert', $sum['originals'] === 1 && $sum['absent'] === 1);
check('Seite: nennt fehlende Originale', str_contains($b->get('/admin/sicherung')->body, 'als Datei fehlt'));
@unlink($s . '/a-orig/ab/0123456789abcdef01234567.jpg');

// ---------------------------------------------------------------- Rückweg: Sicherung auf frischer Installation einspielen
// Zweites Foto wieder mit echtem Original ausstatten (Datenbankzeile zurückbauen), dann neu sichern.
$rel2 = 'ff/' . bin2hex(random_bytes(12)) . '.jpg';
$bytes2 = lk_jpeg(500, 500);
@mkdir($s . '/a-orig/ff', 0700, true);
file_put_contents($s . '/a-orig/' . $rel2, $bytes2);
$db->run('UPDATE photos SET original_path = ?, sha256 = ?, bytes = ? WHERE id = ?', [$rel2, hash('sha256', $bytes2), strlen($bytes2), $id2]);
$A['clock']->advance(31);
$okc = $b->post('/admin/sicherung/bestaetigen', ['_csrf' => $csrf(), 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sa['secret'], $A['clock'])]);
check('Rückweg: erneut bestätigt', $okc->status === 303 && str_contains($b->get('/admin/sicherung')->body, 'Bestätigt'));
// Das Testkonto „redaktion“ trägt eine kopierte Verschlüsselung (andere Kontonummer): für den Rückweg wieder entfernen.
$db->run('DELETE FROM sessions WHERE user_id = (SELECT id FROM users WHERE username = \'redaktion\')');
$db->run("DELETE FROM users WHERE username = 'redaktion'");
$db->run("INSERT INTO meta(k, v) VALUES('variants_missing', '0:2:1'), ('variants_cursor', '1') ON CONFLICT(k) DO NOTHING"); // Stand der Bildvarianten vom alten Server
$pkg2 = $collect($b->post('/admin/sicherung/paket', ['_csrf' => $csrf()]));
$partAll = $collect($b->get('/admin/sicherung/originale'));

$web = $s . '/ziel';
$T = lk_make($web, ['LK_ORIGINALS_DIR' => $s . '/ziel-orig', 'LK_DATA_DIR' => $s . '/ziel-data', 'LK_RESTORE_DIR' => $web . '/public/restore']);
@mkdir($T['config']->restoreDir(), 0777, true);
check('Rückweg: Paket und Originale in den Bereitstellungsordner entpackt', $untar($pkg2, $T['config']->restoreDir()) && $untar($partAll, $T['config']->restoreDir()));
$ri = (new Restore($T['config']))->inspect();
check('Rückweg: Restore erkennt die Sicherung (2 Originale in der Liste)', $ri['state'] === 'ok' && $ri['originals'] === 2 && ($ri['manifest']['format'] ?? null) === 1);
$tb = new TestBrowser($T['app'], $T['config']);
$rp = $tb->get('/admin/setup/wiederherstellen');
$res = $tb->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($rp), 'token' => TEST_SETUP_TOKEN]);
check('Rückweg: Einspielen mit Einrichtungs-Token klappt (1 Konto, 2 Fotos)', $res->status === 200 && str_contains($res->body, '1 Konten, 2 Fotos'));
$T2 = ['app' => new App($T['config'], $T['clock'], TEST_ARGON), 'config' => $T['config'], 'clock' => $T['clock']];
$T['clock']->advance($A['clock']->now() - $T['clock']->now()); // gleiche Uhrzeit wie die Quelle (Schutz vor Code-Wiederverwendung gilt auch nach dem Einspielen)
$li = lk_login($T2, 'lutz', TEST_PASSWORD, $sa['secret']);
check('Rückweg: Anmeldung mit altem Konto, Passwort und zweitem Faktor', lk_in($li));
$st = $li->post('/admin/wiederherstellung/weiter', ['_csrf' => TestBrowser::csrf($li->get('/admin/wiederherstellung'))]);
check('Rückweg: beide Originale zurückgeholt, Prüfsummen stimmen', $st->status === 200 && str_contains($st->body, '2 verschoben') && file_get_contents($s . '/ziel-orig/' . $rel1) === $bytes1 && file_get_contents($s . '/ziel-orig/' . $rel2) === $bytes2);
check('Rückweg: Zu allen Fotos gibt es ein Original', str_contains($st->body, 'Zu allen Fotos in der Datenbank gibt es ein Original'));
check('Rückweg: private Datei ist da', @file_get_contents($s . '/ziel-data/files/ab/datei.pdf') === '%PDF-privat');
$tdb = $T2['app']->services()->db;
check('Rückweg: Stand der Bildvarianten vom alten Server ist verworfen (Durchgang und Zwischenspeicher)', $tdb->value("SELECT 1 FROM meta WHERE k = 'variants_cursor'") === null && $tdb->value("SELECT v FROM meta WHERE k = 'variants_missing'") !== '0:2:1'); // (der Zwischenspeicher wird beim Anmelden neu angelegt)
check('Rückweg: Fotozahl, Konten und Protokoll wie in der Quelle (plus Eintrag)', (int) $tdb->value('SELECT COUNT(*) FROM photos') === 2 && (int) $tdb->value('SELECT COUNT(*) FROM users WHERE username = \'lutz\'') === 1 && (int) $tdb->value("SELECT COUNT(*) FROM audit_log WHERE event = 'backup_restored'") === 1);
// Falscher Schlüssel: Einspielen scheitert, nichts verändert
$W = lk_make($s . '/falsch', ['LK_ORIGINALS_DIR' => $s . '/falsch-orig', 'LK_DATA_DIR' => $s . '/falsch-data', 'LK_RESTORE_DIR' => $s . '/falsch/public/restore', 'LK_APP_KEY' => lk_key('anderer-schluessel')]);
@mkdir($W['config']->restoreDir(), 0777, true);
$untar($pkg2, $W['config']->restoreDir());
$wb = new TestBrowser($W['app'], $W['config']);
$wr = $wb->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($wb->get('/admin/setup/wiederherstellen')), 'token' => TEST_SETUP_TOKEN]);
check('Rückweg: mit falschem Schlüssel abgelehnt, nichts eingespielt', $wr->status === 400 && str_contains($wr->body, 'LK_APP_KEY passt nicht') && (int) $W['app']->services()->db->value('SELECT COUNT(*) FROM users') === 0);

// Ratenbegrenzung (zuletzt, verbraucht den Zähler): höchstens 6 Pakete je Fenster
$codes = [];
for ($i = 0; $i < 8; $i++) {
    $codes[] = $b->post('/admin/sicherung/paket', ['_csrf' => $csrf()])->status;
}
check('Ratenbegrenzung: nach wenigen Paketen 429 mit Wartehinweis', in_array(429, $codes, true) && $codes[0] === 200);
$limited = $b->post('/admin/sicherung/paket', ['_csrf' => $csrf()]);
check('Ratenbegrenzung: Antwort nennt Retry-After, liefert nichts', $limited->status === 429 && isset($limited->headers['Retry-After']) && $limited->emit === null);
check('Protokoll: Kette gültig, keine Namen oder Schlüssel im Protokoll', $svc->audit->verify()['ok'] && (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%rot-blau%' OR detail LIKE '%.jpg%' OR detail LIKE '%" . substr(lk_key(), 0, 12) . "%'") === 0);
// Aufräumen liegengebliebener Kopien
file_put_contents($s . '/a-data/sicherung-alt.tmp', 'x');
touch($s . '/a-data/sicherung-alt.tmp', time() - 7200);
$bk->package()['emit'](static function (string $c): void {
});
check('Aufräumen: alte, liegengebliebene Datenbankkopie wird entfernt', !is_file($s . '/a-data/sicherung-alt.tmp'));

// ---------------------------------------------------------------- Systemprüfung
$bs = lk_login($A, 'lutz', TEST_PASSWORD, $sa['secret']);
$sys = $bs->get('/admin/system');
check('Systemprüfung: Seite für Administratoren', $sys->status === 200 && str_contains($sys->body, 'Systemprüfung') && str_contains($sys->body, 'PHP-Version') && str_contains($sys->body, 'Datenbank'));
check('Systemprüfung: keine Schlüssel, Token oder Pfade', !str_contains($sys->body, lk_key()) && !str_contains($sys->body, TEST_SETUP_TOKEN) && !str_contains($sys->body, $s));
check('Systemprüfung: Einrichtungs-Token gesetzt wird als „Bitte ansehen“ gemeldet', (bool) preg_match('~Einrichtungs-Token</td>\s*<td><span class="badge cover">Bitte ansehen~', $sys->body));
$items = SystemCheck::run($A['config'], $db, $A['clock'], $svc->dataDir);
$byTitle = array_column($items, null, 'title');
check('Systemprüfung: Daten- und Originalordner außerhalb des Webroots in Ordnung', $byTitle['Datenordner']['level'] === 'ok' && $byTitle['Originale']['level'] === 'ok');
check('Systemprüfung: Datenbank bestanden, Version genannt', $byTitle['Datenbank']['level'] === 'ok' && $byTitle['Version']['text'] === Config::VERSION);
check('Systemprüfung: letzte Sicherung ist frisch', $byTitle['Letzte Sicherung']['level'] === 'ok');
$noSecret = SystemCheck::run(lk_config($s . '/sys2', ['LK_SETUP_TOKEN' => '']), $db, $A['clock'], $svc->dataDir);
check('Systemprüfung: ohne Token keine Warnung dazu', array_column($noSecret, null, 'title')['Einrichtungs-Token']['level'] === 'ok');
$webCfg = Config::load($s . '/w/app', $s . '/w', ['LK_APP_KEY' => lk_key(), 'LK_BASE_URL' => 'http://x.test']);
@mkdir($s . '/w/app', 0777, true);
$webItems = array_column(SystemCheck::run($webCfg, $db, $A['clock'], null), null, 'title');
check('Systemprüfung: Originale im Webroot → „Muss behoben werden“', $webItems['Originale']['level'] === 'bad');
check('Systemprüfung: http-Adresse wird angemerkt', $webItems['Adresse der Seite']['level'] === 'warn');
check('Systemprüfung: .user.ini wird gemessen (Marker aus der Datei)', preg_match('/highlight\.string\s*=\s*"' . preg_quote(SystemCheck::USER_INI_MARK, '/') . '"/', (string) file_get_contents(__DIR__ . '/../public/.user.ini')) === 1);
check('Systemprüfung: ohne Marker steht „wird nicht gelesen“', str_contains($byTitle['.user.ini']['text'], 'nicht gelesen'));
check('Systemprüfung: Größenangaben leserlich', SystemCheck::human(0) === '0 Byte' && SystemCheck::human(1536) === '1,5 KB' && SystemCheck::human(5 * 1048576) === '5,0 MB' && SystemCheck::human(3 * 1073741824) === '3,0 GB');

// ---------------------------------------------------------------- Datenordner-Warnung (Platz)
check('Platz: ohne Engpass kein Warnband', SystemCheck::diskWarning($A['config'], $db, $A['clock']) === null && !str_contains($bs->get('/admin')->body, 'Laufwerk sind nur noch'));
$tight = lk_make($s . '/eng', ['LK_ORIGINALS_DIR' => $s . '/eng-orig', 'LK_DATA_DIR' => $s . '/eng-data', 'LK_DISK_MIN_FREE_MB' => '999999999']);
$se = lk_setup($tight);
$wb2 = $se['b']->get('/admin');
check('Platz: zu wenig freier Platz → Warnband mit Hinweis auf die Systemprüfung', str_contains($wb2->body, 'Laufwerk sind nur noch') && str_contains($wb2->body, 'Systemprüfung'));
$used = SystemCheck::usedBytes($A['config'], $db, $A['clock'], true);
check('Platz: belegte Größe wird gezählt und zwischengespeichert', $used > strlen($bytes1) && (int) $db->value("SELECT v FROM meta WHERE k = 'disk_used_bytes'") === $used);
file_put_contents($s . '/a-data/extra.bin', str_repeat('x', 4096));
check('Platz: zweiter Aufruf am selben Tag nutzt den Zwischenspeicher', SystemCheck::usedBytes($A['config'], $db, $A['clock']) === $used);
$gb = lk_config($s . '/gb', ['LK_DISK_WARN_GB' => '1']);
check('Platz: Warnschwelle in GB wird nicht überschritten', SystemCheck::diskWarning($gb, $db, $A['clock']) === null);

// ---------------------------------------------------------------- Neutrale Konfigurationsfehlerseite (Prüfung 08, C6)
$cfgGrund = static function (array $env) use ($s): string {
    $d = $s . '/cfg-' . bin2hex(random_bytes(3));
    $c = lk_config($d, $env);
    $app = new App($c, new \Lichtkammer\Auth\Clock(TEST_START), TEST_ARGON);
    $r = $app->handle(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/login', 'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9'], $c));
    return $r->status . '|' . $r->body;
};
$prodNoToken = $cfgGrund(['LK_SETUP_TOKEN' => '', 'LK_BASE_URL' => '']);
check('Konfigurationsfehler: Produktion ohne Token → neutrale Seite, Grund nicht sichtbar', str_starts_with($prodNoToken, '503|') && str_contains($prodNoToken, 'nicht verfügbar') && !str_contains($prodNoToken, 'LK_BASE_URL') && str_contains($prodNoToken, 'Fehlerprotokoll'));
$prodToken = $cfgGrund(['LK_BASE_URL' => '']);
check('Konfigurationsfehler: mit Einrichtungs-Token wird der Grund genannt (Einrichtung ohne SSH)', str_starts_with($prodToken, '503|') && str_contains($prodToken, 'LK_BASE_URL'));
$dev = $cfgGrund(['LK_SETUP_TOKEN' => '', 'LK_BASE_URL' => '', 'LK_ENV' => 'development']);
check('Konfigurationsfehler: Entwicklung nennt den Grund', str_contains($dev, 'LK_BASE_URL'));
$noKey = $cfgGrund(['LK_APP_KEY' => '', 'LK_SETUP_TOKEN' => '']);
check('Konfigurationsfehler: frische Installation ohne Schlüssel zeigt die Einrichtungshilfe weiter', str_contains($noKey, 'LK_APP_KEY') && str_contains($noKey, 'Zwei Zeilen'));
check('Konfigurationsfehler: nie Schlüsselwerte', !str_contains($prodToken, lk_key()) && !str_contains($prodNoToken, lk_key()));

// ---------------------------------------------------------------- Kleine Härtungen
$cfgH = lk_config($s . '/hdr', []);
$hh = SecurityHeaders::for(new Request(['HTTPS' => 'on'], $cfgH), $cfgH);
check('Köpfe: COOP und CORP gesetzt', ($hh['Cross-Origin-Opener-Policy'] ?? '') === 'same-origin' && ($hh['Cross-Origin-Resource-Policy'] ?? '') === 'same-origin');
$adminLogin = $anon->get('/admin/login');
$full = $adminLogin->withDefaults(SecurityHeaders::for(new Request(['HTTPS' => 'on'], $A['config']), $A['config']));
check('Köpfe: Anmeldeseite trägt COOP, CORP und no-store', ($full->headers['Cross-Origin-Opener-Policy'] ?? '') === 'same-origin' && str_contains($full->headers['Cache-Control'] ?? '', 'no-store'));
$mh = (string) file_get_contents(__DIR__ . '/../public/media/.htaccess');
check('Medien: Cross-Origin-Resource-Policy same-site für Dateien von Apache', str_contains($mh, 'Cross-Origin-Resource-Policy "same-site"'));
$ht = (string) file_get_contents(__DIR__ . '/../public/.htaccess');
check('.htaccess: ErrorDocument für 403 und 500 auf die eigene Fehlerseite', str_contains($ht, 'ErrorDocument 403 /fehler.html') && str_contains($ht, 'ErrorDocument 500 /fehler.html'));
$fe = (string) file_get_contents(__DIR__ . '/../public/fehler.html');
check('Fehlerseite: statisch, deutsch, ohne Pfade, Skripte und Serverkennung, mit luro-Wortmarke', str_contains($fe, 'lang="de"') && !str_contains($fe, '<script') && !str_contains($fe, 'Apache') && !str_contains($fe, '/app/') && str_contains($fe, 'lu<em>ro</em>'));
$di = (string) file_get_contents(__DIR__ . '/../.dockerignore');
check('Docker: fehler.html bleibt im Image (Ausnahme zu public/*.html)', str_contains($di, '!public/fehler.html'));
check('Git: fehler.html ist eingecheckt (Ausnahme zu /public/*.html in .gitignore, Prüfung 34, B1)', str_contains((string) file_get_contents(__DIR__ . '/../.gitignore'), "\n!/public/fehler.html\n"));
$ui = (string) file_get_contents(__DIR__ . '/../public/.user.ini');
check('.user.ini: Fehler nie anzeigen, Fehlerprotokoll an, keine Geheimnisse', str_contains($ui, 'display_errors = Off') && str_contains($ui, 'log_errors = On') && !preg_match('/key|token|passw/i', preg_replace('/^;.*$/m', '', $ui)));
$cy = (string) file_get_contents(__DIR__ . '/../compose.yaml');
check('compose.yaml: keine neuen Rechte, Fähigkeiten eingeschränkt, Speichergrenze, Protokollgrenze', str_contains($cy, 'no-new-privileges:true') && str_contains($cy, 'cap_drop') && str_contains($cy, '- ALL') && str_contains($cy, 'NET_BIND_SERVICE') && str_contains($cy, 'mem_limit') && str_contains($cy, 'max-size: "10m"') && str_contains($cy, 'max-file: "5"'));
check('compose.yaml: Port weiter nur lokal, keine Geheimnisse', str_contains($cy, '127.0.0.1:8080:80') && !str_contains($cy, 'LK_APP_KEY='));
$no = (string) file_get_contents(__DIR__ . '/../NOTICE');
check('NOTICE: Lizenz, vier Schriften (OFL-1.1), eigene Passwortliste und QR-Code, Image-Bestandteile', str_contains($no, 'AGPL-3.0-or-later') && str_contains($no, 'OFL-1.1') && str_contains($no, 'Inter') && str_contains($no, 'Instrument Serif') && str_contains($no, 'Archivo') && str_contains($no, 'Geist Mono') && str_contains($no, 'common-passwords') && str_contains($no, 'Qr.php') && str_contains($no, 'ImageMagick') && !str_contains($no, '@'));
$rz = (string) file_get_contents(__DIR__ . '/../tools/release-zip.sh');
check('Zip: NOTICE, .user.ini und Fehlerseite gehören zum Paket', str_contains($rz, 'LICENSE NOTICE README.md') && str_contains($rz, 'public/.user.ini') && str_contains($rz, 'public/fehler.html'));
$lp = (string) file_get_contents(__DIR__ . '/../tools/livepruefung.sh');
check('Livepruefung: prüft COOP/CORP, Sicherungswege ohne Anmeldung, Fehlerseite', str_contains($lp, 'Cross-Origin-Opener-Policy') && str_contains($lp, '/admin/sicherung/paket') && str_contains($lp, '/fehler.html'));

// ---------------------------------------------------------------- Prüfung 35 (Fable), F1: gestohlene Sitzung darf kein Administrator-Konto erzeugen
$F = lk_make($s . '/f1', ['LK_ORIGINALS_DIR' => $s . '/f1-orig', 'LK_DATA_DIR' => $s . '/f1-data']);
$sf = lk_setup($F);
$svcF = $F['app']->services();
$lutzF = lk_login($F, 'lutz', TEST_PASSWORD, $sf['secret']);
$thief = new TestBrowser($F['app'], $F['config'], '198.51.100.9'); // hat nur das Sitzungs-Cookie, weder Passwort noch Code
$thief->jar[$svcF->cookies->name('lk_sid')] = $lutzF->cookie('lk_sid');
$tcs = TestBrowser::csrf($thief->get('/admin/users'));
$ti = $thief->post('/admin/users/invite', ['_csrf' => $tcs, 'role' => 'admin']);
check('F1: Administrator-Einladung ohne Passwort und Code → Bestätigungsseite, kein Link, keine Einladung', $ti->status === 200 && str_contains($ti->body, 'name="current_password"') && preg_match('#/admin/invite/[0-9a-f]{64}#', $ti->body) !== 1 && (int) $svcF->db->value('SELECT COUNT(*) FROM invites') === 0);
$ti2 = $thief->post('/admin/users/invite', ['_csrf' => $tcs, 'role' => 'admin', 'current_password' => 'geraten-geraten-geraten', 'code' => '123456']);
check('F1: Administrator-Einladung mit falschem Passwort → abgelehnt', $ti2->status === 400 && (int) $svcF->db->value('SELECT COUNT(*) FROM invites') === 0);
$te = $thief->post('/admin/users/invite', ['_csrf' => $tcs, 'role' => 'editor']);
check('F1: Einladung für die Redaktion geht weiter ohne Bestätigung', $te->status === 200 && preg_match('#/admin/invite/[0-9a-f]{64}#', $te->body) === 1);
// Redaktionskonto und zweites Administrator-Konto (gesperrt) als Ziele
$svcF->db->run("INSERT INTO users(username, role, password_hash, totp_confirmed_at, totp_secret_enc, created_at, webauthn_handle) VALUES('redaktion','editor',?,1,'x',1,'h1')", [$svcF->passwords->hash(TEST_PASSWORD)]);
$svcF->db->run("INSERT INTO users(username, role, password_hash, totp_confirmed_at, totp_secret_enc, created_at, webauthn_handle, disabled_at) VALUES('zweit','admin',?,1,'x',1,'h2',1)", [$svcF->passwords->hash(TEST_PASSWORD)]);
$edId = (int) $svcF->db->value("SELECT id FROM users WHERE username = 'redaktion'");
$a2Id = (int) $svcF->db->value("SELECT id FROM users WHERE username = 'zweit'");
$tr = $thief->post('/admin/users/' . $edId . '/role', ['_csrf' => $tcs, 'role' => 'admin']);
check('F1: Redaktion zum Administrator machen ohne Bestätigung → nichts geändert', str_contains($tr->body, 'name="current_password"') && (string) $svcF->db->value('SELECT role FROM users WHERE id = ?', [$edId]) === 'editor');
$ten = $thief->post('/admin/users/' . $a2Id . '/enable', ['_csrf' => $tcs]);
check('F1: Administrator-Konto entsperren ohne Bestätigung → bleibt gesperrt', str_contains($ten->body, 'name="current_password"') && $svcF->db->value('SELECT disabled_at FROM users WHERE id = ?', [$a2Id]) !== null);
$trs = $thief->post('/admin/users/' . $a2Id . '/reset', ['_csrf' => $tcs]);
check('F1: Administrator-Konto zurücksetzen ohne Bestätigung → kein Link, zweiter Faktor bleibt', str_contains($trs->body, 'name="current_password"') && preg_match('#/admin/invite/[0-9a-f]{64}#', $trs->body) !== 1 && $svcF->db->value('SELECT totp_secret_enc FROM users WHERE id = ?', [$a2Id]) === 'x');
// Der echte Administrator mit Passwort und Code
$F['clock']->advance(31);
$okI = $lutzF->post('/admin/users/invite', ['_csrf' => TestBrowser::csrf($lutzF->get('/admin/users')), 'role' => 'admin', 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sf['secret'], $F['clock'])]);
check('F1: mit Passwort und Code entsteht die Administrator-Einladung', $okI->status === 200 && preg_match('#/admin/invite/[0-9a-f]{64}#', $okI->body) === 1 && (int) $svcF->db->value("SELECT COUNT(*) FROM invites WHERE role = 'admin'") === 1);
$replay = $lutzF->post('/admin/users/' . $edId . '/role', ['_csrf' => TestBrowser::csrf($lutzF->get('/admin/users')), 'role' => 'admin', 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sf['secret'], $F['clock'])]);
check('F1: derselbe Code gilt kein zweites Mal', $replay->status === 400 && (string) $svcF->db->value('SELECT role FROM users WHERE id = ?', [$edId]) === 'editor');
$F['clock']->advance(31);
$okR = $lutzF->post('/admin/users/' . $edId . '/role', ['_csrf' => TestBrowser::csrf($lutzF->get('/admin/users')), 'role' => 'admin', 'current_password' => TEST_PASSWORD, 'code' => lk_totp($sf['secret'], $F['clock'])]);
check('F1: Rolle Administrator mit Passwort und neuem Code', $okR->status === 200 && (string) $svcF->db->value('SELECT role FROM users WHERE id = ?', [$edId]) === 'admin');

// Prüfung 35, F2: Die Seite vor der Anmeldung nennt keinen absoluten Pfad des Servers
$G = lk_make($s . '/f2', ['LK_ORIGINALS_DIR' => $s . '/f2-orig', 'LK_DATA_DIR' => $s . '/f2-data']);
$gp = (new TestBrowser($G['app'], $G['config']))->get('/admin/setup/wiederherstellen');
check('F2: Wiederherstellung vor der Anmeldung zeigt keinen absoluten Pfad', $gp->status === 200 && !str_contains($gp->body, $s) && str_contains($gp->body, '&lt;Datenordner&gt;/restore'));
$G2 = lk_make($s . '/f2b', ['LK_ORIGINALS_DIR' => $s . '/f2b-orig', 'LK_DATA_DIR' => $s . '/f2b-data', 'LK_RESTORE_DIR' => $s . '/f2b/public/restore']);
$gp2 = (new TestBrowser($G2['app'], $G2['config']))->get('/admin/setup/wiederherstellen');
check('F2: Bereitstellungsordner im Webroot wird relativ genannt', $gp2->status === 200 && !str_contains($gp2->body, $s) && str_contains($gp2->body, '&lt;Webroot&gt;/restore'));
