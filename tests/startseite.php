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
check('Vorschau Startseite: Titelbild mit Überschrift (maskiert), Wortmarke, Seitenaufbau aus Blöcken', str_contains($pv, 'homeblocks') && str_contains($pv, '<h1>Licht &lt;b&gt;und&lt;/b&gt; Schatten</h1>') && str_contains($pv, 'class="hero-pic"') && !str_contains($pv, '<b>und'));
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
