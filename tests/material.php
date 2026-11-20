<?php
declare(strict_types=1);

// Entwurf 29, Teile D und F: Seitenstreifen „Material“ (Fotos und Galerien) und Vorschau neben dem Editor (Route ohne Speichern).

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

// --- Teil F: Vorschau neben dem Editor ---
$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'impressum']);
$pgId = (int) $db->value("SELECT id FROM pages WHERE slug = 'impressum'");
$saved = $J([['t' => 'text', 'text' => 'Gespeicherter Text']]);
$b->post("/admin/seiten/$pgId/speichern", ['_csrf' => $csrf, 'title' => 'Impressum', 'slug' => 'impressum', 'body' => '', 'blocks' => $saved, 'status' => 'draft', 'nav' => 'footer']);
$live = static fn (TestBrowser $br, array $post, ?string $tok = null) => $br->post('/admin/vorschau-live', $post + ['_csrf' => $tok ?? TestBrowser::csrf($br->get('/admin/seiten'))]);
$snapshot = static function () use ($db): string {
    $out = '';
    foreach (['pages', 'photos', 'galleries', 'gallery_photos', 'photo_variants', 'meta', 'settings', 'audit_log', 'users', 'sessions'] as $t) {
        if ($db->value("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$t]) === null) {
            continue;
        }
        $cols = array_column($db->all("PRAGMA table_info($t)"), 'name');
        $skip = $t === 'sessions' ? ['last_seen_at', 'last_used_at', 'expires_at', 'idle_expires_at'] : [];
        $cols = array_values(array_diff($cols, $skip));
        $out .= $t . ':' . md5(json_encode($db->all('SELECT ' . implode(',', $cols) . " FROM $t ORDER BY 1")) ?: '') . ';';
    }
    return $out;
};
$before = $snapshot();
$dirtyBefore = $db->value("SELECT v FROM meta WHERE k = 'site_dirty'");
$storedBefore = (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pgId]);
$neu = $J([['t' => 'heading', 'level' => 2, 'text' => 'Nur in der Vorschau'], ['t' => 'text', 'text' => 'Ungespeicherter Text <script>x</script>'], ['t' => 'image', 'photo' => $ids[1], 'alt' => 'Alt 2', 'width' => 'normal']]);
$r = $live($b, ['blocks' => $neu, 'ziel' => "page:$pgId"]);
check('Vorschau live: 200, zeigt den ungespeicherten Stand (Überschrift, Text maskiert, Foto)', $r->status === 200 && str_contains($r->body, 'Nur in der Vorschau') && str_contains($r->body, 'Ungespeicherter Text') && !str_contains($r->body, '<script>x</script>') && str_contains($r->body, "v/{$ids[1]}-300.jpg") && !str_contains($r->body, 'Gespeicherter Text'));
check('Vorschau live: schreibt nichts (Seiten, Fotos, Einstellungen, Protokoll, Merker „nicht veröffentlicht“ unverändert)', $snapshot() === $before && $db->value("SELECT v FROM meta WHERE k = 'site_dirty'") === $dirtyBefore && (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pgId]) === $storedBefore);
check('Vorschau live: öffentliche Seite unberührt (Entwurf nicht online)', $anon->get('/impressum/')->status !== 200 && !str_contains($anon->get('/impressum/')->body, 'Nur in der Vorschau'));
$h = $r->headers;
$csp = (string) ($h['Content-Security-Policy'] ?? '');
check('Vorschau live: Kopfzeilen (kein Cache, nosniff, Rahmen nur eigene Herkunft, kein Referrer, noindex)', ($h['Cache-Control'] ?? '') === 'no-store' && ($h['X-Content-Type-Options'] ?? '') === 'nosniff' && ($h['X-Frame-Options'] ?? '') === 'SAMEORIGIN' && ($h['Referrer-Policy'] ?? '') === 'no-referrer' && str_contains((string) ($h['X-Robots-Tag'] ?? ''), 'noindex'));
check('Vorschau live: CSP mit sandbox, ohne Skripterlaubnis, Bilder/Stile nur von sich, Rahmen nur eigene Herkunft', str_contains($csp, "default-src 'none'") && str_contains($csp, 'sandbox') && !str_contains($csp, 'allow-scripts') && !str_contains($csp, 'script-src') && str_contains($csp, "img-src 'self' data:") && str_contains($csp, "style-src 'self'") && str_contains($csp, "frame-ancestors 'self'") && !preg_match('~https?://|\*|unsafe~', $csp));
check('Vorschau live: Links öffnen nirgends (base target), kein fremdes Ziel eingebaut', str_contains($r->body, '<base target="_blank">') && !preg_match('~(src=|<link[^>]*href=)["\']https?://~', $r->body));
check('Vorschau live: Titel und Adresse nicht aus der Eingabe (Zusatzfelder werden nicht beachtet)', !str_contains($live($b, ['blocks' => $neu, 'ziel' => "page:$pgId", 'title' => 'ZZtitelZZ', 'slug' => '../../etc'])->body, 'ZZtitelZZ'));
$gesp = $b->get('/admin/vorschau/impressum');
check('Vorschau live: gleicher Renderer wie die gespeicherte Vorschau (gespeicherte Blöcke ergeben dieselbe Seite)', $gesp->status === 200 && str_replace('<base target="_blank">', '', $live($b, ['blocks' => $saved, 'ziel' => "page:$pgId"])->body) === $gesp->body);
// Startseite
$hs = $J([['t' => 'heading', 'level' => 2, 'text' => 'Startseite live'], ['t' => 'hero', 'photo' => $ids[2], 'alt' => 'Alt 3', 'decor' => false, 'title' => 'Titel live']]);
$rh = $live($b, ['blocks' => $hs, 'ziel' => 'home']);
check('Vorschau live: Startseite (Titelbild nur dort erlaubt), nichts gespeichert', $rh->status === 200 && str_contains($rh->body, 'Startseite live') && str_contains($rh->body, 'Titel live') && $snapshot() === $before);
check('Vorschau live: Titelbild-Block auf einer normalen Seite wird abgelehnt', $live($b, ['blocks' => $hs, 'ziel' => "page:$pgId"])->status === 422);
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $hs]);
$hs2 = $b->get('/admin/vorschau');
check('Vorschau live: Startseite = gespeicherte Vorschau, wenn dieselben Blöcke (alte Seiten byte-gleich)', str_replace('<base target="_blank">', '', $live($b, ['blocks' => $hs, 'ziel' => 'home'])->body) === $hs2->body);
$before = $snapshot();
// Rechte und Schutz
check('Vorschau live: ohne Anmeldung 303 zur Anmeldung', ($x = $anon->post('/admin/vorschau-live', ['blocks' => $neu, 'ziel' => "page:$pgId", '_csrf' => 'x']))->status === 303 && str_contains($x->headers['Location'] ?? '', '/admin/login') && !str_contains($x->body, 'Nur in der Vorschau'));
check('Vorschau live: Redaktion erlaubt (wie die gespeicherte Vorschau)', $live($ed, ['blocks' => $neu, 'ziel' => "page:$pgId"], $csrfEd)->status === 200 && $ed->get('/admin/vorschau/impressum')->status === 200);
check('Vorschau live: ohne CSRF-Token 403', $b->post('/admin/vorschau-live', ['blocks' => $neu, 'ziel' => "page:$pgId"])->status === 403 && $b->post('/admin/vorschau-live', ['blocks' => $neu, 'ziel' => "page:$pgId", '_csrf' => 'falsch'])->status === 403);
check('Vorschau live: nur POST (GET gibt es nicht)', $b->get('/admin/vorschau-live')->status === 404);
$big = $J([['t' => 'text', 'text' => str_repeat('a', 20000)], ['t' => 'text', 'text' => str_repeat('b', 20000)]]) . str_repeat(' ', Blocks::MAX_JSON);
$rb = $live($b, ['blocks' => $big, 'ziel' => "page:$pgId"]);
check('Vorschau live: zu große Eingabe abgelehnt (413), ohne Rendern', $rb->status === 413 && !str_contains($rb->body, 'aaaa') && str_contains((string) ($rb->headers['Content-Security-Policy'] ?? ''), 'sandbox'));
check('Vorschau live: kaputtes JSON, fehlende Blöcke, falsches Ziel → Meldung ohne Absturz', $live($b, ['blocks' => '{kaputt', 'ziel' => "page:$pgId"])->status === 422 && $live($b, ['blocks' => '', 'ziel' => "page:$pgId"])->status === 400 && $live($b, ['blocks' => $neu, 'ziel' => 'x'])->status === 400 && $live($b, ['blocks' => $neu, 'ziel' => 'page:../1'])->status === 400 && $live($b, ['blocks' => $neu, 'ziel' => 'page:99999'])->status === 404);
$ru = $live($b, ['blocks' => $J([['t' => 'image', 'photo' => 999999, 'alt' => 'x']]), 'ziel' => "page:$pgId"]);
check('Vorschau live: unbekannte Foto-Nummer → Meldung, kein Bild, nichts gebaut', $ru->status === 422 && str_contains($ru->body, 'gibt es nicht') && !str_contains($ru->body, '/media/'));
$rx = $live($b, ['blocks' => $J([['t' => 'text', 'text' => '<img src=x onerror=alert(1)>'], ['t' => 'button', 'label' => 'k', 'href' => 'javascript:alert(1)']]), 'ziel' => "page:$pgId"]);
check('Vorschau live: Skript-Adressen und HTML in Blöcken kommen nicht roh in die Seite', !str_contains($rx->body, '<img src=x') && !str_contains($rx->body, 'href="javascript:'));
check('Vorschau live: Fehlerseiten tragen ebenfalls die strenge CSP', str_contains((string) ($ru->headers['Content-Security-Policy'] ?? ''), "default-src 'none'") && ($ru->headers['X-Frame-Options'] ?? '') === 'SAMEORIGIN');
check('Vorschau live: auch die Fehlfälle schreiben nichts', $snapshot() === $before);
// Ratenbegrenzung (300 je 15 Minuten und Konto)
$lim = null;
for ($i = 0; $i < 310 && $lim === null; $i++) {
    $x = $live($ed, ['blocks' => $J([['t' => 'text', 'text' => 'x']]), 'ziel' => "page:$pgId"], $csrfEd);
    if ($x->status === 429) {
        $lim = $x;
    }
}
check('Vorschau live: Ratenbegrenzung greift (429 mit Retry-After und sandbox), Administrator nicht betroffen', $lim !== null && isset($lim->headers['Retry-After']) && str_contains((string) ($lim->headers['Content-Security-Policy'] ?? ''), 'sandbox') && $live($b, ['blocks' => $neu, 'ziel' => "page:$pgId"])->status === 200);

// --- Editor-Seiten und Skripte ---
$pageHtml = $b->get("/admin/seiten/$pgId")->body;
check('Editor-Seite: Skripte, Quellen und Vorschau-Ziel eingebunden', str_contains($pageHtml, '/assets/js/page-material.js') && str_contains($pageHtml, '/assets/js/page-preview.js') && str_contains($pageHtml, 'data-gal-url="/admin/fotos/auswahl-galerien"') && str_contains($pageHtml, 'data-gal-photos-url="/admin/fotos/auswahl-galerie"') && str_contains($pageHtml, 'data-preview-url="/admin/vorschau-live"') && str_contains($pageHtml, 'data-preview-target="page:' . $pgId . '"'));
check('Startseiten-Editor: Ziel „home“', str_contains($b->get('/admin/startseite')->body, 'data-preview-target="home"'));
foreach (['page-material.js', 'page-preview.js', 'page-editor.js'] as $jsFile) {
    $js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/' . $jsFile);
    check("Skript $jsFile: kein innerHTML, kein eval, keine fremden Adressen", !preg_match('~innerHTML|outerHTML|insertAdjacentHTML|eval\(|document\.write~i', $js) && !preg_match('~(src|href)\s*=\s*[\'"]https?://~i', $js));
}
$jsP = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-preview.js');
check('Vorschau-Skript: Rahmen ohne Skripterlaubnis (sandbox nur allow-same-origin), kein allow-scripts/allow-top-navigation/allow-popups', str_contains($jsP, "setAttribute('sandbox', 'allow-same-origin')") && !preg_match('~allow-(scripts|top-navigation|popups|forms|modals)~', $jsP));
$jsM = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-material.js');
$jsE = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-editor.js');
check('Material-Skript: Weg ohne Maus („Wohin?“, Enter/Leertaste auf der Kachel)', str_contains($jsM, 'Wohin?') && str_contains($jsM, "e.key === 'Enter'") && str_contains($jsM, 'aria-label'));
check('Editor-Skript: nur eigene Zieh-Daten gelten (Kennung), Fremddateien werden ignoriert', str_contains($jsE, "indexOf.call(e.dataTransfer.types || [], MIME)"));

// --- Prüfung Fable (Bericht 43): Angriffsversuche gegen die Vorschau-Route und die Auswahl-Routen ---
$db->run("UPDATE photos SET original_name = 'GEHEIMNAME.jpg', original_path = 'orig/GEHEIMPFAD', exif_json = '{\"gps\":\"48.137,11.575\"}', place = 'GEHEIMORT', title = ?, alt_text = ?, caption = ? WHERE id = ?", ['T"><img src=x onerror=alert(1)>', 'A"><svg onload=alert(2)>', 'C<script>alert(3)</script>', $ids[5]]);
$atk = $live($b, ['blocks' => $J([
    ['t' => 'heading', 'level' => 2, 'text' => '<img src=x onerror=alert(1)> [l](javascript:alert(1)) [d](data:text/html,x) [e](&#106;avascript:alert(1))'],
    ['t' => 'text', 'text' => "[b](/x\"onmouseover=\"alert(1)) [c](https://e.com/\"><script>alert(1)</script>)\n\n> <b>q</b>"],
    ['t' => 'quote', 'text' => '<script>alert(2)</script>', 'by' => '"><script>alert(3)</script>'],
    ['t' => 'image', 'photo' => $ids[5], 'alt' => '"><script>alert(4)</script>', 'caption' => '<img src=x onerror=1>', 'href' => '/ok'],
    ['t' => 'gallery', 'photos' => [['photo' => $ids[5], 'alt' => '"onfocus="alert(5)', 'decor' => false]], 'cols' => 3],
    ['t' => 'columns', 'left' => [['t' => 'text', 'text' => '<script>l</script>']], 'right' => [['t' => 'button', 'label' => '<b>L</b>', 'href' => 'https://e.com/']]],
]), 'ziel' => "page:$pgId"]);
$noTags = preg_replace('~<script src="[^"]*"[^>]*></script>~', '', $atk->body) ?? '';
check('Fable: bösartige Blöcke → keine eigene Skript- oder Ereignis-Markierung, kein javascript:/data:-Link, kein ausgebrochenes Attribut', $atk->status === 200 && !preg_match('~<script|<img src=x|<svg~i', $noTags) && !preg_match('~href\s*=\s*"\s*(javascript|data|vbscript):~i', $atk->body) && !preg_match('~<[a-z][^>]*\s(on[a-z]+)\s*=~i', preg_replace('~="[^"]*"~', '=""', $atk->body) ?? ''));
foreach (['javascript:alert(1)', ' javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html;base64,PHNjcmlwdD4=', '//evil.example', '/\\evil.example', 'vbscript:x', '&#106;avascript:1'] as $badHref) {
    check('Fable: Knopf-Adresse ' . json_encode($badHref) . ' wird abgelehnt', $live($b, ['blocks' => $J([['t' => 'button', 'label' => 'K', 'href' => $badHref]]), 'ziel' => "page:$pgId"])->status === 422);
}
$leak = $live($b, ['blocks' => $J([['t' => 'gallery', 'photos' => [['photo' => $ids[5], 'alt' => '', 'decor' => true]], 'cols' => 3], ['t' => 'image', 'photo' => $ids[5], 'alt' => 'a']]), 'ziel' => "page:$pgId"]);
check('Fable: Antwort enthält nie Originalname, Originalpfad, GPS/EXIF, Ort', $leak->status === 200 && !preg_match('~GEHEIMNAME|GEHEIMPFAD|GEHEIMORT|48\.137|orig/~', $leak->body));
$pw = new TestBrowser($m['app'], $m['config']);
$pwS = $svc->sessions->create(1, 'password_ok', ClientIp::forms('203.0.113.7')['prefix'], 'x');
$pw->jar[$svc->cookies->name('lk_sid')] = $pwS['id'];
$xr = $pw->post('/admin/vorschau-live', ['blocks' => $J([['t' => 'text', 'text' => 'GEHEIM']]), 'ziel' => "page:$pgId", '_csrf' => (string) ($pwS['csrf_token'] ?? 'x')]);
check('Fable: Sitzung nur nach dem Passwort (ohne zweiten Schritt) wird abgewiesen', $xr->status === 303 && !str_contains($xr->body, 'GEHEIM') && $pw->get('/admin/fotos/auswahl')->status === 303);
check('Fable: Zahlen in Blöcken: riesige Werte werden begrenzt, ungültige ergeben die Vorgabe, keine Warnung', Blocks::parse($J([['t' => 'galleries', 'title' => '', 'limit' => 1.5e308], ['t' => 'galleries', 'title' => '', 'limit' => '7'], ['t' => 'galleries', 'title' => '', 'limit' => [1]]]), null, true) === [['t' => 'galleries', 'title' => '', 'limit' => 24], ['t' => 'galleries', 'title' => '', 'limit' => 7], ['t' => 'galleries', 'title' => '', 'limit' => 0]]);
// Auswahl-Routen: Einspritzungs- und Randwerte
$inj = $gj($b, '/admin/fotos/auswahl?n=60&q=' . rawurlencode("' OR 1=1 --"));
check('Fable: Auswahl: SQL-Zeichen in der Suche ergeben keine Treffer (nur Text)', $inj['status'] === 200 && $inj['d']['rows'] === []);
check('Fable: Auswahl: % und _ in der Suche sind keine Platzhalter', $gj($b, '/admin/fotos/auswahl?n=60&q=' . rawurlencode('%'))['d']['rows'] === [] && $gj($b, '/admin/fotos/auswahl-galerien?q=' . rawurlencode('%'))['d']['rows'] === []);
check('Fable: Auswahl: Seitenzahl unsinnig (-5, abc, riesig) → 200 und leer/erste Seite', $gj($b, '/admin/fotos/auswahl?seite=-5')['status'] === 200 && $gj($b, '/admin/fotos/auswahl?seite=abc')['status'] === 200 && $gj($b, '/admin/fotos/auswahl?seite=99999999999999999999')['d']['rows'] === [] && $gj($b, '/admin/fotos/auswahl-galerien?seite=99999999999999999999')['d']['rows'] === []);
check('Fable: Auswahl: unbekannte oder ungültige Galerie-Nummer → 404 ohne Daten', $gj($b, '/admin/fotos/auswahl-galerie/999999')['status'] === 404 && $b->get('/admin/fotos/auswahl-galerie/abc')->status === 404);
$ar = $b->get('/admin/fotos/auswahl?n=60&q=' . rawurlencode('Foto 5'))->body . $b->get('/admin/fotos/auswahl-galerien')->body . $b->get('/admin/fotos/auswahl-galerie/' . $g1)->body;
check('Fable: Auswahl-Antworten enthalten nie Originalpfad, Sicherungssumme, Token, EXIF', !preg_match('~GEHEIMPFAD|orig/|"sha|"token|exif|48\.137~i', $ar) && str_starts_with($b->get('/admin/fotos/auswahl')->headers['Content-Type'] ?? '', 'application/json'));
