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
