<?php
declare(strict_types=1);

// Schritt 4: Bildverwaltung (Migration 3, Galerien, Reihenfolge, Titelbild, Mehrfach, Papierkorb, Suche, Fotografen).

use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Media\Catalog;
use Lichtkammer\Media\Galleries;
use Lichtkammer\Media\MediaException;

require_once __DIR__ . '/support.php';

$f4 = lk_new_tmp($tmp, 'verwaltung');

// --- Migration 3 auf Bestandsdaten ---
@mkdir($f4 . '/mig', 0777, true);
$dbFile = $f4 . '/mig/lichtkammer.sqlite';
$raw = new PDO('sqlite:' . $dbFile);
$raw->exec(Schema::migrations()[0]);
$raw->exec(Schema::migrations()[1]);
$raw->exec("INSERT INTO meta(k,v) VALUES('schema_version','2')");
$raw->exec("INSERT INTO galleries(id, slug, title, created_at, updated_at) VALUES (1,'alt','Alt',1,1)");
$ins = "INSERT INTO photos(id, gallery_id, sort_order, photographer, token, original_path, sha256, bytes, mime, created_at, updated_at) VALUES";
$raw->exec("$ins (1, 1, 2, 'Anna', 't1', 'o/1.jpg', 's1', 1, 'image/jpeg', 10, 10), (2, 1, 1, 'Anna', 't2', 'o/2.jpg', 's2', 1, 'image/jpeg', 11, 11), (3, NULL, 0, '', 't3', 'o/3.jpg', 's3', 1, 'image/jpeg', 12, 12)");
unset($raw);
$migrated = Database::open($dbFile);
check('Migration 3: Version 3', Schema::version($migrated) === 3);
check('Migration 3: Sicherung vor der Migration abgelegt', is_file($dbFile . '.vor-schema-2.bak'));
$bak = new PDO('sqlite:' . $dbFile . '.vor-schema-2.bak');
check('Migration 3: Sicherung hat den alten Stand (Version 2, ohne neue Tabelle)', $bak->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn() === '2'
    && $bak->query("SELECT COUNT(*) FROM sqlite_master WHERE name='gallery_photos'")->fetchColumn() === 0
    && (int) $bak->query('SELECT COUNT(*) FROM photos')->fetchColumn() === 3);
unset($bak);
check('Migration 3: bisherige Zuordnung übernommen, mit Position', $migrated->all('SELECT photo_id, position FROM gallery_photos WHERE gallery_id = 1 ORDER BY position') === [['photo_id' => 2, 'position' => 1], ['photo_id' => 1, 'position' => 2]]);
check('Migration 3: Foto ohne Galerie bleibt ohne Zuordnung', $migrated->value('SELECT COUNT(*) FROM gallery_photos WHERE photo_id = 3') === 0);
check('Migration 3: Freitext-Fotograf in Liste übernommen und verknüpft', (int) $migrated->value('SELECT COUNT(*) FROM photographers') === 1
    && (int) $migrated->value('SELECT COUNT(*) FROM photos WHERE photographer_id = (SELECT id FROM photographers WHERE name = \'Anna\')') === 2);
check('Migration 3: nichts Bestehendes verändert (Fotos, Galerie, alte Spalten)', (int) $migrated->value('SELECT COUNT(*) FROM photos') === 3 && (int) $migrated->value('SELECT gallery_id FROM photos WHERE id = 1') === 1
    && $migrated->value("SELECT title FROM galleries WHERE id = 1") === 'Alt');
unset($migrated);
$again = Database::open($dbFile);
check('Migration 3: zweites Öffnen ändert nichts und legt keine zweite Sicherung an', Schema::version($again) === 3 && (int) $again->value('SELECT COUNT(*) FROM gallery_photos') === 2 && count(glob($f4 . '/mig/*.bak')) === 1);
unset($again);
check('Migration 3: keine halbfertigen Sicherungsdateien (.tmp) übrig', glob($f4 . '/mig/*.tmp') === []);

// --- Umgebung ---
$m = lk_make($f4 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);

foreach (['/admin/galerien', '/admin/galerien/1', '/admin/papierkorb', '/admin/fotografen', '/admin/fotos/1', '/admin/galerien/1/hinzufuegen'] as $path) {
    check("Zugriff: $path ohne Anmeldung → Anmeldeseite", $anon->get($path)->status === 303);
}
foreach (['/admin/galerien', '/admin/galerien/1/speichern', '/admin/galerien/1/status', '/admin/galerien/1/loeschen', '/admin/fotos/1/speichern', '/admin/fotos/mehrfach', '/admin/papierkorb', '/admin/fotografen', '/admin/galerien/reihenfolge'] as $path) {
    check("Zugriff: POST $path ohne Anmeldung abgewiesen", $anon->post($path, ['title' => 'x'])->status === 303);
    check("Zugriff: POST $path ohne CSRF-Token abgelehnt", $b->post($path, ['title' => 'x'])->status === 403);
}

$csrf = TestBrowser::csrf($b->get('/admin/galerien'));
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];

/** Foto hochladen und verarbeiten; Rückgabe: Foto-Nummer. */
$mk = function (int $w, int $h = 400) use ($b, $csrf): int {
    $r = lk_upload($b, "foto-$w.jpg", lk_jpeg($w, $h));
    $b->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
    return (int) $r['photo_id'];
};
$p1 = $mk(900);
$p2 = $mk(901);
$p3 = $mk(902);
$p4 = $mk(903);
check('Vorbereitung: vier Fotos fertig', (int) $db->value("SELECT COUNT(*) FROM photos WHERE status='ready'") === 4);
$sha = fn (int $id): string => hash_file('sha256', $m['config']->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$id]));
$origHash = [$p1 => $sha($p1), $p2 => $sha($p2), $p3 => $sha($p3), $p4 => $sha($p4)];

// --- Galerien anlegen ---
$bad = $b->post('/admin/galerien', ['_csrf' => $csrf, 'title' => '   ']);
check('Galerie: leerer Titel → Meldung, nichts angelegt', $bad->status === 400 && str_contains($bad->body, 'Titel') && (int) $db->value('SELECT COUNT(*) FROM galleries') === 0);
$c1 = $b->post('/admin/galerien', ['_csrf' => $csrf, 'title' => 'Größe & Übung <b>']);
check('Galerie: angelegt, Weiterleitung auf die Galerie', $c1->status === 303 && preg_match('~^/admin/galerien/(\d+)\?m=created$~', $c1->headers['Location'] ?? '', $mm) === 1);
$g1 = (int) $mm[1];
check('Galerie: Adresse aus dem Titel (Umlaute, Sonderzeichen)', $db->value('SELECT slug FROM galleries WHERE id = ?', [$g1]) === 'groesse-uebung-b');
$b->post('/admin/galerien', ['_csrf' => $csrf, 'title' => 'Zweite']);
$g2 = (int) $db->value("SELECT id FROM galleries WHERE title = 'Zweite'");
$b->post('/admin/galerien', ['_csrf' => $csrf, 'title' => 'Zweite']);
check('Galerie: gleiche Titel bekommen verschiedene Adressen', $db->value('SELECT COUNT(DISTINCT slug) FROM galleries') === 3 && $db->value("SELECT COUNT(*) FROM galleries WHERE slug = 'zweite-2'") === 1);
check('Galerie: Titel wird beim Anzeigen maskiert', !str_contains($b->get("/admin/galerien/$g1")->body, '<b>') && str_contains($b->get("/admin/galerien/$g1")->body, '&lt;b&gt;'));
check('Galerie: unbekannte Nummer → 404', $b->get('/admin/galerien/9999')->status === 404);
check('Slug: nie leer', Galleries::slugify('!!!') === 'galerie' && Galleries::slugify('Île de Ré') !== '');

// --- Fotos zuordnen, mehrere Galerien ---
$add = $b->request('POST', "/admin/galerien/$g1/hinzufuegen", ['_csrf' => $csrf, 'ids' => [(string) $p1, (string) $p2, (string) $p3, 'abc', '-5', (string) $p1, '999999']]);
check('Zuordnung: hinzugefügt (ohne Doppelte, ohne Ungültiges, ohne fremde Nummern)', $add->status === 303 && str_contains($add->headers['Location'], 'm=added&n=3'));
$pos = fn (int $g): array => array_map('intval', array_column($db->all('SELECT photo_id FROM gallery_photos WHERE gallery_id = ? ORDER BY position, photo_id', [$g]), 'photo_id'));
check('Zuordnung: Reihenfolge = Reihenfolge des Hinzufügens', $pos($g1) === [$p1, $p2, $p3]);
$b->request('POST', "/admin/galerien/$g2/hinzufuegen", ['_csrf' => $csrf, 'ids' => [(string) $p3, (string) $p1]]);
check('Mehrere Galerien: ein Foto liegt in zwei Galerien, eigene Reihenfolge je Galerie', $pos($g2) === [$p3, $p1] && $pos($g1) === [$p1, $p2, $p3]);
check('Mehrere Galerien: Foto nur einmal in der Mediathek', (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p1]) === 1);
check('Zuordnung: Hinzufügen-Seite zeigt vorhandene als „schon in der Galerie“', str_contains($b->get("/admin/galerien/$g2/hinzufuegen")->body, 'schon in der Galerie'));

// --- Reihenfolge ---
$o = $b->request('POST', "/admin/galerien/$g1/reihenfolge", ['ids' => [(string) $p3, (string) $p1]], $H);
check('Reihenfolge: Ziehen speichert, Rest folgt in alter Reihenfolge', $o->status === 200 && $pos($g1) === [$p3, $p1, $p2]);
$b->request('POST', "/admin/galerien/$g1/reihenfolge", ['ids' => [(string) $p2, (string) $p4, (string) $p2, '0']], $H);
check('Reihenfolge: fremde und doppelte Nummern werden ignoriert', $pos($g1) === [$p2, $p3, $p1]);
check('Reihenfolge: Positionen lückenlos 1..n', array_map('intval', array_column($db->all('SELECT position FROM gallery_photos WHERE gallery_id = ? ORDER BY position', [$g1]), 'position')) === [1, 2, 3]);
check('Reihenfolge: andere Galerie unberührt', $pos($g2) === [$p3, $p1]);
$mv = $b->post("/admin/galerien/$g1/foto-bewegen", ['_csrf' => $csrf, 'foto' => (string) $p1, 'richtung' => 'hoch']);
check('Reihenfolge: Pfeil nach oben (ohne Skript)', $mv->status === 303 && $pos($g1) === [$p2, $p1, $p3]);
$b->post("/admin/galerien/$g1/foto-bewegen", ['_csrf' => $csrf, 'foto' => (string) $p2, 'richtung' => 'hoch']);
check('Reihenfolge: Pfeil am Anfang bleibt wirkungslos', $pos($g1) === [$p2, $p1, $p3]);
$db->run('UPDATE photos SET taken_at = ? WHERE id = ?', ['2021-05-01 10:00:00', $p1]);
$db->run('UPDATE photos SET taken_at = ? WHERE id = ?', ['2019-01-01 10:00:00', $p2]);
$db->run('UPDATE photos SET taken_at = NULL WHERE id = ?', [$p3]);
$b->post("/admin/galerien/$g1/sortieren", ['_csrf' => $csrf, 'modus' => 'date_asc']);
check('Sortieren: Aufnahmedatum alt → neu, ohne Datum am Ende', $pos($g1) === [$p2, $p1, $p3]);
$b->post("/admin/galerien/$g1/sortieren", ['_csrf' => $csrf, 'modus' => 'date_desc']);
check('Sortieren: Aufnahmedatum neu → alt, ohne Datum am Ende', $pos($g1) === [$p1, $p2, $p3]);
$db->run("UPDATE photos SET original_name = 'b.jpg' WHERE id = ?", [$p1]);
$db->run("UPDATE photos SET original_name = 'A.jpg' WHERE id = ?", [$p2]);
$db->run("UPDATE photos SET original_name = 'c.jpg' WHERE id = ?", [$p3]);
$b->post("/admin/galerien/$g1/sortieren", ['_csrf' => $csrf, 'modus' => 'name']);
check('Sortieren: nach Dateiname ohne Beachtung der Schreibweise', $pos($g1) === [$p2, $p1, $p3]);
check('Sortieren: unbekannte Art → Fehlermeldung, Reihenfolge bleibt', $b->post("/admin/galerien/$g1/sortieren", ['_csrf' => $csrf, 'modus' => 'zufall'])->status === 400 && $pos($g1) === [$p2, $p1, $p3]);
$b->request('POST', "/admin/galerien/$g1/reihenfolge", ['ids' => [(string) $p1, (string) $p2, (string) $p3]], $H);
check('Sortieren: danach weiter von Hand änderbar', $pos($g1) === [$p1, $p2, $p3]);

// --- Galerien ordnen ---
$gs = fn (): array => array_map('intval', array_column($db->all('SELECT id FROM galleries ORDER BY sort_order, id'), 'id'));
$g3 = (int) $db->value("SELECT id FROM galleries WHERE slug = 'zweite-2'");
$b->request('POST', '/admin/galerien/reihenfolge', ['ids' => [(string) $g3, (string) $g1]], $H);
check('Galerien: Reihenfolge per Ziehen', $gs() === [$g3, $g1, $g2]);
$b->post("/admin/galerien/$g1/bewegen", ['_csrf' => $csrf, 'richtung' => 'hoch']);
check('Galerien: Pfeil nach oben', $gs() === [$g1, $g3, $g2]);

// --- Titelbild ---
check('Titelbild: Standard ist das erste Foto', $svc->galleries->effectiveCover($g1) === $p1);
$cv = $b->post("/admin/galerien/$g1/titelbild", ['_csrf' => $csrf, 'foto' => (string) $p3]);
check('Titelbild: per Klick gesetzt', $cv->status === 303 && $svc->galleries->effectiveCover($g1) === $p3 && (int) $db->value('SELECT cover_photo_id FROM galleries WHERE id = ?', [$g1]) === $p3);
check('Titelbild: Foto aus fremder Galerie abgelehnt', $b->post("/admin/galerien/$g1/titelbild", ['_csrf' => $csrf, 'foto' => (string) $p4])->status === 400 && $svc->galleries->effectiveCover($g1) === $p3);
check('Titelbild: Seite markiert es', str_contains($b->get("/admin/galerien/$g1")->body, 'Titelbild</span>'));
$b->request('POST', "/admin/galerien/$g1/entfernen", ['_csrf' => $csrf, 'ids' => [(string) $p3]]);
check('Titelbild: Entfernen aus der Galerie → fällt auf das erste Foto zurück', $svc->galleries->effectiveCover($g1) === $p1 && $db->value('SELECT cover_photo_id FROM galleries WHERE id = ?', [$g1]) === null);
check('Entfernen: Foto bleibt in der Mediathek und in der anderen Galerie', $pos($g1) === [$p1, $p2] && $pos($g2) === [$p3, $p1] && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p3]) === 1);
$b->request('POST', "/admin/galerien/$g1/hinzufuegen", ['_csrf' => $csrf, 'ids' => [(string) $p3]]);

// --- Veröffentlichen mit Alt-Text-Pflicht ---
$pub = $b->post("/admin/galerien/$g1/status", ['_csrf' => $csrf, 'status' => 'published']);
check('Veröffentlichen: ohne Alt-Text gesperrt, Liste der fehlenden', $pub->status === 400 && $db->value('SELECT status FROM galleries WHERE id = ?', [$g1]) === 'draft'
    && str_contains($pub->body, 'Alt-Text fehlt') && str_contains($pub->body, "/admin/fotos/$p1"));
$empty = $b->post("/admin/galerien/$g3/status", ['_csrf' => $csrf, 'status' => 'published']);
check('Veröffentlichen: leere Galerie gesperrt', $empty->status === 400 && str_contains($empty->body, 'noch keine Fotos'));
foreach ([$p1, $p2, $p3] as $i => $pid) {
    $r = $b->post("/admin/fotos/$pid/speichern", ['_csrf' => $csrf, 'title' => "T$i", 'alt_text' => "Alt $i", 'caption' => '', 'place' => '', 'year' => '', 'photographer_id' => '']);
}
$pub = $b->post("/admin/galerien/$g1/status", ['_csrf' => $csrf, 'status' => 'published']);
check('Veröffentlichen: mit Alt-Texten erlaubt', $pub->status === 303 && $db->value('SELECT status FROM galleries WHERE id = ?', [$g1]) === 'published' && (int) $svc->audit->verify()['ok'] === 1);
$bl = $svc->galleries->blockers($g1);
check('Veröffentlichen: nichts blockiert', $bl === []);
$b->post("/admin/galerien/$g1/status", ['_csrf' => $csrf, 'status' => 'draft']);
check('Veröffentlichen: zurück zum Entwurf', $db->value('SELECT status FROM galleries WHERE id = ?', [$g1]) === 'draft');

// --- Foto bearbeiten ---
$fp = $b->get("/admin/fotos/$p1");
check('Foto: Bearbeiten-Seite mit Vorschau und Formular', $fp->status === 200 && str_contains($fp->body, 'name="alt_text"') && str_contains($fp->body, '/media/'));
$svc->catalog->addPhotographer('Beate');
$svc->catalog->addPhotographer('Chris');
$ph1 = (int) $db->value("SELECT id FROM photographers WHERE name = 'Beate'");
$ph2 = (int) $db->value("SELECT id FROM photographers WHERE name = 'Chris'");
$sv = $b->post("/admin/fotos/$p1/speichern", ['_csrf' => $csrf, 'title' => 'Hafen <i>', 'caption' => 'Morgens', 'alt_text' => 'Boote im Hafen', 'place' => 'Lissabon', 'year' => '2019', 'photographer_id' => (string) $ph1]);
$row = $db->one('SELECT * FROM photos WHERE id = ?', [$p1]);
check('Foto: Felder gespeichert', $sv->status === 303 && $row['title'] === 'Hafen <i>' && $row['place'] === 'Lissabon' && (int) $row['year'] === 2019 && (int) $row['photographer_id'] === $ph1 && $row['alt_text'] === 'Boote im Hafen');
check('Foto: Titel wird maskiert ausgegeben', !str_contains($b->get("/admin/fotos/$p1")->body, '<i>') && str_contains($b->get("/admin/fotos/$p1")->body, 'Hafen &lt;i&gt;'));
$badYear = $b->post("/admin/fotos/$p1/speichern", ['_csrf' => $csrf, 'title' => 'ANDERS', 'caption' => '', 'alt_text' => 'x', 'place' => '', 'year' => '1492', 'photographer_id' => '']);
check('Foto: ungültiges Jahr → Meldung, nichts geändert, Eingabe bleibt im Formular', $badYear->status === 400 && str_contains($badYear->body, 'zwischen 1800 und 2100') && $db->value('SELECT title FROM photos WHERE id = ?', [$p1]) === 'Hafen <i>' && str_contains($badYear->body, 'ANDERS'));
$badPh = $b->post("/admin/fotos/$p1/speichern", ['_csrf' => $csrf, 'title' => 'x', 'caption' => '', 'alt_text' => 'x', 'place' => '', 'year' => '', 'photographer_id' => '9999']);
check('Foto: unbekannter Fotograf abgelehnt', $badPh->status === 400);
$long = $b->post("/admin/fotos/$p2/speichern", ['_csrf' => $csrf, 'title' => str_repeat('ä', 500), 'caption' => '', 'alt_text' => 'a', 'place' => '', 'year' => '', 'photographer_id' => '']);
check('Foto: zu lange Titel werden gekürzt (200 Zeichen)', mb_strlen((string) $db->value('SELECT title FROM photos WHERE id = ?', [$p2])) === 200);
check('Foto: unbekannte Nummer → 404', $b->get('/admin/fotos/9999')->status === 404 && $b->post('/admin/fotos/9999/speichern', ['_csrf' => $csrf, 'title' => 'x'])->status === 404);

// --- Mehrfachbearbeitung ---
$db->run("UPDATE photos SET place = 'Alt-Ort', year = 2000 WHERE id IN (?,?,?)", [$p2, $p3, $p4]);
$form = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'bearbeiten', 'zurueck' => '/admin/fotos', 'ids' => [(string) $p2, (string) $p3]]);
check('Mehrfach: Formular nennt die Zahl der betroffenen Fotos', $form->status === 200 && str_contains($form->body, '<strong>2</strong> Foto'));
$bulk = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'speichern', 'zurueck' => '/admin/fotos', 'ids' => [(string) $p2, (string) $p3],
    'set_place' => '1', 'place' => 'Porto', 'set_photographer_id' => '1', 'photographer_id' => (string) $ph2, 'year' => '1999', 'title' => 'NICHT', 'galerie_add' => (string) $g3]);
$r2 = $db->one('SELECT * FROM photos WHERE id = ?', [$p2]);
$r3 = $db->one('SELECT * FROM photos WHERE id = ?', [$p3]);
$r4 = $db->one('SELECT * FROM photos WHERE id = ?', [$p4]);
check('Mehrfach: nur angehakte Felder gesetzt, andere bleiben', $bulk->status === 303 && $r2['place'] === 'Porto' && $r3['place'] === 'Porto' && (int) $r2['photographer_id'] === $ph2
    && (int) $r2['year'] === 2000 && $r2['title'] !== 'NICHT');
check('Mehrfach: nicht ausgewählte Fotos unberührt', $r4['place'] === 'Alt-Ort' && $r4['photographer_id'] === null);
check('Mehrfach: Galerie hinzufügen', $pos($g3) === [$p2, $p3]);
$badBulk = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'speichern', 'zurueck' => '/admin/fotos', 'ids' => [(string) $p2, (string) $p3], 'set_place' => '1', 'place' => 'Nie', 'set_year' => '1', 'year' => 'abc']);
check('Mehrfach: ungültiger Wert → alles oder nichts, Eingabe bleibt stehen', $badBulk->status === 400 && $db->value('SELECT place FROM photos WHERE id = ?', [$p2]) === 'Porto' && str_contains($badBulk->body, 'value="Nie"'));
$none = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'papierkorb', 'zurueck' => '/admin/fotos']);
check('Mehrfach: ohne Auswahl passiert nichts', $none->status === 303 && str_contains($none->headers['Location'], 'm=nothing'));
$evil = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'papierkorb', 'zurueck' => 'https://evil.example/', 'ids' => ['99999']]);
check('Mehrfach: Rücksprung nur auf /admin-Pfade', str_starts_with($evil->headers['Location'], '/admin'));

// --- Suche ---
$db->run("UPDATE photos SET place = 'Köln', year = 2015, title = '100% Rheinufer' WHERE id = ?", [$p4]);
$q = fn (array $f): array => array_map('intval', array_column($svc->catalog->search($f)['rows'], 'id'));
check('Suche: nach Titel', $q(['q' => 'Rheinufer']) === [$p4]);
check('Suche: nach Ort', $q(['q' => 'porto']) === [$p3, $p2]);
check('Suche: nach Jahr', $q(['q' => '2015']) === [$p4]);
check('Suche: nach Galerietitel', in_array($p1, $q(['q' => 'Größe']), true) && !in_array($p4, $q(['q' => 'Größe']), true));
check('Suche: Platzhalter % und _ wirken nicht als Joker', $q(['q' => '%']) === [$p4] && $q(['q' => '_']) === []);
check('Suche: Filter „ohne Galerie“', $q(['filter' => 'ohne_galerie']) === [$p4]);
check('Suche: Filter „Alt-Text fehlt“', $q(['filter' => 'ohne_alt']) === [$p4]);
check('Suche: Filter Fotograf, Jahr, Galerie', $q(['photographer' => $ph2]) === [$p3, $p2] && $q(['year' => 2015]) === [$p4] && $q(['gallery' => $g3]) === [$p3, $p2]);
check('Suche: Seite zeigt Treffer und Suchfeld', str_contains($b->get('/admin/fotos?q=Rheinufer')->body, '1 gefunden') && str_contains($b->get('/admin/fotos?q=Rheinufer')->body, 'type="search"'));
check('Suche: Eingabe mit Anführungszeichen bricht nichts', $b->get('/admin/fotos?q=' . rawurlencode('"\'<script>'))->status === 200 && !str_contains($b->get('/admin/fotos?q=' . rawurlencode('"\'<script>'))->body, '<script>alert'));
$now = $m['clock']->now();
for ($i = 0; $i < 50; $i++) {
    $db->run("INSERT INTO photos(token, original_path, sha256, bytes, mime, created_at, updated_at, status) VALUES(?,?,?,?,?,?,?, 'ready')", ["fill$i", "x/fill$i.jpg", "fill$i", 1, 'image/jpeg', $now, $now]);
}
$pg = $svc->catalog->search(['page' => 2]);
check('Suche: Seiten à ' . Catalog::PAGE . ' Fotos', $pg['pages'] === 2 && count($pg['rows']) === (int) $pg['total'] - Catalog::PAGE && count($svc->catalog->search(['page' => 1])['rows']) === Catalog::PAGE);
check('Suche: Seite außerhalb des Bereichs wird begrenzt', $svc->catalog->search(['page' => 99])['page'] === 2);
$db->run("DELETE FROM photos WHERE token LIKE 'fill%'");

// --- Papierkorb ---
$variantFiles = array_map(fn (array $v): string => $m['config']->mediaDir() . '/' . $v['path'], $db->all('SELECT path FROM photo_variants WHERE photo_id = ?', [$p3]));
check('Papierkorb: Vorher sind Varianten da', $variantFiles !== [] && is_file($variantFiles[0]));
$tr = $b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'papierkorb', 'zurueck' => '/admin/fotos', 'ids' => [(string) $p3]]);
check('Papierkorb: Foto markiert, Original bleibt', $tr->status === 303 && $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$p3]) !== null
    && $sha($p3) === $origHash[$p3]);
$gone = true;
foreach ($variantFiles as $vf) {
    $gone = $gone && !is_file($vf);
}
check('Papierkorb: Varianten sofort entfernt (nicht mehr öffentlich abrufbar)', $gone && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id = ?', [$p3]) === 0);
check('Papierkorb: Foto fehlt in Galerie und Mediathek', !in_array($p3, array_map(fn ($r) => (int) $r['id'], $svc->galleries->photos($g1)), true) && !in_array($p3, $q([]), true) && !str_contains($b->get("/admin/galerien/$g1")->body, "id=\"foto-$p3\""));
check('Papierkorb: Zuordnung bleibt für das Wiederherstellen bestehen', in_array($p3, $pos($g1), true) && in_array($p3, $pos($g2), true));
$b->post("/admin/galerien/$g1/titelbild", ['_csrf' => $csrf, 'foto' => (string) $p1]);
check('Papierkorb: Galerie ohne Foto im Papierkorb veröffentlichbar (zählt nicht mit)', $svc->galleries->blockers($g1) === []);
check('Papierkorb: Seite listet es mit Restzeit', str_contains($b->get('/admin/papierkorb')->body, '30 Tage') && str_contains($b->get('/admin/papierkorb')->body, 'T2'));
check('Papierkorb: Dublette wird weiter erkannt', lk_upload($b, 'again.jpg', lk_jpeg(902, 400))['in_trash'] === true);

$orderBefore = $pos($g1);
$rs = $b->request('POST', '/admin/papierkorb', ['_csrf' => $csrf, 'aktion' => 'wiederherstellen', 'ids' => [(string) $p3]]);
check('Wiederherstellen: Foto zurück, Varianten neu erzeugt', $rs->status === 303 && $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$p3]) === null
    && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id = ?', [$p3]) > 0 && is_file($m['config']->mediaDir() . '/' . $db->value("SELECT path FROM photo_variants WHERE photo_id = ? AND format = 'jpeg' LIMIT 1", [$p3])));
check('Wiederherstellen: an alter Position in beiden Galerien', $pos($g1) === $orderBefore && $pos($g2) === [$p3, $p1] && in_array($p3, array_map(fn ($r) => (int) $r['id'], $svc->galleries->photos($g1)), true));
check('Wiederherstellen: Texte erhalten, Original unverändert', $db->value('SELECT title FROM photos WHERE id = ?', [$p3]) === 'T2' && $sha($p3) === $origHash[$p3]);

// endgültig löschen
$b->request('POST', '/admin/fotos/mehrfach', ['_csrf' => $csrf, 'aktion' => 'papierkorb', 'zurueck' => '/admin/fotos', 'ids' => [(string) $p4]]);
$origPath = $m['config']->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$p4]);
$notTrashed = $b->request('POST', '/admin/papierkorb', ['_csrf' => $csrf, 'aktion' => 'loeschen', 'ids' => [(string) $p1]]);
check('Endgültig löschen: nur Fotos aus dem Papierkorb', (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p1]) === 1 && is_file($m['config']->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$p1])));
$pu = $b->request('POST', '/admin/papierkorb', ['_csrf' => $csrf, 'aktion' => 'loeschen', 'ids' => [(string) $p4]]);
check('Endgültig löschen: Zeile, Zuordnungen und Original weg', $pu->status === 303 && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p4]) === 0 && !is_file($origPath));
check('Endgültig löschen: Protokoll vermerkt, Kette intakt', (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event = 'photos_purged'") === 1 && $svc->audit->verify()['ok']);

// automatisch nach 30 Tagen
$p5 = $mk(904);
$p6 = $mk(905);
$svc->catalog->trash([$p5]);
$m['clock']->advance(20 * 86400);
$svc->catalog->trash([$p6]);
$m['clock']->advance(11 * 86400);
$o5 = $m['config']->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$p5]);
$o6 = $m['config']->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$p6]);
$b2 = lk_login($m, 'lutz', TEST_PASSWORD, $s['secret']);
$b2->get('/admin/fotos');
check('Aufräumen: nach 30 Tagen automatisch endgültig gelöscht (mit Original)', (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p5]) === 0 && !is_file($o5));
check('Aufräumen: jüngere Papierkorb-Fotos bleiben', (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p6]) === 1 && is_file($o6));
check('Aufräumen: Galerie-Titelbild auf gelöschtes Foto wird leer', $svc->galleries->effectiveCover($g1) !== null);

// --- Galerie löschen ---
$cnt = (int) $db->value('SELECT COUNT(*) FROM photos');
$del = $b2->post("/admin/galerien/$g2/loeschen", ['_csrf' => TestBrowser::csrf($b2->get('/admin/galerien'))]);
check('Galerie löschen: Fotos und Originale bleiben', $del->status === 303 && (int) $db->value('SELECT COUNT(*) FROM galleries WHERE id = ?', [$g2]) === 0 && (int) $db->value('SELECT COUNT(*) FROM photos') === $cnt
    && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ?', [$g2]) === 0 && $sha($p1) === $origHash[$p1]);
check('Galerie löschen: Foto bleibt in der anderen Galerie', in_array($p1, $pos($g1), true));

// --- Fotografen ---
$csrf2 = TestBrowser::csrf($b2->get('/admin/fotografen'));
$dupP = $b2->post('/admin/fotografen', ['_csrf' => $csrf2, 'aktion' => 'neu', 'name' => 'beate']);
check('Fotografen: doppelter Name (ohne Beachtung der Schreibweise) abgelehnt', $dupP->status === 400 && str_contains($dupP->body, 'schon in der Liste'));
$b2->post('/admin/fotografen', ['_csrf' => $csrf2, 'aktion' => 'umbenennen', 'id' => (string) $ph1, 'name' => 'Beate R.']);
check('Fotografen: umbenennen wirkt an allen Fotos', $db->value('SELECT name FROM photographers WHERE id = ?', [$ph1]) === 'Beate R.' && (int) $db->value('SELECT photographer_id FROM photos WHERE id = ?', [$p1]) === $ph1);
$b2->post('/admin/fotografen', ['_csrf' => $csrf2, 'aktion' => 'loeschen', 'id' => (string) $ph1]);
check('Fotografen: entfernen löscht keine Fotos, nur die Angabe', $db->value('SELECT photographer_id FROM photos WHERE id = ?', [$p1]) === null && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$p1]) === 1);

// --- Hochladen direkt in eine Galerie ---
$g4 = $svc->galleries->create('Direkt');
$st = json_decode($b2->post('/admin/upload/start', ['_csrf' => $csrf2, 'name' => 'd.jpg', 'size' => (string) strlen(lk_jpeg(910, 300)), 'gallery' => (string) $g4])->body, true);
$bytes = lk_jpeg(910, 300);
$b2->request('POST', '/admin/upload/' . $st['id'] . '/chunk?offset=0', [], ['HTTP_X_CSRF_TOKEN' => $csrf2], $bytes);
$fin = json_decode($b2->request('POST', '/admin/upload/' . $st['id'] . '/finish', [], ['HTTP_X_CSRF_TOKEN' => $csrf2])->body, true);
check('Upload in Galerie: Foto liegt ans Ende der Galerie', $pos($g4) === [(int) $fin['photo_id']]);
check('Upload in Galerie: Galerieseite hat den Upload-Bereich mit Galerie-Nummer', str_contains($b2->get("/admin/galerien/$g4")->body, 'data-gallery="' . $g4 . '"'));

// --- Nachprüfung Opus: Atomarität, Papierkorb-Nachbarn, Pfad-Schutz, Sicherung ---
$tmpJ = $f4 . '/unique.jpg';
file_put_contents($tmpJ, lk_jpeg(700, 500));
$before = (int) $db->value('SELECT COUNT(*) FROM photos');
try {
    $svc->library->ingest(1, $tmpJ, 'x.jpg', 987654);
    $threw = false;
} catch (\Throwable) {
    $threw = true;
}
check('Ingest in unbekannte Galerie: Fehler, keine Foto-Zeile ohne Original zurück', $threw && (int) $db->value('SELECT COUNT(*) FROM photos') === $before);

$gm = $svc->galleries->create('Nachbarn');
$ids3 = [];
foreach ([1, 2, 3] as $k) {
    $db->run("INSERT INTO photos(token, original_path, sha256, bytes, mime, status, alt_text, created_at, updated_at) VALUES(?,?,?,1,'image/jpeg','ready','a',1,1)", ["nb$k", "nb/$k.jpg", "nbsha$k"]);
    $ids3[$k] = $db->lastId();
}
$svc->galleries->addPhotos($gm, [$ids3[1], $ids3[2], $ids3[3]]);
$svc->catalog->trash([$ids3[2]]);
$svc->galleries->movePhoto($gm, $ids3[3], 'hoch');
check('Foto verschieben: überspringt Foto im Papierkorb, sichtbare Reihenfolge ändert sich', array_map(static fn (array $r): int => (int) $r['id'], $svc->galleries->photos($gm)) === [$ids3[3], $ids3[1]]);
$svc->catalog->restore([$ids3[2]]);

$outside = $f4 . '/outside.txt';
file_put_contents($outside, 'bleibt');
$db->run("INSERT INTO photos(token, original_path, sha256, bytes, mime, deleted_at, created_at, updated_at) VALUES('evil', ?, 'evilsha', 1, 'image/jpeg', 1, 1, 1)", ['../../outside.txt']);
$evil = $db->lastId();
$svc->catalog->purge([$evil]);
check('Endgültig löschen: Pfad mit .. löscht nichts außerhalb der Originale', is_file($outside) && $db->value('SELECT 1 FROM photos WHERE id = ?', [$evil]) === null);

// --- Fehlerfälle und Sicherheit ---
check('Flash: nur feste Meldungen, kein freier Text aus der Adresse', !str_contains($b2->get('/admin/galerien?m=%3Cscript%3E')->body, '<script>') && !str_contains($b2->get('/admin/galerien?m=Hallo')->body, 'Hallo') && str_contains($b2->get('/admin/galerien?m=created')->body, 'Galerie angelegt'));
$all = $b2->get('/admin/fotos')->body . $b2->get("/admin/galerien/$g1")->body . $b2->get('/admin/galerien')->body . $b2->get('/admin/papierkorb')->body . $b2->get('/admin/fotografen')->body . $b2->get("/admin/fotos/$p1")->body . $b2->get("/admin/galerien/$g1/hinzufuegen")->body;
check('Seiten: ohne Inline-Skript und Inline-Stil (CSP)', !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\sonclick=|\sonchange=~i', $all));
check('Seiten: Skript und Stil nur lokal', !preg_match('~(src|href)="https?://(?!lurohome\.de")~', $all));
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin.js');
check('admin.js: ohne innerHTML/eval/Fremdquellen', !preg_match('~eval\(|innerHTML|document\.write|https?://~', $js));
check('Protokoll: neue Ereignisse nur mit Nummern, Kette intakt', $svc->audit->verify()['ok'] && (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event IN ('gallery_saved','gallery_deleted','gallery_published','photos_saved','photos_trashed','photos_restored','photos_purged') AND detail GLOB '*[^a-z0-9_:.=,/-]*'") === 0);
try {
    $svc->galleries->create('');
    $threw = false;
} catch (MediaException) {
    $threw = true;
}
check('Dienst: Galerie ohne Titel wirft MediaException', $threw);
check('Fremdschlüssel: keine Verwaisten (gallery_photos)', (int) $db->value('SELECT COUNT(*) FROM gallery_photos gp WHERE NOT EXISTS (SELECT 1 FROM photos p WHERE p.id = gp.photo_id) OR NOT EXISTS (SELECT 1 FROM galleries g WHERE g.id = gp.gallery_id)') === 0);
check('Alle Originale der übrigen Fotos unverändert', $sha($p1) === $origHash[$p1] && $sha($p2) === $origHash[$p2] && $sha($p3) === $origHash[$p3]);
