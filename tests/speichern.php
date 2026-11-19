<?php
declare(strict_types=1);

// Entwurf 28: Speichern und Veröffentlichen (Kombiknopf, Meldungen, Hinweisband, Fehler im Bau, Sperre, Entwurf, Galerie-Status).

require_once __DIR__ . '/support.php';

$f28 = lk_new_tmp($tmp, 'speichern');
$m = lk_make($f28 . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$anon = new TestBrowser($m['app'], $m['config']);
$H = ['HTTP_X_CSRF_TOKEN' => $csrf];

$r = lk_upload($b, 'a.jpg', lk_jpeg(900, 600));
$b->request('POST', '/admin/fotos/' . $r['photo_id'] . '/verarbeiten', [], $H);
$p1 = (int) $r['photo_id'];
$svc->catalog->update($p1, ['title' => 'Hafen', 'alt_text' => 'Boote im Hafen']);
$g1 = $svc->galleries->create('Island 2022', 'Text');
$svc->galleries->addPhotos($g1, [$p1]);
$slug1 = (string) $db->value('SELECT slug FROM galleries WHERE id = ?', [$g1]);
$svc->galleries->setStatus($g1, 'published');
$b->post('/admin/seiten', ['_csrf' => $csrf, 'vorlage' => 'impressum']);
$save = static function (array $extra = [], int $id = 1, string $body = 'Alter Text', string $status = 'published') use ($b, $csrf) {
    return $b->post("/admin/seiten/$id/speichern", $extra + ['_csrf' => $csrf, 'title' => 'Impressum', 'slug' => 'impressum', 'body' => $body, 'status' => $status, 'nav' => 'footer']);
};
$first = $save();
$pub0 = $svc->builder->publish(1);
check('Vorbereitung: erste Fassung online', $pub0['ok'] && $svc->builder->current() === $pub0['id'] && $first->status === 303);
$rel0 = (string) $svc->builder->current();
$db->run("INSERT INTO meta(k, v) VALUES('site_dirty', '0') ON CONFLICT(k) DO UPDATE SET v = '0'");
$db->run("INSERT INTO meta(k, v) VALUES('site_dirty_n', '0') ON CONFLICT(k) DO UPDATE SET v = '0'");

// --- Nur speichern ---
$sv = $save([], 1, 'Neuer Text A');
check('Nur speichern: Meldung „noch nicht online“, nichts gebaut', ($sv->headers['Location'] ?? '') === '/admin/seiten/1?m=page' && $svc->builder->current() === $rel0 && str_contains($b->get('/admin/seiten/1?m=page')->body, 'Gespeichert. Noch nicht online.'));
check('Nur speichern: öffentliche Seite zeigt weiter die alte Fassung', str_contains($anon->get('/impressum/')->body, 'Alter Text') && !str_contains($anon->get('/impressum/')->body, 'Neuer Text A'));
check('Nur speichern: Merker „nicht veröffentlicht“ gesetzt, Zähler 1', $db->value("SELECT v FROM meta WHERE k = 'site_dirty'") === '1' && $db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'") === '1');
$dash = $b->get('/admin/fotos');
check('Hinweisband: „1 Änderung ist noch nicht online“ mit Knopf und Ansehen', str_contains($dash->body, 'class="pubband"') && str_contains($dash->body, '1 Änderung</strong> ist noch nicht online') && str_contains($dash->body, 'Jetzt veröffentlichen') && str_contains($dash->body, 'href="/admin/vorschau"'));
$save([], 1, 'Neuer Text B');
check('Hinweisband: zählt weiter (2 Änderungen)', str_contains($b->get('/admin/fotos')->body, '2 Änderungen</strong> sind noch nicht online'));
check('Hinweisband: nicht auf der Seite „Veröffentlichen“ selbst', !str_contains($b->get('/admin/veroeffentlichen')->body, 'class="pubband"'));
check('Hinweisband: Öffnen von „Veröffentlichen“ ändert den Zähler nicht (zählt weiter Speichervorgänge, Prüfung 31)', $db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'") === '2' && str_contains($b->get('/admin/fotos')->body, '2 Änderungen</strong>'));
$pageForm = $b->get('/admin/seiten/1')->body;
check('Formular (online): Hauptknopf „Speichern und veröffentlichen“, Zweitknopf „Nur speichern (noch nicht online)“, Sperre per data-busy',
    str_contains($pageForm, 'name="veroeffentlichen" value="1"') && str_contains($pageForm, 'Speichern und veröffentlichen') && str_contains($pageForm, 'Nur speichern (noch nicht online)') && str_contains($pageForm, 'data-busy='));
check('Formular: Status heißt „Online sichtbar“ / „Entwurf (nicht online)“', str_contains($pageForm, '>Online sichtbar<') && str_contains($pageForm, '>Entwurf (nicht online)<') && !str_contains($pageForm, 'mit dem nächsten Veröffentlichen'));

// --- Speichern und veröffentlichen ---
$auditPage = (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='page_saved'");
$auditPub = (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='site_published'");
$m['clock']->advance(61);
$sp = $save(['veroeffentlichen' => '1'], 1, 'Neuer Text C');
check('Speichern und veröffentlichen: Weiterleitung mit „veröffentlicht“, neue Fassung aktiv', ($sp->headers['Location'] ?? '') === '/admin/seiten/1?m=savedpub' && $svc->builder->current() !== $rel0);
check('Speichern und veröffentlichen: Text ist online', str_contains($anon->get('/impressum/')->body, 'Neuer Text C'));
check('Speichern und veröffentlichen: Meldung und Link „Seite ansehen (online)“', str_contains($b->get('/admin/seiten/1?m=savedpub')->body, 'Gespeichert und veröffentlicht.') && str_contains($b->get('/admin/seiten/1?m=savedpub')->body, 'Seite ansehen (online)'));
check('Speichern und veröffentlichen: Protokoll page_saved und site_published je eine Zeile mehr, Kette unversehrt',
    (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='page_saved'") === $auditPage + 1 && (int) $db->value("SELECT COUNT(*) FROM audit_log WHERE event='site_published'") === $auditPub + 1 && $svc->audit->verify()['ok']);
check('Speichern und veröffentlichen: Merker zurückgesetzt, Band weg', $db->value("SELECT v FROM meta WHERE k = 'site_dirty'") === '0' && $db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'") === '0' && !str_contains($b->get('/admin/fotos')->body, 'class="pubband"'));
check('Speichern und veröffentlichen: gespeichert wurde vor dem Bauen (Datenbank = online)', $db->value('SELECT body FROM pages WHERE id = 1') === 'Neuer Text C');

// --- Fehler im Bau: Speichern gilt trotzdem ---
$rel1 = (string) $svc->builder->current();
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Leer']);
$leer = (int) $db->value("SELECT id FROM pages WHERE title = 'Leer'");
$m['clock']->advance(61);
$bad = $b->post("/admin/seiten/$leer/speichern", ['_csrf' => $csrf, 'title' => 'Leer', 'slug' => 'leer', 'body' => '', 'status' => 'published', 'nav' => 'main', 'veroeffentlichen' => '1']);
check('Fehler im Bau: Speichern gilt (Seite steht auf „veröffentlicht“), online bleibt die alte Fassung', ($bad->headers['Location'] ?? '') === "/admin/seiten/$leer?m=savedfail&r=fehler" && $db->value('SELECT status FROM pages WHERE id = ?', [$leer]) === 'published' && $svc->builder->current() === $rel1);
$fl = $b->get("/admin/seiten/$leer?m=savedfail&r=fehler")->body;
check('Fehler im Bau: Meldung mit Grund und Knopf „Erneut versuchen“', str_contains($fl, 'Gespeichert. Veröffentlichen hat nicht geklappt, online ist weiter die alte Fassung: Vor dem Veröffentlichen sind Fehler zu beheben') && str_contains($fl, 'Erneut versuchen') && str_contains($fl, 'class="notice error"'));
check('Fehler im Bau: Merker bleibt „nicht veröffentlicht“', $db->value("SELECT v FROM meta WHERE k = 'site_dirty'") === '1');
$nBad = (int) $db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'");
check('Fehler im Bau: technischer Grund ohne doppelte Aussage', str_contains($b->get("/admin/seiten/$leer?m=savedfail&r=technisch")->body, 'alte Fassung: Ein technischer Fehler ist aufgetreten'));
check('Fehler im Bau: unbekannter Grund aus der Adresse wird nicht ausgegeben', !str_contains($b->get("/admin/seiten/$leer?m=savedfail&r=<b>x</b>")->body, '<b>x</b>') && str_contains($b->get("/admin/seiten/$leer?m=savedfail&r=zzz")->body, 'technischer Fehler'));
$b->post("/admin/seiten/$leer/loeschen", ['_csrf' => $csrf]);

// --- Sperre ---
$lock = fopen($f28 . '/a/data/site/.lock', 'c');
flock($lock, LOCK_EX);
$lk = $save(['veroeffentlichen' => '1'], 1, 'Text unter Sperre');
check('Sperre: Kombiweg zählt genau eine Änderung dazu (Merker vor dem Bau gesetzt, nicht doppelt)', (int) $db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'") === $nBad + 2);
check('Sperre: Meldung „anderes Veröffentlichen“, Text trotzdem gespeichert, nichts umgeschaltet', ($lk->headers['Location'] ?? '') === '/admin/seiten/1?m=savedfail&r=sperre' && $db->value('SELECT body FROM pages WHERE id = 1') === 'Text unter Sperre' && $svc->builder->current() === $rel1
    && str_contains($b->get('/admin/seiten/1?m=savedfail&r=sperre')->body, 'anderes Veröffentlichen'));
flock($lock, LOCK_UN);
fclose($lock);
$m['clock']->advance(61);
$retry = $b->post('/admin/veroeffentlichen', ['_csrf' => $csrf]);
check('Erneut versuchen: veröffentlicht den gespeicherten Stand', $retry->status === 200 && str_contains($anon->get('/impressum/')->body, 'Text unter Sperre') && $db->value("SELECT v FROM meta WHERE k = 'site_dirty'") === '0');

// --- Menü: Pfeile bauen nicht ---
$rel2 = (string) $svc->builder->current();
$b->post('/admin/seiten/1/verlinken', ['_csrf' => $csrf, 'nav' => 'main']);
check('Menü: Änderung baut nicht, Band erscheint', $svc->builder->current() === $rel2 && str_contains($b->get('/admin/menue')->body, 'class="pubband"'));
$pf = $b->get('/admin/seiten/1')->body;
check('Zeile „Dabei geht alles Gespeicherte online …“ nennt andere gespeicherte Änderungen (Menü)', str_contains($pf, 'Dabei geht alles Gespeicherte online') && str_contains($pf, 'Noch nicht online sind außerdem'));
$m['clock']->advance(61);
$svc->builder->publish(1);
$b->post('/admin/seiten/1/verlinken', ['_csrf' => $csrf, 'nav' => 'footer']);
$svc->builder->publish(1); // wieder sauber
$db->run("INSERT INTO meta(k, v) VALUES('site_dirty', '0') ON CONFLICT(k) DO UPDATE SET v = '0'");
check('Zeile: ohne Ungespeichertes keine Zeile', !str_contains($b->get('/admin/seiten/1')->body, 'Dabei geht alles Gespeicherte'));

// --- Entwurfsseite: online stellen ---
$b->post('/admin/seiten', ['_csrf' => $csrf, 'title' => 'Neu']);
$nid = (int) $db->value("SELECT id FROM pages WHERE title = 'Neu'");
$df = $b->get("/admin/seiten/$nid")->body;
check('Formular (Entwurf): „Speichern und online stellen“ und „Nur speichern (noch nicht online)“', str_contains($df, 'Speichern und online stellen') && str_contains($df, 'Nur speichern (noch nicht online)') && str_contains($df, 'name="online" value="1"'));
$m['clock']->advance(61);
$dr = $b->post("/admin/seiten/$nid/speichern", ['_csrf' => $csrf, 'title' => 'Neu', 'slug' => 'neu', 'body' => 'Neue Seite Inhalt', 'status' => 'draft', 'nav' => 'none']);
check('Entwurf speichern: bleibt Entwurf, nicht online', ($dr->headers['Location'] ?? '') === "/admin/seiten/$nid?m=page" && $db->value('SELECT status FROM pages WHERE id = ?', [$nid]) === 'draft' && $anon->get('/neu/')->status === 404);
$m['clock']->advance(61);
$on = $b->post("/admin/seiten/$nid/speichern", ['_csrf' => $csrf, 'title' => 'Neu', 'slug' => 'neu', 'body' => 'Neue Seite Inhalt', 'status' => 'draft', 'nav' => 'none', 'online' => '1']);
check('Entwurf online stellen: Status „veröffentlicht“, gebaut, erreichbar', ($on->headers['Location'] ?? '') === "/admin/seiten/$nid?m=savedpub" && $db->value('SELECT status FROM pages WHERE id = ?', [$nid]) === 'published' && str_contains($anon->get('/neu/')->body, 'Neue Seite Inhalt'));

// --- Adresse ändern: Weiterleitung? (Befund zu Entwurf 28, Risiko „Weiterleitungen“) ---
$m['clock']->advance(61);
$redirBefore = (int) $db->value('SELECT COUNT(*) FROM redirects');
$b->post("/admin/seiten/$nid/speichern", ['_csrf' => $csrf, 'title' => 'Neu', 'slug' => 'neu-anders', 'body' => 'Neue Seite Inhalt', 'status' => 'published', 'nav' => 'none', 'veroeffentlichen' => '1']);
$old = $anon->get('/neu/');
check('Adresse geändert: Seite unter neuer Adresse online, alte Adresse leitet automatisch weiter (R3, Prüfung 31)', str_contains($anon->get('/neu-anders/')->body, 'Neue Seite Inhalt') && (int) $db->value('SELECT COUNT(*) FROM redirects') === $redirBefore && $old->status === 301 && ($old->headers['Location'] ?? '') === '/neu-anders/');

// --- Einstellungen: Design ---
$m['clock']->advance(61);
$b->post('/admin/einstellungen', ['_csrf' => $csrf, 'site_name' => 'br.foto', 'tagline' => '', 'design' => 'blau']);
check('Einstellungen nur speichern: Design noch nicht online', !str_contains($anon->get('/impressum/')->body, 'data-design="blau"'));
$m['clock']->advance(61);
$sd = $b->post('/admin/einstellungen', ['_csrf' => $csrf, 'site_name' => 'br.foto', 'tagline' => '', 'design' => 'blau', 'veroeffentlichen' => '1']);
check('Einstellungen speichern und veröffentlichen: Design ist online', ($sd->headers['Location'] ?? '') === '/admin/einstellungen?m=savedpub' && str_contains($anon->get('/impressum/')->body, 'data-design="blau"'));
$sf = $b->get('/admin/einstellungen')->body;
check('Einstellungen, Logos, Galerie: Formular warnt vor Verlust ungespeicherter Eingaben (data-guard, Prüfung 31)', str_contains($sf, 'action="/admin/einstellungen" data-guard') && str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin.js'), 'form[data-guard]'));
check('Einstellungen: Hauptknopf, Zweitknopf, Hinweis auf alle Seiten', str_contains($sf, 'Speichern und veröffentlichen') && str_contains($sf, 'Nur speichern (noch nicht online)') && str_contains($sf, 'alle neu erzeugt'));

// --- Logos ---
$m['clock']->advance(61);
$lg = $b->post('/admin/logos/zuordnen', ['_csrf' => $csrf, 'light' => '', 'dark' => '', 'favicon' => '', 'veroeffentlichen' => '1']);
check('Logos: speichern und veröffentlichen', ($lg->headers['Location'] ?? '') === '/admin/logos?m=savedpub');
check('Logos: nur speichern bleibt möglich', ($b->post('/admin/logos/zuordnen', ['_csrf' => $csrf, 'light' => '', 'dark' => '', 'favicon' => ''])->headers['Location'] ?? '') === '/admin/logos?m=logochosen');

// --- Startseite ---
$m['clock']->advance(61);
$hb = json_encode(['v' => 1, 'blocks' => [['t' => 'text', 'text' => 'Willkommen auf der Startseite', 'style' => 'normal']]]);
$hs = $b->post('/admin/startseite', ['_csrf' => $csrf, 'blocks' => $hb, 'veroeffentlichen' => '1']);
check('Startseite: speichern und veröffentlichen, Text online', ($hs->headers['Location'] ?? '') === '/admin/startseite?m=savedpub' && str_contains($anon->get('/')->body, 'Willkommen auf der Startseite'));
$hf = $b->get('/admin/startseite')->body;
check('Startseite: Haupt- und Zweitknopf', str_contains($hf, 'Speichern und veröffentlichen') && str_contains($hf, 'Nur speichern (noch nicht online)'));

// --- Galerie: Status baut sofort ---
$m['clock']->advance(61);
$gd = $b->post("/admin/galerien/$g1/status", ['_csrf' => $csrf, 'status' => 'draft']);
check('Galerie auf Entwurf setzen: baut sofort, ist nicht mehr online', ($gd->headers['Location'] ?? '') === "/admin/galerien/$g1?m=galoffline" && $anon->get("/galerien/$slug1/")->status === 404 && $db->value('SELECT status FROM galleries WHERE id = ?', [$g1]) === 'draft');
$m['clock']->advance(61);
$go = $b->post("/admin/galerien/$g1/status", ['_csrf' => $csrf, 'status' => 'published']);
check('Galerie online stellen: baut sofort, ist online', ($go->headers['Location'] ?? '') === "/admin/galerien/$g1?m=galonline" && $anon->get("/galerien/$slug1/")->status === 200);
$gp = $b->get("/admin/galerien/$g1")->body;
check('Galerie: Knöpfe „Galerie auf Entwurf setzen“, „Speichern und veröffentlichen“, Status „Online sichtbar“', str_contains($gp, 'Galerie auf Entwurf setzen') && str_contains($gp, 'Speichern und veröffentlichen') && str_contains($gp, 'Online sichtbar') && !str_contains($gp, 'Als veröffentlicht markieren'));
$m['clock']->advance(61);
$gs = $b->post("/admin/galerien/$g1/speichern", ['_csrf' => $csrf, 'title' => 'Island 2022', 'description' => 'Geändert', 'slug' => $slug1, 'veroeffentlichen' => '1']);
check('Galerie: Text speichern und veröffentlichen', ($gs->headers['Location'] ?? '') === "/admin/galerien/$g1?m=savedpub" && str_contains($anon->get("/galerien/$slug1/")->body, 'Geändert'));
$m['clock']->advance(61);
$b->post("/admin/galerien/$g1/speichern", ['_csrf' => $csrf, 'title' => 'Island 2022', 'description' => 'Nur gespeichert', 'slug' => $slug1]);
check('Galerie: nur speichern baut nicht', !str_contains($anon->get("/galerien/$slug1/")->body, 'Nur gespeichert'));

// --- Vorschau, Meldungstexte, Zugriff ---
check('Vorschau: Band „Vorschau, nicht online“', str_contains($b->get('/admin/vorschau')->body, 'Vorschau, nicht online'));
check('Zugriff: Kombiknopf ohne Anmeldung abgewiesen, ohne CSRF-Token abgelehnt', $anon->post('/admin/seiten/1/speichern', ['veroeffentlichen' => '1'])->status === 303 && $b->post('/admin/seiten/1/speichern', ['veroeffentlichen' => '1'])->status === 403);
$js = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/admin.js');
check('Sperre nach Klick: Skript sperrt Knöpfe erst nach dem Absenden (setTimeout), sonst ginge der Wert verloren', str_contains($js, 'data-busy') && str_contains($js, "'submit'") && str_contains($js, 'setTimeout'));
check('Protokoll: Kette nach allem unversehrt', $svc->audit->verify()['ok']);
