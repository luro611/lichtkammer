<?php
declare(strict_types=1);

// Schritt 7: Umzug von WordPress (Importpaket prüfen, Foto für Foto übernehmen, Galerien/Seiten, Weiterleitungen).
// Alle Namen und Inhalte sind erfunden.

use Lichtkammer\Import\Importer;
use Lichtkammer\Site\Blocks;

require_once __DIR__ . '/support.php';

$f7 = lk_new_tmp($tmp, 'import');
$imp = $f7 . '/import';
$m = lk_make($f7 . '/a', ['LK_IMPORT_DIR' => $imp]);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];

// --- Prüfung des Pakets ohne Datenbank ---
$bad = static function (array $mod): ?string {
    $base = ['format' => 'lichtkammer-import', 'version' => 1, 'photos' => [], 'galleries' => [], 'pages' => [], 'files' => [], 'redirects' => []];
    try {
        Importer::parse((string) json_encode(array_replace($base, $mod)));
    } catch (\Lichtkammer\Media\MediaException $e) {
        return $e->getMessage();
    }
    return null;
};
$sha = str_repeat('a', 64);
check('Import: fremdes JSON und falsche Version abgelehnt', $bad(['format' => 'x']) !== null && $bad(['version' => 2]) !== null);
foreach (['../etc/passwd', 'bilder/../x.jpg', '/bilder/x.jpg', 'bilder/a b.jpg', 'bilder/.htaccess', 'andere/x.jpg', 'bilder/x/y.jpg', 'bilder/'] as $path) {
    check("Import: Dateipfad „{$path}“ abgelehnt", $bad(['photos' => [['wp' => 1, 'file' => $path, 'sha256' => $sha]]]) !== null);
}
check('Import: ungültige Prüfsumme und doppelte Nummer abgelehnt', $bad(['photos' => [['wp' => 1, 'file' => 'bilder/a.jpg', 'sha256' => 'xyz']]]) !== null
    && $bad(['photos' => [['wp' => 1, 'file' => 'bilder/a.jpg', 'sha256' => $sha], ['wp' => 1, 'file' => 'bilder/b.jpg', 'sha256' => $sha]]]) !== null);
check('Import: Datei muss .pdf sein und im Ordner dateien liegen', $bad(['files' => [['name' => 'x.php', 'file' => 'dateien/x.php', 'sha256' => $sha]]]) !== null
    && $bad(['files' => [['name' => 'x.pdf', 'file' => 'bilder/x.pdf', 'sha256' => $sha]]]) !== null && $bad(['files' => [['name' => 'x.pdf', 'file' => 'dateien/x.pdf', 'sha256' => $sha]]]) === null);
check('Import: Galerie ohne Titel abgelehnt', $bad(['galleries' => [['wp' => 1, 'title' => ' ']]]) !== null);
check('Import: alte Adressen werden normalisiert, Unzulässiges verworfen', Importer::normPath('/Featured-Content/Island-2022/') === '/featured-content/island-2022'
    && Importer::normPath('https://x.de/a/b/?x=1') === '/a/b' && Importer::normPath('/a%20b/') === null && Importer::normPath('/../x') === null && Importer::normPath('//x') === null && Importer::normPath('x') === null && Importer::normPath('/') === '/');

// --- Admin: ohne Paket ---
check('Import: nur mit Anmeldung erreichbar', $anon->get('/admin/import')->status === 303 && $anon->post('/admin/import/schritt', [])->status !== 200);
$none = $b->get('/admin/import');
check('Import: ohne Paket Anleitung mit Importordner, Ordner ist für Besucher gesperrt', $none->status === 200 && str_contains($none->body, 'Noch kein Paket') && str_contains($none->body, htmlspecialchars($imp))
    && is_file($imp . '/.htaccess') && str_contains((string) file_get_contents($imp . '/.htaccess'), 'denied'));
check('Import: Schritt ohne Paket liefert Fehler', $b->request('POST', '/admin/import/schritt', [], $H)->status === 400);

// --- Paket bauen ---
@mkdir($imp . '/bilder', 0777, true);
@mkdir($imp . '/dateien', 0777, true);
$jpgA = lk_jpeg(1200, 800);
$jpgB = lk_jpeg(800, 1200);
$jpgC = lk_jpeg(1000, 1000);
$files = ['101-anna.jpg' => $jpgA, '102-ben.jpg' => $jpgB, '103-hafen.jpg' => $jpgC, '104-kopie.jpg' => $jpgA, '105-boese.jpg' => "<?php echo 'x';", '106-kaputt.jpg' => lk_jpeg(700, 500)];
foreach ($files as $n => $bytes) {
    file_put_contents($imp . '/bilder/' . $n, $bytes);
}
$pdf = "%PDF-1.4\n% Test\n";
file_put_contents($imp . '/dateien/flyer.pdf', $pdf);
$ph = static fn (int $wp, string $file, ?string $bytes, array $x = []): array => $x + ['wp' => $wp, 'file' => 'bilder/' . $file, 'sha256' => hash('sha256', (string) $bytes), 'name' => preg_replace('/^\d+-/', '', $file),
    'alt' => '', 'title' => '', 'caption' => '', 'photographer' => null, 'place' => '', 'year' => null, 'used' => true];
$pkg = [
    'format' => 'lichtkammer-import', 'version' => 1, 'source' => 'https://alt.example', 'created' => 1, 'hero_wp' => 103,
    'photographers' => ['Anna Test', 'Ben Test'],
    'photos' => [
        $ph(101, '101-anna.jpg', $jpgA, ['alt' => 'Anna am Meer', 'title' => 'Meerblick', 'photographer' => 'Anna Test', 'place' => 'Island', 'year' => 2022]),
        $ph(102, '102-ben.jpg', $jpgB, ['photographer' => 'Ben Test', 'place' => 'Island', 'year' => 2022]),
        $ph(103, '103-hafen.jpg', $jpgC, ['caption' => 'Abend']),
        $ph(104, '104-kopie.jpg', $jpgA, ['title' => 'Kopie']),
        $ph(105, '105-boese.jpg', "<?php echo 'x';"),
        $ph(106, '106-kaputt.jpg', 'ganz anderer Inhalt'),
    ],
    'galleries' => [
        ['wp' => 900, 'title' => 'Island 2022', 'description' => "Aufnahmen mit Kamera X.\n\nMehr auf [Über uns](lk-page:ueber-uns).", 'photos' => [101, 102, 103, 104], 'cover' => 102, 'place' => 'Island', 'year' => 2022],
        ['wp' => 901, 'title' => 'Zweite Galerie', 'description' => '', 'photos' => [103], 'cover' => null, 'place' => '', 'year' => null],
    ],
    'pages' => [
        ['wp' => 10, 'slug' => 'ueber-uns', 'title' => 'Über uns', 'nav' => 'main', 'blocks' => [
            ['t' => 'heading', 'level' => 2, 'text' => 'Wer wir sind'],
            ['t' => 'text', 'text' => 'Siehe [Island](lk-gallery:900) und den [Flyer](lk-file:flyer.pdf).'],
            ['t' => 'image', 'wp' => 101, 'alt' => '', 'caption' => 'Bild <i>eins</i>'],
            ['t' => 'gallery', 'photos' => [['wp' => 102], ['wp' => 103], ['wp' => 999]], 'cols' => 3],
            ['t' => 'button', 'label' => 'Flyer (PDF)', 'href' => 'lk-file:flyer.pdf'],
            ['t' => 'image', 'wp' => 999, 'alt' => 'unbekannt'],
        ]],
        ['wp' => 11, 'slug' => 'impressum', 'title' => 'Impressum', 'nav' => 'footer', 'body' => "## Impressum\n\nZurück zu [Über uns](lk-page:ueber-uns)."],
    ],
    'files' => [['name' => 'flyer.pdf', 'file' => 'dateien/flyer.pdf', 'sha256' => hash('sha256', $pdf)]],
    'redirects' => [
        ['from' => '/featured-content/island-2022/', 'kind' => 'gallery', 'wp' => 900],
        ['from' => '/featured-content/zweite/', 'kind' => 'gallery', 'wp' => 901],
        ['from' => '/impressum/datenschutz/', 'kind' => 'page', 'slug' => 'impressum'],
        ['from' => '/portfolio/', 'kind' => 'galleries'],
        ['from' => '/home/', 'kind' => 'home'],
        ['from' => '/wp-content/uploads/2021/09/flyer.pdf', 'kind' => 'file', 'name' => 'flyer.pdf'],
        ['from' => '/impressum/', 'kind' => 'page', 'slug' => 'impressum'],
        ['from' => '/Böse Adresse/', 'kind' => 'home'],
    ],
];
$manifest = $imp . '/manifest.json';
file_put_contents($manifest, json_encode($pkg, JSON_UNESCAPED_UNICODE));
$origSha = hash_file('sha256', $imp . '/bilder/101-anna.jpg');

$pg = $b->get('/admin/import');
check('Import: Paket erkannt, Zahlen und Startknopf', $pg->status === 200 && str_contains($pg->body, '6 Fotos') && str_contains($pg->body, '2 Galerien') && str_contains($pg->body, '2 Seiten') && str_contains($pg->body, 'id="import-run"')
    && str_contains($pg->body, '/assets/js/import.js') && !str_contains($pg->body, 'Durchsicht</h2>'));
$early = $b->request('POST', '/admin/import/abschluss', [], $H);
check('Import: Abschluss vor den Fotos wird abgelehnt', $early->status === 400 && str_contains($early->body, 'noch nicht alle Fotos'));
check('Import: Paket löschen vor dem Abschluss wird abgelehnt', $b->post('/admin/import/loeschen', ['_csrf' => $csrf])->status === 400 && is_file($manifest));
check('Import: Schritt ohne CSRF-Token abgewiesen', $b->request('POST', '/admin/import/schritt')->status === 403);

// --- Foto für Foto ---
$res = [];
for ($i = 0; $i < 12; $i++) {
    $r = json_decode($b->request('POST', '/admin/import/schritt', [], $H)->body, true);
    if (!is_array($r) || ($r['done'] ?? false)) {
        $res[] = $r;
        break;
    }
    $res[$r['index']] = $r;
}
check('Import: sechs Schritte, danach „fertig“', count($res) === 7 && ($res[7]['done'] ?? false) === true && $res[1]['index'] === 1 && $res[6]['total'] === 6);
check('Import: gutes Foto „ok“, gleicher Inhalt „duplicate“, PHP-Datei und falsche Prüfsumme „failed“', $res[1]['status'] === 'ok' && $res[4]['status'] === 'duplicate' && $res[5]['status'] === 'failed' && $res[6]['status'] === 'failed'
    && str_contains($res[6]['message'], 'Prüfsumme') && str_contains($res[5]['message'], 'nicht erlaubt'));
$map = array_column($db->all("SELECT wp_id, local_id, note FROM import_map WHERE kind = 'photo'"), null, 'wp_id');
$p1 = (int) $map[101]['local_id'];
check('Import: drei verschiedene Fotos entstehen, Dublette zeigt auf dasselbe', (int) $db->value('SELECT COUNT(*) FROM photos') === 3 && (int) $map[104]['local_id'] === $p1 && str_starts_with((string) $map[104]['note'], 'Doppelt')
    && (int) $map[105]['local_id'] === 0 && str_starts_with((string) $map[105]['note'], 'Fehler:'));
$row1 = $db->one('SELECT * FROM photos WHERE id = ?', [$p1]);
check('Import: Fotos sind fertig verarbeitet (Varianten, ohne GPS) und Felder übernommen', $row1['status'] === 'ready' && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id = ?', [$p1]) >= 3
    && $row1['alt_text'] === 'Anna am Meer' && $row1['title'] === 'Meerblick' && $row1['place'] === 'Island' && (int) $row1['year'] === 2022 && $row1['original_name'] === 'anna.jpg');
check('Import: Dublette überschreibt keine Felder (Titel bleibt „Meerblick“)', $row1['title'] === 'Meerblick');
check('Import: Fotografen angelegt und zugeordnet', (int) $db->value('SELECT COUNT(*) FROM photographers') === 2
    && $db->value('SELECT name FROM photographers WHERE id = ?', [$row1['photographer_id']]) === 'Anna Test'
    && $db->value('SELECT photographer_id FROM photos WHERE id = ?', [(int) $map[103]['local_id']]) === null);
check('Import: Paketdatei bleibt unverändert, Original liegt getrennt im Speicher', hash_file('sha256', $imp . '/bilder/101-anna.jpg') === $origSha && $row1['original_path'] !== 'bilder/101-anna.jpg' && is_file($m['config']->originalsDir() . '/' . $row1['original_path']));
check('Import: Schritte sind wiederholbar (nichts doppelt)', json_decode($b->request('POST', '/admin/import/schritt', [], $H)->body, true)['done'] === true && (int) $db->value('SELECT COUNT(*) FROM photos') === 3);

// --- Abschluss ---
$fin = json_decode($b->request('POST', '/admin/import/abschluss', [], $H)->body, true);
check('Import: Abschluss meldet zwei Galerien, zwei Seiten, eine Datei, Weiterleitungen', $fin['galleries'] === 2 && $fin['pages'] === 2 && $fin['files'] === 1 && $fin['redirects'] === 7 && is_array($fin['problems']));
$g = $db->one("SELECT * FROM galleries WHERE title = 'Island 2022'");
$gid = (int) $g['id'];
check('Import: Galerie als Entwurf, EXIF freigegeben, Adresse aus dem Titel, Beschreibung mit aufgelöstem Link', $g['status'] === 'draft' && (int) $g['show_exif'] === 1 && $g['slug'] === 'island-2022'
    && str_contains((string) $g['description'], '(/ueber-uns/)') && !str_contains((string) $g['description'], 'lk-page'));
$gp = array_map('intval', array_column($db->all('SELECT photo_id FROM gallery_photos WHERE gallery_id = ? ORDER BY position', [$gid]), 'photo_id'));
check('Import: Fotos in Paketreihenfolge, Dublette nicht doppelt, Titelbild gesetzt', $gp === [$p1, (int) $map[102]['local_id'], (int) $map[103]['local_id']] && (int) $g['cover_photo_id'] === (int) $map[102]['local_id']);
check('Import: Abschluss nennt die Dublette in der Galerie', str_contains(implode(' ', $fin['problems']), 'Island 2022'));
$up = $db->one("SELECT * FROM pages WHERE slug = 'ueber-uns'");
$blk = Blocks::decode((string) $up['blocks']);
check('Import: Seite mit Blöcken als Entwurf, Foto-Nummern ersetzt, unbekannte Fotos fallen weg', $up['status'] === 'draft' && $up['nav'] === 'main' && array_column($blk, 't') === ['heading', 'text', 'image', 'gallery', 'button']
    && $blk[2]['photo'] === $p1 && count($blk[3]['photos']) === 2);
check('Import: Alt-Text des Fotos landet im Bildblock; fehlende bleiben leer und werden gezählt', $blk[2]['alt'] === 'Anna am Meer' && $blk[3]['photos'][0]['alt'] === '' && Blocks::missingAlt($blk) === 2);
check('Import: Links in Texten und Knopf zeigen auf neue Adressen und die kopierte Datei', str_contains($blk[1]['text'], '(/galerien/island-2022/)') && str_contains($blk[1]['text'], '(/media/dokumente/flyer.pdf)') && $blk[4]['href'] === '/media/dokumente/flyer.pdf');
check('Import: PDF liegt im Medienordner, ohne PHP-Möglichkeit (Ordner media/.htaccess)', is_file($m['config']->mediaDir() . '/dokumente/flyer.pdf') && hash_file('sha256', $m['config']->mediaDir() . '/dokumente/flyer.pdf') === hash('sha256', $pdf));
$imprint = $db->one("SELECT * FROM pages WHERE slug = 'impressum'");
check('Import: einfache Seite behält Markdown, Link auf andere Seite aufgelöst, nav Fußzeile', $imprint['blocks'] === '' && str_contains((string) $imprint['body'], '[Über uns](/ueber-uns/)') && $imprint['nav'] === 'footer' && $imprint['status'] === 'draft');
check('Import: Startbild gesetzt', $svc->settings->all()['hero_photo_id'] === (int) $map[103]['local_id']);
check('Import: Weiterleitungen gespeichert, Unzulässiges verworfen', (int) $db->value('SELECT COUNT(*) FROM redirects') === 7 && $db->value("SELECT to_path FROM redirects WHERE kind = 'path'") === '/media/dokumente/flyer.pdf');

// --- Bericht ---
$rp = $b->get('/admin/import');
check('Import: Bericht zeigt Alt-Text-Lücken, Fotos ohne Fotograf, fehlgeschlagene Fotos, Galerien und Seiten', $rp->status === 200 && str_contains($rp->body, 'Durchsicht') && str_contains($rp->body, '<strong>2</strong> ohne Alt-Text')
    && str_contains($rp->body, '<strong>1</strong> ohne Fotograf') && str_contains($rp->body, 'WordPress-Nr. 105') && str_contains($rp->body, 'Island 2022') && str_contains($rp->body, '2 ohne Alt-Text') && str_contains($rp->body, 'Über uns')
    && str_contains($rp->body, 'Importpaket löschen') && str_contains($rp->body, 'Der Import ist abgeschlossen'));

// --- Wiederholung ändert nichts ---
$gal0 = (int) $db->value('SELECT COUNT(*) FROM galleries');
$b->post('/admin/seiten/' . (int) $imprint['id'] . '/speichern', ['_csrf' => $csrf, 'title' => 'Impressum (bearbeitet)', 'slug' => 'impressum', 'body' => 'Eigener Text', 'status' => 'draft', 'nav' => 'footer']);
$fin2 = json_decode($b->request('POST', '/admin/import/abschluss', [], $H)->body, true);
check('Import: zweiter Abschluss legt nichts doppelt an und überschreibt keine Änderungen im Admin', $fin2['galleries'] === 0 && $fin2['pages'] === 0 && (int) $db->value('SELECT COUNT(*) FROM galleries') === $gal0 && (int) $db->value('SELECT COUNT(*) FROM pages') === 2
    && $db->value("SELECT body FROM pages WHERE slug = 'impressum'") === 'Eigener Text');

// --- Veröffentlichen: Alt-Text-Pflicht bleibt, Weiterleitungen erst bei veröffentlichtem Ziel ---
check('Veröffentlichen: Galerie ohne Alt-Texte lässt sich nicht veröffentlichen', $svc->galleries->setStatus($gid, 'published')['ok'] === false);
$svc->catalog->bulkUpdate($gp, ['alt_text' => 'Ein Foto aus Island']);
check('Veröffentlichen: mit Alt-Texten geht es', $svc->galleries->setStatus($gid, 'published')['ok'] === true);
$svc->pages->save((int) $up['id'], ['title' => 'Über uns', 'body' => '', 'status' => 'published', 'nav' => 'main']);
check('Veröffentlichen: Seite mit Bildern ohne Alt-Text sperrt (sichtbare Lücke)', str_contains(implode(' ', $svc->builder->plan()['errors']), 'Alt-Text'));
$svc->pages->save((int) $up['id'], ['title' => 'Über uns', 'body' => '', 'status' => 'draft', 'nav' => 'main']);
$svc->pages->save((int) $imprint['id'], ['title' => 'Impressum', 'body' => 'Eigener Text', 'status' => 'published', 'nav' => 'footer']);
$pub = $svc->builder->publish(1);
check('Veröffentlichen: gelingt', $pub['ok'] === true);
$rel = $m['config']->dataDir() . '/site/releases/' . $pub['id'];
$map2 = json_decode((string) file_get_contents($rel . '/_redirects.json'), true);
check('Weiterleitungen: nur Ziele, die in der Fassung stehen; eigene Adresse und Entwurf fehlen', $map2 === [
    '/featured-content/island-2022' => '/galerien/island-2022/', '/home' => '/', '/impressum/datenschutz' => '/impressum/', '/portfolio' => '/galerien/',
    '/wp-content/uploads/2021/09/flyer.pdf' => '/media/dokumente/flyer.pdf',
]);
$rd = $b->get('/featured-content/island-2022/');
check('Weiterleitung: alte Galerie-Adresse → 301 auf die neue', $rd->status === 301 && ($rd->headers['Location'] ?? '') === '/galerien/island-2022/');
check('Weiterleitung: auch ohne Schrägstrich am Ende direkt (kein Umweg)', ($x = $b->get('/featured-content/island-2022'))->status === 301 && ($x->headers['Location'] ?? '') === '/galerien/island-2022/');
check('Weiterleitung: Groß-/Kleinschreibung egal', ($b->get('/Featured-Content/Island-2022/')->headers['Location'] ?? '') === '/galerien/island-2022/');
check('Weiterleitung: Übersicht, Startseite, Seite, PDF', ($b->get('/portfolio/')->headers['Location'] ?? '') === '/galerien/' && ($b->get('/home/')->headers['Location'] ?? '') === '/'
    && ($b->get('/impressum/datenschutz/')->headers['Location'] ?? '') === '/impressum/' && ($b->get('/wp-content/uploads/2021/09/flyer.pdf')->headers['Location'] ?? '') === '/media/dokumente/flyer.pdf');
check('Weiterleitung: eine vorhandene Seite wird nie umgeleitet (/impressum/ → 200)', $b->get('/impressum/')->status === 200);
check('Weiterleitung: Ziel im Entwurf → keine Weiterleitung (404)', $b->get('/featured-content/zweite/')->status === 404 && $b->get('/ueber-uns/')->status === 404);
check('Weiterleitung: die neue Galerie-Adresse ist erreichbar', $b->get('/galerien/island-2022/')->status === 200);
check('Weiterleitung: _redirects.json wird nicht ausgeliefert', $b->get('/_redirects.json')->status === 404);
file_put_contents($rel . '/_redirects.json', json_encode(['/boese' => '//evil.example/', '/boese2' => '/a/../b', '/boese3' => "/x\r\nSet-Cookie: a=b", '/ok' => '/impressum/']));
$tamper = static fn (string $p): string => (string) ($b->get($p)->headers['Location'] ?? '');
check('Weiterleitung: manipulierte Ziele (//, .., Zeilenumbruch) werden ignoriert, gültige bleiben', $tamper('/boese/') === '' && $tamper('/boese2/') === '' && $tamper('/boese3/') === '' && !str_contains($tamper('/boese'), 'evil') && $tamper('/ok/') === '/impressum/');

// --- Paket löschen ---
$cnt = $svc->importer->counts(Importer::parse((string) file_get_contents($manifest)));
check('Import: Zähler am Ende (6 von 6, abgeschlossen, 2 Fehler)', $cnt['done'] === 6 && $cnt['finished'] && $cnt['failed'] === 2);
$pu = $b->post('/admin/import/loeschen', ['_csrf' => $csrf]);
check('Import: Paket löschen entfernt Bilder, PDF und manifest.json, Fotos bleiben', $pu->status === 303 && !is_file($manifest) && !is_file($imp . '/bilder/101-anna.jpg') && !is_file($imp . '/bilder/105-boese.jpg'));
check('Import: nach dem Löschen sind Fotos, Galerien und Weiterleitungen unverändert da', (int) $db->value('SELECT COUNT(*) FROM photos') === 3 && (int) $db->value('SELECT COUNT(*) FROM redirects') === 7 && !is_file($imp . '/manifest.json')
    && !is_file($imp . '/dateien/flyer.pdf') && is_file($m['config']->mediaDir() . '/dokumente/flyer.pdf'));
check('Import: Admin-Seite danach wieder ohne Paket, Bericht bleibt sichtbar', str_contains($b->get('/admin/import')->body, 'Noch kein Paket') && str_contains($b->get('/admin/import')->body, 'Durchsicht'));
