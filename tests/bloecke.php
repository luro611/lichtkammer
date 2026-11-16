<?php
declare(strict_types=1);

// Entwurf 12: Seiten aus Blöcken (Prüfung, Speichern, Ausgabe, Vorschau, Veröffentlichen, Fotoauswahl, Umwandlung).

use Lichtkammer\Media\MediaException;
use Lichtkammer\Site\Blocks;
use Lichtkammer\Site\Markdown;

require_once __DIR__ . '/support.php';

$f6 = lk_new_tmp($tmp, 'bloecke');
$throws = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (MediaException $e) {
        return $e->getMessage();
    }
    return null;
};
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE);

// --- Prüfklasse ohne Datenbank ---
$ok = Blocks::parse($J([
    ['t' => 'heading', 'level' => 3, 'text' => ' Titel '], ['t' => 'text', 'text' => 'Hallo'], ['t' => 'quote', 'text' => 'Z', 'by' => 'Q'], ['t' => 'rule'],
    ['t' => 'button', 'label' => 'Los', 'href' => '/galerien/'], ['t' => 'image', 'photo' => 5, 'alt' => 'A', 'width' => 'weird'],
    ['t' => 'gallery', 'photos' => [['photo' => 1, 'alt' => 'x'], ['photo' => '2']], 'cols' => 9],
    ['t' => 'columns', 'left' => [['t' => 'text', 'text' => 'l']], 'right' => [['t' => 'button', 'label' => 'm', 'href' => 'mailto:a@b.de']]],
]));
check('Blöcke: alle Typen werden angenommen und bereinigt (Breite, Spalten, Titel getrimmt)', count($ok) === 8 && $ok[0]['text'] === 'Titel' && $ok[0]['level'] === 3 && $ok[5]['width'] === 'normal' && $ok[6]['cols'] === 3 && $ok[6]['photos'][1]['photo'] === 2);
check('Blöcke: leere Text-, Überschrift- und Zitatblöcke fallen weg', Blocks::parse($J([['t' => 'text', 'text' => "  \n"], ['t' => 'heading', 'text' => ''], ['t' => 'quote', 'text' => ''], ['t' => 'rule']])) === [['t' => 'rule']]);
check('Blöcke: unbekannter Typ abgelehnt', $throws(fn () => Blocks::parse($J([['t' => 'html', 'text' => '<b>']]))) !== null);
check('Blöcke: kaputtes JSON und falsche Form abgelehnt', $throws(fn () => Blocks::parse('{kaputt')) !== null && $throws(fn () => Blocks::parse('{"blocks":{"a":1}}')) !== null && $throws(fn () => Blocks::parse('[1]')) !== null);
check('Blöcke: höchstens 100 Blöcke', $throws(fn () => Blocks::parse($J(array_fill(0, 101, ['t' => 'rule'])))) !== null && Blocks::parse($J(array_fill(0, 100, ['t' => 'rule']))) !== []);
check('Blöcke: JSON über 200 KB abgelehnt', $throws(fn () => Blocks::parse($J([['t' => 'text', 'text' => str_repeat('x', 19000)]]) . str_repeat(' ', 200000))) !== null);
check('Blöcke: lange Felder werden gekappt (Text 20000, Alt 250, Unterschrift 300)', ($l = Blocks::parse($J([['t' => 'text', 'text' => str_repeat('ä', 30000)], ['t' => 'image', 'photo' => 1, 'alt' => str_repeat('a', 400), 'caption' => str_repeat('c', 500)]])))
    && mb_strlen($l[0]['text']) === 20000 && strlen($l[1]['alt']) === 250 && strlen($l[1]['caption']) === 300);
foreach (['javascript:alert(1)', '//evil.de', 'data:text/html,x', 'https://a.de/ x', 'ftp://a.de', '\\evil'] as $bad) {
    check("Blöcke: Adresse „{$bad}“ im Knopf abgelehnt", $throws(fn () => Blocks::parse($J([['t' => 'button', 'label' => 'x', 'href' => $bad]]))) !== null);
}
check('Blöcke: javascript: als Bildlink abgelehnt', $throws(fn () => Blocks::parse($J([['t' => 'image', 'photo' => 1, 'href' => 'javascript:alert(1)']]))) !== null);
check('Blöcke: Bild ohne Foto, Raster ohne Fotos, Raster mit 13 Fotos abgelehnt', $throws(fn () => Blocks::parse($J([['t' => 'image', 'photo' => 0]]))) !== null
    && $throws(fn () => Blocks::parse($J([['t' => 'gallery', 'photos' => []]]))) !== null
    && $throws(fn () => Blocks::parse($J([['t' => 'gallery', 'photos' => array_map(static fn (int $i): array => ['photo' => $i + 1], range(1, 13))]]))) !== null);
check('Blöcke: in Spalten keine Spalten und kein Raster', $throws(fn () => Blocks::parse($J([['t' => 'columns', 'left' => [['t' => 'columns', 'left' => [['t' => 'text', 'text' => 'x']], 'right' => []]], 'right' => []]]))) !== null
    && $throws(fn () => Blocks::parse($J([['t' => 'columns', 'left' => [['t' => 'gallery', 'photos' => [['photo' => 1]]]], 'right' => []]]))) !== null);
check('Blöcke: Alt-Text-Zählung (Bild, Raster, Spalten; Dekoration zählt nicht)', Blocks::missingAlt(Blocks::parse($J([
    ['t' => 'image', 'photo' => 1, 'alt' => ''], ['t' => 'image', 'photo' => 1, 'alt' => '', 'decor' => true], ['t' => 'gallery', 'photos' => [['photo' => 1, 'alt' => ''], ['photo' => 2, 'alt' => 'ok']]],
    ['t' => 'columns', 'left' => [['t' => 'image', 'photo' => 3, 'alt' => '']], 'right' => []],
]))) === 3);
check('Blöcke: Foto-Ids werden gesammelt', Blocks::photoIds(Blocks::parse($J([['t' => 'image', 'photo' => 4], ['t' => 'gallery', 'photos' => [['photo' => 5], ['photo' => 4]]], ['t' => 'columns', 'left' => [['t' => 'image', 'photo' => 6]], 'right' => []]]))) === [4, 5, 6]);

// --- Markdown hin und zurück ---
$md = "## Wer wir sind\n\nWir sind **Anna** und Ben.\n\n- eins\n- zwei\n\n> Ein Zitat\n\n---\n\n### Klein\n\nLetzter Absatz.";
$fm = Blocks::fromMarkdown($md);
check('Umwandlung: Überschriften, Text, Zitat, Trennlinie erkannt, Listen bleiben Text', array_column($fm, 't') === ['heading', 'text', 'quote', 'rule', 'heading', 'text'] && $fm[0]['level'] === 2 && $fm[4]['level'] === 3 && str_contains($fm[1]['text'], '- zwei'));
check('Umwandlung: zurück zu Markdown ergibt dieselbe Ausgabe wie vorher', Markdown::render((string) Blocks::toMarkdown($fm)) === Markdown::render($md));
check('Umwandlung: Seiten mit Bild lassen sich nicht als Text speichern', Blocks::toMarkdown([['t' => 'image', 'photo' => 1, 'alt' => '', 'decor' => false, 'caption' => '', 'width' => 'normal', 'href' => '']]) === null);
check('Text-Block: Überschriften im Text werden nicht zu Überschriften', !str_contains(Markdown::render("# Nicht\n\nText", false), '<h'));

// --- Nachbesserungen aus der Prüfung ---
$long = str_repeat("Absatz mit Text.\n\n", 2500) . '## ' . str_repeat('L', 300) . "\n\n" . str_repeat('x', 25000);
$lb = Blocks::fromMarkdown($long);
$sum = array_sum(array_map(static fn (array $b): int => mb_strlen($b['text'] ?? ''), $lb));
check('Umwandlung: lange Texte werden geteilt statt gekürzt, zu lange Überschrift bleibt Text', max(array_map(static fn (array $b): int => mb_strlen($b['text'] ?? ''), $lb)) <= 20000 && $sum >= mb_strlen($long) - 2600 - 10 * 2 && !in_array('heading', array_column($lb, 't'), true)
    && count(Blocks::parse($J($lb))) === count($lb) && str_contains(implode('', array_column($lb, 'text')), str_repeat('L', 300)));
check('Überschrift: Fett und Links aus altem Markdown bleiben erhalten (Ausgabe wie vorher)', str_contains(Markdown::inline('Wer **wir** [sind](/x/)'), '<strong>wir</strong>'));
check('Blöcke: „Nur Dekoration“ mit Link abgelehnt, im Raster gibt es keine Dekoration', $throws(fn () => Blocks::parse($J([['t' => 'image', 'photo' => 1, 'decor' => true, 'href' => '/x/']]))) !== null
    && Blocks::parse($J([['t' => 'gallery', 'photos' => [['photo' => 1, 'alt' => '', 'decor' => true]]]]))[0]['photos'][0]['decor'] === false);

// --- Umgebung ---
$m = lk_make($f6 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];
$mk = function (int $w, int $h) use ($b, $H): int {
    $r = lk_upload($b, "bloecke-$w-$h.jpg", lk_jpeg($w, $h));
    $b->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], $H);
    return (int) $r['photo_id'];
};
$q1 = $mk(900, 600);
$q2 = $mk(600, 900);
$q3 = $mk(800, 800);
$svc->catalog->update($q1, ['title' => 'Hafen', 'alt_text' => 'Boote im Hafen', 'caption' => 'Abendlicht']);
$svc->catalog->update($q2, ['title' => 'Treppe', 'alt_text' => 'Eine Treppe']);
check('Blöcke: Migration 5 hat die Spalte blocks (leer bei alten Seiten)', (string) $db->value("SELECT name FROM pragma_table_info('pages') WHERE name = 'blocks'") === 'blocks');

// Alte Seite im Textmodus: Ausgabe bleibt, bis sie als Blöcke gespeichert wird.
$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'fotografen']);
$pid = (int) $db->value("SELECT id FROM pages WHERE slug = 'fotografen'");
$b->post("/admin/seiten/$pid/speichern", ['_csrf' => $csrf, 'title' => 'Fotografen', 'slug' => 'fotografen', 'body' => $md, 'status' => 'draft', 'nav' => 'main']);
$old = $b->get('/admin/vorschau/fotografen')->body;
check('Alte Seite (Text): Ausgabe wie bisher mit prose, ohne Blockklassen', str_contains($old, '<div class="prose">') && str_contains($old, '<h2>Wer wir sind</h2>') && !str_contains($old, 'class="blocks"'));
$ed = $b->get("/admin/seiten/$pid");
check('Editor: Seite öffnet mit Editor, alter Text als Blöcke vorbelegt (noch nicht gespeichert)', $ed->status === 200 && str_contains($ed->body, 'id="page-editor"') && str_contains($ed->body, 'id="editor-data"') && str_contains($ed->body, 'Wer wir sind') && (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]) === '');
check('Editor: Skript und Wortlaut der Bedienung eingebunden', str_contains($ed->body, '/assets/js/page-editor.js') && str_contains($ed->body, 'Block hinzufügen'));
check('Editor: Rückweg „Als einfachen Text speichern“ vorhanden', str_contains($ed->body, 'name="modus" value="text"'));

// Blöcke speichern
$blocks = [
    ['t' => 'heading', 'level' => 2, 'text' => 'Wer wir sind <b>'],
    ['t' => 'text', 'text' => "Wir sind **Anna**.\n\n<script>alert(1)</script></script><script>x</script>"],
    ['t' => 'image', 'photo' => $q1, 'alt' => 'Anna "am" Hafen', 'caption' => 'Lissabon <i>2019</i>', 'width' => 'voll', 'href' => ''],
    ['t' => 'gallery', 'photos' => [['photo' => $q1, 'alt' => 'Boote'], ['photo' => $q2, 'alt' => 'Treppe']], 'cols' => 2],
    ['t' => 'quote', 'text' => 'Licht ist alles', 'by' => 'Jemand'],
    ['t' => 'rule'],
    ['t' => 'button', 'label' => 'Zu den Galerien', 'href' => '/galerien/'],
    ['t' => 'columns', 'left' => [['t' => 'text', 'text' => 'links']], 'right' => [['t' => 'image', 'photo' => $q2, 'alt' => 'rechts', 'width' => 'schmal']]],
];
$post = ['_csrf' => $csrf, 'title' => 'Fotografen', 'slug' => 'fotografen', 'status' => 'published', 'nav' => 'main', 'blocks' => $J($blocks)];
$sv = $b->post("/admin/seiten/$pid/speichern", $post);
check('Speichern (Blöcke): Weiterleitung mit Bestätigung, Blöcke gespeichert, alter Text bleibt als Sicherung', $sv->status === 303 && ($sv->headers['Location'] ?? '') === "/admin/seiten/$pid?m=page"
    && str_contains((string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]), '"t":"gallery"') && (string) $db->value('SELECT body FROM pages WHERE id = ?', [$pid]) === $md);
$stored = Blocks::decode((string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]));
check('Speichern (Blöcke): gespeichert wird die geprüfte Form (Typen, Reihenfolge)', array_column($stored, 't') === ['heading', 'text', 'image', 'gallery', 'quote', 'rule', 'button', 'columns']);
$ed2 = $b->get("/admin/seiten/$pid");
check('Editor: Text mit </script> bricht nicht aus dem Datenblock aus', substr_count($ed2->body, '<script') === 4 && substr_count($ed2->body, '</script>') === 4 && !str_contains($ed2->body, '</script><script>x') && str_contains($ed2->body, '<'));
check('Editor: Fotos des Seiteninhalts mit Vorschaubild im Datenblock', str_contains($ed2->body, '"photos":{"' . $q1 . '"') && str_contains($ed2->body, '/media/'));

// Vorschau
$pv = $b->get('/admin/vorschau/fotografen');
check('Vorschau (Blöcke): Blockklassen, Überschrift maskiert, kein rohes HTML', $pv->status === 200 && str_contains($pv->body, 'class="blocks"') && str_contains($pv->body, 'Wer wir sind &lt;b&gt;') && !str_contains($pv->body, '<b>')
    && !str_contains($pv->body, '<script>alert') && str_contains($pv->body, '&lt;script&gt;alert(1)&lt;/script&gt;'));
check('Vorschau (Blöcke): Bild mit Alt-Text, Bildunterschrift maskiert, Breite', str_contains($pv->body, 'alt="Anna &quot;am&quot; Hafen"') && str_contains($pv->body, '<figcaption>Lissabon &lt;i&gt;2019&lt;/i&gt;</figcaption>') && str_contains($pv->body, 'bimg w-voll'));
check('Vorschau (Blöcke): Raster mit Lightbox-Daten und Spalten', str_contains($pv->body, 'class="bgrid c2" data-lightbox') && str_contains($pv->body, 'data-alt="Boote"') && str_contains($pv->body, 'data-srcset="'));
check('Vorschau (Blöcke): Zitat, Trennlinie, Knopf mit Vorschau-Präfix, zwei Spalten', str_contains($pv->body, '<blockquote class="bquote"><p>Licht ist alles</p><footer>Jemand</footer>') && str_contains($pv->body, '<hr class="brule">')
    && str_contains($pv->body, 'class="knopf" href="/admin/vorschau/galerien/"') && str_contains($pv->body, 'class="bcols"'));
check('Vorschau (Blöcke): Pfade der Bilder führen nicht auf Originale', !str_contains($pv->body, 'originals') && !str_contains($pv->body, 'bloecke-900'));

$dup = $b->post("/admin/seiten/$pid/speichern", ['blocks' => $J([['t' => 'gallery', 'photos' => [['photo' => $q1, 'alt' => 'a'], ['photo' => $q1, 'alt' => 'b']]]])] + $post);
check('Raster: dasselbe Foto zweimal ergibt keine doppelte Anker-Id', substr_count($b->get('/admin/vorschau/fotografen')->body, 'id="foto-' . $q1 . '"') === 1);
$b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($blocks)] + $post);
$svc->catalog->trash([$q2]);
$pvT = $b->get('/admin/vorschau/fotografen')->body;
check('Vorschau: Foto im Papierkorb erscheint nicht (weder Bild noch Rasterkachel)', !str_contains($pvT, 'id="foto-' . $q2 . '"') && str_contains($pvT, 'id="foto-' . $q1 . '"'));
check('Speichern: Foto im Papierkorb wird abgelehnt', $b->post("/admin/seiten/$pid/speichern", ['blocks' => $J([['t' => 'image', 'photo' => $q2, 'alt' => 'x']])] + $post)->status === 400);
$svc->catalog->restore([$q2]);

// Alt-Text-Pflicht und Sperren
$noAlt = $blocks;
$noAlt[2]['alt'] = '';
$b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($noAlt)] + $post);
$plan = $svc->builder->plan();
check('Veröffentlichen: Bild ohne Alt-Text in veröffentlichter Seite sperrt, Seite ist trotzdem speicherbar', (int) $db->value('SELECT COUNT(*) FROM pages WHERE id = ? AND blocks LIKE \'%"alt":""%\'', [$pid]) === 1
    && str_contains(implode(' ', $plan['errors']), 'Alt-Text') && str_contains(implode(' ', $plan['errors']), 'Fotografen'));
$deco = $noAlt;
$deco[2]['decor'] = true;
$b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($deco)] + $post);
check('Veröffentlichen: „Nur Dekoration“ erfüllt die Alt-Text-Pflicht (alt="")', !str_contains(implode(' ', $svc->builder->plan()['errors']), 'Alt-Text') && str_contains($b->get('/admin/vorschau/fotografen')->body, 'alt=""'));
$b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($noAlt), 'status' => 'draft'] + $post);
check('Veröffentlichen: Entwurf ohne Alt-Text sperrt nicht', !str_contains(implode(' ', $svc->builder->plan()['errors']), 'Alt-Text'));
$b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($blocks)] + $post);

// Fehler beim Speichern
$bad = $b->post("/admin/seiten/$pid/speichern", ['blocks' => $J([['t' => 'image', 'photo' => 999999, 'alt' => 'x']])] + $post);
check('Speichern: fremde/fehlende Foto-Id → 400 mit Meldung, nichts überschrieben', $bad->status === 400 && str_contains($bad->body, 'Block 1') && str_contains($bad->body, 'gibt es nicht') && Blocks::decode((string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid])) === $stored);
check('Speichern: ungültiger Block → 400, Eingabe bleibt im Editor erhalten', ($b->post("/admin/seiten/$pid/speichern", ['blocks' => $J([['t' => 'button', 'label' => 'x', 'href' => 'javascript:1']])] + $post))->status === 400
    && (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]) !== '');
check('Speichern: nach Fehlern sind die Blöcke in der Datenbank unverändert', Blocks::decode((string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid])) === Blocks::parse($J($blocks)));
check('Speichern: ohne gültige Sitzung/CSRF abgewiesen', $anon->post("/admin/seiten/$pid/speichern", $post)->status === 303 && $b->post("/admin/seiten/$pid/speichern", ['_csrf' => 'x'] + $post)->status === 403);

// Foto in den Papierkorb
$svc->catalog->trash([$q2]);
$planT = $svc->builder->plan();
check('Veröffentlichen: Foto im Papierkorb sperrt die Seite mit Meldung', str_contains(implode(' ', $planT['errors']), 'Foto ' . $q2));
$svc->catalog->restore([$q2]);
check('Veröffentlichen: nach dem Wiederherstellen frei', !str_contains(implode(' ', $svc->builder->plan()['errors']), 'Foto ' . $q2));

// Leere Blockseite
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Leer2']);
$lid = (int) $db->value("SELECT id FROM pages WHERE title = 'Leer2'");
$b->post("/admin/seiten/$lid/speichern", ['_csrf' => $csrf, 'title' => 'Leer2', 'slug' => 'leer2', 'status' => 'published', 'nav' => 'none', 'blocks' => $J([['t' => 'text', 'text' => '  ']])]);
check('Leere Blockseite: veröffentlicht sperrt (kein Inhalt)', str_contains(implode(' ', $svc->builder->plan()['errors']), 'Leer2'));
$b->post("/admin/seiten/$lid/loeschen", ['_csrf' => $csrf]);

// Veröffentlichen und statisches HTML
$pub = $svc->builder->publish(1);
check('Veröffentlichen: Seite mit Blöcken wird veröffentlicht', $pub['ok'] === true);
$live = $anon->get('/fotografen/');
check('Öffentlich: Blockseite ausgeliefert, Bilder und Raster, Seite weiter zwischengespeichert', $live->status === 200 && str_contains($live->body, 'class="blocks"') && str_contains($live->body, 'data-lightbox') && str_contains($live->body, '<figure class="bimg'));
check('Öffentlich: keine Skripte aus Texten, keine Admin-Adressen', !str_contains($live->body, '<script>alert') && !str_contains($live->body, '/admin/vorschau'));
check('Öffentlich: Knopf zeigt auf die echte Adresse', str_contains($live->body, 'class="knopf" href="/galerien/"'));
check('Veröffentlichen: Fotos der Seitenblöcke zählen als „live“ (Hinweis beim Löschen)', $svc->builder->isLive([$q1]) && $svc->builder->isLive([$q2]) && !$svc->builder->isLive([$q3]));

// Zurück zu Text
$tb = [['t' => 'heading', 'level' => 2, 'text' => 'Nur Text'], ['t' => 'text', 'text' => 'Absatz']];
$tx = $b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($tb), 'modus' => 'text'] + $post);
check('Als einfachen Text speichern: Blöcke leer, body neu, Ausgabe als prose', $tx->status === 303 && (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]) === ''
    && (string) $db->value('SELECT body FROM pages WHERE id = ?', [$pid]) === "## Nur Text\n\nAbsatz" && str_contains($b->get('/admin/vorschau/fotografen')->body, '<div class="prose">'));
$tx2 = $b->post("/admin/seiten/$pid/speichern", ['blocks' => $J($blocks), 'modus' => 'text'] + $post);
check('Als einfachen Text speichern: mit Bild/Knopf/Spalten abgelehnt (400) und nichts geändert', $tx2->status === 400 && str_contains($tx2->body, 'lässt sich nicht als einfacher Text') && (string) $db->value('SELECT blocks FROM pages WHERE id = ?', [$pid]) === '');

$longBody = ['_csrf' => $csrf, 'title' => '', 'slug' => 'fotografen', 'status' => 'draft', 'nav' => 'main', 'modus' => 'text', 'blocks' => $J([['t' => 'text', 'text' => 'Meine Änderung XYZ']])];
$fail = $b->post("/admin/seiten/$pid/speichern", $longBody);
check('Als Text speichern mit Fehler (leerer Titel): Eingaben bleiben im Editor erhalten', $fail->status === 400 && str_contains($fail->body, 'Meine Änderung XYZ'));

// Fotoauswahl (JSON)
$pk = $b->get('/admin/fotos/auswahl');
$pj = json_decode($pk->body, true);
check('Fotoauswahl: JSON mit fertigen Fotos, Vorschauadresse, ohne Dateinamen der Originale', $pk->status === 200 && is_array($pj) && count($pj['rows']) === 3 && str_starts_with($pj['rows'][0]['u'], '/media/') && !str_contains($pk->body, 'bloecke-900'));
$pq = json_decode($b->get('/admin/fotos/auswahl?q=Treppe')->body, true);
check('Fotoauswahl: Suche nach Titel', count($pq['rows']) === 1 && $pq['rows'][0]['id'] === $q2 && $pq['rows'][0]['a'] === 'Eine Treppe');
check('Fotoauswahl: Suche mit Platzhaltern findet nichts Falsches', count(json_decode($b->get('/admin/fotos/auswahl?q=%25')->body, true)['rows']) === 0);
$svc->catalog->trash([$q3]);
check('Fotoauswahl: Fotos im Papierkorb fehlen', count(json_decode($b->get('/admin/fotos/auswahl')->body, true)['rows']) === 2);
check('Fotoauswahl: ohne Anmeldung abgewiesen, Adresse kollidiert nicht mit /admin/fotos/{id}', $anon->get('/admin/fotos/auswahl')->status === 303 && $b->get('/admin/fotos/auswahl')->status === 200);
$db->run("INSERT INTO pages (slug,title,body,blocks,status,nav,sort_order,created_at,updated_at) VALUES ('papierkorbtest','Zeigt <Foto>','', ?, 'draft','none',0,1,1)", [Blocks::encode([['t' => 'image', 'photo' => $q3, 'alt' => 'x', 'size' => 'full', 'caption' => '', 'link' => '', 'decor' => false]])]);
$tr = $b->get('/admin/papierkorb')->body;
check('Papierkorb: nennt die Seiten, die das Foto verwenden (maskiert)', str_contains($tr, 'Wird verwendet auf: Zeigt &lt;Foto&gt;') && !str_contains($tr, 'Zeigt <Foto>'));
$svc->catalog->restore([$q3]);
$pubT = $b->get('/admin/veroeffentlichen')->body;
check('Veröffentlichen: Entwurfsseiten werden mit Namen genannt (maskiert)', str_contains($pubT, 'Noch nicht online') && str_contains($pubT, 'Zeigt &lt;Foto&gt;') && !str_contains($pubT, 'Zeigt <Foto>'));
$tr2 = $b->get('/admin/papierkorb')->body;
check('Papierkorb: nach dem Wiederherstellen kein Hinweis mehr', !str_contains($tr2, 'Wird verwendet auf'));

// Editor-Skript: keine unsichere Ausgabe, keine Fremdadressen
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/page-editor.js');
check('Editor-Skript: kein innerHTML, kein eval, keine fremden Adressen', !preg_match('~innerHTML|outerHTML|insertAdjacentHTML|eval\(|document\.write~i', $js) && !preg_match('~(src|href)\s*=\s*[\'"]https?://~i', $js));
