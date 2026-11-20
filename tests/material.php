<?php
declare(strict_types=1);

// Entwurf 29, Teil D: Seitenstreifen „Material“ (Fotos und Galerien).

use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Site\Blocks;

require_once __DIR__ . '/support.php';

$f29 = lk_new_tmp($tmp, 'material');
$m = lk_make($f29 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$anon = new TestBrowser($m['app'], $m['config']);
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE);

// Redaktion (Rolle „editor“) mit eigener Sitzung
$db->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'redaktion', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ed' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$edSess = $svc->sessions->create((int) $db->value("SELECT id FROM users WHERE username = 'redaktion'"), 'full', ClientIp::forms('203.0.113.9')['prefix'], 'TestBrowser/1');
$ed = new TestBrowser($m['app'], $m['config'], '203.0.113.9');
$ed->jar[$svc->cookies->name('lk_sid')] = $edSess['id'];
$csrfEd = TestBrowser::csrf($ed->get('/admin/seiten'));

// 70 fertige Fotos (Zeilen direkt angelegt; die Auswahl liest nur Tabelle und Vorschaubild), 14 davon in einer Galerie
$now = $m['clock']->now();
$ids = [];
for ($i = 1; $i <= 70; $i++) {
    $db->run("INSERT INTO photos(token, original_name, original_path, sha256, bytes, mime, width, height, status, title, alt_text, created_at, updated_at) VALUES(?, ?, ?, ?, 1, 'image/jpeg', 300, 200, 'ready', ?, ?, ?, ?)", ["tok$i", "orig$i.jpg", "o/$i", "sha$i", $i === 70 ? 'Möwe <b>' : "Foto $i", "Alt $i", $now, $now]);
    $pid = $db->lastId();
    $db->run("INSERT INTO photo_variants(photo_id, width, height, format, path, bytes) VALUES(?, 300, 200, 'jpeg', ?, 1)", [$pid, "v/$pid-300.jpg"]);
    $ids[] = $pid;
}
$g1 = $svc->galleries->create('Island 2022', 'Text');
$svc->galleries->addPhotos($g1, array_slice($ids, 0, 14));
$g2 = $svc->galleries->create('Leer', '');
$gj = static function (TestBrowser $br, string $path): array {
    $r = $br->get($path);
    return ['status' => $r->status, 'ct' => $r->headers['Content-Type'] ?? '', 'd' => json_decode($r->body, true) ?: []];
};

// --- Teil D: Quellen für den Seitenstreifen ---
$p1 = $gj($b, '/admin/fotos/auswahl?n=60&seite=1');
check('Material: 60 Kacheln je Seite, „mehr“ gesetzt', $p1['status'] === 200 && count($p1['d']['rows']) === 60 && $p1['d']['more'] === true);
$p2 = $gj($b, '/admin/fotos/auswahl?n=60&seite=2');
check('Material: zweite Seite hat den Rest, kein „mehr“, keine Doppelten', count($p2['d']['rows']) === 10 && $p2['d']['more'] === false && array_intersect(array_column($p1['d']['rows'], 'id'), array_column($p2['d']['rows'], 'id')) === []);
check('Material: Dialog bleibt bei 24 je Seite', count($gj($b, '/admin/fotos/auswahl')['d']['rows']) === 24);
check('Material: n ist nur 24 oder 60 (kein beliebiger Wert)', count($gj($b, '/admin/fotos/auswahl?n=500')['d']['rows']) === 24 && count($gj($b, '/admin/fotos/auswahl?n=-1')['d']['rows']) === 24);
$row = $p1['d']['rows'][0];
check('Material: Zeile hat Nummer, Titel, Alt-Text, Vorschau-Adresse unter /media/', isset($row['id'], $row['t'], $row['a'], $row['c'], $row['u']) && str_starts_with($row['u'], '/media/'));
$srr = $gj($b, '/admin/fotos/auswahl?n=60&q=' . rawurlencode('Möwe'));
check('Material: Suche findet Titel (Antwort ist JSON, nie HTML)', ($sr = $srr['d']['rows']) !== [] && $sr[0]['t'] === 'Möwe <b>' && str_starts_with($srr['ct'], 'application/json'));
check('Material: ohne Anmeldung 303', $anon->get('/admin/fotos/auswahl-galerien')->status === 303 && $anon->get('/admin/fotos/auswahl-galerie/' . $g1)->status === 303);
$gl = $gj($b, '/admin/fotos/auswahl-galerien');
check('Material: Galerien mit Titel, Zahl, Titelbild', $gl['status'] === 200 && count($gl['d']['rows']) === 2 && $gl['d']['rows'][0]['t'] === 'Island 2022' && $gl['d']['rows'][0]['n'] === 14 && str_starts_with($gl['d']['rows'][0]['u'], '/media/') && $gl['d']['rows'][1]['n'] === 0 && $gl['d']['rows'][1]['u'] === '');
check('Material: Galerien-Suche und Redaktion erlaubt', count($gj($b, '/admin/fotos/auswahl-galerien?q=isl')['d']['rows']) === 1 && $gj($ed, '/admin/fotos/auswahl-galerien')['status'] === 200 && $gj($ed, '/admin/fotos/auswahl?n=60')['status'] === 200);
$gp = $gj($b, '/admin/fotos/auswahl-galerie/' . $g1);
check('Material: Galerie liefert höchstens 12 Fotos, meldet die Gesamtzahl (14)', $gp['status'] === 200 && count($gp['d']['rows']) === Blocks::MAX_PHOTOS && $gp['d']['total'] === 14 && isset($gp['d']['rows'][0]['u']));
check('Material: leere Galerie liefert nichts, unbekannte Galerie 404', $gj($b, '/admin/fotos/auswahl-galerie/' . $g2)['d']['rows'] === [] && $gj($b, '/admin/fotos/auswahl-galerie/99999')['status'] === 404 && $gj($b, '/admin/fotos/auswahl-galerie/abc')['status'] === 404);
$db->run("UPDATE photos SET deleted_at = 1 WHERE id = ?", [$ids[0]]);
check('Material: gelöschtes Foto fehlt in der Galerie-Auswahl', $gj($b, '/admin/fotos/auswahl-galerie/' . $g1)['d']['total'] === 13);
$db->run("UPDATE photos SET deleted_at = NULL WHERE id = ?", [$ids[0]]);

// --- Bausteine, die der Editor beim Ablegen erzeugt, gehen durch dieselbe Serverprüfung ---
$made = Blocks::parse($J([
    ['t' => 'image', 'photo' => $ids[0], 'alt' => 'Alt 1', 'decor' => false, 'caption' => '', 'width' => 'normal', 'href' => ''],
    ['t' => 'gallery', 'photos' => array_map(static fn (int $pid): array => ['photo' => $pid, 'alt' => 'x', 'decor' => false], array_slice($ids, 0, 12)), 'cols' => 3],
]), $db);
check('Ablegen: Bildblock und Galerie-Raster (12) werden angenommen', count($made) === 2 && $made[0]['photo'] === $ids[0] && count($made[1]['photos']) === 12);
$over = null;
try {
    Blocks::parse($J([['t' => 'gallery', 'photos' => array_map(static fn (int $pid): array => ['photo' => $pid, 'alt' => 'x'], array_slice($ids, 0, 14)), 'cols' => 3]]), $db);
} catch (\Throwable $e) {
    $over = $e->getMessage();
}
check('Ablegen: mehr als 12 Fotos lehnt der Server ab (der Editor übernimmt höchstens 12)', $over !== null && str_contains($over, 'höchstens 12'));
$nope = null;
try {
    Blocks::parse($J([['t' => 'image', 'photo' => 999999, 'alt' => 'x']]), $db);
} catch (\Throwable $e) {
    $nope = $e->getMessage();
}
check('Ablegen: unbekannte Foto-Nummer wird abgelehnt', $nope !== null && str_contains($nope, 'gibt es nicht'));

$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'impressum']);
$pgId = (int) $db->value("SELECT id FROM pages WHERE slug = 'impressum'");
// --- Editor-Seiten und Skripte ---
$pageHtml = $b->get("/admin/seiten/$pgId")->body;
check('Editor-Seite: Skript und Quellen eingebunden', str_contains($pageHtml, '/assets/js/page-material.js') && str_contains($pageHtml, 'data-gal-url="/admin/fotos/auswahl-galerien"') && str_contains($pageHtml, 'data-gal-photos-url="/admin/fotos/auswahl-galerie"'));
foreach (['page-material.js', 'page-editor.js'] as $jsFile) {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/' . $jsFile);
    check("Skript $jsFile: kein innerHTML, kein eval, keine fremden Adressen", !preg_match('~innerHTML|outerHTML|insertAdjacentHTML|eval\(|document\.write~i', $js) && !preg_match('~(src|href)\s*=\s*[\'"]https?://~i', $js));
}
$jsM = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-material.js');
$jsE = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-editor.js');
check('Material-Skript: Weg ohne Maus („Wohin?“, Enter/Leertaste auf der Kachel)', str_contains($jsM, 'Wohin?') && str_contains($jsM, "e.key === 'Enter'") && str_contains($jsM, 'aria-label'));
check('Editor-Skript: nur eigene Zieh-Daten gelten (Kennung), Fremddateien werden ignoriert', str_contains($jsE, "indexOf.call(e.dataTransfer.types || [], MIME)"));
