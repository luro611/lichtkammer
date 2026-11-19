<?php
declare(strict_types=1);

// Erzeugt eine künstliche Test-Sicherung für die Wiederherstellungsprobe (tools/restore-probe.sh). Läuft NICHT bei tests/run.php:
//   docker run --rm -v "$PWD":/app -v <Ausgabe>:/out -w /app lichtkammer-test php tests/erzeuge_testsicherung.php /out/sicherung /out/zugang
// Baut eine Wegwerf-Installation (zufälliger LK_APP_KEY, Konto mit Passwort und TOTP, Fotos mit echten Originalen, Galerie, Seiten,
// private Datei), zieht daraus Paket und Originale in Teilen (wie Verwaltung → Sicherung) und legt sie unter <Sicherungsordner> ab.
// <Zugangsordner> bekommt schluessel.txt (nur der Schlüssel) und zugang.env (Benutzer, Passwort, TOTP-Geheimnis der Testinstallation).
// Nur Testwerte; die Dateien gehören nie ins Repository.
use Lichtkammer\App;
use Lichtkammer\Auth\Clock;
use Lichtkammer\Backup\Backup;

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/support.php';

if ($argc < 3) {
    fwrite(STDERR, "Aufruf: php tests/erzeuge_testsicherung.php <Sicherungsordner> <Zugangsordner>\n");
    exit(2);
}
[$outDir, $accDir] = [rtrim($argv[1], '/'), rtrim($argv[2], '/')];
foreach ([$outDir, $accDir] as $d) {
    if (!is_dir($d) && !mkdir($d, 0700, true)) {
        fwrite(STDERR, "Ordner nicht anlegbar: $d\n");
        exit(1);
    }
}
if (glob($outDir . '/*') !== [] && glob($outDir . '/*') !== false) {
    fwrite(STDERR, "Der Sicherungsordner ist nicht leer: $outDir\n");
    exit(1);
}

function noisy_jpeg(int $w, int $h): string
{
    $im = new Imagick();
    $im->newPseudoImage($w, $h, 'plasma:fractal');
    $im->addNoiseImage(Imagick::NOISE_GAUSSIAN);
    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality(95);
    return $im->getImageBlob();
}

function small_jpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, (int) imagecolorallocate($im, 220, 30, 30));
    imagefilledrectangle($im, intdiv($w, 2) + 1, 0, $w, $h, (int) imagecolorallocate($im, 30, 30, 220));
    ob_start();
    imagejpeg($im, null, 90);
    return (string) ob_get_clean();
}

$key = base64_encode(random_bytes(32));
$tmp = sys_get_temp_dir() . '/lk-testsicherung-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
// Echte Zeit (eine Stunde zurück), damit das Anmeldeverfahren im Container mit der echten Uhr nichts als „aus der Zukunft“ ablehnt.
$config = lk_config($tmp, ['LK_APP_KEY' => $key, 'LK_BACKUP_PART_MB' => '10']);
$clock = new Clock(time() - 3600);
$app = new App($config, $clock, TEST_ARGON);
$m = ['app' => $app, 'config' => $config, 'clock' => $clock];
$s = lk_setup($m, 'probe', TEST_PASSWORD);
$b = $s['b'];
$svc = $app->services();

$ids = [];
foreach ([[2400, 1600], [2400, 1600], [2400, 1600], [640, 400]] as $i => [$w, $h]) {
    $bytes = $i < 3 ? noisy_jpeg($w, $h) : small_jpeg($w, $h);
    $csrf = TestBrowser::csrf($b->get('/admin/fotos'));
    $start = json_decode($b->post('/admin/upload/start', ['_csrf' => $csrf, 'name' => "probe-$i.jpg", 'size' => (string) strlen($bytes)])->body, true);
    for ($off = 0; $off < strlen($bytes); $off += 262144) {
        $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=' . $off, [], ['HTTP_X_CSRF_TOKEN' => $csrf], substr($bytes, $off, 262144));
    }
    $fin = (array) json_decode($b->request('POST', '/admin/upload/' . $start['id'] . '/finish', [], ['HTTP_X_CSRF_TOKEN' => $csrf])->body, true);
    $b->request('POST', '/admin/fotos/' . $fin['photo_id'] . '/verarbeiten', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
    $svc->catalog->update((int) $fin['photo_id'], ['title' => 'Probefoto ' . ($i + 1), 'alt_text' => 'Farbfläche ' . ($i + 1)]);
    $ids[] = (int) $fin['photo_id'];
}
$g1 = $svc->galleries->create('Probegalerie Eins', 'Erste Galerie der Test-Sicherung');
$svc->galleries->addPhotos($g1, [$ids[0], $ids[1], $ids[3]]);
$svc->galleries->setStatus($g1, 'published');
$g2 = $svc->galleries->create('Probegalerie Zwei', 'Zweite Galerie');
$svc->galleries->addPhotos($g2, [$ids[2]]);
$svc->galleries->setStatus($g2, 'published');
$svc->pages->save(null, ['title' => 'Über die Probe', 'body' => "## Über die Probe\n\nText der Test-Sicherung.", 'status' => 'published', 'nav' => 'main', 'slug' => '']);
$svc->pages->save(null, ['title' => 'Kontakt Probe', 'body' => "## Kontakt\n\nNur zur Probe.", 'status' => 'published', 'nav' => 'footer', 'slug' => '']);
@mkdir($tmp . '/data/files/ab', 0700, true);
file_put_contents($tmp . '/data/files/ab/probe.pdf', '%PDF-1.4 privat, nur Test');

// Paket und Originale in Teilen, wie sie die Verwaltung liefert.
$bk = new Backup($config, $svc->db, $clock);
$write = static function (array $part, string $path): void {
    $fh = fopen($path, 'wb');
    ($part['emit'])(static function (string $c) use ($fh): void {
        fwrite($fh, $c);
    });
    fclose($fh);
};
$pkg = $bk->package();
$write($pkg, $outDir . '/lichtkammer-paket.tar');
$after = 0;
$n = 0;
while (($part = $bk->originalsPart($after)) !== null) {
    $n++;
    $write($part, sprintf('%s/lichtkammer-originale-teil-%d.tar', $outDir, $n));
    $after = $part['last'];
}

file_put_contents($accDir . '/schluessel.txt', $key . "\n");
file_put_contents($accDir . '/zugang.env', "LK_PROBE_USER=probe\nLK_PROBE_PASSWORD=" . TEST_PASSWORD . "\nLK_PROBE_TOTP_SECRET=" . $s['secret'] . "\n");
chmod($accDir . '/schluessel.txt', 0600);
chmod($accDir . '/zugang.env', 0600);
echo "Test-Sicherung: " . count($ids) . " Fotos, 2 Galerien, 2 Seiten, $n Originalteile in $outDir\n";
echo "Zugang (nur Test): $accDir/schluessel.txt, $accDir/zugang.env\n";
