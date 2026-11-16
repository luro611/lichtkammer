<?php
declare(strict_types=1);

// Entwurf 14: Startseite als Blöcke, Blockstile, Karussell, Menü- und Fußzeilen-Reihenfolge.

use Lichtkammer\Media\MediaException;
use Lichtkammer\Site\Blocks;

require_once __DIR__ . '/support.php';

$f8 = lk_new_tmp($tmp, 'startseite');
$throws = static function (callable $fn): bool {
    try {
        $fn();
    } catch (MediaException) {
        return true;
    }
    return false;
};
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE);

// --- Prüfklasse ---
$p = Blocks::parse($J([
    ['t' => 'text', 'text' => 'x', 'style' => 'flaeche'], ['t' => 'text', 'text' => 'y', 'style' => '"><script>'], ['t' => 'heading', 'text' => 'h', 'style' => 'mitte'], ['t' => 'heading', 'text' => 'h2', 'style' => 'gross'],
    ['t' => 'quote', 'text' => 'q', 'style' => 'gross'], ['t' => 'button', 'label' => 'b', 'href' => '/x/', 'style' => 'mitte'],
]));
check('Stile: gültige Werte bleiben, ungültige werden zu „normal“', array_column($p, 'style') === ['flaeche', 'normal', 'mitte', 'normal', 'gross', 'mitte']);
check('Stile: ältere Blöcke ohne Feld lesen sich als „normal“', Blocks::parse($J([['t' => 'text', 'text' => 'x']]))[0]['style'] === 'normal');
$ims = Blocks::parse($J([['t' => 'imagetext', 'photo' => 3, 'alt' => 'A', 'text' => 'Hallo', 'side' => 'rechts', 'style' => 'flaeche'], ['t' => 'imagetext', 'photo' => 3, 'side' => 'quer', 'style' => 'x']]));
check('Bild und Text: Seite und Stil aus Positivliste', $ims[0]['side'] === 'rechts' && $ims[0]['style'] === 'flaeche' && $ims[1]['side'] === 'links' && $ims[1]['style'] === 'normal');
check('Bild und Text: braucht ein Foto, in Spalten nicht erlaubt', $throws(fn () => Blocks::parse($J([['t' => 'imagetext', 'photo' => 0, 'text' => 'x']]))) && $throws(fn () => Blocks::parse($J([['t' => 'columns', 'left' => [['t' => 'imagetext', 'photo' => 1, 'text' => 'x']], 'right' => []]]))));
$car = Blocks::parse($J([['t' => 'carousel', 'photos' => [['photo' => 1, 'alt' => 'a'], ['photo' => 2]], 'auto' => true, 'interval' => 99, 'ratio' => 'x']]))[0];
check('Karussell: Intervall begrenzt (3 bis 15), Format aus Positivliste, Automatik nur bei true', $car['interval'] === 15 && $car['ratio'] === '3-2' && $car['auto'] === true
    && Blocks::parse($J([['t' => 'carousel', 'photos' => [['photo' => 1]], 'auto' => 'ja', 'interval' => 0]]))[0]['auto'] === false && Blocks::parse($J([['t' => 'carousel', 'photos' => [['photo' => 1]], 'interval' => 0]]))[0]['interval'] === 3);
check('Karussell: ohne Fotos und mit 13 Fotos abgelehnt', $throws(fn () => Blocks::parse($J([['t' => 'carousel', 'photos' => []]]))) && $throws(fn () => Blocks::parse($J([['t' => 'carousel', 'photos' => array_map(static fn (int $i): array => ['photo' => $i], range(1, 13))]]))));
check('Karussell: Alt-Text-Zählung und Foto-Ids', Blocks::missingAlt($car !== null ? [$car] : []) === 1 && Blocks::photoIds([$car]) === [1, 2]);
check('Titelbild: nur auf der Startseite erlaubt', $throws(fn () => Blocks::parse($J([['t' => 'hero', 'photo' => 1, 'title' => 'x']]))) && Blocks::parse($J([['t' => 'hero', 'photo' => 1, 'title' => 'x']]), null, true)[0]['t'] === 'hero');
$hr = Blocks::parse($J([['t' => 'hero', 'photo' => 0, 'title' => 'Nur Text']]), null, true)[0];
check('Titelbild: ohne Foto nur Überschrift; ganz leer fällt weg; Alt-Text ist vorbelegt als Dekoration', $hr['photo'] === 0 && $hr['decor'] === true && Blocks::parse($J([['t' => 'hero', 'photo' => 0, 'title' => '']]), null, true) === [] && Blocks::missingAlt([$hr]) === 0);
$gl = Blocks::parse($J([['t' => 'galleries', 'title' => 'Alle', 'limit' => 500]]))[0];
check('Galerien-Kacheln: Anzahl begrenzt', $gl['limit'] === 24);

// --- Umgebung ---
$m = lk_make($f8 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];
$mk = function (int $w, int $h) use ($b, $H): int {
    $r = lk_upload($b, "start-$w-$h.jpg", lk_jpeg($w, $h));
    $b->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], $H);
    return (int) $r['photo_id'];
};
$a1 = $mk(900, 600);
$a2 = $mk(600, 900);
$a3 = $mk(800, 800);
foreach ([$a1 => 'Boote im Hafen', $a2 => 'Eine Treppe', $a3 => 'Ein Quadrat'] as $id => $alt) {
    $svc->catalog->update($id, ['title' => "T$id", 'alt_text' => $alt]);
}

// --- Startseite im Admin ---
$h0 = $b->get('/admin/startseite');
check('Startseite: Editor öffnet, vorbefüllt mit Titelbild-Block und Galerien-Kacheln, Titelbild-Typ im Editor', $h0->status === 200 && str_contains($h0->body, 'id="page-editor"') && str_contains($h0->body, '"t":"hero"') && str_contains($h0->body, '"t":"galleries"') && str_contains($h0->body, '"home":true'));
check('Startseite: ohne Anmeldung abgewiesen', $anon->get('/admin/startseite')->status === 303 && $anon->post('/admin/startseite', ['blocks' => '{}'])->status !== 200);
check('Startseite: Navigation nennt Startseite', str_contains($h0->body, 'href="/admin/startseite"'));
check('Startseite: ohne Speichern bleibt die feste Startseite', !str_contains($b->get('/admin/vorschau')->body, 'homeblocks'));

$home = [
    ['t' => 'hero', 'photo' => $a1, 'alt' => '', 'decor' => true, 'title' => 'Licht <b>und</b> Schatten'],
    ['t' => 'carousel', 'photos' => [['photo' => $a1, 'alt' => 'Boote'], ['photo' => $a2, 'alt' => 'Treppe'], ['photo' => $a3, 'alt' => 'Quadrat']], 'auto' => true, 'interval' => 5, 'ratio' => '16-9'],
    ['t' => 'imagetext', 'photo' => $a2, 'alt' => 'Treppe', 'text' => "Wir **zeigen** Fotos.", 'side' => 'rechts', 'style' => 'flaeche'],
    ['t' => 'text', 'text' => 'Einleitung', 'style' => 'gross'],
    ['t' => 'galleries', 'title' => 'Unsere Galerien', 'limit' => 0],
];
$sv = $b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J($home)]);
check('Startseite speichern: Weiterleitung, geprüfte Form in den Einstellungen', $sv->status === 303 && str_contains((string) $db->value("SELECT v FROM site_settings WHERE k = 'home_blocks'"), '"t":"carousel"'));
$pv = $b->get('/admin/vorschau')->body;
check('Vorschau Startseite: Titelbild mit Überschrift (maskiert), Wortmarke, Seitenaufbau aus Blöcken', str_contains($pv, 'homeblocks') && str_contains($pv, '<h1>Licht &lt;b&gt;und&lt;/b&gt; Schatten</h1>') && str_contains($pv, 'class="hero-pic px50 py50"') && !str_contains($pv, '<b>und'));
check('Vorschau Startseite: Karussell mit Rollen, Folien, Format, Automatik und Intervall', str_contains($pv, 'data-carousel data-auto="5"') && str_contains($pv, 'aria-roledescription="Karussell"') && substr_count($pv, 'class="bcar-slide"') === 3
    && str_contains($pv, 'aria-label="Folie 2 von 3"') && str_contains($pv, 'bcar r-16-9') && str_contains($pv, 'alt="Treppe"'));
check('Vorschau Startseite: Bild und Text rechts auf Fläche, Text groß', str_contains($pv, 'class="bit bit-rechts s-flaeche"') && str_contains($pv, '<strong>zeigen</strong>') && str_contains($pv, 'bt-text s-gross'));
check('Vorschau Startseite: Galerien-Kacheln mit eigenem Titel', str_contains($pv, '<h2 id="gal-h">Unsere Galerien</h2>') || !str_contains($pv, 'bgalleries'));
$ni = $b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'carousel', 'photos' => [['photo' => $a1, 'alt' => 'x']], 'auto' => false]])]);
check('Startseite: Karussell ohne Automatik hat kein data-auto', $ni->status === 303 && !str_contains($b->get('/admin/vorschau')->body, 'data-auto'));
check('Startseite: leere Startseite und Foto im Papierkorb/unbekanntes Foto werden abgelehnt', $b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'text', 'text' => ' ']])])->status === 400
    && $b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'hero', 'photo' => 99999, 'title' => 'x']])])->status === 400);
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J($home)]);

// Veröffentlichen: Alt-Text fehlt auf der Startseite sperrt
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'carousel', 'photos' => [['photo' => $a1, 'alt' => ''], ['photo' => $a2, 'alt' => 'ok']]]])]);
$pubErr = $b->get('/admin/veroeffentlichen')->body;
check('Veröffentlichen: Startseite mit Bild ohne Alt-Text wird genannt', str_contains($pubErr, 'Startseite: 1 Bild(er) ohne Alt-Text'));
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J($home)]);

// Zurück zur festen Startseite
$rs = $b->post('/admin/startseite', ['_csrf' => $csrf, 'zuruecksetzen' => '1']);
check('Startseite: Zurück zur festen Startseite leert die Blöcke', $rs->status === 303 && (string) $db->value("SELECT v FROM site_settings WHERE k = 'home_blocks'") === '' && !str_contains($b->get('/admin/vorschau')->body, 'homeblocks'));
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J($home)]);

// --- Veröffentlichen enthält die Startseite aus Blöcken ---
// Stile in Einzelblöcken (Ausgabe)
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Stile']);
$sid = (int) $db->value("SELECT id FROM pages WHERE slug = 'stile'");
$b->post("/admin/seiten/$sid/speichern", ['_csrf' => $csrf, 'title' => 'Stile', 'slug' => 'stile', 'status' => 'published', 'nav' => 'main', 'blocks' => $J([
    ['t' => 'heading', 'level' => 2, 'text' => 'Mitte', 'style' => 'mitte'], ['t' => 'quote', 'text' => 'Z', 'by' => '', 'style' => 'flaeche'], ['t' => 'button', 'label' => 'Los', 'href' => '/galerien/', 'style' => 'mitte'],
    ['t' => 'carousel', 'photos' => [['photo' => $a1, 'alt' => 'a'], ['photo' => $a2, 'alt' => 'b']]], ['t' => 'imagetext', 'photo' => $a1, 'alt' => 'x', 'text' => 'Text', 'side' => 'links'],
])]);
$sp = $b->get('/admin/vorschau/stile')->body;
check('Seite: Stilklassen in der Ausgabe, Karussell ohne Automatik, Bild links', str_contains($sp, 'class="bh s-mitte"') && str_contains($sp, 'bquote s-flaeche') && str_contains($sp, 'bbtn s-mitte') && str_contains($sp, 'bit bit-links') && !str_contains($sp, 'data-auto'));
check('Seite: Titelbild-Block auf normaler Seite abgelehnt (400)', $b->post("/admin/seiten/$sid/speichern", ['_csrf' => $csrf, 'title' => 'Stile', 'slug' => 'stile', 'status' => 'draft', 'nav' => 'main', 'blocks' => $J([['t' => 'hero', 'photo' => $a1, 'title' => 'x']])])->status === 400);

// --- Reihenfolge Menü und Fußzeile ---
$mkPage = static function (string $t, string $nav) use ($db): int {
    $db->run("INSERT INTO pages (slug,title,body,blocks,status,nav,sort_order,created_at,updated_at) VALUES (?,?, 'x','', 'published', ?, (SELECT COALESCE(MAX(sort_order),0)+1 FROM pages), 1, 1)", [strtolower($t) . '-' . bin2hex(random_bytes(2)), $t, $nav]);
    return $db->lastId();
};
$db->run('DELETE FROM pages');
$pA = $mkPage('A', 'main');
$pB = $mkPage('B', 'footer');
$pC = $mkPage('C', 'main');
$pD = $mkPage('D', 'footer');
$pE = $mkPage('E', 'main');
$order = static fn (string $nav): array => array_column($db->all('SELECT title FROM pages WHERE nav = ? ORDER BY sort_order, id', [$nav]), 'title');
$b->post("/admin/seiten/$pE/bewegen", ['_csrf' => $csrf, 'richtung' => 'hoch']);
check('Reihenfolge: Menü-Seite rückt innerhalb des Menüs vor (überspringt die Fußzeile)', $order('main') === ['A', 'E', 'C'] && $order('footer') === ['B', 'D']);
$b->post("/admin/seiten/$pB/bewegen", ['_csrf' => $csrf, 'richtung' => 'runter']);
check('Reihenfolge: Fußzeile hat eine eigene Reihenfolge, Menü bleibt', $order('footer') === ['D', 'B'] && $order('main') === ['A', 'E', 'C']);
$b->post("/admin/seiten/$pA/bewegen", ['_csrf' => $csrf, 'richtung' => 'hoch']);
$b->post("/admin/seiten/$pB/bewegen", ['_csrf' => $csrf, 'richtung' => 'runter']);
check('Reihenfolge: am Rand passiert nichts', $order('main') === ['A', 'E', 'C'] && $order('footer') === ['D', 'B']);
$db->run('UPDATE pages SET sort_order = 1'); // Altbestand mit gleichen Werten
$b->post("/admin/seiten/$pC/bewegen", ['_csrf' => $csrf, 'richtung' => 'hoch']);
check('Reihenfolge: gleiche sort_order-Werte werden bereinigt, Verschieben funktioniert', count(array_unique(array_column($db->all('SELECT sort_order FROM pages'), 'sort_order'))) === 5 && array_search('C', $order('main')) < array_search('E', $order('main')) + 1);
$lst = $b->get('/admin/seiten')->body;
check('Liste: drei Gruppen mit Überschrift, Verlinkung je Zeile änderbar', str_contains($lst, 'Im Menü oben') && str_contains($lst, 'In der Fußzeile') && str_contains($lst, 'action="/admin/seiten/' . $pA . '/verlinken"') && str_contains($lst, 'data-autosubmit'));
$b->post("/admin/seiten/$pA/verlinken", ['_csrf' => $csrf, 'nav' => 'footer']);
check('Verlinken: Seite wechselt in die Fußzeile, ungültiger Wert wird ignoriert', $db->value('SELECT nav FROM pages WHERE id = ?', [$pA]) === 'footer' && $b->post("/admin/seiten/$pA/verlinken", ['_csrf' => $csrf, 'nav' => '<x>'])->status === 303 && $db->value('SELECT nav FROM pages WHERE id = ?', [$pA]) === 'footer');
$b->post("/admin/seiten/$pA/verlinken", ['_csrf' => $csrf, 'nav' => 'main']);

// Fußzeile öffentlich: nur Verlinkung, getrennt vom Menü, in der Reihenfolge
$db->run('DELETE FROM pages');
$q1 = $mkPage('Impressum', 'footer');
$q2 = $mkPage('Datenschutz', 'footer');
$q3 = $mkPage('Über uns', 'main');
$b->post("/admin/seiten/$q2/bewegen", ['_csrf' => $csrf, 'richtung' => 'hoch']);
$pvH = $b->get('/admin/vorschau')->body;
check('Fußzeile: Seiten in eigener Reihenfolge nur im Fuß, nicht im Hauptmenü', preg_match('~<nav class="menu"[^>]*>.*?</nav>~s', $pvH, $mm) === 1 && !str_contains($mm[0], 'Impressum') && str_contains($mm[0], 'Über uns')
    && strpos($pvH, 'Datenschutz</a>') < strpos($pvH, 'Impressum</a>'));

// Doppelte Seiten werden in der Liste erkannt
$mkPage('Impressum', 'none');
$ld = $b->get('/admin/seiten')->body;
check('Liste: gleicher Titel wird als „Doppelt?“ markiert', substr_count($ld, 'Doppelt?') === 2);

// Vorlagen Impressum und Datenschutz: nur Platzhalter
$db->run('DELETE FROM pages');
$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'impressum']);
$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'datenschutz']);
$tpl = (string) $db->value("SELECT body FROM pages WHERE slug = 'impressum'") . (string) $db->value("SELECT body FROM pages WHERE slug = 'datenschutz'");
check('Vorlagen: Impressum und Datenschutz nach dem Aufbau von lurohome, mit Platzhaltern', str_contains($tpl, 'Digitale-Dienste-Gesetz') && str_contains($tpl, 'Vorname Nachname') && str_contains($tpl, 'Server-Protokolle') && !str_contains($tpl, 'Heidelberg') && !str_contains($tpl, 'lurohome'));

// Skripte: Karussell ohne fremde Adressen/unsichere Ausgabe
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/site.js');
check('Karussell-Skript: kein innerHTML, keine fremden Adressen, respektiert „weniger Bewegung“', str_contains($js, 'data-carousel') && str_contains($js, 'prefers-reduced-motion') && !preg_match('~innerHTML|eval\(|https?://~', $js));

// --- Zuordnung nach Galerie ---
$db->run('DELETE FROM pages');
$gA = $svc->galleries->create('Berlin 2022/02');
$gB = $svc->galleries->create('Ausstellung Heidelberg');
$gC = $svc->galleries->create('Kiew 2007/03');
$svc->galleries->addPhotos($gA, [$a1]);
$svc->galleries->addPhotos($gB, [$a2, $a1]);
$svc->galleries->addPhotos($gC, [$a3]);
$db->run("UPDATE photos SET alt_text = '' WHERE id = ?", [$a3]);
$rules = "Mathias und Lutz: Ausstellung\nLutz: Berlin, Tokio\nRest: Mathias";
$pl = (new \Lichtkammer\Media\Assign($db, $svc->catalog))->plan($rules);
check('Zuordnung: Vorschau nennt je Galerie den Fotografen, neue werden angekündigt, nichts geändert', array_column($pl['rows'], 'photographer') === ['Lutz', 'Mathias und Lutz', 'Mathias'] && count($pl['newNames']) === 3 && $db->value('SELECT COUNT(*) FROM photographers') == 0);
$bad = $b->post('/admin/galerien/zuordnung', ['_csrf' => $csrf, 'regeln' => 'Lutz Berlin', 'aktion' => 'anwenden']);
check('Zuordnung: Regel ohne Doppelpunkt wird abgelehnt', $bad->status === 400 && str_contains($bad->body, 'Doppelpunkt'));
$prev = $b->post('/admin/galerien/zuordnung', ['_csrf' => $csrf, 'regeln' => $rules, 'alt' => '1', 'aktion' => 'vorschau']);
check('Zuordnung: Vorschau im Admin ohne Änderung', $prev->status === 200 && str_contains($prev->body, 'Jetzt anwenden') && $db->value('SELECT COUNT(*) FROM photographers') == 0);
$ap = $b->post('/admin/galerien/zuordnung', ['_csrf' => $csrf, 'regeln' => $rules, 'alt' => '1', 'aktion' => 'anwenden']);
$who = static fn (int $id): string => (string) $db->value('SELECT ph.name FROM photos p JOIN photographers ph ON ph.id = p.photographer_id WHERE p.id = ?', [$id]);
check('Zuordnung: Fotografen gesetzt (erste Galerie gewinnt bei Foto in zwei Galerien), Rest bekommt die Restregel', $ap->status === 200 && $who($a1) === 'Lutz' && $who($a2) === 'Mathias und Lutz' && $who($a3) === 'Mathias');
check('Zuordnung: Alt-Text nur bei leeren Feldern aus dem Galerienamen; vorhandene bleiben', (string) $db->value('SELECT alt_text FROM photos WHERE id = ?', [$a3]) === 'Foto aus der Galerie „Kiew 2007/03“' && (string) $db->value('SELECT alt_text FROM photos WHERE id = ?', [$a1]) === 'Boote im Hafen');

// --- Kopfzeile: Seitenname und Veröffentlichen-Knopf ---
$svc->settings->save('br.foto', '', 0);
$hd = $b->get('/admin/galerien')->body;
check('Admin: Kopfzeile zeigt den Seitennamen statt „Lichtkammer“, Plattform im Fuß', preg_match('~<a class="brand"[^>]*>br\.foto</a>~', $hd) === 1 && str_contains($hd, '>Lichtkammer</a> 0.'));
$pubx = $b->get('/admin/veroeffentlichen');
$b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
$clean = $b->get('/admin/galerien')->body;
check('Veröffentlichen: nach dem Veröffentlichen steht im Kopf „✓ Veröffentlicht“ statt des Knopfs', str_contains($clean, '✓ Veröffentlicht') && !str_contains($clean, 'btn small publish'));
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Neu']);
$dirty = $b->get('/admin/galerien')->body;
check('Veröffentlichen: nach einer Änderung wieder der Knopf „Veröffentlichen“', str_contains($dirty, 'btn small publish') && !str_contains($dirty, '✓ Veröffentlicht'));
$b->get('/admin/veroeffentlichen');
check('Veröffentlichen: Seite „Veröffentlichen“ gleicht den Kopf an (Entwurfsseite ist keine Änderung)', str_contains($b->get('/admin/galerien')->body, '✓ Veröffentlicht'));

// --- Logos ---
$svgOk = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="1" y="1" width="8" height="8" fill="#c75d3b"/><text x="1" y="9">br</text></svg>';
$svgDark = str_replace('#c75d3b', '#e47a55', $svgOk);
$du = static fn (string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);
$up = static fn (string $name, string $data) => $b->post('/admin/logos', ['_csrf' => $csrf, 'name' => $name, 'daten' => $data]);
check('Logos: Seite erreichbar, ohne Anmeldung nicht', $b->get('/admin/logos')->status === 200 && $anon->get('/admin/logos')->status === 303);
check('Logos: einfaches SVG wird angenommen', $up('br-hell.svg', $du('image/svg+xml', $svgOk))->status === 303 && count($svc->settings->logos()) === 1);
$up('br-dunkel.svg', $du('image/svg+xml', $svgDark));
foreach ([
    'Skript' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'Ereignis' => '<svg xmlns="http://www.w3.org/2000/svg"><rect onclick="x()" width="1" height="1"/></svg>',
    'Verweis nach außen' => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:href="https://evil.de/x.svg#a"/></svg>',
    'eingebettetes Bild' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/png;base64,AAAA"/></svg>',
    'foreignObject' => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div/></foreignObject></svg>',
    'DOCTYPE' => '<!DOCTYPE svg [<!ENTITY a "b">]><svg xmlns="http://www.w3.org/2000/svg"/>',
    'CSS-Adresse' => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="fill:url(https://evil.de/a)" width="1" height="1"/></svg>',
    'kein SVG' => '<html><body>x</body></html>',
] as $why => $bad) {
    check("Logos: SVG mit $why wird abgelehnt", $up('x.svg', $du('image/svg+xml', $bad))->status === 400);
}
check('Logos: Typ und Inhalt müssen zusammenpassen, zu große Dateien und fremde Typen werden abgelehnt', $up('x.png', $du('image/png', 'kein bild'))->status === 400
    && $up('x.svg', $du('image/svg+xml', str_pad($svgOk, 310000, ' ')))->status === 400 && $up('x.gif', $du('image/gif', 'GIF89a'))->status === 400 && count($svc->settings->logos()) === 2);
check('Logos: JPEG wird am Inhalt erkannt', $up('foto.jpg', $du('image/jpeg', lk_jpeg(40, 20)))->status === 303 && count($svc->settings->logos()) === 3);
$ls = $svc->settings->logos();
$idL = $ls[0]['id'];
$idD = $ls[1]['id'];
$b->post('/admin/logos/zuordnen', ['_csrf' => $csrf, 'light' => $idL, 'dark' => $idD, 'favicon' => $idL]);
$ch = $svc->settings->logoChoice();
check('Logos: Auswahl für hell, dunkel und Favicon gespeichert', $ch['light'] === $idL && $ch['dark'] === $idD && $ch['favicon'] === $idL);
$pubH = $b->get('/admin/vorschau')->body;
check('Logos: öffentliche Seite zeigt Hell- und Dunkel-Logo und Favicon als Data-URL, kein Seitenname als Schrift', substr_count($pubH, 'class="logo logo-light"') === 1 && substr_count($pubH, 'class="logo logo-dark"') === 1 && str_contains($pubH, 'rel="icon" href="data:image/svg+xml;base64,') && str_contains($pubH, 'type="image/svg+xml"'));
$adm = $b->get('/admin/galerien')->body;
check('Logos: Verwaltung zeigt das Logo in der Seitenleiste', str_contains($adm, 'class="logo logo-light"') && str_contains($adm, 'class="logo logo-dark"') && str_contains($adm, 'rel="icon"'));
$b->post('/admin/logos/zuordnen', ['_csrf' => $csrf, 'light' => $idL, 'dark' => '', 'favicon' => '']);
check('Logos: nur ein Logo gewählt gilt für beide Modi', preg_match_all('~class="logo logo-(light|dark)" src="(data:[^"]+)"~', $b->get('/admin/vorschau')->body, $mm) === 2 && $mm[2][0] === $mm[2][1]);
$b->post("/admin/logos/$idL/loeschen", ['_csrf' => $csrf]);
check('Logos: gelöschtes Logo verschwindet samt Auswahl, ohne Logo wieder der Name als Schrift', count($svc->settings->logos()) === 2 && $svc->settings->logoChoice()['light'] === '' && !str_contains($b->get('/admin/vorschau')->body, 'class="logo logo-light"'));
check('Logos: ungültige Kennung beim Löschen ist wirkungslos', $b->post('/admin/logos/..%2Fx/loeschen', ['_csrf' => $csrf])->status < 500 && count($svc->settings->logos()) === 2);
$js2 = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/logos.js');
check('Logo-Skript: kein innerHTML, keine fremden Adressen', !preg_match('~innerHTML|eval\(|https?://~', $js2));

// --- Veröffentlichen-Seite: Knopf je nach Stand ---
$svc->settings->save('br.foto', '', 0);
$db->run("DELETE FROM pages WHERE trim(body) = '' AND blocks = ''");
$db->run("UPDATE pages SET status = 'published'");
$db->run("UPDATE galleries SET status = 'published'");
$b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
$pg = $b->get('/admin/veroeffentlichen')->body;
check('Veröffentlichen-Seite: ohne Änderungen kein „Jetzt veröffentlichen“, sondern „✓ Alles veröffentlicht“', str_contains($pg, 'Alles veröffentlicht, keine Änderungen') && !str_contains($pg, 'Jetzt veröffentlichen</button>'));
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Wieder neu']);
$b->post('/admin/seiten/' . (int) $db->value("SELECT id FROM pages WHERE slug = 'wieder-neu'") . '/speichern', ['_csrf' => $csrf, 'title' => 'Wieder neu', 'slug' => 'wieder-neu', 'body' => 'Text', 'status' => 'published', 'nav' => 'main']);
check('Veröffentlichen-Seite: mit Änderungen wieder „Jetzt veröffentlichen“', str_contains($b->get('/admin/veroeffentlichen')->body, 'Jetzt veröffentlichen</button>'));

// --- Alle Entwürfe mit veröffentlichen ---
$gx = $svc->galleries->create('Entwurf-Galerie');
$svc->galleries->addPhotos($gx, [$a1]);
$db->run("UPDATE galleries SET status = 'draft' WHERE id = ?", [$gx]);
$svc->settings->save('br.foto', '', 0);
$dp = $b->get('/admin/veroeffentlichen')->body;
check('Veröffentlichen-Seite: bei Entwürfen Auswahl „Alle Entwürfe mit veröffentlichen“ und Knopf bleibt', str_contains($dp, 'name="entwuerfe"') && str_contains($dp, 'Jetzt veröffentlichen</button>'));
$b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
check('Veröffentlichen ohne Auswahl lässt Entwürfe unverändert', $db->value("SELECT status FROM galleries WHERE id = ?", [$gx]) === 'draft');
$b->post('/admin/veroeffentlichen', ['_csrf' => $csrf, 'entwuerfe' => '1']);
check('Veröffentlichen mit Auswahl stellt Seiten und Galerien auf „veröffentlicht“ und erzeugt die Seite', $db->value("SELECT status FROM galleries WHERE id = ?", [$gx]) === 'published' && (int) $db->value("SELECT COUNT(*) FROM pages WHERE status <> 'published'") === 0);

// --- Titelbild: Breite, Höhe, Ausschnitt; Karussell voll ---
$h2 = Blocks::parse($J([['t' => 'hero', 'photo' => 1, 'title' => 'x', 'span' => 'voll', 'height' => 'hoch', 'fx' => 33, 'fy' => 140], ['t' => 'hero', 'photo' => 1, 'span' => 'x', 'height' => '<', 'fx' => 'a']]), null, true);
check('Titelbild: Breite und Höhe aus Positivliste, Ausschnitt in Zehnerschritten 0 bis 100, Vorgabe Mitte', $h2[0]['span'] === 'voll' && $h2[0]['height'] === 'hoch' && $h2[0]['fx'] === 30 && $h2[0]['fy'] === 100 && $h2[1]['span'] === 'box' && $h2[1]['height'] === 'normal' && $h2[1]['fx'] === 50);
$c2 = Blocks::parse($J([['t' => 'carousel', 'span' => 'voll', 'ratio' => '3-1', 'photos' => [['photo' => 1, 'alt' => 'a', 'fx' => 20, 'fy' => 80]]]]))[0];
check('Karussell: volle Breite, schmales Format, Ausschnitt je Foto', $c2['span'] === 'voll' && $c2['ratio'] === '3-1' && $c2['photos'][0]['fx'] === 20 && $c2['photos'][0]['fy'] === 80);
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([
    ['t' => 'hero', 'photo' => $a1, 'title' => 'Voll', 'span' => 'voll', 'height' => 'hoch', 'fx' => 20, 'fy' => 70],
    ['t' => 'carousel', 'span' => 'voll', 'ratio' => '3-1', 'photos' => [['photo' => $a1, 'alt' => 'a', 'fx' => 90, 'fy' => 10], ['photo' => $a2, 'alt' => 'b']]],
])]);
$pf = $b->get('/admin/vorschau')->body;
check('Vorschau: Titelbild volle Breite, hoch, Ausschnitt als Klassen (keine Inline-Stile)', str_contains($pf, 'class="hero hh-hoch w-voll"') && str_contains($pf, 'hero-pic px20 py70') && !str_contains($pf, 'style='));
check('Vorschau: Karussell volle Breite und Ausschnitt je Foto', str_contains($pf, 'bcar r-3-1 w-voll') && str_contains($pf, 'px90 py10') && str_contains($pf, 'px50 py50'));
$cssT = (string) file_get_contents(dirname(__DIR__) . '/public/assets/css/site.css');
check('CSS: Ausschnittsklassen px0 bis px100 und py0 bis py100 vorhanden', str_contains($cssT, '.px0{--px:0%}') && str_contains($cssT, '.py100{--py:100%}') && str_contains($cssT, '.hero.w-voll'));

// --- Schalter „Startseite“ je Foto und Block „Startseiten-Fotos“ ---
check('Schalter Startseite: Spalte on_home vorhanden (Migration 7), Vorgabe aus', (string) $db->value("SELECT name FROM pragma_table_info('photos') WHERE name = 'on_home'") === 'on_home' && (int) $db->value('SELECT COUNT(*) FROM photos WHERE on_home = 1') === 0);
$gh = $svc->galleries->create('Startgalerie');
$svc->galleries->addPhotos($gh, [$a1, $a2, $a3]);
$gp = $b->get("/admin/galerien/$gh")->body;
check('Galerie: jedes Foto hat den Knopf „Startseite“', substr_count($gp, '/startseite"') >= 3 && str_contains($gp, '>Startseite</button>'));
$b->post("/admin/galerien/$gh/startseite", ['_csrf' => $csrf, 'foto' => (string) $a1, 'an' => '1']);
$b->post("/admin/galerien/$gh/startseite", ['_csrf' => $csrf, 'foto' => (string) $a3, 'an' => '1']);
$gp2 = $b->get("/admin/galerien/$gh")->body;
check('Galerie: markierte Fotos tragen die Marke „Startseite“ und den Knopf zum Zurücknehmen', substr_count($gp2, 'badge cover">Startseite') === 2 && str_contains($gp2, 'Nicht mehr Startseite'));
check('Galerie: Startseite-Schalter ohne Anmeldung abgewiesen, unbekanntes Foto abgelehnt', $anon->post("/admin/galerien/$gh/startseite", ['foto' => (string) $a1, 'an' => '1'])->status !== 200 && $b->post("/admin/galerien/$gh/startseite", ['_csrf' => $csrf, 'foto' => '99999', 'an' => '1'])->status === 400);
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'homephotos', 'mode' => 'karussell', 'span' => 'voll', 'ratio' => '2-1', 'auto' => true, 'interval' => 5, 'limit' => 8]])]);
$hv = $b->get('/admin/vorschau')->body;
check('Startseiten-Fotos: Karussell zeigt genau die markierten Fotos (2), voll breit, mit Automatik', substr_count($hv, 'class="bcar-slide"') === 2 && str_contains($hv, 'bcar r-2-1 w-voll') && str_contains($hv, 'data-auto="5"') && str_contains($hv, 'alt="Boote im Hafen"'));
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'homephotos', 'mode' => 'raster', 'limit' => 8]])]);
check('Startseiten-Fotos: Raster mit Lightbox', str_contains($b->get('/admin/vorschau')->body, 'class="bgrid c3" data-lightbox'));
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'homephotos', 'mode' => 'titelbild', 'span' => 'voll', 'height' => 'hoch']])]);
check('Startseiten-Fotos: ein großes Bild (Titelbild-Darstellung)', str_contains($b->get('/admin/vorschau')->body, 'class="hero hh-hoch w-voll"'));
$b->post("/admin/galerien/$gh/startseite", ['_csrf' => $csrf, 'foto' => (string) $a1, 'an' => '0']);
$b->post("/admin/galerien/$gh/startseite", ['_csrf' => $csrf, 'foto' => (string) $a3, 'an' => '0']);
check('Startseiten-Fotos: ohne markierte Fotos entfällt der Block ohne Fehler', !str_contains($b->get('/admin/vorschau')->body, 'class="hero') && $b->get('/admin/vorschau')->status === 200);
$svc->catalog->trash([$a1]);
$db->run('UPDATE photos SET on_home = 1 WHERE id = ?', [$a1]);
$b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $J([['t' => 'homephotos', 'mode' => 'karussell']])]);
check('Startseiten-Fotos: Fotos im Papierkorb erscheinen nicht', !str_contains($b->get('/admin/vorschau')->body, 'bcar-slide'));
$svc->catalog->restore([$a1]);
// Logo im Fuß neben dem Urheberhinweis
$svc->settings->chooseLogos(['light' => $ls[1]['id'], 'dark' => $ls[1]['id'], 'favicon' => '']);
check('Fuß: Logo steht beim Urheberhinweis', preg_match('~<span class="mono copy"><span>© \d{4}</span><img class="logo logo-light flogo"[^>]*alt="br\.foto"~', $b->get('/admin/vorschau')->body) === 1);

// --- Seitentitel an/aus ---
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Titeltest']);
$tid = (int) $db->value("SELECT id FROM pages WHERE slug = 'titeltest'");
$base = ['_csrf' => $csrf, 'title' => 'Titeltest', 'slug' => 'titeltest', 'body' => 'Text', 'status' => 'published', 'nav' => 'none', 'titel_form' => '1'];
check('Seitentitel: Vorgabe an, Häkchen im Formular', (int) $db->value('SELECT show_title FROM pages WHERE id = ?', [$tid]) === 1 && str_contains($b->get("/admin/seiten/$tid")->body, 'name="titel_zeigen" value="1" checked'));
check('Seitentitel: angezeigt, wenn an', str_contains($b->get('/admin/vorschau/titeltest')->body, '<h1 class="h-page">Titeltest</h1>'));
$b->post("/admin/seiten/$tid/speichern", $base);
check('Seitentitel: ohne Häkchen aus, h1 bleibt für Screenreader (nur unsichtbar)', (int) $db->value('SELECT show_title FROM pages WHERE id = ?', [$tid]) === 0 && str_contains($b->get('/admin/vorschau/titeltest')->body, '<h1 class="h-page sr">Titeltest</h1>') && !str_contains($b->get("/admin/seiten/$tid")->body, 'name="titel_zeigen" value="1" checked'));
$b->post("/admin/seiten/$tid/speichern", ['titel_zeigen' => '1'] + $base);
check('Seitentitel: mit Häkchen wieder an', (int) $db->value('SELECT show_title FROM pages WHERE id = ?', [$tid]) === 1);
$b->post("/admin/seiten/$tid/speichern", array_diff_key($base, ['titel_form' => 1]));
check('Seitentitel: ohne Formularfeld (z. B. alter Aufruf) bleibt der Wert unverändert', (int) $db->value('SELECT show_title FROM pages WHERE id = ?', [$tid]) === 1);
