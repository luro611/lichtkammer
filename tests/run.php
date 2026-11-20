<?php
declare(strict_types=1);

// Minimaler Testläufer ohne Composer: php tests/run.php
require __DIR__ . '/../app/bootstrap.php';

use Lichtkammer\Config;
use Lichtkammer\Request;
use Lichtkammer\Router;
use Lichtkammer\Response;
use Lichtkammer\SecurityHeaders;

$fails = 0;
$count = 0;
function check(string $name, bool $ok): void
{
    global $fails, $count;
    $count++;
    if (!$ok) {
        $fails++;
        fwrite(STDERR, "FEHLER: $name\n");
    }
}

$tmp = sys_get_temp_dir() . '/lk-test-' . bin2hex(random_bytes(4));
ini_set('error_log', sys_get_temp_dir() . '/lk-test-errors.log'); // Fehlerprotokoll der App nicht in die Testausgabe mischen

// --- Pfade: Betriebsart all-inkl (app/ liegt im Webroot neben index.php) ---
mkdir($tmp . '/webroot/app', 0777, true);
$c = Config::load($tmp . '/webroot/app', $tmp . '/webroot', []);
check('all-inkl: data neben app', $c->dataDir() === $tmp . '/webroot/data');
check('all-inkl: originals neben app', $c->originalsDir() === $tmp . '/webroot/originals');
check('all-inkl: media in public', $c->mediaDir() === $tmp . '/webroot/media');

// all-inkl mit Datenordner über dem Webroot (LK_DATA_DIR aus app/.env)
file_put_contents($tmp . '/webroot/app/.env', "# Kommentar\nLK_DATA_DIR=/www/htdocs/v1/lichtkammer-data/\nLK_ORIGINALS_DIR=\"/www/htdocs/v1/lichtkammer-originals\"\nLK_NOINDEX=1\n");
$c = Config::load($tmp . '/webroot/app', $tmp . '/webroot', []);
check('.env: data', $c->dataDir() === '/www/htdocs/v1/lichtkammer-data');
check('.env: originals mit Anführungszeichen', $c->originalsDir() === '/www/htdocs/v1/lichtkammer-originals');
check('.env: noindex', $c->noindex());

// --- Pfade: Betriebsart Docker (app/ neben public/, Werte aus Umgebung) ---
mkdir($tmp . '/docker/app', 0777, true);
mkdir($tmp . '/docker/public', 0777, true);
$c = Config::load($tmp . '/docker/app', $tmp . '/docker/public', [
    'LK_DATA_DIR' => '/var/lib/lichtkammer/data',
    'LK_MEDIA_DIR' => '/var/lib/lichtkammer/media',
    'PATH' => '/usr/bin',          // fremde Variablen werden ignoriert
]);
check('docker: data aus Umgebung', $c->dataDir() === '/var/lib/lichtkammer/data');
check('docker: media aus Umgebung', $c->mediaDir() === '/var/lib/lichtkammer/media');
check('docker: originals Standard', $c->originalsDir() === $tmp . '/docker/originals');
check('docker: noindex aus', !$c->noindex());

// Umgebung hat Vorrang vor .env
$c = Config::load($tmp . '/webroot/app', $tmp . '/webroot', ['LK_DATA_DIR' => '/x/data']);
check('Umgebung vor .env', $c->dataDir() === '/x/data');

// .env-Parser
$p = Config::parseEnvFile("A=1\nLK_X = 'y z'\nLK_BAD KEY=1\n=\nLK_EMPTY=\n");
check('Parser: Kleinbuchstaben-/fremde Schlüssel erlaubt nur A-Z', isset($p['A']));
check('Parser: Leerzeichen und Quotes', ($p['LK_X'] ?? null) === 'y z');
check('Parser: leerer Wert', ($p['LK_EMPTY'] ?? null) === '');
check('Parser: Schlüssel mit Leerzeichen verworfen', !isset($p['LK_BAD KEY']));
$p = Config::parseEnvFile("LK_SESSION_IDLE=3600        # Leerlauf\nLK_URL=https://a.de/#x\nLK_Q=\"a # b\"\n");
check('Parser: Kommentar hinter dem Wert wird abgeschnitten (A9)', ($p['LK_SESSION_IDLE'] ?? null) === '3600');
check('Parser: # ohne Leerzeichen davor bleibt Teil des Werts', ($p['LK_URL'] ?? null) === 'https://a.de/#x');
check('Parser: # in Anführungszeichen bleibt', ($p['LK_Q'] ?? null) === 'a # b');

// --- Request / HTTPS hinter Reverse-Proxy ---
$plain = Config::load($tmp . '/docker/app', $tmp . '/docker/public', []);
$proxy = Config::load($tmp . '/docker/app', $tmp . '/docker/public', ['LK_TRUST_PROXY' => '1']);
$srv = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_X_FORWARDED_PROTO' => 'https'];
check('Proxy-Header ohne Vertrauen ignoriert', !(new Request($srv, $plain))->isHttps());
check('Proxy-Header mit Vertrauen', (new Request($srv, $proxy))->isHttps());
check('HTTPS direkt', (new Request(['HTTPS' => 'on'], $plain))->isHttps());
check('HTTPS=off ist kein HTTPS', !(new Request(['HTTPS' => 'off'], $plain))->isHttps());

// Pfad-Bereinigung
check('Pfad ohne Query', (new Request(['REQUEST_URI' => '/a/b?x=1'], $plain))->path() === '/a/b');
check('Pfad doppelte Schrägstriche', (new Request(['REQUEST_URI' => '//a///b'], $plain))->path() === '/a/b');

// --- Router ---
$r = new Router();
$r->get('/', static fn (): Response => Response::html('start'));
$r->get('/x', static fn (): Response => Response::html('x'));
$rq = static fn (string $m, string $u): Request => new Request(['REQUEST_METHOD' => $m, 'REQUEST_URI' => $u], $plain);
check('Router: Start', $r->dispatch($rq('GET', '/'))->body === 'start');
check('Router: HEAD wie GET', $r->dispatch($rq('HEAD', '/x'))->status === 200);
check('Router: Schrägstrich am Ende', $r->dispatch($rq('GET', '/x/'))->status === 200);
check('Router: 404', $r->dispatch($rq('GET', '/nope'))->status === 404);
check('Router: falsche Methode liefert 404 (nicht 405)', $r->dispatch($rq('POST', '/x'))->status === 404);

// --- Sicherheitsköpfe ---
$h = SecurityHeaders::for(new Request(['HTTPS' => 'on'], $plain), $plain);
check('HSTS nur mit HTTPS', isset($h['Strict-Transport-Security']));
check('Kein HSTS ohne HTTPS', !isset(SecurityHeaders::for(new Request([], $plain), $plain)['Strict-Transport-Security']));
check('CSP ohne externe Quellen', !str_contains($h['Content-Security-Policy'], 'http'));
$ni = Config::load($tmp . '/webroot/app', $tmp . '/webroot', []);
check('noindex setzt X-Robots-Tag', isset(SecurityHeaders::for(new Request([], $ni), $ni)['X-Robots-Tag']));

// --- Anmeldung (Schritt 2) ---
foreach (['auth_unit.php', 'auth_flow.php', 'auth_race.php', 'design.php', 'media.php', 'bildverwaltung.php', 'medien.php', 'oeffentlich.php', 'bloecke.php', 'import.php', 'startseite.php', 'speichern.php', 'weiterleitung.php', 'haertung.php', 'sicherung.php', 'varianten.php', 'material.php'] as $file) {
    if (is_file(__DIR__ . '/' . $file)) {
        require __DIR__ . '/' . $file;
    }
}

// --- Asset-Adressen mit Version (Browser-Cache nach Upload) ---
mkdir($tmp . '/webroot/assets/css', 0777, true);
file_put_contents($tmp . '/webroot/assets/css/app.css', 'x');
touch($tmp . '/webroot/assets/css/app.css', 1700000000);
$vw = new \Lichtkammer\View(__DIR__ . '/../app/templates', Config::load($tmp . '/webroot/app', $tmp . '/webroot', []));
check('asset: Version aus Änderungszeit', $vw->asset('/assets/css/app.css') === '/assets/css/app.css?v=1700000000');
check('asset: fehlende Datei ohne Version', $vw->asset('/assets/nix.js') === '/assets/nix.js');
check('layout: CSS und JS mit Version', str_contains($vw->render('error', ['title' => 'T', 'detail' => null]), 'href="/assets/css/app.css'));

// --- Marke: Verwaltung heißt Lichtkammer, öffentliche Seite trägt den Seitennamen und "Powered by Lichtkammer" ---
check('Admin-Kopf zeigt den Seitennamen (Lichtkammer ist die Plattform, im Fuß)', str_contains(file_get_contents(__DIR__ . '/../app/templates/layout.php'), '$e($siteName ?? \'Lichtkammer\')') && str_contains(file_get_contents(__DIR__ . '/../app/templates/layout.php'), 'rel="noopener">Lichtkammer</a> <?= $e(\Lichtkammer\Config::VERSION)'));
check('Seitenfuß: Powered by Lichtkammer', str_contains(file_get_contents(__DIR__ . '/../app/templates/site/layout.php'), 'Powered by <a href="https://lurohome.de/lichtkammer/" target="_blank" rel="noopener">Lichtkammer</a>'));

// --- Aufräumen und Ergebnis ---
exec('rm -rf ' . escapeshellarg($tmp));
echo ($fails === 0 ? 'OK' : 'FEHLGESCHLAGEN') . ": $count Prüfungen, $fails Fehler\n";
exit($fails === 0 ? 0 : 1);
