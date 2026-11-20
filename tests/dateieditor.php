<?php
declare(strict_types=1);

// Entwurf 44, Block 2 (Stufen 3, 4, 5): Dateiliste für den Editor, Daten im Editor-JSON, Detailfeld (JSON und Seite ohne Skript),
// Logo aus Medien, Links in den Meldungen des Probelaufs. Das Verhalten im Browser (Ziehen, Dialoge) prüft die Browser-Liste, nicht dieser Test.

use Lichtkammer\Auth\ClientIp;
use Lichtkammer\Site\Settings;

require_once __DIR__ . '/support.php';

$fe = lk_new_tmp($tmp, 'dateieditor');
$m = lk_make($fe);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$anon = new TestBrowser($m['app'], $m['config']);
$J = static fn (array $blocks): string => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdf = static fn (string $tag): string => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% $tag\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$put = static function (string $bytes, string $name, int $folder = 0) use ($svc, $fe): int {
    $p = $fe . '/' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($p, $bytes);
    return $svc->files->ingest(1, $p, $name, $folder)['file_id'];
};
$png = static function (int $shade): string {
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, $shade, 40, 90));
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
};
$jpg = static function (int $shade): string {
    $im = imagecreatetruecolor(8, 8);
    imagefill($im, 0, 0, imagecolorallocate($im, $shade, 90, 40));
    ob_start();
    imagejpeg($im);
    return (string) ob_get_clean();
};
$gj = static function (TestBrowser $br, string $path): array {
    $r = $br->get($path);
    return ['status' => $r->status, 'ct' => $r->headers['Content-Type'] ?? '', 'cc' => $r->headers['Cache-Control'] ?? '', 'raw' => $r->body, 'd' => json_decode($r->body, true) ?: []];
};
$editorData = static function (string $html): array {
    return preg_match('~<script type="application/json" id="editor-data">(.*?)</script>~s', $html, $mm) === 1 ? (json_decode($mm[1], true) ?: []) : [];
};

// ---------- Stufe 4: Dateiliste für den Editor ----------
$fo = $svc->folders->create(0, 'Preise 2026');
$anna = $put($pdf('anna'), 'Anna.pdf');
$preis = $put($pdf('preis'), 'Preisliste.pdf');
$inFo = $put($pdf('fo'), 'Blatt.pdf', $fo);
$zeta = $put($pdf('zeta'), 'Zeta.pdf');
$priv = $put($pdf('priv'), 'Intern.pdf');
$weg = $put($pdf('weg'), 'Weg.pdf');
foreach ([$anna, $preis, $inFo, $zeta, $weg] as $id) {
    $svc->files->setPublic($id, true);
}
$svc->files->trash([$weg]);
$db->run('UPDATE files SET public = 1, public_name = ? WHERE id = ?', ['deadbeef-weg.pdf', $weg]); // Papierkorb mit noch gesetztem public=1 (wie bei Ordnern)
$pk = $gj($b, '/admin/dateien/auswahl');
check('Dateiliste: JSON, nicht im Zwischenspeicher, nur öffentliche Dateien ohne Papierkorb, nach Name sortiert', $pk['status'] === 200 && str_starts_with($pk['ct'], 'application/json') && str_contains($pk['cc'], 'no-store')
    && array_column($pk['d']['rows'], 'n') === ['Anna.pdf', 'Blatt.pdf', 'Preisliste.pdf', 'Zeta.pdf'] && $pk['d']['more'] === false);
check('Dateiliste: je Zeile nur Nummer, Name, Art, Größe, Ordner; nie eine Adresse oder ein Pfad', array_keys($pk['d']['rows'][0]) === ['id', 'n', 'k', 's', 'o'] && $pk['d']['rows'][0]['k'] === 'PDF'
    && !str_contains($pk['raw'], '/media') && !str_contains($pk['raw'], 'dateien/') && !str_contains($pk['raw'], 'public_name') && !str_contains($pk['raw'], 'Intern'));
check('Dateiliste: Ordnerpfad steht dabei', array_column($pk['d']['rows'], 'o', 'n')['Blatt.pdf'] === 'Preise 2026' && array_column($pk['d']['rows'], 'o', 'n')['Anna.pdf'] === 'Medien');
check('Dateiliste: Suche über Name und Ordnername', array_column($gj($b, '/admin/dateien/auswahl?q=preis')['d']['rows'], 'n') === ['Blatt.pdf', 'Preisliste.pdf'] && array_column($gj($b, '/admin/dateien/auswahl?q=zet')['d']['rows'], 'n') === ['Zeta.pdf']);
check('Dateiliste: Platzhalter % und _ in der Suche sind keine Platzhalter', $gj($b, '/admin/dateien/auswahl?q=%25')['d']['rows'] === [] && $gj($b, '/admin/dateien/auswahl?q=Z_ta')['d']['rows'] === []);
check('Dateiliste: ohne Anmeldung keine Daten', $anon->get('/admin/dateien/auswahl')->status === 303);
$db->run("DELETE FROM meta WHERE k = 'svg_probe'");
$svgId = $put('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><rect width="1" height="1"/></svg>', 'Zeichen.svg');
$db->run("UPDATE files SET public = 1, public_name = 'abcdef12-zeichen.svg' WHERE id = ?", [$svgId]);
$sv = $gj($b, '/admin/dateien/auswahl');
check('Dateiliste: SVG ohne bestätigten Schutzkopf fehlt, die Antwort sagt warum', !in_array('Zeichen.svg', array_column($sv['d']['rows'], 'n'), true) && $sv['d']['svgHidden'] === true);
$db->run("INSERT INTO meta(k, v) VALUES('svg_probe', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v", [$m['clock']->now() . ':1']);
$sv = $gj($b, '/admin/dateien/auswahl');
check('Dateiliste: SVG mit bestätigtem Schutzkopf erscheint als „SVG“', in_array('Zeichen.svg', array_column($sv['d']['rows'], 'n'), true) && array_column($sv['d']['rows'], 'k', 'n')['Zeichen.svg'] === 'SVG' && $sv['d']['svgHidden'] === false);
$db->run('DELETE FROM files WHERE id = ?', [$svgId]);
for ($i = 1; $i <= 62; $i++) { // Seiten zu 60: direkt angelegte Zeilen (die Liste liest nur die Tabelle)
    $db->run("INSERT INTO files(token, folder_id, name, kind, mime, bytes, sha256, path, public, public_name, created_at, updated_at) VALUES(?, 0, ?, 'pdf', 'application/pdf', 2048, ?, ?, 1, ?, 1, 1)",
        ['tok' . $i, sprintf('Mass %02d.pdf', $i), 'sha' . $i, sprintf('%02x/%024x.pdf', $i, $i), sprintf('%08x-mass.pdf', $i)]);
}
$p1 = $gj($b, '/admin/dateien/auswahl?seite=1');
$p2 = $gj($b, '/admin/dateien/auswahl?seite=2');
check('Dateiliste: 60 je Seite, „mehr“, zweite Seite hat den Rest ohne Doppelte', count($p1['d']['rows']) === 60 && $p1['d']['more'] === true && count($p2['d']['rows']) === 6 && $p2['d']['more'] === false && array_intersect(array_column($p1['d']['rows'], 'id'), array_column($p2['d']['rows'], 'id')) === []);
$db->run("DELETE FROM files WHERE token LIKE 'tok%'");

// Die Rolle „Redaktion“ darf dieselbe Liste lesen
$db->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'redaktion', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ed' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$edSess = $svc->sessions->create((int) $db->value("SELECT id FROM users WHERE username = 'redaktion'"), 'full', ClientIp::forms('203.0.113.9')['prefix'], 'TestBrowser/1');
$ed = new TestBrowser($m['app'], $m['config'], '203.0.113.9');
$ed->jar[$svc->cookies->name('lk_sid')] = $edSess['id'];
check('Dateiliste: Redaktion darf sie lesen', count($gj($ed, '/admin/dateien/auswahl')['d']['rows']) === 4);

// ---------- Editor-Startdaten ----------
$pid = $svc->pages->save(null, ['title' => 'Preise', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'preise', 'blocks' => $J([
    ['t' => 'heading', 'level' => 2, 'text' => 'Preise'],
    ['t' => 'download', 'file' => $preis, 'title' => 'Die Liste'],
    ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'Intern', 'file' => $priv]], 'right' => []],
])]);
$db->run('UPDATE pages SET blocks = ? WHERE id = ?', [$J([['t' => 'heading', 'level' => 2, 'text' => 'Preise', 'style' => 'normal'], ['t' => 'download', 'file' => $preis, 'title' => 'Die Liste', 'style' => 'normal'],
    ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'Intern', 'href' => '', 'style' => 'normal', 'file' => $priv]], 'right' => []], ['t' => 'download', 'file' => 987654, 'title' => '', 'style' => 'normal']]), $pid]); // eine inzwischen gelöschte Datei
$html = $b->get('/admin/seiten/' . $pid)->body;
$ed1 = $editorData($html);
$szPreis = \Lichtkammer\Media\Files::formatBytes((int) $db->value('SELECT bytes FROM files WHERE id = ?', [$preis]));
check('Editor: Seite kennt die Dateiliste (data-file-url) und liefert die benutzten Dateien mit Anzeigedaten', str_contains($html, 'data-file-url="/admin/dateien/auswahl"') && ($ed1['files'][(string) $preis] ?? null) === ['n' => 'Preisliste.pdf', 'k' => 'PDF', 's' => $szPreis, 'x' => 0, 'r' => '']);
check('Editor: private Datei als „nicht verfügbar (privat)“, unbekannte Nummer als „gelöscht“, kein Pfad in den Daten', ($ed1['files'][(string) $priv]['x'] ?? 0) === 1 && ($ed1['files'][(string) $priv]['r'] ?? '') === 'privat' && ($ed1['files']['987654']['x'] ?? 0) === 1 && ($ed1['files']['987654']['r'] ?? '') === 'gelöscht'
    && !str_contains($html, 'dateien/' . $db->value('SELECT public_name FROM files WHERE id = ?', [$preis])));
check('Editor: Datei ohne Kopie auf der Platte zeigt „Kopie fehlt“', (function () use ($b, $db, $svc, $preis, $pid, $editorData): bool {
    $name = (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$preis]);
    $f = $svc->config->mediaDir() . '/dateien/' . $name;
    $keep = (string) file_get_contents($f);
    unlink($f);
    $d = $editorData($b->get('/admin/seiten/' . $pid)->body);
    file_put_contents($f, $keep);
    return ($d['files'][(string) $preis]['x'] ?? 0) === 1 && ($d['files'][(string) $preis]['r'] ?? '') === 'Kopie fehlt';
})());
$db->run('UPDATE pages SET blocks = ? WHERE id = ?', [$J([['t' => 'heading', 'level' => 2, 'text' => 'Preise', 'style' => 'normal'], ['t' => 'download', 'file' => $preis, 'title' => 'Die Liste', 'style' => 'normal'],
    ['t' => 'columns', 'left' => [['t' => 'button', 'label' => 'Intern', 'href' => '', 'style' => 'normal', 'file' => $priv]], 'right' => []]]), $pid]); // wieder heil
check('Editor: Startseite hat ebenfalls die Dateiliste', str_contains($b->get('/admin/startseite')->body, 'data-file-url="/admin/dateien/auswahl"'));
$js = (string) file_get_contents(__DIR__ . '/../public/assets/js/page-editor.js');
check('Editor: das Skript kennt den Typ „download“ (Baustein, Spalten) und schreibt das Format v:1 unverändert', str_contains($js, "download: { name: 'Datei zum Herunterladen'") && str_contains($js, "var SIMPLE = ['heading', 'text', 'image', 'quote', 'button', 'download']") && str_contains($js, '{ v: 1, blocks: state }'));
check('Editor: ein Block mit file überlebt Hin- und Herschicken byte-gleich (Serverformat)', \Lichtkammer\Site\Blocks::encode(\Lichtkammer\Site\Blocks::parse($J([['t' => 'download', 'file' => $preis, 'title' => '', 'style' => 'normal'], ['t' => 'button', 'label' => 'x', 'href' => '', 'style' => 'normal', 'file' => $preis]]))) === $J([['t' => 'download', 'file' => $preis, 'title' => '', 'style' => 'normal'], ['t' => 'button', 'label' => 'x', 'href' => '', 'style' => 'normal', 'file' => $preis]]));

// ---------- Stufe 3: Detailfeld ----------
$dd = $gj($b, '/admin/medien/detail?art=datei&id=' . $preis);
$pubName = (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$preis]);
check('Detail: Datei – Name, Art, Größe, Ort, Datum, hochgeladen von, öffentlich, volle Adresse', $dd['status'] === 200 && $dd['d']['name'] === 'Preisliste.pdf' && $dd['d']['type'] === 'PDF' && $dd['d']['size'] === $szPreis && $dd['d']['place'] === 'Medien' && $dd['d']['public'] === true
    && $dd['d']['address'] === 'https://lk.test/media/dateien/' . $pubName && $dd['d']['by'] === 'lutz' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd['d']['date']) === 1 && $dd['d']['legacy'] === false);
check('Detail: JSON nicht im Zwischenspeicher; kein Pfad der privaten Datei, keine Rohfelder', str_contains($dd['cc'], 'no-store') && !str_contains($dd['raw'], (string) $db->value('SELECT path FROM files WHERE id = ?', [$preis])) && !str_contains($dd['raw'], '"path"') && !str_contains($dd['raw'], 'sha256'));
check('Detail: „Verwendet in“ nennt Seite, Blocknummer und Link', $dd['d']['uses'] === [['label' => 'Seite „Preise“', 'link' => '/admin/seiten/' . $pid, 'pos' => 'Block 2', 'linked' => true]] && $dd['d']['live'] === false);
$dp = $gj($b, '/admin/medien/detail?art=datei&id=' . $priv);
check('Detail: private Datei hat keine Adresse und ist nicht öffentlich; ihre Verwendung in der Spalte wird genannt', $dp['d']['public'] === false && $dp['d']['address'] === '' && $dp['d']['uses'][0]['pos'] === 'Block 3 (links, Eintrag 1)');
$svc->files->setPublic($priv, true);
$svc->builder->publish(1);
check('Detail: Live-Kennzeichen aus dem Manifest', $gj($b, '/admin/medien/detail?art=datei&id=' . $priv)['d']['live'] === true);
foreach (['art=x&id=' . $preis, 'art=datei&id=0', 'art=datei&id=abc', 'art=datei&id=99999', 'art=datei&id=' . $weg, 'art=foto&id=99999', 'art=datei', '', 'art=datei&id=1%00'] as $q) {
    check('Detail: ungültige Anfrage „' . $q . '“ → 404 als JSON', $gj($b, '/admin/medien/detail?' . $q)['status'] === 404);
}
check('Detail: ohne Anmeldung keine Daten', $anon->get('/admin/medien/detail?art=datei&id=' . $preis)->status === 303);
check('Detail: Redaktion darf lesen', $gj($ed, '/admin/medien/detail?art=datei&id=' . $preis)['status'] === 200);

// Foto: Galerie, Seitenblock, Startseite
$now = $m['clock']->now();
$db->run("INSERT INTO photos(token, original_name, original_path, sha256, bytes, mime, width, height, status, title, alt_text, created_at, updated_at, uploaded_by) VALUES('tp', 'möwe.jpg', 'o/1', 'shap', 123456, 'image/jpeg', 3000, 2000, 'ready', 'Möwe <b>', 'Alt', ?, ?, 1)", [$now, $now]);
$ph = $db->lastId();
$db->run("INSERT INTO photo_variants(photo_id, width, height, format, path, bytes) VALUES(?, 300, 200, 'jpeg', 'v/a-300.jpg', 1), (?, 600, 400, 'jpeg', 'v/a-600.jpg', 1)", [$ph, $ph]);
$g = $svc->galleries->create('Island', '');
$svc->galleries->addPhotos($g, [$ph]);
$svc->pages->save(null, ['title' => 'Über', 'body' => '', 'status' => 'published', 'nav' => 'none', 'slug' => 'ueber', 'blocks' => $J([['t' => 'image', 'photo' => $ph, 'alt' => 'x', 'decor' => false, 'caption' => '', 'width' => 'normal', 'href' => '']])]);
$fd = $gj($b, '/admin/medien/detail?art=foto&id=' . $ph);
check('Detail: Foto – Name, Maße, Vorschau nahe 480 Pixel, Bearbeiten-Link', $fd['status'] === 200 && $fd['d']['name'] === 'Möwe <b>' && $fd['d']['dims'] === '3000 × 2000 Pixel' && $fd['d']['previewUrl'] === '/media/v/a-600.jpg' && $fd['d']['editUrl'] === '/admin/fotos/' . $ph && $fd['d']['type'] === 'Foto');
check('Detail: Foto – „Verwendet in“ nennt Galerie und Seite', array_column($fd['d']['uses'], 'label') === ['Galerie „Island“ (Entwurf)', 'Seite „Über“']);
$db->run('UPDATE photos SET deleted_at = 5 WHERE id = ?', [$ph]);
check('Detail: Foto im Papierkorb → 404', $gj($b, '/admin/medien/detail?art=foto&id=' . $ph)['status'] === 404);
$db->run('UPDATE photos SET deleted_at = NULL WHERE id = ?', [$ph]);

// Seite ohne Skript
$page = $b->get('/admin/medien?detail=d' . $preis)->body;
check('Detail ohne Skript: Abschnitt unter der Liste mit Name, Adresse (nur lesbar), Verwendung und Schließen', str_contains($page, 'id="mdetail"') && str_contains($page, '<h2 id="mdt">Preisliste.pdf</h2>') && str_contains($page, 'value="https://lk.test/media/dateien/' . $pubName . '"')
    && str_contains($page, '<a href="/admin/seiten/' . $pid . '">Seite „Preise“</a>, Block 2') && str_contains($page, 'Schließen'));
check('Detail ohne Skript: jede Zeile hat den Link „Details“ (mit Rückfall auf die Seite)', str_contains($b->get('/admin/medien')->body, 'class="mdlink" href="/admin/medien?detail=d' . $preis . '#mdetail" data-detail="datei:' . $preis . '"'));
check('Detail ohne Skript: ungültige oder fremde Angabe zeigt kein Feld und keinen Fehler', $b->get('/admin/medien?detail=d99999')->status === 200 && !str_contains($b->get('/admin/medien?detail=d99999')->body, 'id="mdetail"') && !str_contains($b->get('/admin/medien?detail=x1')->body, 'id="mdetail"') && $b->get('/admin/medien?detail=' . rawurlencode('d1<script>'))->status === 200);
$db->run("UPDATE files SET name = 'A <i>&</i>.pdf' WHERE id = ?", [$anna]);
check('Detail ohne Skript: Namen maskiert', str_contains($b->get('/admin/medien?detail=d' . $anna)->body, '<h2 id="mdt">A &lt;i&gt;&amp;&lt;/i&gt;.pdf</h2>'));
$db->run("UPDATE files SET name = 'Anna.pdf' WHERE id = ?", [$anna]);
$fp = $b->get('/admin/medien?detail=f' . $ph)->body;
check('Detail ohne Skript: Foto mit Vorschau, Maßen und „Foto bearbeiten“', str_contains($fp, '3000 × 2000 Pixel') && str_contains($fp, 'Foto bearbeiten') && str_contains($fp, 'Möwe &lt;b&gt;'));
$mj = (string) file_get_contents(__DIR__ . '/../public/assets/js/medien.js');
check('Detail mit Skript: Seitenleiste per textContent, Esc schließt, kein innerHTML', str_contains($mj, 'mdetailpanel') && str_contains($mj, "e.key === 'Escape' && panel") && !str_contains($mj, 'innerHTML'));

// ---------- Stufe 5: Logo aus Medien ----------
$lp = $put($png(10), 'Logo hell.png');
$ls = $put('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="1" y="1" width="8" height="8" fill="#c75d3b"/></svg>', 'Logo dunkel.svg');
$lj = $put($jpg(20), 'Foto.jpg');
$lg = $put($png(30), 'Gross.png');
$db->run('UPDATE files SET bytes = ? WHERE id = ?', [Settings::LOGO_MAX + 1, $lg]);
$cand = array_column($svc->settings->mediaLogoCandidates(), 'name', 'id');
check('Logo aus Medien: wählbar sind PNG und SVG bis 300 KB; JPEG, PDF und zu große Dateien nicht', isset($cand['f' . $lp], $cand['f' . $ls]) && !isset($cand['f' . $lj]) && !isset($cand['f' . $lg]) && !isset($cand['f' . $anna]));
$svc->settings->chooseLogos(['light' => 'f' . $lp, 'dark' => 'f' . $ls, 'favicon' => 'f' . $lj]);
$ch = $svc->settings->logoChoice();
check('Logo aus Medien: Auswahl wird gespeichert; ungeeignete Datei (JPEG) wird nicht übernommen', $ch === ['light' => 'f' . $lp, 'dark' => 'f' . $ls, 'favicon' => '']);
$uri = (string) $svc->settings->logoUri('f' . $lp);
check('Logo aus Medien: als Data-URL aus der privaten Datei (Typ aus der gespeicherten Endung)', str_starts_with($uri, 'data:image/png;base64,') && base64_decode(substr($uri, strlen('data:image/png;base64,'))) === $png(10) && str_starts_with((string) $svc->settings->logoUri('f' . $ls), 'data:image/svg+xml;base64,'));
$prev = $b->get('/admin/vorschau')->body;
check('Logo aus Medien: Vorschau zeigt Hell- und Dunkel-Logo als Data-URL, keine öffentliche Kopie nötig', substr_count($prev, 'class="logo logo-light" src="data:image/png;base64,') === 1 && substr_count($prev, 'class="logo logo-dark" src="data:image/svg+xml;base64,') === 1 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$lp]) === 0);
check('Logo aus Medien: Verwaltung zeigt es ebenfalls', str_contains($b->get('/admin')->body, 'class="logo logo-light" src="data:image/png;base64,'));
foreach (['f0', 'f', 'f1x', 'f-3', 'f99999', 'F' . $lp, 'f' . $lp . "\n", 'f' . $anna, str_repeat('f', 5)] as $odd) {
    check('Logo aus Medien: Kennung „' . json_encode($odd) . '“ wird nicht gewählt und liefert keine Daten', $svc->settings->logoUri($odd) === null);
}
$svc->settings->chooseLogos(['light' => 'f' . $anna, 'dark' => "f$ls\n", 'favicon' => 'f99999']);
check('Logo aus Medien: PDF, Kennung mit Zeilenumbruch und unbekannte Nummer werden verworfen', $svc->settings->logoChoice() === ['light' => '', 'dark' => '', 'favicon' => '']);
$svc->settings->chooseLogos(['light' => 'f' . $lp, 'dark' => 'f' . $ls, 'favicon' => '']);
$page = $b->get('/admin/logos')->body;
check('Logo aus Medien: Auswahlfeld hat die Gruppe „Aus Medien“ mit der gewählten Datei vorgewählt; JPEG und PDF fehlen darin', str_contains($page, '<optgroup label="Aus Medien">') && str_contains($page, '<option value="f' . $lp . '" selected>Logo hell.png</option>') && !str_contains($page, 'value="f' . $lj . '"') && !str_contains($page, 'value="f' . $anna . '"'));
$post = $b->post('/admin/logos/zuordnen', ['_csrf' => TestBrowser::csrf($b->get('/admin/logos')), 'light' => 'f' . $ls, 'dark' => 'f' . $lp, 'favicon' => 'f' . $ls]);
check('Logo aus Medien: Zuordnung über die Seite (mit Kennungen „f<Nummer>“)', ($post->headers['Location'] ?? '') === '/admin/logos?m=logochosen' && $svc->settings->logoChoice() === ['light' => 'f' . $ls, 'dark' => 'f' . $lp, 'favicon' => 'f' . $ls]);
$svc->settings->chooseLogos(['light' => 'f' . $lp, 'dark' => 'f' . $ls, 'favicon' => '']);
$du = static fn (string $mime, string $bytes): string => 'data:' . $mime . ';base64,' . base64_encode($bytes);
$old = $svc->settings->addLogo('alt.png', $du('image/png', $png(50)));
$svc->settings->chooseLogos(['light' => $old, 'dark' => 'f' . $ls, 'favicon' => $old]);
check('Logo aus Medien: bisherige hochgeladene Logos (16 Zeichen) gehen weiter, auch gemischt', $svc->settings->logoChoice() === ['light' => $old, 'dark' => 'f' . $ls, 'favicon' => $old] && str_starts_with((string) $svc->settings->logoUri($old), 'data:image/png'));
$svc->settings->chooseLogos(['light' => 'f' . $lp, 'dark' => 'f' . $ls, 'favicon' => '']);
$bare = new Settings($db, $svc->config);
check('Logo aus Medien: Settings ohne Files verhält sich wie bisher (Kennung „f…“ ist unbekannt, keine Warnung)', $bare->logoChoice() === ['light' => '', 'dark' => '', 'favicon' => ''] && $bare->logoUri('f' . $lp) === null && $bare->logoProblems() === [] && $bare->mediaLogoCandidates() === []);
check('Logo aus Medien: „Verwendet in“ nennt die Rollen mit Link', $svc->files->get($lp) !== null && (new \Lichtkammer\Media\Usage($db, $svc->builder))->forFile($lp) === [['label' => 'Logo für den hellen Modus', 'link' => '/admin/logos', 'pos' => '', 'linked' => true]]);
$csrf = TestBrowser::csrf($b->get('/admin/medien'));
$conf = $b->post('/admin/medien/loeschen', ['_csrf' => $csrf, 'datei' => [(string) $lp]]);
check('Logo aus Medien: Löschen einer benutzten Logo-Datei fragt nach (Bestätigungsseite), nichts ist geändert', $conf->status === 200 && str_contains($conf->body, 'Noch verwendet') && str_contains($conf->body, 'Logo für den hellen Modus') && $svc->files->get($lp) !== null);
$svc->files->trash([$lp]);
check('Logo aus Medien: Datei später im Papierkorb → Rolle fällt auf „keines“ zurück (Seitenname als Schrift)', $svc->settings->logoChoice()['light'] === '' && $svc->settings->logoChoice()['dark'] === 'f' . $ls && !str_contains($b->get('/admin/vorschau')->body, 'class="logo logo-light" src="data:image/png'));
$plan = $svc->builder->plan();
check('Logo aus Medien: Probelauf warnt (kein harter Fehler) und nennt die Rolle', in_array('Logo für den hellen Modus: Die gewählte Datei aus Medien gibt es nicht mehr oder sie ist nicht mehr als Logo geeignet. Es erscheint der Seitenname als Schrift.', $plan['warnings'], true) && array_filter($plan['errors'], static fn (string $e): bool => str_contains($e, 'Logo')) === []);
$svc->files->restore([$lp]);
check('Logo aus Medien: nach dem Zurückholen gilt die Wahl wieder', $svc->settings->logoChoice()['light'] === 'f' . $lp && $svc->settings->logoProblems() === []);
$db->run('UPDATE files SET bytes = ? WHERE id = ?', [Settings::LOGO_MAX + 1, $lp]);
check('Logo aus Medien: wird die Datei zu groß, fällt die Rolle zurück', $svc->settings->logoChoice()['light'] === '' && $svc->settings->logoUri('f' . $lp) === null);
$db->run('UPDATE files SET bytes = 100 WHERE id = ?', [$lp]);
$svc->settings->chooseLogos(['light' => '', 'dark' => '', 'favicon' => '']);

// ---------- H2: Links in den Meldungen des Probelaufs ----------
$svc->files->setPublic($preis, false);
$plan = $svc->builder->plan();
$msg = '';
foreach ($plan['errors'] as $er) {
    if (str_contains($er, 'Preisliste.pdf')) {
        $msg = $er;
    }
}
check('Probelauf: Meldung zur fehlenden Datei hat die Links „Seite öffnen“ und „In Medien ansehen“', $msg !== '' && ($plan['links'][$msg] ?? null) === [['Seite öffnen', '/admin/seiten/' . $pid], ['In Medien ansehen', '/admin/medien?detail=d' . $preis]]);
$pubPage = $b->get('/admin/veroeffentlichen')->body;
check('Veröffentlichen-Seite zeigt die Links neben der Meldung (Adressen maskiert)', str_contains($pubPage, '<a class="small" href="/admin/seiten/' . $pid . '">Seite öffnen</a> <a class="small" href="/admin/medien?detail=d' . $preis . '">In Medien ansehen</a>'));
$svc->files->setPublic($preis, true);
$db->run('UPDATE files SET deleted_at = 5 WHERE id = ?', [$preis]);
$plan = $svc->builder->plan();
$msg = '';
foreach ($plan['errors'] as $er) {
    if (str_contains($er, 'Preisliste.pdf')) {
        $msg = $er;
    }
}
check('Probelauf: gelöschte Datei verweist auf den Medien-Papierkorb statt auf ein leeres Detailfeld', $msg !== '' && ($plan['links'][$msg][1] ?? null) === ['Medien-Papierkorb', '/admin/medien/papierkorb']);
$db->run('UPDATE files SET deleted_at = NULL WHERE id = ?', [$preis]);
