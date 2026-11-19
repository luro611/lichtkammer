<?php
declare(strict_types=1);

// R3 (Prüfung 31): alte Adressen umbenannter, online stehender Seiten und Galerien leiten beim Bau automatisch weiter. R1: 20 Fassungen.

require_once __DIR__ . '/support.php';

use Lichtkammer\Site\Builder;
use Lichtkammer\Site\Pages;

$fw = lk_new_tmp($tmp, 'weiterleitung');
$m = lk_make($fw . '/a');
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$csrf = TestBrowser::csrf($b->get('/admin/seiten'));
$anon = new TestBrowser($m['app'], $m['config']);

$pg = static fn (string $title, string $slug, string $status = 'published'): int => $svc->pages->save(null, ['title' => $title, 'body' => 'Text ' . $title, 'status' => $status, 'nav' => 'none', 'slug' => $slug]);
$ren = static function (int $id, string $slug, ?string $status = null) use ($svc): void {
    $cur = $svc->pages->get($id);
    $svc->pages->save($id, ['title' => (string) $cur['title'], 'body' => (string) $cur['body'], 'status' => $status ?? (string) $cur['status'], 'nav' => 'none', 'slug' => $slug]);
};
$pub = static function () use ($svc, $m): void {
    $m['clock']->advance(61);
    $r = $svc->builder->publish(1);
    if (!$r['ok']) {
        throw new RuntimeException('Veröffentlichen fehlgeschlagen: ' . $r['error']);
    }
};
$map = static function () use ($svc): array {
    $c = $svc->builder->current();
    $d = json_decode((string) @file_get_contents($svc->builder->siteDir() . '/releases/' . $c . '/_redirects.json'), true);
    return is_array($d) ? $d : [];
};

$a = $pg('Über uns', 'ueber-uns');
$pub();
check('Vorbereitung: keine Weiterleitungen ohne Umbenennung', $map() === []);

// --- einfache Umbenennung ---
$ren($a, 'about');
check('Umbenennen einer Online-Seite: alte Adresse wird vermerkt', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE kind='page' AND old_slug='ueber-uns' AND target_id=?", [$a]) === 1);
$plan = $svc->builder->plan();
check('Plan zeigt die neue Weiterleitung', $plan['redirects'] === [['from' => '/ueber-uns/', 'to' => '/about/']]);
check('Vor dem Veröffentlichen: alte Adresse noch ohne Weiterleitung (alte Fassung liefert die Seite)', $anon->get('/ueber-uns/')->status === 200);
$pub();
check('Nach dem Veröffentlichen: _redirects.json enthält alte → neue Adresse', $map() === ['/ueber-uns' => '/about/']);
$rd = $anon->get('/ueber-uns/');
check('Alte Adresse leitet weiter, Ziel /about/ (Status wie bei Umzugsweiterleitungen: kurz zwischengespeichert, mit LK_REDIRECTS_PERMANENT dauerhaft)', $rd->status === 301 && ($rd->headers['Location'] ?? '') === '/about/' && str_contains((string) ($rd->headers['Cache-Control'] ?? ''), 'max-age'));
check('Plan danach ohne neue Weiterleitung', $svc->builder->plan()['redirects'] === []);

// --- Kette: mehrfach geändert, alle alten zeigen auf die aktuelle ---
$ren($a, 'team');
$pub();
check('Kette A→B→C: beide alten Adressen zeigen direkt auf die aktuelle', $map() === ['/about' => '/team/', '/ueber-uns' => '/team/']);

// --- Rücknahme A→B→A ---
$ren($a, 'about');
check('Rücknahme: die zurückgeholte Adresse hat keinen Eintrag mehr (keine Schleife)', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE old_slug='about'") === 0);
$pub();
check('Rücknahme: /about/ ist wieder die Seite, /team/ und /ueber-uns leiten dorthin', $map() === ['/team' => '/about/', '/ueber-uns' => '/about/'] && $anon->get('/about/')->status === 200);

// --- Vorrang einer neuen Seite mit früher benutzter Adresse ---
$n = $pg('Neu', 'team');
check('Neue Seite mit früher benutzter Adresse: Eintrag entfällt', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE old_slug='team'") === 0);
$pub();
check('Neue Seite hat Vorrang: /team/ ist sie selbst, keine Weiterleitung', $map() === ['/ueber-uns' => '/about/'] && $anon->get('/team/')->status === 200);
$ren($n, 'team-neu');
$pub();
check('Verschiebt sich die neue Seite später, bleibt die alte Weiterleitung nicht am falschen Ziel', ($map()['/team'] ?? null) === '/team-neu/' && ($map()['/ueber-uns'] ?? null) === '/about/');

// --- Entwurf wird nie weitergeleitet, Entwurf umbenannt erzeugt nichts ---
$dr = $pg('Entwurf', 'entwurf', 'draft');
$ren($dr, 'entwurf-2');
check('Entwurf umbenannt: kein Eintrag', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE target_id=? AND kind='page'", [$dr]) === 0);
$ren($a, 'about-b');
$ren($a, 'about-b', 'draft');
$pub();
check('Seite auf Entwurf: ihre alten Adressen leiten nicht ins Leere', !isset($map()['/about']) && !isset($map()['/ueber-uns']));
$ren($a, 'about-b', 'published');
$pub();
check('Seite wieder online: Weiterleitungen sind wieder da', ($map()['/ueber-uns'] ?? null) === '/about-b/');

// --- reservierte Adresse im Verlauf ---
$db->run("INSERT INTO slug_history(kind, target_id, old_slug, created_at) VALUES('page', ?, 'admin', 1)", [$a]);
$db->run("INSERT INTO slug_history(kind, target_id, old_slug, created_at) VALUES('page', ?, 'galerien', 1)", [$a]);
$pub();
check('Reservierte Adressen werden nie umgeleitet', !isset($map()['/admin']) && !isset($map()['/galerien']));

// --- Löschen ---
$gone = $pg('Weg', 'weg');
$pub();
$ren($gone, 'weg-neu');
$svc->pages->delete($gone);
check('Gelöschte Seite: Verlauf wird entfernt (Kennung könnte neu vergeben werden)', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE target_id=? AND kind='page'", [$gone]) === 0);

// --- Galerien ---
$p = lk_upload($b, 'g.jpg', lk_jpeg(900, 600));
$b->request('POST', '/admin/fotos/' . $p['photo_id'] . '/verarbeiten', [], ['HTTP_X_CSRF_TOKEN' => $csrf]);
$ph = (int) $p['photo_id'];
$svc->catalog->update($ph, ['title' => 'Hafen', 'alt_text' => 'Boote']);
$g = $svc->galleries->create('Island', '');
$svc->galleries->addPhotos($g, [$ph]);
$gs = (string) $db->value('SELECT slug FROM galleries WHERE id=?', [$g]);
$svc->galleries->setStatus($g, 'published');
$pub();
$svc->galleries->update($g, 'Island', '', false, 'island-2022');
$pub();
check('Galerie umbenannt: alte Adresse leitet auf die neue', ($map()['/galerien/' . $gs] ?? null) === '/galerien/island-2022/');
$svc->galleries->update($g, 'Island', '', false, $gs);
$pub();
check('Galerie A→B→A: keine Schleife, die Zwischenadresse leitet auf A', !isset($map()['/galerien/' . $gs]) && ($map()['/galerien/island-2022'] ?? null) === '/galerien/' . $gs . '/');
$svc->galleries->delete($g);
check('Galerie gelöscht: Verlauf entfernt', (int) $db->value("SELECT COUNT(*) FROM slug_history WHERE kind='gallery'") === 0);

// --- Importer: eigene Weiterleitungen bleiben, Zusammenführung ---
$db->run("INSERT OR REPLACE INTO redirects(from_path, kind, target_id, to_path, created_at) VALUES('/wp-alt', 'page', ?, '', 1)", [$a]);
$db->run("INSERT OR REPLACE INTO redirects(from_path, kind, target_id, to_path, created_at) VALUES('/ueber-uns', 'path', NULL, '/impressum/', 1)", []);
$pub();
check('Import + automatisch: beide Quellen zusammengeführt, Importeintrag gewinnt bei gleicher Adresse', ($map()['/wp-alt'] ?? null) === '/about-b/' && ($map()['/ueber-uns'] ?? null) === '/impressum/');
$db->run("DELETE FROM redirects");

// --- Schema ---
check('Migration: Tabelle slug_history da, Version = Anzahl der Migrationen', \Lichtkammer\Auth\Schema::version($db) === count(\Lichtkammer\Auth\Schema::migrations()) && $db->value("SELECT 1 FROM sqlite_master WHERE name='slug_history'") === 1);

// --- Oberfläche ---
check('Seiten-Editor: Hinweis zur automatischen Weiterleitung', str_contains($b->get("/admin/seiten/$a")->body, 'leitet die alte Adresse nach dem Veröffentlichen automatisch hierher weiter'));
$g2 = $svc->galleries->create('Zwei', '');
check('Galerie-Formular: Hinweis zur automatischen Weiterleitung', str_contains($b->get("/admin/galerien/$g2")->body, 'leitet nach dem Veröffentlichen automatisch hierher weiter'));
$ren($a, 'about-c');
check('Veröffentlichen-Seite zeigt neue Weiterleitungen', str_contains($b->get('/admin/veroeffentlichen')->body, '/about-b/ → /about-c/'));

// --- R1: 20 Fassungen ---
check('R1: KEEP ist 20, Sicherungen 20', Builder::KEEP === 20 && Builder::KEEP_BACKUPS === 20);
for ($i = 0; $i < 22; $i++) {
    $pub();
}
$rel = $svc->builder->releases();
check('R1: genau 20 Fassungen, jüngste zuerst, aktive dabei', count($rel) === 20 && $rel[0]['active'] && strcmp($rel[0]['id'], $rel[19]['id']) > 0);
check('R1: höchstens 20 Datenbankkopien', count(glob($fw . '/a/data/backups/lichtkammer-*.sqlite') ?: []) <= 20);
$oldest = $rel[19]['id'];
check('R1: älteste Fassung zurückholen geht', $svc->builder->rollback($oldest) && $svc->builder->current() === $oldest);
$mm = new ReflectionMethod($svc->builder, 'prune');
$mm->invoke($svc->builder);
check('R1: Aufräumen lässt die aktive (auch ältere) Fassung stehen, sonst bleiben 21', is_dir($fw . '/a/data/site/releases/' . $oldest) && count($svc->builder->releases()) === 20);
$list = $b->get('/admin/veroeffentlichen')->body;
check('R1: Liste zeigt 20 Fassungen mit Datum und Uhrzeit', substr_count($list, 'Diese Fassung zurückholen') === 19 && preg_match('/\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2} UTC/', $list) === 1);
