<?php
declare(strict_types=1);

// Entwurf 44, Stufe 1a: Dateien aus Medien in Blöcken (Knopf mit `file`, Block „download“), Auflösung beim Bau, Fehler im Probelauf,
// Vorschau-Platzhalter, Manifest, Abbruch vor dem Umschalten. Der Editor kennt das Feld noch nicht; getestet wird über JSON und PHP.

use Lichtkammer\Media\Files;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Site\Blocks;

require_once __DIR__ . '/support.php';

$fb = lk_new_tmp($tmp, 'dateiblocks');
$m = lk_make($fb);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE);
$thrownMsg = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (MediaException $e) {
        return $e->getMessage();
    }
    return null;
};
$pdf = static fn (string $tag): string => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% $tag\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$put = static function (string $tag, string $name) use ($svc, $fb, $pdf): int {
    $p = $fb . '/' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($p, $pdf($tag));
    return $svc->files->ingest(1, $p, $name, 0)['file_id'];
};
$mediaDir = $svc->config->mediaDir();

// ---------- Format ----------
$oldJson = '{"v":1,"blocks":[{"t":"button","label":"Los","href":"/media/dokumente/x.pdf","style":"normal"},{"t":"columns","left":[{"t":"text","text":"l","style":"normal"}],"right":[{"t":"button","label":"m","href":"mailto:a@b.de","style":"mitte"}]}]}';
check('Datei-Blöcke: alte Blöcke (Knopf mit Adresse, Spalten) bleiben beim Lesen und Schreiben byte-gleich', Blocks::encode(Blocks::parse($oldJson)) === $oldJson && Blocks::encode(Blocks::parse(Blocks::encode(Blocks::parse($oldJson)))) === $oldJson);
$bt = Blocks::parse($J([['t' => 'button', 'label' => 'Preise', 'href' => 'https://boese.example/', 'file' => 12, 'style' => 'mitte']]));
check('Datei-Blöcke: Knopf mit file: Adresse wird geleert, file steht hinter den bisherigen Schlüsseln', array_keys($bt[0]) === ['t', 'label', 'href', 'style', 'file'] && $bt[0]['href'] === '' && $bt[0]['file'] === 12 && $bt[0]['style'] === 'mitte');
check('Datei-Blöcke: Knopf mit file ohne Beschriftung ist ein Fehler (der Block verschwindet nicht still)', $thrownMsg(fn () => Blocks::parse($J([['t' => 'button', 'label' => '', 'href' => '', 'file' => 12]]))) === 'Block 1: Der Knopf braucht eine Beschriftung.');
foreach (['12abc', 1.5, -3, 0, '0', 99999999999, [1], null, '', true, '１２'] as $odd) {
    $k = Blocks::parse($J([['t' => 'button', 'label' => 'x', 'href' => '/a/', 'file' => $odd]]));
    check('Datei-Blöcke: file=' . json_encode($odd) . ' zählt als „keine Datei“ (Knopf bleibt ein Adress-Knopf)', !isset($k[0]['file']) && $k[0]['href'] === '/a/');
    $e = $thrownMsg(fn () => Blocks::parse($J([['t' => 'download', 'file' => $odd]])));
    check('Datei-Blöcke: Download mit file=' . json_encode($odd) . ' → „Bitte eine Datei auswählen.“', $e === 'Block 1: Bitte eine Datei auswählen.');
}
$dl = Blocks::parse($J([['t' => 'download', 'file' => '7', 'title' => str_repeat('ä', 130), 'style' => 'wild', 'href' => 'javascript:alert(1)', 'bytes' => 1]]));
check('Datei-Blöcke: Download wird neu aufgebaut (Titel 120, Stil Vorgabe, Fremdes verworfen)', $dl[0] === ['t' => 'download', 'file' => 7, 'title' => str_repeat('ä', 120), 'style' => 'normal']);
check('Datei-Blöcke: Download-Stil „flaeche“ erlaubt', Blocks::parse($J([['t' => 'download', 'file' => 7, 'style' => 'flaeche']]))[0]['style'] === 'flaeche');
$nested = Blocks::parse($J([['t' => 'text', 'text' => 'a'], ['t' => 'download', 'file' => 3], ['t' => 'columns', 'left' => [['t' => 'text', 'text' => 'x'], ['t' => 'download', 'file' => 4]], 'right' => [['t' => 'button', 'label' => 'k', 'file' => 3]]]]));
check('Datei-Blöcke: Download darf in Spalten stehen; fileIds sammelt rekursiv ohne Doppelte', array_column($nested[2]['left'], 't') === ['text', 'download'] && Blocks::fileIds($nested) === [3, 4]);
check('Datei-Blöcke: fileRefs nennt die Blocknummer, in Spalten Seite und Eintrag', Blocks::fileRefs($nested) === [
    ['pos' => 'Block 2', 'file' => 3], ['pos' => 'Block 3 (links, Eintrag 2)', 'file' => 4], ['pos' => 'Block 3 (rechts, Eintrag 1)', 'file' => 3]]);
check('Datei-Blöcke: Seite mit Datei lässt sich nicht als Text speichern', Blocks::toMarkdown($nested) === null && Blocks::toMarkdown([['t' => 'download', 'file' => 3, 'title' => '', 'style' => 'normal']]) === null);
check('Datei-Blöcke: Spalten-Meldung nennt den neuen Typ', str_contains((string) $thrownMsg(fn () => Blocks::parse($J([['t' => 'columns', 'left' => [['t' => 'gallery', 'photos' => [['photo' => 1]]]], 'right' => []]]))), 'Datei-Download'));

// ---------- Speichern gegen die Datenbank ----------
$idPub = $put('preise', 'Preisliste 2026.pdf');
$idPriv = $put('privat', 'Intern.pdf');
$svc->files->setPublic($idPub, true);
$e = $thrownMsg(fn () => Blocks::parse($J([['t' => 'text', 'text' => 'x'], ['t' => 'download', 'file' => 99999]]), $db));
check('Speichern: unbekannte Datei → „Block 2: Die Datei gibt es nicht (mehr)“', $e === 'Block 2: Die Datei gibt es nicht (mehr). Bitte eine andere wählen.');
$e = $thrownMsg(fn () => Blocks::parse($J([['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'x', 'file' => 99999]], 'right' => []]]), $db));
check('Speichern: unbekannte Datei im Knopf in einer Spalte nennt die Stelle', $e !== null && str_starts_with($e, 'Block 1 (links, Eintrag 1): Die Datei gibt es nicht'));
check('Speichern: private Datei lässt sich speichern (geprüft wird erst beim Bau)', count(Blocks::parse($J([['t' => 'download', 'file' => $idPriv]]), $db)) === 1);
$idGone = $put('weg', 'Weg.pdf');
$svc->files->trash([$idGone]);
check('Speichern: Datei im Papierkorb wird abgelehnt', str_contains((string) $thrownMsg(fn () => Blocks::parse($J([['t' => 'download', 'file' => $idGone]]), $db)), 'gibt es nicht (mehr)'));

// ---------- Bau ----------
$pid = $svc->pages->save(null, ['title' => 'Preise', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'preise', 'blocks' => $J([
    ['t' => 'heading', 'level' => 2, 'text' => 'Preise'],
    ['t' => 'download', 'file' => $idPub, 'title' => '<script>alert(1)</script> **fett**'],
    ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'Liste "laden"', 'file' => $idPriv]], 'right' => []],
])]);
$pubName = (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$idPub]);
$db->run('UPDATE files SET bytes = 1258291 WHERE id = ?', [$idPub]);
$plan = $svc->builder->plan();
check('Bau: private Datei im Knopf sperrt das Veröffentlichen mit Seite, Block (Spalte) und Dateiname', $plan['errors'] !== [] && str_contains(implode(' ', $plan['errors']), 'Seite „Preise“, Block 3 (links, Eintrag 1): Datei „Intern.pdf“ ist nicht mehr öffentlich'));
$pv = $b->get('/admin/vorschau/preise')->body;
check('Vorschau: öffentliche Datei als Link auf /media/dateien (ohne Vorschau-Präfix), Titel maskiert, Größe aus der Datenbank', str_contains($pv, '<a class="bfile-link" href="/media/dateien/' . $pubName . '">')
    && str_contains($pv, '<span class="bfile-title">&lt;script&gt;alert(1)&lt;/script&gt; **fett**</span>') && str_contains($pv, '<span class="bfile-meta">PDF · 1,2 MB</span>') && !str_contains($pv, '<script>alert'));
check('Vorschau: private Datei zeigt einen Platzhalter ohne Link', str_contains($pv, 'bfile-missing') && str_contains($pv, 'Liste &quot;laden&quot;: Datei nicht verfügbar') && !str_contains($pv, 'admin/medien/datei') && substr_count($pv, '/media/dateien/') === 1);
$svc->files->setPublic($idPriv, true);
$privName = (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$idPriv]);
check('Bau: nach dem Öffentlichschalten keine Fehler mehr', $svc->builder->plan()['errors'] === []);
$pub = $svc->builder->publish(1);
check('Bau: Veröffentlichen klappt', $pub['ok'] === true);
$rel = (string) $svc->builder->current();
$html = (string) file_get_contents($svc->dataDir . '/site/releases/' . $rel . '/preise/index.html');
check('Bau: Download-Block und Knopf mit Datei erscheinen mit festem Ausgabemuster', str_contains($html, '<p class="bfile"><a class="bfile-link" href="/media/dateien/' . $pubName . '"><span class="bfile-title">')
    && str_contains($html, '<p class="bbtn"><a class="knopf" href="/media/dateien/' . $privName . '"><span>Liste &quot;laden&quot;</span></a></p>') && preg_match('~<p class="(bfile|bbtn)[^>]*><a [^>]*(target=| download)~', $html) === 0);
$man = $svc->builder->manifest($rel);
check('Manifest: file_ids und file_urls der benutzten Dateien', $man !== null && $man['file_ids'] === [$idPub, $idPriv] && $man['file_urls'] === [(string) $idPub => '/media/dateien/' . $pubName, (string) $idPriv => '/media/dateien/' . $privName]);

// Nicht verfügbar in allen Spielarten
$bad = [
    'privat' => static function () use ($db, $idPub): void { $db->run('UPDATE files SET public = 0 WHERE id = ?', [$idPub]); },
    'Papierkorb mit public=1 (Ordner-Papierkorb)' => static function () use ($db, $idPub): void { $db->run('UPDATE files SET deleted_at = 5 WHERE id = ?', [$idPub]); },
    'gelöscht' => static function () use ($db, $idPub): void { $db->run('DELETE FROM files WHERE id = ?', [$idPub]); },
    'Kopie fehlt' => static function () use ($mediaDir, $pubName): void { unlink($mediaDir . '/dateien/' . $pubName); },
    'Name mit ../' => static function () use ($db, $idPub): void { $db->run("UPDATE files SET public_name = '../../x.pdf' WHERE id = ?", [$idPub]); },
    'Name mit Zeilenumbruch am Ende' => static function () use ($db, $idPub, $pubName): void { $db->run('UPDATE files SET public_name = ? WHERE id = ?', [$pubName . "\n", $idPub]); },
    'Altpfad ungültig' => static function () use ($db, $idPub): void { $db->run("UPDATE files SET legacy_public_path = 'dokumente/../../a.pdf' WHERE id = ?", [$idPub]); },
    'Altpfad mit Zeilenumbruch' => static function () use ($db, $idPub): void { $db->run("UPDATE files SET legacy_public_path = ? WHERE id = ?", ["dokumente/a.pdf\n", $idPub]); },
];
$orig = $db->one('SELECT * FROM files WHERE id = ?', [$idPub]);
foreach ($bad as $label => $break) {
    $db->run('DELETE FROM files WHERE id = ?', [$idPub]);
    $db->run('INSERT INTO files(id, token, folder_id, name, kind, mime, bytes, sha256, path, fingerprint, uploaded_by, created_at, updated_at, public, public_name, legacy_public_path, deleted_at, trash_root_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$orig['id'], $orig['token'], $orig['folder_id'], $orig['name'], $orig['kind'], $orig['mime'], $orig['bytes'], $orig['sha256'], $orig['path'], $orig['fingerprint'], $orig['uploaded_by'], $orig['created_at'], $orig['updated_at'], 1, $pubName, '', null, null]);
    @copy($svc->dataDir . '/files/' . $orig['path'], $mediaDir . '/dateien/' . $pubName);
    $break();
    $plan = $svc->builder->plan();
    $pvx = $b->get('/admin/vorschau/preise')->body;
    $text = implode(' ', $plan['errors']);
    check("Bau ($label): harter Fehler mit Seite und Block, Vorschau ohne Link", str_contains($text, 'Seite „Preise“, Block 2:') && str_contains($pvx, 'Datei nicht verfügbar') && !str_contains($pvx, 'href="/media/dateien/' . $pubName) && !str_contains($pvx, '../') && !str_contains($pvx, 'dokumente/'));
    check("Bau ($label): Veröffentlichen wird verweigert, die aktive Fassung bleibt", $svc->builder->publish(1)['code'] === 'fehler' && $svc->builder->current() === $rel);
}
// Wieder heil machen
$db->run('DELETE FROM files WHERE id = ?', [$idPub]);
$db->run('INSERT INTO files(id, token, folder_id, name, kind, mime, bytes, sha256, path, fingerprint, uploaded_by, created_at, updated_at, public, public_name, legacy_public_path) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
    [$orig['id'], $orig['token'], $orig['folder_id'], $orig['name'], $orig['kind'], $orig['mime'], $orig['bytes'], $orig['sha256'], $orig['path'], $orig['fingerprint'], $orig['uploaded_by'], $orig['created_at'], $orig['updated_at'], 1, $pubName, '']);
@copy($svc->dataDir . '/files/' . $orig['path'], $mediaDir . '/dateien/' . $pubName);
check('Bau: nach dem Beheben wieder frei', $svc->builder->plan()['errors'] === []);

// Entwurfsseite mit fehlender Datei sperrt nicht (der Bau rendert nur Veröffentlichtes), die Vorschau warnt
$draft = $svc->pages->save(null, ['title' => 'Entwurf', 'body' => '', 'status' => 'draft', 'nav' => 'none', 'slug' => 'entwurf', 'blocks' => $J([['t' => 'download', 'file' => $put('entwurf', 'E.pdf')]])]);
check('Bau: Entwurfsseite mit privater Datei sperrt das Veröffentlichen nicht', $svc->builder->plan()['errors'] === []);
check('Vorschau: Entwurfsseite mit privater Datei zeigt den Platzhalter', str_contains($b->get('/admin/vorschau/entwurf')->body, 'bfile-missing'));

// Startseite
$svc->settings->saveHome(Blocks::encode(Blocks::parse($J([['t' => 'download', 'file' => $idPriv], ['t' => 'button', 'label' => 'Alt', 'href' => '/media/dokumente/alt.pdf']]))));
check('Bau: Startseite mit öffentlicher Datei sperrt nicht', $svc->builder->plan()['errors'] === []);
$svc->files->setPublic($idPriv, false);
check('Bau: Startseite mit nicht mehr öffentlicher Datei sperrt immer („Startseite, Block 1“)', str_contains(implode(' ', $svc->builder->plan()['errors']), 'Startseite, Block 1: Datei „Intern.pdf“ ist nicht mehr öffentlich'));
$svc->files->setPublic($idPriv, true);
check('Vorschau: alter Knopf mit /media/-Adresse bekommt kein Vorschau-Präfix mehr', str_contains($b->get('/admin/vorschau')->body, 'href="/media/dokumente/alt.pdf"') && !str_contains($b->get('/admin/vorschau')->body, '/admin/vorschau/media/'));
$svc->settings->saveHome('');

// Rennen: Datei wird privat gestellt, während veröffentlicht wird
$before = array_map('basename', glob($svc->dataDir . '/site/releases/*', GLOB_ONLYDIR) ?: []);
$svc->builder->beforeSwitch = function () use ($svc, $idPub): void {
    $svc->files->setPublic($idPub, false);
};
$race = $svc->builder->publish(1);
$svc->builder->beforeSwitch = null;
$after = array_map('basename', glob($svc->dataDir . '/site/releases/*', GLOB_ONLYDIR) ?: []);
check('Rennen: Privatstellen während des Baus bricht mit „datei“ ab', $race['ok'] === false && $race['code'] === 'datei' && str_contains($race['error'], 'noch einmal veröffentlichen'));
check('Rennen: der Abbruch hinterlässt keine wählbare Fassung, keinen Bauordner, die aktive Fassung bleibt', $before === $after && $svc->builder->current() === $rel && glob($svc->dataDir . '/site/building-*') === [] && count($svc->builder->releases()) === count($before));
$svc->files->setPublic($idPub, true);

// Alle gespeicherten Seiten bleiben beim Lesen und Schreiben byte-gleich
$allSame = true;
foreach ($db->all("SELECT blocks FROM pages WHERE blocks <> ''") as $row) {
    $allSame = $allSame && Blocks::encode(Blocks::decode((string) $row['blocks'])) === (string) $row['blocks'];
}
check('Datei-Blöcke: alle gespeicherten Seiten ergeben nach decode/encode dieselbe Zeichenkette', $allSame);
check('Files: publicUrlOrNull ist nur für öffentliche Dateien mit gültigem Namen gesetzt', Files::publicUrlOrNull(['public' => 1, 'public_name' => 'ab12cd34-x.pdf', 'legacy_public_path' => '']) === '/media/dateien/ab12cd34-x.pdf'
    && Files::publicUrlOrNull(['public' => 0, 'public_name' => 'ab12cd34-x.pdf', 'legacy_public_path' => '']) === null
    && Files::publicUrlOrNull(['public' => 1, 'public_name' => "ab12cd34-x.pdf\n", 'legacy_public_path' => '']) === null
    && Files::publicUrlOrNull(['public' => 1, 'public_name' => 'AB12CD34-x.pdf', 'legacy_public_path' => '']) === null
    && Files::publicUrlOrNull(['public' => 1, 'public_name' => '', 'legacy_public_path' => 'dokumente/a.pdf']) === '/media/dokumente/a.pdf'
    && Files::publicUrlOrNull(['public' => 1, 'public_name' => '', 'legacy_public_path' => 'dokumente/sub/a.pdf']) === null
    && Files::publicUrlOrNull(['public' => 1, 'public_name' => '', 'legacy_public_path' => 'dateien/a.pdf']) === null);
check('Files: Größenangabe fest formatiert', Files::formatBytes(500) === '500 Bytes' && Files::formatBytes(2048) === '2 KB' && Files::formatBytes(1258291) === '1,2 MB' && Files::formatBytes(52428800) === '50,0 MB');
