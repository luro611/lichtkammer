<?php
declare(strict_types=1);

// Messung der Bauzeit (Entwurf 28, Risiko „Dauer“). Läuft NICHT bei tests/run.php, nur von Hand:
//   docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/messung_bauzeit.php [Galerien] [Fotos je Galerie] [Seiten]
// Erzeugt eine Wegwerf-Installation mit vielen Galerien und Seiten und misst plan() und publish().
// Das ist Docker auf dem Mac, nicht all-inkl: die Messung auf dem Testserver folgt erst nach Freigabe von Lutz.
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/support.php';

function check(string $n, bool $ok): void
{
}

function lk_jpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2), $h, (int) imagecolorallocate($im, 220, 30, 30));
    ob_start();
    imagejpeg($im, null, 90);
    return (string) ob_get_clean();
}

$nG = (int) ($argv[1] ?? 40);
$nP = (int) ($argv[2] ?? 25);
$nS = (int) ($argv[3] ?? 200);
$tmp = sys_get_temp_dir() . '/lk-messung-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
$m = lk_make($tmp);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$csrf = TestBrowser::csrf($b->get('/admin/fotos'));
$ids = [];
for ($i = 0; $i < $nP; $i++) {
    $csrf = TestBrowser::csrf($b->get('/admin/fotos'));
    $start = json_decode($b->post('/admin/upload/start', ['_csrf' => $csrf, 'name' => "m$i.jpg", 'size' => (string) strlen($j = lk_jpeg(900 + $i, 600))])->body, true);
    for ($off = 0; $off < strlen($j); $off += 5000) {
        $b->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=' . $off, [], ['HTTP_X_CSRF_TOKEN' => $csrf], substr($j, $off, 5000));
    }
    $fin = (array) json_decode($b->request('POST', '/admin/upload/' . $start['id'] . '/finish', [], ['HTTP_X_CSRF_TOKEN' => $csrf])->body, true);
    $b->request('POST', '/admin/fotos/' . $fin['photo_id'] . '/verarbeiten', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
    $svc->catalog->update((int) $fin['photo_id'], ['title' => "Foto $i", 'alt_text' => "Beschreibung $i"]);
    $ids[] = (int) $fin['photo_id'];
}
for ($g = 0; $g < $nG; $g++) {
    $gid = $svc->galleries->create("Galerie $g", "Beschreibung **$g**");
    $svc->galleries->addPhotos($gid, $ids);
    $svc->galleries->setStatus($gid, 'published');
}
for ($p = 0; $p < $nS; $p++) {
    $pid = $svc->pages->save(null, ['title' => "Seite $p", 'body' => "## Seite $p\n\nText " . str_repeat('Lorem ipsum dolor sit amet. ', 40), 'status' => 'published', 'nav' => 'none', 'slug' => '']);
}
$t = microtime(true);
$plan = $svc->builder->plan();
$tPlan = microtime(true) - $t;
$t = microtime(true);
$res = $svc->builder->publish(1);
$tPub = microtime(true) - $t;
$m['clock']->advance(61);
$t = microtime(true);
$res2 = $svc->builder->publish(1);
$tPub2 = microtime(true) - $t;
printf("Galerien %d, Fotos je Galerie %d (%d verschiedene), Seiten %d, Dateien %d\n", $nG, $nP, $nP, $nS, $plan['files']);
printf("plan(): %.2f s, Fehler: %d\n", $tPlan, count($plan['errors']));
printf("publish() 1: %.2f s (%s), publish() 2: %.2f s (%s)\n", $tPub, $res['ok'] ? 'ok' : $res['error'], $tPub2, $res2['ok'] ? 'ok' : $res2['error']);
printf("Speicher-Spitze: %.0f MB\n", memory_get_peak_usage(true) / 1048576);
