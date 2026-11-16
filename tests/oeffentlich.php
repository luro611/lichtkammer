<?php
declare(strict_types=1);

// Schritt 5: öffentliche Seite (Seiten, Markdown, Rendern, Veröffentlichen, Rückgängig, Ausliefern, Vorschau).

use Lichtkammer\Auth\Schema;
use Lichtkammer\Site\Builder;
use Lichtkammer\Site\Markdown;
use Lichtkammer\Site\Renderer;

require_once __DIR__ . '/support.php';

$f5 = lk_new_tmp($tmp, 'oeffentlich');

// --- Markdown: maskiert alles, nur sichere Links ---
$md = Markdown::render("## Titel <script>x</script>\n\nEin **fetter** und *kursiver* Text mit [Link](https://a.de/?x=1&y=2) und [böse](javascript:alert(1)) und [auch](//evil.de).\n\n- eins\n- zwei\n\n1. a\n2. b\n\n> Zitat\n\n---\n\n<img src=x onerror=alert(1)>");
check('Markdown: Überschrift, fett, kursiv, Listen, Zitat, Trennlinie', str_contains($md, '<h2>Titel') && str_contains($md, '<strong>fetter</strong>') && str_contains($md, '<em>kursiver</em>')
    && str_contains($md, '<ul><li>eins</li><li>zwei</li></ul>') && str_contains($md, '<ol><li>a</li><li>b</li></ol>') && str_contains($md, '<blockquote>') && str_contains($md, '<hr>'));
check('Markdown: rohes HTML wird maskiert', !str_contains($md, '<script') && !str_contains($md, '<img') && str_contains($md, '&lt;script&gt;'));
check('Markdown: https-Link bleibt, mit rel; javascript: und // verworfen', str_contains($md, '<a href="https://a.de/?x=1&amp;y=2" rel="noopener noreferrer">Link</a>') && !str_contains($md, 'javascript:') && !str_contains($md, 'evil.de'));
check('Markdown: mailto und interne Adresse erlaubt', str_contains(Markdown::render('[m](mailto:a@b.de) [i](/galerien/)'), 'href="mailto:a@b.de"') && str_contains(Markdown::render('[i](/galerien/)'), 'href="/galerien/"'));
check('Markdown: Anführungszeichen im Link brechen nicht aus', !str_contains(Markdown::render('[x](https://a.de/"onmouseover="y)'), 'onmouseover="'));

// --- Seitenverhältnis-Klassen ---
check('Raster: Klasse aus Seitenverhältnis, begrenzt auf 0,5 bis 3,0', Renderer::ratioClass(['width' => 3000, 'height' => 2000]) === 'r150' && Renderer::ratioClass(['width' => 9000, 'height' => 1000]) === 'r300'
    && Renderer::ratioClass(['width' => 100, 'height' => 1000]) === 'r50');
$siteCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/site.css');
check('Raster: Datei kennt alle Klassen r50 bis r300', str_contains($siteCss, '.r50{--ar:0.5}') && str_contains($siteCss, '.r150{--ar:1.5}') && str_contains($siteCss, '.r300{--ar:3}'));
check('Theme: CSS und Skripte ohne fremde Adressen', !preg_match('~https?://|@import~i', $siteCss) && !preg_match('~https?://|eval\(|innerHTML~', (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/site.js')));

// --- Umgebung ---
$m = lk_make($f5 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];

check('Migration 4 bis 6: Tabellen pages, site_settings, redirects und import_map', Schema::version($db) === count(Schema::migrations()) && $db->value("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('redirects','import_map')") === 2 && $db->value("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('pages','site_settings')") === 2);
check('Öffentlich: vor dem ersten Veröffentlichen 503, /health läuft', $anon->get('/')->status === 503 && $anon->get('/health')->status === 200);
foreach (['/admin/seiten', '/admin/seiten/1', '/admin/einstellungen', '/admin/veroeffentlichen', '/admin/vorschau', '/admin/vorschau/galerien', '/admin/vorschau/galerien/x'] as $path) {
    check("Zugriff: $path ohne Anmeldung → Anmeldeseite", $anon->get($path)->status === 303);
}
foreach (['/admin/seiten', '/admin/seiten/1/speichern', '/admin/seiten/1/loeschen', '/admin/einstellungen', '/admin/veroeffentlichen', '/admin/veroeffentlichen/zuruecksetzen'] as $path) {
    check("Zugriff: POST $path ohne Anmeldung abgewiesen", $anon->post($path, ['title' => 'x'])->status === 303);
    check("Zugriff: POST $path ohne CSRF-Token abgelehnt", $b->post($path, ['title' => 'x'])->status === 403);
}

$mk = function (int $w, int $h) use ($b, $H): int {
    $r = lk_upload($b, "geheim-name-$w.jpg", lk_jpeg($w, $h));
    $b->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], $H);
    return (int) $r['photo_id'];
};
$p1 = $mk(900, 600);
$p2 = $mk(600, 900);
$p3 = $mk(800, 800);
$ph = $svc->catalog->addPhotographer('Anna Beispiel');
$svc->catalog->update($p1, ['title' => 'Hafen <i>', 'alt_text' => 'Boote im Hafen', 'place' => 'Lissabon', 'year' => '2019', 'photographer_id' => (string) $ph, 'caption' => 'Abendlicht']);
$svc->catalog->update($p2, ['title' => 'Treppe', 'alt_text' => 'Eine Treppe', 'place' => 'Porto', 'year' => '2021']);
$db->run("UPDATE photos SET exif_json = ?, has_gps = 1 WHERE id = ?", [json_encode(['camera' => 'KameraX', 'lens' => 'LinseY', 'aperture' => 'f/2.8', 'exposure' => '1/250 s', 'iso' => '400', 'focal_length' => '35 mm', 'gps' => '52.5,13.4', 'software' => 'GEHEIMSOFT']), $p1]);

$g1 = $svc->galleries->create('Island 2022', "Beschreibung mit **Fett**");
$g2 = $svc->galleries->create('Entwurfsgalerie');
$svc->galleries->addPhotos($g1, [$p1, $p2]);
$svc->galleries->addPhotos($g2, [$p3]);
$slug1 = (string) $db->value('SELECT slug FROM galleries WHERE id = ?', [$g1]);
$slug2 = (string) $db->value('SELECT slug FROM galleries WHERE id = ?', [$g2]);
$svc->galleries->setCover($g1, $p2);
$st = $svc->galleries->setStatus($g1, 'published');
check('Vorbereitung: Galerie veröffentlicht markiert', $st['ok']);

// --- Seiten anlegen ---
$cr = $b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'impressum']);
check('Seite: aus Vorlage angelegt (Adresse impressum, Fußzeile, Entwurf)', $cr->status === 303 && $db->value("SELECT slug FROM pages WHERE id = 1") === 'impressum' && $db->value('SELECT nav FROM pages WHERE id = 1') === 'footer' && $db->value('SELECT status FROM pages WHERE id = 1') === 'draft');
$res = $b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Admin']);
check('Seite: reservierte Adresse bekommt Zusatz', $db->value('SELECT slug FROM pages WHERE id = 2') === 'admin-seite');
$b->post('/admin/seiten/2/loeschen', ['_csrf' => $csrf]);
check('Seite: löschen', (int) $db->value('SELECT COUNT(*) FROM pages') === 1);
$bad = $b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => '  ']);
check('Seite: leerer Titel abgelehnt', $bad->status === 400);
$sv = $b->post('/admin/seiten/1/speichern', ['_csrf' => $csrf, 'title' => 'Impressum', 'slug' => 'impressum', 'body' => "## Impressum\n\nLutz <b>Beispiel</b>", 'status' => 'published', 'nav' => 'footer']);
check('Seite: gespeichert und veröffentlicht markiert', $sv->status === 303 && $db->value('SELECT status FROM pages WHERE id = 1') === 'published');
$e1 = $b->get('/admin/seiten/1');
check('Seite: Bearbeiten-Formular maskiert den Text', $e1->status === 200 && str_contains($e1->body, '&lt;b&gt;Beispiel'));
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Leer']);
$leerId = (int) $db->value("SELECT id FROM pages WHERE title = 'Leer'");
$b->post("/admin/seiten/$leerId/speichern", ['_csrf' => $csrf, 'title' => 'Leer', 'slug' => 'leer', 'body' => '', 'status' => 'published', 'nav' => 'main']);

// --- Einstellungen ---
$b->post('/admin/einstellungen', ['_csrf' => $csrf, 'site_name' => 'br.foto', 'tagline' => 'Licht & <Schatten>', 'hero_photo_id' => (string) $p3]);
check('Einstellungen: gespeichert', $svc->settings->all()['site_name'] === 'br.foto' && $svc->settings->all()['hero_photo_id'] === $p3);

// --- Probelauf und Sperren ---
$plan = $svc->builder->plan();
check('Veröffentlichen: leere veröffentlichte Seite sperrt', $plan['errors'] !== [] && str_contains(implode(' ', $plan['errors']), 'Leer'));
$blocked = $b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
check('Veröffentlichen: bei Fehlern kein Ergebnis, Seite bleibt 503', $blocked->status === 409 && $anon->get('/')->status === 503 && $svc->builder->current() === null);
$b->post("/admin/seiten/$leerId/loeschen", ['_csrf' => $csrf]);
$plan = $svc->builder->plan();
check('Veröffentlichen: Änderungsliste nennt Neues mit Namen', $plan['errors'] === [] && in_array(['kind' => 'neu', 'label' => 'Galerie „Island 2022“'], $plan['changes'], true) && in_array(['kind' => 'neu', 'label' => 'Seite „Impressum“'], $plan['changes'], true));
check('Veröffentlichen: Entwurfsgalerie nicht in der Liste', !str_contains(json_encode($plan['changes'], JSON_UNESCAPED_UNICODE), 'Entwurfsgalerie'));
$pg = $b->get('/admin/veroeffentlichen');
check('Veröffentlichen: Seite zeigt Änderungen', $pg->status === 200 && str_contains($pg->body, 'Island 2022') && str_contains($pg->body, 'Jetzt veröffentlichen'));
check('Übersicht: Hinweis auf unveröffentlichte Seite', str_contains($b->get('/admin')->body, 'noch nicht veröffentlicht'));

// --- Vorschau mit Entwürfen ---
$pv = $b->get("/admin/vorschau/galerien/$slug2");
check('Vorschau: Entwurfsgalerie sichtbar, mit Leiste, noindex, nicht zwischenspeichern', $pv->status === 200 && str_contains($pv->body, 'Vorschau, noch nicht veröffentlicht') && str_contains($pv->body, 'noindex')
    && ($pv->headers['Cache-Control'] ?? '') === 'no-store' && str_contains($pv->headers['X-Robots-Tag'] ?? '', 'noindex'));
check('Vorschau: Links bleiben in der Vorschau', str_contains($b->get('/admin/vorschau')->body, 'href="/admin/vorschau/galerien/"'));
check('Vorschau: unbekannte Adresse 404', $b->get('/admin/vorschau/gibt-es-nicht')->status === 404 && $b->get('/admin/vorschau/galerien/nix')->status === 404);

// --- Veröffentlichen ---
$pub = $b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
check('Veröffentlichen: erfolgreich, Fassung aktiv', $pub->status === 200 && str_contains($pub->body, 'Veröffentlicht') && $svc->builder->current() !== null);
$rel1 = (string) $svc->builder->current();
check('Veröffentlichen: Protokolleintrag und Kette unversehrt', (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='site_published'") === 1 && $svc->audit->verify()['ok']);
check('Veröffentlichen: Datenbankkopie abgelegt', count(glob($m['config']->dataDir() . '/backups/lichtkammer-*.sqlite') ?: []) === 1);
$home = $anon->get('/');
$list = $anon->get('/galerien/');
$gal = $anon->get("/galerien/$slug1/");
check('Öffentlich: Startseite, Übersicht, Galerie erreichbar ohne Anmeldung', $home->status === 200 && $list->status === 200 && $gal->status === 200);
check('Öffentlich: Seitenname und Titelfoto-Text', str_contains($home->body, '<title>br.foto</title>') && str_contains($home->body, 'Licht &amp; &lt;Schatten&gt;') && str_contains($home->body, '<span class="dot">.</span>'));
check('Öffentlich: Hero-Foto aus Entwurfsgalerie wird nicht genommen, stattdessen Titelbild der ersten Galerie', preg_match('/<img src="\/media\/([^"]+)"[^>]*class="hero-pic"/', $home->body, $hm) === 1 && str_contains($hm[1], (string) $db->value('SELECT token FROM photos WHERE id = ?', [$p2])));
check('Öffentlich: Galerie zeigt Fotos mit Alt-Text, Seitenverhältnis-Klasse und Bildinfo', str_contains($gal->body, 'alt="Boote im Hafen"') && str_contains($gal->body, 'class="t r150"') && str_contains($gal->body, 'class="t r67"')
    && str_contains($gal->body, 'data-meta="Lissabon · 2019"') && str_contains($gal->body, 'data-photographer="Anna Beispiel"') && str_contains($gal->body, 'Hafen &lt;i&gt;'));
check('Öffentlich: picture mit WebP und JPEG, Breiten und Maße', str_contains($gal->body, '<source type="image/webp"') && str_contains($gal->body, 'srcset="/media/') && preg_match('/<img src="\/media\/[0-9a-f\/-]+\.jpg"[^>]* width="\d+" height="\d+"/', $gal->body) === 1);
check('Öffentlich: Beschreibung als Markdown', str_contains($gal->body, '<strong>Fett</strong>'));
check('Öffentlich: Entwurfsgalerie und ihr Foto fehlen überall', $anon->get("/galerien/$slug2/")->status === 404 && !str_contains($home->body . $list->body . $gal->body, 'Entwurfsgalerie')
    && !str_contains($list->body . $gal->body . $home->body, (string) $db->value('SELECT token FROM photos WHERE id = ?', [$p3])));
check('Öffentlich: Nicht freigegebenes fehlt (Original-Dateiname, GPS, Software, EXIF bei show_exif=0)', !str_contains($gal->body, 'geheim-name') && !str_contains($gal->body, '52.5') && !str_contains($gal->body, 'GEHEIMSOFT') && !str_contains($gal->body, 'KameraX') && !str_contains($gal->body, 'gps'));
check('Öffentlich: Impressum erreichbar, in der Fußzeile verlinkt, Text maskiert', ($imp = $anon->get('/impressum/'))->status === 200 && str_contains($imp->body, '&lt;b&gt;Beispiel') && str_contains($home->body, 'href="/impressum/"'));
check('Öffentlich: Marke luro in der Fußzeile, Sprunglink, Landmarken, Sprache', str_contains($home->body, 'class="luro" href="https://lurohome.de"') && str_contains($home->body, 'href="#inhalt"') && str_contains($home->body, '<main id="inhalt"') && str_contains($home->body, '<html lang="de">'));
check('Öffentlich: eine h1 je Seite', substr_count($home->body, '<h1') === 1 && substr_count($gal->body, '<h1') === 1 && substr_count($list->body, '<h1') === 1);
$all = $home->body . $list->body . $gal->body . $imp->body;
check('Öffentlich: ohne Inline-Style, Inline-Skript, Ereignisattribute', !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\son[a-z]+=~i', $all));
preg_match_all('~(?:src|href)="(https?://[^"]+)"~', $all, $ext);
check('Öffentlich: einzige fremde Adresse ist lurohome.de (sonst nur die eigene Basis-Adresse)', array_values(array_filter(array_unique($ext[1]), static fn (string $u): bool => !str_starts_with($u, 'https://lk.test/'))) === ['https://lurohome.de']);
check('Öffentlich: Sprung-Adresse der Kacheln verlinkt die große Datei (ohne Skript nutzbar)', preg_match('/<a id="foto-\d+" href="\/media\/[0-9a-f\/-]+-\d+\.jpg"/', $gal->body) === 1);
check('Öffentlich: Filter ab Start verborgen, Skript im Kopf mit defer', str_contains($list->body, 'data-filters hidden') && str_contains($list->body, 'site.js') && str_contains($list->body, 'defer'));
check('Öffentlich: Karten tragen Filterwerte', str_contains($list->body, 'data-jahre="2019 2021"') && str_contains($list->body, 'data-orte="lissabon porto"') && str_contains($list->body, 'data-fotografen="' . $ph . '"'));
check('Öffentlich: Titelbild der Galerie ist das gewählte (Foto 2)', preg_match('/<ul class="cards">.*?<picture>.*?<img src="\/media\/([^"]+)"/s', $home->body, $cm) === 1 && str_contains($cm[1], (string) $db->value('SELECT token FROM photos WHERE id = ?', [$p2])));
check('Öffentlich: Sitemap und robots mit absoluten Adressen', str_contains($anon->get('/sitemap.xml')->body, '<loc>https://lk.test/galerien/' . $slug1 . '/</loc>') && str_contains($anon->get('/robots.txt')->body, 'Sitemap: https://lk.test/sitemap.xml'));
check('Öffentlich: Entwurfsseiten (Leer) nicht erreichbar', $anon->get('/leer/')->status === 404);

// --- Ausliefern ---
$r301 = $anon->get('/impressum');
check('Ausliefern: Adresse ohne Schrägstrich → 301 mit Schrägstrich', $r301->status === 301 && $r301->headers['Location'] === '/impressum/');
$etag = $home->headers['ETag'] ?? '';
check('Ausliefern: ETag und Cache-Control', $etag !== '' && str_contains($home->headers['Cache-Control'], 'max-age=300'));
check('Ausliefern: If-None-Match → 304 ohne Inhalt', ($nm = $anon->request('GET', '/', [], ['HTTP_IF_NONE_MATCH' => $etag]))->status === 304 && $nm->body === '');
$nf = $anon->get('/gibt-es-nicht/');
check('Ausliefern: unbekannt → 404 im Theme', $nf->status === 404 && str_contains($nf->body, 'Nicht <em>gefunden</em>'));
check('Ausliefern: Pfad-Tricks → 404, nie eine Datei außerhalb', $anon->get('/../data/lichtkammer.sqlite')->status === 404 && $anon->get('/%2e%2e/manifest.json')->status === 404 && $anon->get('/.lock')->status === 404 && $anon->get('/manifest.json')->status === 404);
$evil = $anon->get('/x%0d%0aSet-Cookie:%20a=b');
$bs = $anon->get('/%5Cevil');
check('Ausliefern: Steuerzeichen und Backslash in der Adresse → 404, keine Weiterleitung', $evil->status === 404 && !isset($evil->headers['Location']) && $bs->status === 404 && !isset($bs->headers['Location']));
mkdir($f5 . '/a/data/site/building-20200101-000000-abcd/x', 0700, true);
file_put_contents($f5 . '/a/data/site/building-20200101-000000-abcd/x/index.html', 'rest');
check('Veröffentlichen: Reste eines abgebrochenen Laufs werden beim nächsten Lauf entfernt', $svc->builder->publish(1)['ok'] && !is_dir($f5 . '/a/data/site/building-20200101-000000-abcd'));
check('Ausliefern: POST auf öffentliche Adresse → 404', $anon->post('/', ['a' => 'b'])->status === 404);
// ohne Datenbank und ohne Schlüssel: nur Konfiguration und eine Datei
$cfgNoKey = lk_config($f5 . '/a', ['LK_APP_KEY' => '']);
$bare = new TestBrowser(new \Lichtkammer\App($cfgNoKey), $cfgNoKey);
check('Ausliefern: läuft ohne Schlüssel und ohne Datenbankzugriff', $bare->get('/')->status === 200 && $bare->get('/galerien/')->status === 200);

// --- Zweite Fassung, Rückgängig ---
$m['clock']->advance(120);
$svc->galleries->update($g1, 'Island 2022', 'Neu', true, null);
$svc->catalog->trash([$p2]);
$trashLive = $b->post('/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'papierkorb', 'ids' => [(string) $p1], 'zurueck' => '/admin/fotos']);
check('Papierkorb: Hinweis, wenn das Foto live sichtbar ist', str_contains($trashLive->headers['Location'] ?? '', 'm=trashed_live'));
$svc->catalog->restore([$p1]);
$db->run('UPDATE photos SET exif_json = ? WHERE id = ?', [json_encode(['camera' => 'KameraX', 'lens' => 'LinseY', 'aperture' => 'f/2.8', 'exposure' => '1/250 s', 'iso' => '400', 'focal_length' => '35 mm', 'gps' => '52.5,13.4', 'software' => 'GEHEIMSOFT']), $p1]); // Wiederherstellen liest EXIF neu
$plan2 = $svc->builder->plan();
check('Änderungsliste: geänderte Galerie erkannt', in_array(['kind' => 'geändert', 'label' => 'Galerie „Island 2022“'], $plan2['changes'], true));
$pub2 = $svc->builder->publish(1);
$rel2 = $pub2['id'];
check('Fassung 2: aktiv, Fassung 1 bleibt als Ordner', $pub2['ok'] && $svc->builder->current() === $rel2 && is_dir($f5 . '/a/data/site/releases/' . $rel1));
$gal2 = $anon->get("/galerien/$slug1/");
check('Fassung 2: EXIF jetzt freigegeben (Kamera, Objektiv, Einstellungen), GPS und Software weiter nicht', str_contains($gal2->body, 'data-gear="KameraX · LinseY"') && str_contains($gal2->body, 'data-settings="f/2.8 · 1/250 s · ISO 400 · 35 mm"')
    && !str_contains($gal2->body, '52.5') && !str_contains($gal2->body, 'GEHEIMSOFT'));
check('Fassung 2: Papierkorb-Foto fehlt, Titelbild fällt auf das erste zurück', !str_contains($gal2->body, (string) $db->value('SELECT token FROM photos WHERE id = ?', [$p2])) && str_contains($gal2->body, 'Boote im Hafen'));
check('Fassung 2: Beschreibung neu', str_contains($gal2->body, 'Neu'));
check('Rückgängig: Fassung 1 zurück (alter Text, kein EXIF)', $svc->builder->rollback($rel1) && $svc->builder->current() === $rel1 && !str_contains($anon->get("/galerien/$slug1/")->body, 'KameraX') && str_contains($anon->get("/galerien/$slug1/")->body, 'Fett'));
check('Rückgängig: Aktive oder unbekannte Fassung wird abgelehnt', !$svc->builder->rollback($rel1) && !$svc->builder->rollback('20000101-000000-abcd') && !$svc->builder->rollback('../etc'));
$rb = $b->post('/admin/veroeffentlichen/zuruecksetzen', ['_csrf' => $csrf, 'fassung' => $rel2]);
check('Rückgängig im Admin: wieder Fassung 2, protokolliert', $rb->status === 200 && $svc->builder->current() === $rel2 && (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='site_rolled_back'") === 1);
check('Zeiger: Datei current enthält nur eine gültige Kennung, keine halben Dateien', Builder::validId(trim((string) file_get_contents($f5 . '/a/data/site/current'))) && glob($f5 . '/a/data/site/*.tmp') === [] && glob($f5 . '/a/data/site/building-*') === []);

// --- Sperre, Aufräumen ---
$lockFile = fopen($f5 . '/a/data/site/.lock', 'c');
flock($lockFile, LOCK_EX);
$busy = $svc->builder->publish(1);
check('Sperre: gleichzeitiges Veröffentlichen wird abgewiesen', !$busy['ok'] && str_contains($busy['error'], 'anderes Veröffentlichen') && $svc->builder->current() === $rel2);
flock($lockFile, LOCK_UN);
fclose($lockFile);
for ($i = 0; $i < 6; $i++) {
    $m['clock']->advance(61);
    $svc->builder->publish(1);
}
$dirs = glob($f5 . '/a/data/site/releases/*', GLOB_ONLYDIR) ?: [];
check('Aufräumen: höchstens fünf Fassungen, die aktive bleibt', count($dirs) === 5 && is_dir($f5 . '/a/data/site/releases/' . $svc->builder->current()));
check('Sicherung: höchstens zehn Datenbankkopien', count(glob($f5 . '/a/data/backups/lichtkammer-*.sqlite') ?: []) <= 10);

// --- Alt-Text-Pflicht und fehlende Dateien beim Veröffentlichen ---
$db->run("UPDATE photos SET alt_text = '' WHERE id = ?", [$p1]);
$pl = $svc->builder->plan();
check('Alt-Text-Pflicht: fehlender Alt-Text einer veröffentlichten Galerie sperrt', $pl['errors'] !== [] && str_contains(implode(' ', $pl['errors']), 'Alt-Text') && !$svc->builder->publish(1)['ok']);
check('Alt-Text-Pflicht: in der Vorschau nur Hinweis', ($b->get("/admin/vorschau/galerien/$slug1")->status === 200));
$db->run("UPDATE photos SET alt_text = 'Boote im Hafen' WHERE id = ?", [$p1]);

// --- Dashboard ---
$svc->galleries->update($g1, 'Island 2022', 'Nochmal neu', true, null);
check('Übersicht: Hinweis auf unveröffentlichte Änderungen', str_contains($b->get('/admin')->body, 'unveröffentlichte Änderung'));
check('Navigation: Seiten und Veröffentlichen verlinkt', str_contains($b->get('/admin')->body, 'href="/admin/seiten"') && str_contains($b->get('/admin')->body, 'href="/admin/veroeffentlichen"'));
check('Admin-Seiten laden: Seitenliste, Einstellungen, Veröffentlichen', $b->get('/admin/seiten')->status === 200 && str_contains($b->get('/admin/einstellungen')->body, 'br.foto') && $b->get('/admin/veroeffentlichen')->status === 200);

// --- Menü, Rückmeldung, 404 ohne Abmelden ---
$dash = $b->get('/admin')->body;
check('Menü: Gruppen Inhalte und Verwaltung, Einstellungen sichtbar, Veröffentlichen als Knopf', substr_count($dash, '<details class="navgroup"') === 2 && str_contains($dash, 'href="/admin/einstellungen"')
    && str_contains($dash, 'class="btn small publish" href="/admin/veroeffentlichen"') && str_contains($dash, 'href="/admin/papierkorb"') && str_contains($dash, 'href="/admin/account"'));
check('Menü: Konten nur für Administratoren, Marke führt in die Verwaltung', str_contains($dash, 'href="/admin/users"') && str_contains($dash, 'class="brand" href="/admin"'));
check('Menü: aktuelle Seite markiert, Gruppe als aktiv', str_contains($b->get('/admin/seiten')->body, '<details class="navgroup" data-active>') && str_contains($b->get('/admin/einstellungen')->body, 'href="/admin/einstellungen" aria-current="page"'));
$nf = $b->get('/admin/seiten/9999');
check('404 im Admin: bekannte Seite fehlt → Fehlerseite im Rahmen, Abmelden-Knopf noch da', $nf->status === 404 && str_contains($nf->body, 'action="/admin/logout"'));
check('404 im Admin: Sitzung bleibt bestehen', $b->get('/admin')->status === 200 && $b->get('/admin/seiten')->status === 200);
$unk = $b->get('/admin/gibt-es-nicht');
check('404 im Admin: unbekannte Adresse → Fehlerseite im Rahmen, Sitzung bleibt', $unk->status === 404 && str_contains($unk->body, 'action="/admin/logout"') && $b->get('/admin')->status === 200);
$unkPost = $b->get('/admin/seiten/1/speichern');
check('404 im Admin: GET auf reine POST-Adresse → Fehlerseite, Sitzung bleibt', $unkPost->status === 404 && str_contains($unkPost->body, 'action="/admin/logout"') && $b->get('/admin')->status === 200);
check('404 im Admin: ohne Anmeldung nur schlichtes 404', $anon->get('/admin/gibt-es-nicht')->status === 404 && !str_contains($anon->get('/admin/gibt-es-nicht')->body, '<html'));
$sm = $b->post('/admin/seiten/9999/speichern', ['_csrf' => $csrf, 'title' => 'x', 'slug' => 'x', 'body' => '', 'status' => 'draft', 'nav' => 'none']);
check('404 im Admin: Speichern einer fehlenden Seite → 404, Sitzung bleibt', $sm->status === 404 && $b->get('/admin')->status === 200);
$c1 = $b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'fotografen']);
$fid = (int) $db->value("SELECT id FROM pages WHERE slug = 'fotografen'");
check('Seite anlegen (Vorlage Fotografen): Weiterleitung auf die neue Seite, die es gibt', $c1->status === 303 && ($c1->headers['Location'] ?? '') === "/admin/seiten/$fid?m=pagecreated" && $b->get("/admin/seiten/$fid?m=pagecreated")->status === 200);
$c2 = $b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Fotografen']);
$fid2 = (int) $db->value("SELECT id FROM pages WHERE slug = 'fotografen-2'");
check('Seite anlegen (leer, gleicher Titel): eigene Adresse, Seite erreichbar', $c2->status === 303 && $fid2 > $fid && $b->get($c2->headers['Location'] ?? '/x')->status === 200);
$ed = $b->get("/admin/seiten/$fid?m=pagecreated")->body;
check('Anlegen: deutliche Bestätigung oben', str_contains($ed, 'class="notice ok" role="status">Seite angelegt'));
$sv = $b->post("/admin/seiten/$fid/speichern", ['_csrf' => $csrf, 'title' => 'Fotografen', 'slug' => 'fotografen', 'body' => 'Text', 'status' => 'draft', 'nav' => 'main']);
$after = $b->get($sv->headers['Location'] ?? '/x')->body;
check('Speichern: Bestätigung „Gespeichert“ deutlich, mit Wegen danach (Vorschau, Liste, Veröffentlichen) und Entwurfshinweis',
    $sv->status === 303 && str_contains($after, 'class="notice ok" role="status">Gespeichert') && str_contains($after, 'Vorschau ansehen') && str_contains($after, 'href="/admin/seiten">Zur Liste') && str_contains($after, 'Entwurf'));
$sl = $b->post("/admin/seiten/$fid/speichern", ['_csrf' => $csrf, 'title' => 'Fotografen', 'slug' => 'fotografen', 'body' => 'Text', 'status' => 'published', 'nav' => 'main', 'weiter' => 'liste']);
check('Speichern und zur Liste: Weiterleitung auf die Liste mit Bestätigung', ($sl->headers['Location'] ?? '') === '/admin/seiten?m=page' && str_contains($b->get('/admin/seiten?m=page')->body, 'class="notice ok"'));
check('Speichern: Knöpfe „Speichern“ und „Speichern und zur Liste“ im Formular', str_contains($b->get("/admin/seiten/$fid")->body, 'name="weiter" value="liste"'));
$b->post("/admin/seiten/$fid/loeschen", ['_csrf' => $csrf]);
$b->post("/admin/seiten/$fid2/loeschen", ['_csrf' => $csrf]);

// --- Kontrast (WCAG AA, 4,5:1) der benutzten Farbpaare, hell und dunkel ---
$lum = static function (string $hex): float {
    $c = array_map(static fn (string $h): float => ($v = hexdec($h) / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, str_split(ltrim($hex, '#'), 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$ratio = static fn (string $a, string $b): float => (max($lum($a), $lum($b)) + 0.05) / (min($lum($a), $lum($b)) + 0.05);
$sets = ['hell' => ['bg' => '#f5f2ed', 'surface' => '#fffdf9', 'soft' => '#eee8df', 'ink' => '#242321', 'muted' => '#6b675f', 'link' => '#a7472c'],
    'dunkel' => ['bg' => '#1d1b1a', 'surface' => '#282523', 'soft' => '#352e2a', 'ink' => '#f6f0e8', 'muted' => '#b8aea4', 'link' => '#f09a76']];
foreach ($sets as $name => $c) {
    $ok = true;
    foreach ([['ink', 'bg'], ['ink', 'surface'], ['ink', 'soft'], ['muted', 'bg'], ['muted', 'surface'], ['link', 'bg'], ['link', 'surface']] as [$fg, $bgk]) {
        $ok = $ok && $ratio($c[$fg], $c[$bgk]) >= 4.5;
    }
    check("Kontrast $name: Text, Gedämpft und Links mindestens 4,5:1", $ok);
    check("Kontrast $name: Farben stehen so im Theme", str_contains($siteCss, $c['bg']) && str_contains($siteCss, $c['muted']) && str_contains($siteCss, $c['link']));
}
