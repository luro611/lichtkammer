<?php
declare(strict_types=1);

// Entwurf 44, Stufe 2: „Verwendet in“, Bestätigungsseite statt stiller Aktion, Spalte „in N Seiten“, Rollback-Hinweis, Meldung nach Wiederherstellen.

use Lichtkammer\Media\Usage;

require_once __DIR__ . '/support.php';

$fu = lk_new_tmp($tmp, 'dateiusage');
$m = lk_make($fu);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$cs = static fn (): string => TestBrowser::csrf($b->get('/admin/medien'));
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdf = static fn (string $tag): string => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% $tag\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$put = static function (string $tag, string $name, int $folder = 0) use ($svc, $fu, $pdf): int {
    $p = $fu . '/' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($p, $pdf($tag));
    return $svc->files->ingest(1, $p, $name, $folder)['file_id'];
};
$dirty = static fn (): string => (string) ($db->value("SELECT v FROM meta WHERE k = 'site_dirty_n'") ?? '');

$fo = $svc->folders->create(0, 'Dokumente');
$sub = $svc->folders->create($fo, 'Unten');
$used = $put('used', '<i>Preisliste</i>.pdf', $sub);
$unused = $put('unused', 'Frei.pdf');
$old = $put('old', 'Alt.pdf');
$priv = $put('priv', 'Nur-Block.pdf');
foreach ([$used, $unused, $old] as $id) {
    $svc->files->setPublic($id, true);
}
$oldName = 'dokumente/alt.pdf';
$db->run("UPDATE files SET public_name = 'alt.pdf', legacy_public_path = ? WHERE id = ?", [$oldName, $old]);
@mkdir($svc->config->mediaDir() . '/dokumente', 0777, true);
$svc->files->repairPublicCopies();

$p1 = $svc->pages->save(null, ['title' => 'Preise', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'preise', 'blocks' => $J([
    ['t' => 'text', 'text' => 'x'], ['t' => 'download', 'file' => $used], ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'k', 'file' => $priv]], 'right' => []],
    ['t' => 'button', 'label' => 'Alt', 'href' => '/media/dokumente/alt.pdf']])]);
$p2 = $svc->pages->save(null, ['title' => 'Info', 'body' => "Siehe [Liste](/media/dokumente/alt.pdf) und [Preise](/media/dateien/" . (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$used]) . ').', 'status' => 'published', 'nav' => 'none', 'slug' => 'info']);
$svc->settings->saveHome($J([['t' => 'download', 'file' => $used]]));
$u = new Usage($db, $svc->builder);
$all = $u->files();
check('Verwendet in: Datei in Seite, Spalte und Startseite mit Blocknummer', array_values(array_filter(array_column($all[$used], 'pos'))) === ['Block 2', 'Block 1'] && in_array('Seite „Preise“', array_column($all[$used], 'label'), true) && $all[$priv][0]['pos'] === 'Block 3 (links, Eintrag 1)' && $all[$priv][0]['linked']);
check('Verwendet in: Altverweis im Block und im Seitentext (nicht verknüpft), Links auf die Seiten', count($all[$old]) === 2 && !$all[$old][0]['linked'] && array_column($all[$old], 'link') === ['/admin/seiten/' . $p2, '/admin/seiten/' . $p1]);
check('Verwendet in: Seitentext verweist auch per Adresse auf die neue Datei', in_array('Seite „Info“', array_column($all[$used], 'label'), true));
check('Verwendet in: unbenutzte Datei fehlt, usedFileIds und forFile stimmen', !isset($all[$unused]) && isset($u->usedFileIds()[$used]) && !isset($u->usedFileIds()[$unused]) && $u->forFile($unused) === [] && count($u->forFile($old)) === 2);
check('Verwendet in: Einschränkung auf Nummern', array_keys($u->files([$old])) === [$old] && $u->files([]) === []);
check('Verwendet in: Ordner liefert Dateien aus dem Unterbaum', $u->fileIdsInFolders($svc->folders, [$fo]) === [$used]);
$svc->files->setPublic($priv, true);
$pr0 = $svc->builder->publish(1);
check('Verwendet in: auf der Live-Seite laut Manifest', isset($u->liveFileIds()[$used]) && !isset($u->liveFileIds()[$unused]));

// Spalte in der Medienliste
$list = $b->get('/admin/medien?ordner=0')->body;
check('Medienliste: Spalte „in 2 Seiten“ bei benutzter Datei, keine bei unbenutzter', str_contains($list, 'in 2 Seiten') && substr_count($list, 'in 2 Seiten') >= 1);

// Bestätigungsseite: privat
$before = $dirty();
$db->run('UPDATE files SET name = ? WHERE id = ?', ['<i>Preisliste</i>.pdf', $used]);
$r = $b->post("/admin/medien/datei/$used/oeffentlich", ['_csrf' => $cs(), 'an' => '0', 'ordner' => '0']);
check('Privat: benutzte Datei zeigt „Das wird noch verwendet“ mit Seite, Block, Live-Hinweis, Name maskiert', $r->status === 200 && str_contains($r->body, 'Das wird noch') && str_contains($r->body, 'Seite „Preise“, Block 2') && str_contains($r->body, 'auf der Live-Seite')
    && str_contains($r->body, '&lt;i&gt;Preisliste&lt;/i&gt;.pdf') && !str_contains($r->body, '<i>Preisliste'));
check('Privat: Datei ist unverändert öffentlich, Hinweisband-Zähler unverändert (B5)', (int) $db->value('SELECT public FROM files WHERE id = ?', [$used]) === 1 && $dirty() === $before);
check('Privat: Bestätigungsseite trägt CSRF und „bestaetigt“', str_contains($r->body, 'name="_csrf"') && str_contains($r->body, 'name="bestaetigt" value="1"'));
check('Privat: ohne CSRF abgelehnt', $b->request('POST', "/admin/medien/datei/$used/oeffentlich", ['an' => '0', 'bestaetigt' => '1'])->status === 403 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$used]) === 1);
$r = $b->post("/admin/medien/datei/$unused/oeffentlich", ['_csrf' => $cs(), 'an' => '0', 'ordner' => '0']);
check('Privat: unbenutzte Datei sofort, ohne Frage', $r->status === 303 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$unused]) === 0);
$r = $b->post("/admin/medien/datei/$used/oeffentlich", ['_csrf' => $cs(), 'an' => '0', 'ordner' => '0', 'bestaetigt' => '1']);
check('Privat: mit „Trotzdem ausführen“ wird es ausgeführt', $r->status === 303 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$used]) === 0);
check('Privat: danach stoppt der Probelauf (Seite, Block)', str_contains(implode(' ', $svc->builder->plan()['errors']), 'Seite „Preise“, Block 2'));
$svc->files->setPublic($used, true);

// Papierkorb einer Auswahl und eines Ordners (Menge aus dem Unterbaum)
$r = $b->post('/admin/medien/loeschen', ['_csrf' => $cs(), 'ordner' => '0', 'ordner_id' => [(string) $fo]]);
check('Papierkorb: Ordner mit benutzter Datei im Unterordner fragt nach, nichts geändert (N1)', $r->status === 200 && str_contains($r->body, 'Das wird noch') && $db->value('SELECT deleted_at FROM files WHERE id = ?', [$used]) === null && $db->value('SELECT deleted_at FROM folders WHERE id = ?', [$fo]) === null);
check('Papierkorb: Bestätigungsseite wiederholt die Auswahl', str_contains($r->body, 'name="ordner_id" value="' . $fo . '"'));
$r = $b->post('/admin/medien/loeschen', ['_csrf' => $cs(), 'ordner' => '0', 'datei' => [(string) $unused]]);
check('Papierkorb: unbenutzte Datei sofort', $r->status === 303 && $db->value('SELECT deleted_at FROM files WHERE id = ?', [$unused]) !== null);
$r = $b->post('/admin/medien/loeschen', ['_csrf' => $cs(), 'ordner' => '0', 'ordner_id' => [(string) $fo], 'bestaetigt' => '1']);
check('Papierkorb: bestätigt wird der Ordner samt benutzter Datei gelöscht', $r->status === 303 && $db->value('SELECT deleted_at FROM files WHERE id = ?', [$used]) !== null);

// Wiederherstellen einer benutzten Datei: Meldung
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'zurueck', 'ordner_id' => [(string) $fo]]);
check('Wiederherstellen: benutzte Datei ist wieder da, aber privat; Meldung sagt es', $r->status === 303 && str_contains($r->headers['Location'] ?? '', 'm=zurueck_benutzt') && (int) $db->value('SELECT public FROM files WHERE id = ?', [$used]) === 0);
$fl = $b->get($r->headers['Location'] ?? '/admin/medien');
check('Wiederherstellen: Meldung steht im Wortlaut auf der Seite', str_contains($fl->body, 'Öffentlich schalten'));
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'zurueck', 'datei' => [(string) $unused]]);
check('Wiederherstellen: unbenutzte Datei bekommt die normale Meldung', str_contains($r->headers['Location'] ?? '', 'm=zurueck&'));

// Endgültig löschen
$svc->files->setPublic($used, true);
$svc->files->trash([$used]);
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'loeschen', 'datei' => [(string) $used]]);
check('Endgültig löschen: benutzte Datei fragt nach, nichts gelöscht', $r->status === 200 && str_contains($r->body, 'Das wird noch') && $db->value('SELECT 1 FROM files WHERE id = ?', [$used]) !== null);
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'loeschen', 'datei' => [(string) $used], 'bestaetigt' => '1']);
check('Endgültig löschen: bestätigt wird gelöscht', $r->status === 303 && $db->value('SELECT 1 FROM files WHERE id = ?', [$used]) === null);
$svc->files->trash([$unused]);
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'loeschen', 'datei' => [(string) $unused]]);
check('Endgültig löschen: unbenutzte Datei sofort', $r->status === 303 && $db->value('SELECT 1 FROM files WHERE id = ?', [$unused]) === null);

// Rollback-Hinweis
$svc->pages->save($p1, ['title' => 'Preise', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'preise', 'blocks' => $J([['t' => 'text', 'text' => 'x'], ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'k', 'file' => $priv]], 'right' => []]])]);
$svc->settings->saveHome('');
$svc->pages->delete($p2);
$svc->files->setPublic($priv, true);
$r1 = $svc->builder->publish(1);
$man = $svc->builder->manifest($r1['id']);
$svc->files->setPublic($priv, false);
$svc->pages->save($p1, ['title' => 'Preise', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'preise', 'blocks' => $J([['t' => 'text', 'text' => 'y']])]);
$r2 = $svc->builder->publish(1);
$rel = array_column($svc->builder->releases(), null, 'id');
check('Rollback: Fassung mit inzwischen privater Datei meldet sie (gone_files), aktive und neuere nicht', $r2['ok'] && ($rel[$r1['id']]['gone_files'] ?? 0) === 1 && ($rel[$r2['id']]['gone_files'] ?? 1) === 0);
$pub = $b->get('/admin/veroeffentlichen')->body;
check('Rollback: Seite „Veröffentlichen“ zeigt den Hinweis, Zurückholen bleibt möglich', str_contains($pub, 'inzwischen nicht mehr öffentlich') && str_contains($pub, 'Diese Fassung zurückholen'));
file_put_contents($svc->dataDir . '/site/releases/' . $r1['id'] . '/manifest.json', json_encode(array_diff_key((array) $man, ['file_ids' => 1, 'file_urls' => 1])));
check('Rollback: Fassung ohne file_urls (vor diesem Stand) ohne Hinweis', array_column($svc->builder->releases(), null, 'id')[$r1['id']]['gone_files'] === 0);
check('Rollback: Zurücksetzen klappt trotz Hinweis', $svc->builder->rollback($r1['id']) === true);
