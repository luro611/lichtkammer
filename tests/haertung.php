<?php
declare(strict_types=1);

// Härtung und Livegang (Entwurf 20, Schritt 8a): Originale außerhalb des Webroots (H1/E19).

use Lichtkammer\App;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\ConfigError;
use Lichtkammer\Auth\Preflight;
use Lichtkammer\Config;
use Lichtkammer\Media\OriginalsMover;
use Lichtkammer\Request;

require_once __DIR__ . '/support.php';

$h = $tmp . '/haertung';
mkdir($h, 0777, true);

/** all-inkl-Aufbau: app/ liegt im Webroot $web; Daten und Originale nach Angabe. */
$allInkl = static function (string $web, array $env) use ($h): Config {
    @mkdir($web . '/app', 0777, true);
    return Config::load($web . '/app', $web, $env + ['LK_APP_KEY' => lk_key(), 'LK_BASE_URL' => 'https://lk.test', 'LK_DATA_DIR' => $h . '/daten-' . md5($web)]);
};
$fehler = static function (Config $c): ?string {
    try {
        Preflight::check($c);
        return null;
    } catch (ConfigError $e) {
        return $e->getMessage();
    }
};
$putOrig = static function (string $dir, string $rel, string $inhalt): void {
    @mkdir(dirname($dir . '/' . $rel), 0700, true);
    file_put_contents($dir . '/' . $rel, $inhalt);
};

// --- Preflight: Originale im Webroot werden abgelehnt (wie der Datenordner) ---
$c1 = $allInkl($h . '/w1', []); // kein LK_ORIGINALS_DIR: Standard liegt im Webroot
$m1 = $fehler($c1);
check('Originale im Webroot (Standard auf all-inkl) werden abgelehnt', $m1 !== null && str_contains($m1, 'Originale'));
check('Meldung nennt LK_ORIGINALS_DIR, aber keinen Schlüssel', $m1 !== null && str_contains($m1, 'LK_ORIGINALS_DIR') && !str_contains($m1, lk_key()));
check('Originale außerhalb des Webroots: Start ok', $fehler($allInkl($h . '/w2', ['LK_ORIGINALS_DIR' => $h . '/orig-w2'])) === null);
check('Originale in noch nicht angelegtem Unterordner des Webroots werden abgelehnt', $fehler($allInkl($h . '/w3', ['LK_ORIGINALS_DIR' => $h . '/w3/neu/tief/originale'])) !== null);
check('Ausnahme LK_ALLOW_DATA_IN_WEBROOT erlaubt es, zeigt aber dauerhaft eine Warnung', ($cw = $allInkl($h . '/w4', ['LK_ALLOW_DATA_IN_WEBROOT' => '1'])) && $fehler($cw) === null
    && array_filter(Preflight::warnings($cw, null), static fn (string $w): bool => str_contains($w, 'Originale')) !== []);
$cDocker = Config::load($h . '/dk/app', $h . '/dk/public', ['LK_APP_KEY' => lk_key(), 'LK_BASE_URL' => 'https://lk.test', 'LK_DATA_DIR' => $h . '/dk-data']);
@mkdir($h . '/dk/app', 0777, true);
@mkdir($h . '/dk/public', 0777, true);
check('Entwicklungs-Aufbau (originals neben public/) bleibt erlaubt', $fehler($cDocker) === null);

// --- Umzug: ganzer Ordner in einem Schritt ---
$web = $h . '/m1';
$cm = $allInkl($web, ['LK_ORIGINALS_DIR' => $h . '/m1-orig']);
Preflight::prepareDataDir($cm);
$putOrig($web . '/originals', 'ab/abc.jpg', 'Bild-A');
$putOrig($web . '/originals', 'cd/cde.png', 'Bild-B');
file_put_contents($web . '/originals/.htaccess', "Require all denied\n");
$shaA = hash('sha256', 'Bild-A');
check('Umzug: wartende Dateien werden gezählt (ohne Sperrdateien)', OriginalsMover::pending($cm) === 2);
check('Umzug: Warnband meldet wartende Originale', array_filter(Preflight::warnings($cm, null), static fn (string $w): bool => str_contains($w, 'noch im alten Ordner')) !== []);
$r = OriginalsMover::run($cm);
check('Umzug: beide Dateien verschoben, nichts übrig, kein Konflikt', $r === ['moved' => 2, 'remaining' => 0, 'conflicts' => 0]);
check('Umzug: Inhalt unverändert (SHA-256)', hash_file('sha256', $h . '/m1-orig/ab/abc.jpg') === $shaA && file_get_contents($h . '/m1-orig/cd/cde.png') === 'Bild-B');
check('Umzug: alter Ordner im Webroot ist weg', !file_exists($web . '/originals'));
check('Umzug: Zielordner nicht für andere lesbar (0700)', (fileperms($h . '/m1-orig') & 0077) === 0);
check('Umzug: zweiter Lauf tut nichts', OriginalsMover::run($cm) === ['moved' => 0, 'remaining' => 0, 'conflicts' => 0] && OriginalsMover::pending($cm) === 0);

// --- Umzug: Ziel existiert schon (Datei für Datei, nie überschreiben) ---
$web = $h . '/m2';
$cm2 = $allInkl($web, ['LK_ORIGINALS_DIR' => $h . '/m2-orig']);
Preflight::prepareDataDir($cm2);
$putOrig($web . '/originals', 'aa/gleich.jpg', 'gleich');
$putOrig($web . '/originals', 'bb/anders.jpg', 'alt-im-webroot');
$putOrig($web . '/originals', 'cc/neu.jpg', 'nur-alt');
$putOrig($h . '/m2-orig', 'aa/gleich.jpg', 'gleich');
$putOrig($h . '/m2-orig', 'bb/anders.jpg', 'schon-am-ziel');
$r2 = OriginalsMover::run($cm2);
check('Zusammenführen: eine neue Datei verschoben, gleiche Quelle entfernt, eine abweichende als Konflikt gemeldet', $r2['moved'] === 2 && $r2['conflicts'] === 1 && $r2['remaining'] === 1);
check('Zusammenführen: abweichende Datei am Ziel wird NIE überschrieben', file_get_contents($h . '/m2-orig/bb/anders.jpg') === 'schon-am-ziel');
check('Zusammenführen: abweichende Quelldatei bleibt erhalten (kein Datenverlust)', file_get_contents($web . '/originals/bb/anders.jpg') === 'alt-im-webroot');
check('Zusammenführen: neue Datei angekommen', file_get_contents($h . '/m2-orig/cc/neu.jpg') === 'nur-alt');
check('Zusammenführen: alter Ordner bleibt, solange etwas darin liegt', is_dir($web . '/originals'));

// --- Umzug: Zeitgrenze, Symlinks ---
$web = $h . '/m3';
$cm3 = $allInkl($web, ['LK_ORIGINALS_DIR' => $h . '/m3-orig']);
Preflight::prepareDataDir($cm3);
@mkdir($h . '/m3-orig', 0700, true); // Zielordner existiert: Datei-für-Datei-Weg
for ($i = 0; $i < 5; $i++) {
    $putOrig($web . '/originals', sprintf('%02d/f%d.jpg', $i, $i), "inhalt$i");
}
file_put_contents($h . '/ausserhalb.txt', 'geheim');
@mkdir($web . '/originals/zz', 0700, true);
symlink($h . '/ausserhalb.txt', $web . '/originals/zz/link.jpg');
$rz = OriginalsMover::run($cm3, -1); // Budget abgelaufen: nichts, aber auch kein Fehler
check('Zeitgrenze: Lauf ohne Zeit verschiebt nichts und meldet den Rest', $rz['moved'] === 0 && $rz['remaining'] === 6);
$rz = OriginalsMover::run($cm3);
check('Symlink wird nicht angefasst und nicht verfolgt', $rz['moved'] === 5 && $rz['remaining'] === 1 && is_link($web . '/originals/zz/link.jpg') && !file_exists($h . '/m3-orig/zz/link.jpg') && file_get_contents($h . '/ausserhalb.txt') === 'geheim');

// --- Ende-zu-Ende: der erste Aufruf des Admins verschiebt, Daten bleiben lesbar ---
$web = $h . '/m4';
$cm4 = $allInkl($web, ['LK_ORIGINALS_DIR' => $h . '/m4-orig', 'LK_SETUP_TOKEN' => TEST_SETUP_TOKEN]);
symlink(realpath(__DIR__ . '/../app/templates'), $web . '/app/templates'); // all-inkl-Aufbau: Vorlagen und Ressourcen neben app/
symlink(realpath(__DIR__ . '/../app/resources'), $web . '/app/resources');
$putOrig($web . '/originals', 'ef/efg.jpg', 'Bild-E');
$app = new App($cm4, new Clock(TEST_START), TEST_ARGON);
$res = $app->handle(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/admin/login', 'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9'], $cm4));
check('Admin-Aufruf verschiebt die alten Originale', $res->status < 500 && is_file($h . '/m4-orig/ef/efg.jpg') && !file_exists($web . '/originals'));

// --- Es gibt keinen öffentlichen Weg zu Originalen ---
$putOrig($web . '/originals', 'ab/geheim.jpg', 'GPS-Daten');
foreach (['/originals/ab/geheim.jpg', '/originals/', '/../originals/ab/geheim.jpg', '/m4-orig/ef/efg.jpg'] as $pfad) {
    $rr = $app->handle(new Request(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $pfad, 'HTTPS' => 'on'], $cm4));
    check("Originale: $pfad wird von der App nicht ausgeliefert", $rr->status !== 200 && !str_contains($rr->body, 'GPS-Daten') && !str_contains($rr->body, 'Bild-E'));
}
$ht = (string) file_get_contents(__DIR__ . '/../public/.htaccess');
check('.htaccess sperrt weiterhin den alten Ordner originals/ im Webroot', preg_match('/RewriteRule \^\([^)]*\boriginals\b[^)]*\)\(\/\|\$\) - \[F,L\]/', $ht) === 1);

// ======================= H2: Sicherung einspielen (Entwurf 20, E12) =======================
use Lichtkammer\Backup\Restore;

// Quelle: eine Installation mit Konto, einem Foto und einer privaten Datei.
$A = lk_make($h . '/qA', ['LK_ORIGINALS_DIR' => $h . '/qA-orig', 'LK_DATA_DIR' => $h . '/qA-data']);
$sa = lk_setup($A);
$svcA = $A['app']->services();
$up = lk_upload($sa['b'], 'rot-blau.jpg', lk_jpeg(640, 400));
$photoRel = (string) $svcA->db->value('SELECT original_path FROM photos WHERE id = ?', [(int) $up['photo_id']]);
$origBytes = (string) file_get_contents($h . '/qA-orig/' . $photoRel);
$svcA->db->run("INSERT INTO invites(token_hash, purpose, role, expires_at, created_at) VALUES('x1','new_account','editor', ?, ?)", [TEST_START + 99999, TEST_START]);
check('Quelle: Konto, Sitzung, Gerät und offene Einladung vorhanden', (int) $svcA->db->value('SELECT COUNT(*) FROM sessions') > 0 && (int) $svcA->db->value('SELECT COUNT(*) FROM invites WHERE used_at IS NULL') === 1);

/** Sicherung im Bereitstellungsordner anlegen (so wie Schritt 8b sie liefert). */
$package = static function (string $dir, ?int $schema = null) use ($svcA, $photoRel, $origBytes): void {
    @mkdir($dir . '/originals/' . dirname($photoRel), 0777, true);
    @mkdir($dir . '/files/ab', 0777, true);
    $svcA->db->pdo->exec('VACUUM INTO ' . $svcA->db->pdo->quote($dir . '/lichtkammer.sqlite'));
    file_put_contents($dir . '/files/ab/datei.pdf', '%PDF-privat');
    file_put_contents($dir . '/originals/' . $photoRel, $origBytes);
    file_put_contents($dir . '/originals.sha256', hash('sha256', $origBytes) . '  ' . $photoRel . "\n");
    file_put_contents($dir . '/MANIFEST.json', json_encode(['format' => 1, 'schema_version' => $schema ?? count(\Lichtkammer\Auth\Schema::migrations()), 'app_version' => Config::VERSION, 'created_at' => TEST_START]));
};

// Ziel: frischer Server, anderer Datenordner, derselbe Schlüssel; Bereitstellungsordner per LK_RESTORE_DIR im „Webroot“.
$mkTarget = static function (string $name, array $env = []) use ($h, $package): array {
    $web = $h . '/' . $name;
    $m = lk_make($web, ['LK_ORIGINALS_DIR' => $h . '/' . $name . '-orig', 'LK_DATA_DIR' => $h . '/' . $name . '-data', 'LK_RESTORE_DIR' => $web . '/public/restore'] + $env);
    return $m;
};
$B = $mkTarget('qB');
$package($B['config']->restoreDir());
$bb = new TestBrowser($B['app'], $B['config']);
$anonPage = $bb->get('/admin/setup');
check('Einrichtung: Link zur Wiederherstellung vorhanden', str_contains($anonPage->body, '/admin/setup/wiederherstellen'));
$rp = $bb->get('/admin/setup/wiederherstellen');
check('Wiederherstellung: Seite zeigt die Sicherung (Schema, Originale)', $rp->status === 200 && str_contains($rp->body, 'Sicherung vom') && str_contains($rp->body, '1 Originale'));
$bad = $bb->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($rp), 'token' => str_repeat('a', 48)]);
check('Wiederherstellung: falsches Token wird abgewiesen, nichts eingespielt', $bad->status === 400 && (int) $B['app']->services()->db->value('SELECT COUNT(*) FROM users') === 0 && is_file($B['config']->restoreDir() . '/lichtkammer.sqlite'));
$ok = $bb->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($rp), 'token' => TEST_SETUP_TOKEN]);
check('Wiederherstellung: mit Token eingespielt (1 Konto, 1 Foto)', $ok->status === 200 && str_contains($ok->body, '1 Konten, 1 Fotos'));
$dbB = \Lichtkammer\Auth\Database::open($B['config']->dataDir() . '/lichtkammer.sqlite');
check('Eingespielt: Sitzungen, Geräte und offene Einladungen verworfen', (int) $dbB->value('SELECT COUNT(*) FROM sessions') === 0 && (int) $dbB->value('SELECT COUNT(*) FROM invites WHERE used_at IS NULL') === 0 && (int) $dbB->value('SELECT COUNT(*) FROM trusted_devices') === 0);
check('Eingespielt: Protokoll enthält den Eintrag, nur Zahlen', (string) $dbB->value("SELECT detail FROM audit_log WHERE event = 'backup_restored'") === 'users=1,photos=1,schema=' . count(\Lichtkammer\Auth\Schema::migrations()));
check('Eingespielt: private Dateien kopiert', file_get_contents($B['config']->dataDir() . '/files/ab/datei.pdf') === '%PDF-privat');
check('Eingespielt: Datenbank nicht länger im Bereitstellungsordner', !is_file($B['config']->restoreDir() . '/lichtkammer.sqlite'));
check('Eingespielt: die leere Datenbank wurde beiseitegelegt, nicht gelöscht', glob($B['config']->dataDir() . '/lichtkammer.sqlite.vor-wiederherstellung-*') !== []);
// Der nächste Aufruf ist ein neuer Prozess: neue App, neue Datenbankverbindung
$B2 = ['app' => new \Lichtkammer\App($B['config'], $B['clock'], TEST_ARGON), 'config' => $B['config'], 'clock' => $B['clock']];
check('Nach der Wiederherstellung ist die Einrichtung geschlossen (Konten vorhanden)', (new TestBrowser($B2['app'], $B2['config']))->get('/admin/setup/wiederherstellen')->status === 404);

// Anmeldung mit dem Konto der Quelle, Passwort und TOTP-Geheimnis wie dort (derselbe Schlüssel)
$li = lk_login($B2, 'lutz', TEST_PASSWORD, $sa['secret']);
check('Nach dem Einspielen: Anmeldung mit altem Konto, Passwort und zweitem Faktor klappt', lk_in($li));

// Schritt 2: Originale
check('Schritt 2: Original wartet, Warnband meldet es', $B2['app']->services()->db && str_contains($li->get('/admin')->body, 'warten noch im Bereitstellungsordner'));
$csrfB = TestBrowser::csrf($li->get('/admin/wiederherstellung'));
check('Schritt 2: nicht für Redakteure/Anonyme (Anonym → Anmeldeseite)', (new TestBrowser($B2['app'], $B2['config']))->get('/admin/wiederherstellung')->status === 303);
check('Schritt 2: ohne CSRF-Token abgelehnt', $li->post('/admin/wiederherstellung/weiter', [])->status === 403);
$st = $li->post('/admin/wiederherstellung/weiter', ['_csrf' => $csrfB]);
check('Schritt 2: Original verschoben, Inhalt gleich, Meldung', $st->status === 200 && str_contains($st->body, '1 verschoben') && file_get_contents($h . '/qB-orig/' . $photoRel) === $origBytes && !is_file($B['config']->restoreDir() . '/originals/' . $photoRel));
check('Schritt 2: Zu allen Fotos gibt es ein Original', str_contains($st->body, 'Zu allen Fotos in der Datenbank gibt es ein Original'));
check('Schritt 2: Protokoll', (string) $B2['app']->services()->db->value("SELECT detail FROM audit_log WHERE event = 'originals_restored'") === 'moved=1,left=0,bad=0,missing=0');

// --- Falscher Schlüssel: nichts wird verändert ---
$C = $mkTarget('qC', ['LK_APP_KEY' => lk_key('anderer-schluessel')]);
$package($C['config']->restoreDir());
$bc = new TestBrowser($C['app'], $C['config']);
$pc = $bc->get('/admin/setup/wiederherstellen');
$rc = $bc->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($pc), 'token' => TEST_SETUP_TOKEN]);
check('Falscher Schlüssel: abgelehnt mit klarer Meldung, ohne Schlüsselwerte', $rc->status === 400 && str_contains($rc->body, 'LK_APP_KEY passt nicht') && !str_contains($rc->body, lk_key()) && !str_contains($rc->body, lk_key('anderer-schluessel')));
check('Falscher Schlüssel: Sicherung und leere Datenbank unverändert', is_file($C['config']->restoreDir() . '/lichtkammer.sqlite') && (int) $C['app']->services()->db->value('SELECT COUNT(*) FROM users') === 0 && glob($C['config']->dataDir() . '/wiederherstellung-*') === []);

// --- Beschädigte, neuere oder manipulierte Sicherungen ---
$D = $mkTarget('qD');
$package($D['config']->restoreDir());
file_put_contents($D['config']->restoreDir() . '/lichtkammer.sqlite', 'das ist keine datenbank');
$bd = new TestBrowser($D['app'], $D['config']);
$pd = $bd->get('/admin/setup/wiederherstellen');
$rd = $bd->post('/admin/setup/wiederherstellen', ['_csrf' => TestBrowser::csrf($pd), 'token' => TEST_SETUP_TOKEN]);
check('Beschädigte Datenbank: abgelehnt, leere Datenbank bleibt', $rd->status === 400 && (int) $D['app']->services()->db->value('SELECT COUNT(*) FROM users') === 0 && glob($D['config']->dataDir() . '/wiederherstellung-*') === []);
$E = $mkTarget('qE');
$package($E['config']->restoreDir(), 999);
check('Neueres Schema: Seite meldet es, Einspielen nicht möglich', str_contains((new TestBrowser($E['app'], $E['config']))->get('/admin/setup/wiederherstellen')->body, 'neueren Version'));

// --- Originale: Prüfsumme, Pfadfalle, Konflikt ---
$F = $mkTarget('qF');
$package($F['config']->restoreDir());
$rF = new Restore($F['config']);
file_put_contents($F['config']->restoreDir() . '/originals/' . $photoRel, 'verfälscht');
$mv = $rF->moveOriginals();
check('Originale: falsche Prüfsumme wird nicht übernommen', $mv['bad'] === 1 && $mv['moved'] === 0 && !is_file($F['config']->originalsDir() . '/' . $photoRel));
file_put_contents($F['config']->restoreDir() . '/originals.sha256', hash('sha256', 'x') . "  ../../etc/passwd\n");
$trap = null;
try {
    $rF->originalList();
} catch (\Lichtkammer\Backup\RestoreException $ex) {
    $trap = $ex->getMessage();
}
check('Originale: Pfade außerhalb xx/name.ext in der Liste werden abgelehnt', $trap !== null);
file_put_contents($F['config']->restoreDir() . '/originals.sha256', hash('sha256', $origBytes) . '  ' . $photoRel . "\n");
file_put_contents($F['config']->restoreDir() . '/originals/' . $photoRel, $origBytes);
@mkdir(dirname($F['config']->originalsDir() . '/' . $photoRel), 0700, true);
file_put_contents($F['config']->originalsDir() . '/' . $photoRel, 'schon-da-anderer-inhalt');
$mv2 = $rF->moveOriginals();
check('Originale: vorhandene Datei am Ziel wird nie überschrieben', $mv2['moved'] === 0 && file_get_contents($F['config']->originalsDir() . '/' . $photoRel) === 'schon-da-anderer-inhalt');
$ht = (string) file_get_contents(__DIR__ . '/../public/.htaccess');
check('.htaccess sperrt den Bereitstellungsordner restore/', str_contains($ht, '|restore)'));
