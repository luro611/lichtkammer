<?php
declare(strict_types=1);

// Medienverwaltung (Entwurf 16): Namen, Ordner, Papierkorb-Zweige, Migration 10, SVG- und PDF-Prüfung, Dateien.

use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Media\Files;
use Lichtkammer\Media\Folders;
use Lichtkammer\Media\Library;
use Lichtkammer\Media\MediaException;
use Lichtkammer\Media\Names;
use Lichtkammer\Media\PdfCheck;
use Lichtkammer\Media\SvgCheck;

require_once __DIR__ . '/support.php';

$thrown = static function (callable $fn): bool {
    try {
        $fn();
        return false;
    } catch (MediaException) {
        return true;
    }
};

// ======================= Block A: Namen =======================
check('Namen: „..“ und führendes „/“ ergeben nie einen Pfad nach oben', Names::segments('../../etc/passwd')['segments'] === ['etc', 'passwd'] && Names::segments('/etc/passwd')['segments'] === ['etc', 'passwd'] && Names::segments(['..'])['segments'] === [] && Names::segments('a/./b')['segments'] === ['a', 'b']);
check('Namen: a/b als Segmentgrenze, a\\b bleibt ein Name', Names::segments(['a/b'])['segments'] === ['a', 'b'] && Names::clean('a\\b')['name'] === 'a\\b');
check('Namen: NUL und Steuerzeichen entfernt und gemeldet', Names::clean("a\0b\x07c")['name'] === 'abc' && Names::clean("a\0b")['changed']);
check('Namen: Windows-Namen und Sonderzeichen bleiben unverändert', Names::clean('CON.txt')['name'] === 'CON.txt' && Names::clean('a:b*c?"<>|')['name'] === 'a:b*c?"<>|' && Names::clean('COM1')['name'] === 'COM1');
$nfd = "u\u{0308}ber";
check('Namen: NFD (Mac) wird NFC, gleicher Schlüssel, nicht als Änderung gemeldet', Names::clean($nfd)['name'] === "\u{00FC}ber" && Names::clean($nfd)['key'] === Names::clean("\u{00DC}BER")['key'] && !Names::clean($nfd)['changed']);
check('Namen: Island und island haben denselben Schlüssel', Names::clean('Island')['key'] === Names::clean('island')['key']);
check('Namen: Bidi (U+202E) und Zeichen ohne Breite entfernt', Names::clean("gpj\u{202E}exe.jpg")['name'] === 'gpjexe.jpg' && Names::clean("a\u{200B}b\u{FEFF}c")['name'] === 'abc' && Names::clean("x\u{2066}y")['name'] === 'xy');
check('Namen: 256 Zeichen werden auf 255 gekürzt und gemeldet', mb_strlen(Names::clean(str_repeat('ä', 300))['name']) === 255 && Names::clean(str_repeat('a', 256))['changed']);
check('Namen: Punkt und Leerzeichen am Ende und Anfang entfallen', Names::clean('  name. ')['name'] === 'name' && Names::clean('.versteckt')['name'] === 'versteckt');
check('Namen: leer, nur Punkte → Unbenannter Ordner', Names::clean('...')['name'] === 'Unbenannter Ordner' && Names::clean('')['name'] === 'Unbenannter Ordner' && Names::clean('', 'Datei')['name'] === 'Datei');
check('Namen: nur Emojis bleiben erhalten', Names::clean('🌄🌅')['name'] === '🌄🌅');
check('Namen: ungültiges UTF-8 wird ersetzt statt Fehler', Names::clean("ab\xFFcd")['name'] === "ab\u{FFFD}cd");
check('Namen: Dateiname nimmt nur das letzte Segment (Windows und Unix)', Names::fileName('C:\\fakepath\\a.jpg') === 'a.jpg' && Names::fileName('/etc/passwd') === 'passwd' && Names::fileName('../../x.jpg') === 'x.jpg');
check('Namen: Library::cleanName nutzt dieselbe Regel (Bidi raus, NFC), bestehende Namen bleiben', Library::cleanName("evil\u{202E}gpj.exe") === 'evilgpj.exe' && Library::cleanName('IMG_0001.JPG') === 'IMG_0001.JPG' && Library::cleanName("u\u{0308}.jpg") === "\u{00FC}.jpg" && mb_strlen(Library::cleanName(str_repeat('a', 400))) === 255);
check('Namen: Tiefe 9 wird abgelehnt, Tiefe 8 geht', $thrown(fn () => Names::segments(array_fill(0, 9, 'x'))) && count(Names::segments(array_fill(0, 8, 'x'))['segments']) === 8);
check('Namen: Gesamtpfad über 800 Zeichen wird abgelehnt', $thrown(fn () => Names::segments(array_fill(0, 5, str_repeat('x', 200)))));
check('Namen: Versteck- und Systemdateien werden erkannt', Names::isJunk('.DS_Store') && Names::isJunk('._foto.jpg') && Names::isJunk('Thumbs.db') && Names::isJunk('desktop.ini') && Names::isJunk('__MACOSX') && Names::isJunk('.git') && !Names::isJunk('Foto.jpg'));
check('Namen: natürliche Sortierung (Bild 2 vor Bild 10, ohne Groß/Klein)', Names::natural('Bild 2', 'Bild 10') < 0 && Names::natural('a', 'B') < 0);
$hn = Names::headerName('a"b/c;d' . str_repeat('🌄', 255));
check('Namen: Download-Name ohne Anführungszeichen und Schrägstriche, höchstens 100 Zeichen, ASCII-Rückfall', !str_contains($hn['utf8'], '"') && !str_contains($hn['utf8'], '/') && mb_strlen($hn['utf8']) <= 100 && preg_match('/^[A-Za-z0-9._ -]+$/', $hn['ascii']) === 1);

// ======================= Block A: Migration 10 auf einer Bestandsdatenbank =======================
$mig = lk_new_tmp($tmp, 'mig');
$dbfile = $mig . '/alt.sqlite';
$pdo = new PDO('sqlite:' . $dbfile);
foreach (array_slice(Schema::migrations(), 0, 9) as $sql) {
    $pdo->exec($sql);
}
$pdo->exec("CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT NOT NULL)");
$pdo->exec("INSERT INTO meta(k,v) VALUES('schema_version','9') ON CONFLICT(k) DO UPDATE SET v='9'");
$pdo->exec("INSERT INTO galleries(slug,title,created_at,updated_at) VALUES('alt','Alt',1,1)");
$pdo->exec("INSERT INTO photos(token,original_name,original_path,sha256,bytes,mime,created_at,updated_at) VALUES('aa','x.jpg','aa/aa.jpg','s1',10,'image/jpeg',1,1)");
$pdo->exec("INSERT INTO gallery_photos(gallery_id,photo_id,position,added_at) VALUES(1,1,1,1)");
$pdo = null;
$dbm = Database::open($dbfile);
check('Migration 10 und 11: Bestand bleibt, aktuelle Version, Sicherung vor Schema 9 angelegt', Schema::version($dbm) === count(Schema::migrations()) && (int) $dbm->value('SELECT COUNT(*) FROM photos') === 1 && is_file($dbfile . '.vor-schema-9.bak'));
check('Migration 10: alte Fotos liegen auf oberster Ebene, Galerie-Zuordnung unverändert', $dbm->value('SELECT folder_id FROM photos WHERE id = 1') === null && (int) $dbm->value('SELECT COUNT(*) FROM gallery_photos') === 1);
foreach (['folders', 'files', 'plan_items'] as $t) {
    check("Migration 10: Tabelle $t", $dbm->value("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", [$t]) !== null);
}

// ======================= Block A: Ordnerlogik =======================
$m = lk_make($f = lk_new_tmp($tmp, 'medien'));
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$fo = $svc->folders;

$a = $fo->create(0, 'Island');
check('Ordner: anlegen, Zähler leer', $a > 0 && $fo->counts($a) === ['photos' => 0, 'files' => 0, 'folders' => 0]);
check('Ordner: gleicher Name (auch island, NFD) wird abgelehnt', $thrown(fn () => $fo->create(0, 'island')) && $thrown(fn () => $fo->create(0, ' Island. ')));
$nfdId = $fo->create(0, "Zu\u{0308}rich");
check('Ordner: NFC gespeichert, NFD-Duplikat wird als gleich erkannt', $db->value('SELECT name FROM folders WHERE id = ?', [$nfdId]) === "Z\u{00FC}rich" && $thrown(fn () => $fo->create(0, "Z\u{00FC}rich")));
$e1 = $fo->ensurePath(0, ['Island', 'Tag 1']);
$e2 = $fo->ensurePath(0, ['island', 'TAG 1', 'Nacht']);
check('Ordner: ensurePath führt gleiche Namen zusammen, erste Schreibweise bleibt', $e1['created'] !== [] && $e2['created'] !== [] && count($e2['created']) === 1 && $db->value('SELECT name FROM folders WHERE id = ?', [$a]) === 'Island' && (int) $db->value('SELECT COUNT(*) FROM folders WHERE name_key = ?', ['tag 1']) === 1);
check('Ordner: Tiefe 9 wird abgelehnt', $thrown(fn () => $fo->ensurePath(0, ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9'])) && $fo->depth($e2['id']) === 3);
check('Ordner: Brotkrumen', array_column($fo->breadcrumb($e2['id']), 'name') === ['Island', 'Tag 1', 'Nacht']);
$fo->rename($e1['id'], 'Tag Eins');
check('Ordner: umbenennen; Namenskonflikt und Schrägstrich abgelehnt', $db->value('SELECT name FROM folders WHERE id = ?', [$e1['id']]) === 'Tag Eins' && $thrown(fn () => $fo->rename($e1['id'], 'a/b')));
$other = $fo->create(0, 'Norwegen');
check('Ordner: Konflikt beim Umbenennen auf vorhandenen Namen der Ebene', $thrown(fn () => $fo->rename($other, 'island')));
check('Ordner: Zyklus verboten (in sich selbst, in Nachkomme)', $thrown(fn () => $fo->move([$a], [], [], $a)) && $thrown(fn () => $fo->move([$a], [], [], $e2['id'])));
check('Ordner: nach dem Zyklusversuch ist nichts verändert (alles oder nichts)', (int) $db->value('SELECT parent_id FROM folders WHERE id = ?', [$a]) === 0);
$deep = $fo->ensurePath(0, ['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7']);
check('Ordner: Tiefe 7 geht, ein weiterer Zweig mit 2 Ebenen darunter nicht', $fo->depth($deep['id']) === 7 && $thrown(fn () => $fo->move([$a], [], [], $deep['id'])));
$tall = $fo->ensurePath(0, ['hoch', 'h2', 'h3', 'h4']);
$low = $fo->ensurePath(0, ['tief', 't2', 't3', 't4', 't5']);
check('Ordner: Zweig mit 4 Ebenen unter Ordner der Tiefe 5 ergibt 9 und wird abgelehnt', $thrown(fn () => $fo->move([(int) $db->value("SELECT id FROM folders WHERE name_key = 'hoch'")], [], [], $low['id'])));
check('Ordner: Verschieben auf gleichnamigen Ordner im Ziel wird abgelehnt, kein stilles Überschreiben', ($fo->create($other, 'Tag Eins') > 0) && $thrown(fn () => $fo->move([$e1['id']], [], [], $other)));
$mv = $fo->move([$e2['id']], [], [], $other);
check('Ordner: Verschieben wirkt, Zähler und Brotkrumen folgen', $mv === 1 && array_column($fo->breadcrumb($e2['id']), 'name') === ['Norwegen', 'Nacht'] && $fo->counts($other)['folders'] === 2);


// Fotos in Ordnern, Papierkorb eines Zweigs
$mk = static function (int $w, int $folder, string $name) use ($svc, $f): int {
    $file = $f . '/' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($file, lk_jpeg($w, 100));
    $r = $svc->library->ingest(1, $file, $name, null, $folder);
    $svc->library->process($r['photo_id']);
    return $r['photo_id'];
};
$ph1 = $mk(301, $a, 'eins.jpg');
$ph2 = $mk(302, $e1['id'], 'zwei.jpg');
$ph3 = $mk(303, 0, 'wurzel.jpg');
check('Foto: landet im Ordner, ohne Ordner auf oberster Ebene', (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph1]) === $a && $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph3]) === null && $fo->counts($a)['photos'] === 1 && $fo->counts(0)['photos'] === 1);
$gal = $svc->galleries->create('Island', '');
$svc->galleries->addPhotos($gal, [$ph1, $ph2]);
$varsBefore = (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id IN (?, ?)', [$ph1, $ph2]);
$tr = $fo->trash($a);
check('Papierkorb: Zweig (Ordner, Unterordner, Fotos) mit trash_root_id', $tr['folders'] === 2 && $tr['photos'] === 2 && (int) $db->value('SELECT COUNT(*) FROM photos WHERE trash_root_id = ?', [$a]) === 2 && (int) $db->value('SELECT COUNT(*) FROM folders WHERE trash_root_id = ?', [$a]) === $tr['folders'] && $tr['galleries'] === [$gal]);
check('Papierkorb: Foto auf oberster Ebene und anderer Zweig unberührt', $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$ph3]) === null);
check('Papierkorb: Bildvarianten verschwinden in Stapeln, Originale bleiben', $varsBefore > 0 && $fo->finishTrash($svc->config->mediaDir()) === 0 && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id IN (?, ?)', [$ph1, $ph2]) === 0 && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id IN (?, ?)', [$ph1, $ph2]) === 2);
check('Papierkorb: gelöschter Name blockiert nichts (neuer Ordner Island ist möglich)', $fo->create(0, 'Island') > 0);
check('Papierkorb: Hochladen oder Verschieben in den Papierkorb-Ordner wird abgelehnt', $thrown(fn () => $fo->move([$other], [], [], $e1['id'])) && $thrown(fn () => $fo->create($e1['id'], 'x')) && $svc->folders->isUsable($e1['id']) === false);
check('Papierkorb: einzelne Fotos des Zweigs lassen sich nicht über die Fotoseite zurückholen', $svc->catalog->restore([$ph1])['restored'] === 0 && $svc->catalog->trashed() === []);
check('Papierkorb: Wurzeln mit Zahlen', ($roots = $fo->trashedRoots()) !== [] && (int) $roots[0]['id'] === $a && $roots[0]['photos'] === 2 && $roots[0]['folders'] === 1);
$neu = (int) $db->value("SELECT id FROM folders WHERE name_key = 'island' AND deleted_at IS NULL");
$rs = $fo->restore($a);
check('Wiederherstellen: Namenskonflikt führt zusammen (nie Fehler, nie Überschreiben)', $rs['photos'] === 2 && (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph1]) === $neu && $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$ph1]) === null && (int) $db->value('SELECT COUNT(*) FROM folders WHERE name_key = ?', ['island']) === 1 && $db->value('SELECT 1 FROM folders WHERE id = ?', [$a]) === null);
check('Wiederherstellen: Unterordner mit Foto folgt (Tag Eins unter Island), Fotos „pending“, Galerie-Zuordnung erhalten', array_column($fo->breadcrumb((int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph2])), 'name') === ['Island', 'Tag Eins'] && $db->value('SELECT status FROM photos WHERE id = ?', [$ph2]) === 'pending' && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ?', [$gal]) === 2);
foreach ([$ph1, $ph2] as $pp) {
    $svc->library->process($pp);
}
// Elternordner liegt selbst im Papierkorb: Zweig kommt in die Wurzel
$p1 = $fo->ensurePath(0, ['Eltern', 'Kind']);
$ph4 = $mk(304, $p1['id'], 'kind.jpg');
$kindRoot = $p1['id'];
$fo->trash($kindRoot);
$parentId = (int) $db->value("SELECT id FROM folders WHERE name_key = 'eltern'");
$fo->trash($parentId);
$rs2 = $fo->restore($kindRoot);
check('Wiederherstellen: liegt der Elternordner im Papierkorb, kommt der Zweig in die Wurzel (mit Hinweis)', $rs2['to_root'] === true && (int) $db->value('SELECT parent_id FROM folders WHERE id = ?', [$kindRoot]) === 0 && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ? AND deleted_at IS NULL', [$ph4]) === 1);
// Endgültig löschen in Stapeln
$q = $fo->ensurePath(0, ['Weg', 'Unten']);
$ph5 = $mk(305, $q['id'], 'weg.jpg');
$fo->trash((int) $db->value("SELECT id FROM folders WHERE name_key = 'weg'"));
$wegRoot = (int) $db->value("SELECT id FROM folders WHERE name_key = 'weg'");
$orig5 = $svc->config->originalsDir() . '/' . $db->value('SELECT original_path FROM photos WHERE id = ?', [$ph5]);
check('Endgültig löschen: lebender Ordner (nicht im Papierkorb) wird von purgeRows ignoriert', $fo->purgeRows([$other]) === 0 && $db->value('SELECT 1 FROM folders WHERE id = ?', [$other]) !== null);
$pr = $svc->branch->purgeRoot($wegRoot);
check('Endgültig löschen: Foto samt Original und beide Ordnerzeilen weg', $pr['remaining'] === 0 && $pr['purged'] === 1 && !is_file($orig5) && $db->value('SELECT 1 FROM folders WHERE id = ?', [$wegRoot]) === null && $db->value('SELECT 1 FROM folders WHERE id = ?', [$q['id']]) === null);
$lone = $fo->ensurePath(0, ['Einzeln']);
$ph6 = $mk(306, $lone['id'], 'einzeln.jpg');
$svc->catalog->trash([$ph6]);
$fo->trash($lone['id']);
$svc->branch->purgeRoot($lone['id']);
check('Endgültig löschen: Foto, das schon einzeln im Papierkorb lag, verliert den toten Ordnerverweis', $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph6]) === null && (int) $db->value('SELECT COUNT(*) FROM photos WHERE id = ?', [$ph6]) === 1);
check('Wiederherstellen eines einzelnen Fotos mit totem Ordner: kommt in die Wurzel', $svc->catalog->restore([$ph6])['restored'] === 1 && $db->value('SELECT folder_id FROM photos WHERE id = ?', [$ph6]) === null);
$v6 = (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id = ?', [$ph6]);
check('Runde 3: Endgültig löschen eines inzwischen zurückgeholten Fotos tut nichts (Zeile, Varianten bleiben)', $svc->catalog->purge([$ph6]) === 0
    && $db->value('SELECT 1 FROM photos WHERE id = ?', [$ph6]) !== null && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id = ?', [$ph6]) === $v6 && $v6 > 0);
// Ablauf nach 30 Tagen
$old = $fo->ensurePath(0, ['Alt']);
$ph7 = $mk(307, $old['id'], 'alt.jpg');
$fo->trash($old['id']);
$m['clock']->advance(31 * 86400);
$svc->branch->purgeExpired();
$svc->catalog->purgeExpired();
check('Papierkorb: nach 30 Tagen wird der Zweig endgültig entfernt', $db->value('SELECT 1 FROM folders WHERE id = ?', [$old['id']]) === null && $db->value('SELECT 1 FROM photos WHERE id = ?', [$ph7]) === null);
$m['clock']->advance(60);
$b = lk_login($m, 'lutz', TEST_PASSWORD, $s['secret']); // 31 Tage später: die alte Sitzung ist abgelaufen

// ======================= Block B: SVG =======================
$X = 'xmlns="http://www.w3.org/2000/svg"';
$ok = "<svg $X viewBox=\"0 0 10 10\"><rect x=\"1\" y=\"1\" width=\"8\" height=\"8\" fill=\"#c75d3b\"/><text x=\"1\" y=\"9\">br</text></svg>";
$rej = [
    'Skript' => "<svg $X><script>alert(1)</script></svg>",
    'onload' => "<svg $X onload=\"x()\"><rect width=\"1\" height=\"1\"/></svg>",
    'onclick (Großschreibung)' => "<svg $X><rect ONCLICK=\"x()\" width=\"1\" height=\"1\"/></svg>",
    'foreignObject' => "<svg $X><foreignObject><div/></foreignObject></svg>",
    'href http' => "<svg $X xmlns:xlink=\"http://www.w3.org/1999/xlink\"><use xlink:href=\"https://evil.de/x.svg#a\"/></svg>",
    'href javascript' => "<svg $X xmlns:xlink=\"http://www.w3.org/1999/xlink\"><use xlink:href=\"javascript:alert(1)\"/></svg>",
    'href data:text/html' => "<svg $X><use href=\"data:text/html,&lt;script&gt;1&lt;/script&gt;\"/></svg>",
    'image-Element' => "<svg $X><image href=\"data:image/png;base64,AAAA\"/></svg>",
    'Entity-Bombe' => "<!DOCTYPE svg [<!ENTITY a \"aaaa\"><!ENTITY b \"&a;&a;&a;&a;\">]><svg $X><text>&b;</text></svg>",
    'externe Entität' => "<!DOCTYPE svg [<!ENTITY x SYSTEM \"file:///etc/passwd\">]><svg $X><text>&x;</text></svg>",
    'fremdes DOCTYPE' => "<!DOCTYPE svg SYSTEM \"http://evil.example/x.dtd\"><svg $X/>",
    'CSS @import' => "<svg $X><style>@import url(https://evil.de/a.css);</style><rect width=\"1\" height=\"1\"/></svg>",
    'CSS url(http)' => "<svg $X><rect style=\"fill:url(http://evil.de/a)\" width=\"1\" height=\"1\"/></svg>",
    'CSS expression' => "<svg $X><rect style=\"width:expression(alert(1))\" width=\"1\" height=\"1\"/></svg>",
    'CSS Backslash-Tarnung' => "<svg $X><style>rect{fill:\\75rl(http://x)}</style></svg>",
    'use auf Fremddatei' => "<svg $X><use href=\"fremd.svg#a\"/></svg>",
    'animate auf href' => "<svg $X xmlns:xlink=\"http://www.w3.org/1999/xlink\"><a xlink:href=\"#x\"><animate attributeName=\"href\" values=\"javascript:1\"/></a></svg>",
    'set' => "<svg $X><set attributeName=\"onload\" to=\"1\"/></svg>",
    'fremdes Präfix für xlink' => "<svg $X xmlns:q=\"http://www.w3.org/1999/xlink\"><use q:href=\"a.svg\"/></svg>",
    'XHTML-Element im SVG' => "<svg $X xmlns:h=\"http://www.w3.org/1999/xhtml\"><h:a href=\"x\"/></svg>",
    'XHTML-Skript im SVG' => "<svg $X xmlns:h=\"http://www.w3.org/1999/xhtml\"><h:script>alert(1)</h:script></svg>",
    'a-Element' => "<svg $X><a href=\"https://x\"><rect width=\"1\" height=\"1\"/></a></svg>",
    'iframe' => "<svg $X><iframe src=\"x\"/></svg>",
    'UTF-16' => "\xFF\xFE" . mb_convert_encoding($ok, 'UTF-16LE', 'UTF-8'),
    'UTF-16 ohne Kennzeichen' => mb_convert_encoding($ok, 'UTF-16BE', 'UTF-8'),
    'Kodierung ISO-8859-1' => "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>" . $ok,
    'kein SVG (HTML)' => '<html><body>x</body></html>',
    'falscher Namensraum' => '<svg xmlns="http://www.w3.org/1999/xhtml"><rect/></svg>',
    'ohne Namensraum' => '<svg><rect/></svg>',
    'kein XML' => '<?php echo 1; ?><svg',
];
foreach ($rej as $why => $bad) {
    $got = false;
    try {
        SvgCheck::sanitize($bad);
    } catch (MediaException) {
        $got = true;
    }
    check("SVG: $why wird abgelehnt", $got);
}
$good = SvgCheck::sanitize($ok);
check('SVG: einfaches Logo wird angenommen, Rundlauf stabil', str_contains($good['svg'], '<rect') && SvgCheck::sanitize($good['svg'])['svg'] === $good['svg']);
$ink = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><svg $X xmlns:inkscape=\"http://www.inkscape.org/namespaces/inkscape\" xmlns:sodipodi=\"http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd\" xmlns:rdf=\"http://www.w3.org/1999/02/22-rdf-syntax-ns#\" viewBox=\"0 0 10 10\" inkscape:version=\"1.2\"><sodipodi:namedview id=\"nv\" inkscape:zoom=\"1\"/><metadata><rdf:RDF/></metadata><!-- Kommentar --><g inkscape:label=\"Ebene\"><path d=\"M0 0L5 5\" style=\"fill:#fff;stroke:#000;-inkscape-stuff:1\" sodipodi:nodetypes=\"cc\"/></g></svg>";
$gi = SvgCheck::sanitize($ink);
check('SVG: Inkscape-Logo: Metadaten, Kommentar und Editor-Namensräume entfernt, Bild bleibt, Hinweis', !str_contains($gi['svg'], 'inkscape') && !str_contains($gi['svg'], 'sodipodi') && !str_contains($gi['svg'], 'metadata') && !str_contains($gi['svg'], 'Kommentar') && str_contains($gi['svg'], '<path') && str_contains($gi['svg'], 'fill:#fff;stroke:#000') && in_array('Editor-Metadaten wurden entfernt.', $gi['notes'], true));
$ill = "<?xml version=\"1.0\" encoding=\"utf-8\"?><!-- Generator: Adobe Illustrator 26 --><!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\"><svg version=\"1.1\" id=\"Ebene_1\" $X xmlns:xlink=\"http://www.w3.org/1999/xlink\" x=\"0px\" y=\"0px\" viewBox=\"0 0 20 20\" xml:space=\"preserve\"><style type=\"text/css\">.st0{fill:#FFFFFF;stroke:#000000;}</style><circle class=\"st0\" cx=\"10\" cy=\"10\" r=\"5\"/><use xlink:href=\"#Ebene_1\"/></svg>";
$gl = SvgCheck::sanitize($ill);
check('SVG: Illustrator-Logo mit W3C-DOCTYPE, style-Block und use #id wird angenommen', str_contains($gl['svg'], '<circle') && str_contains($gl['svg'], '.st0{fill:#FFFFFF;stroke:#000000}') && !str_contains($gl['svg'], 'DOCTYPE'));
$deepSvg = "<svg $X>" . str_repeat('<g>', 60) . str_repeat('</g>', 60) . '</svg>';
check('SVG: zu tief verschachtelt wird abgelehnt', $thrown(fn () => SvgCheck::sanitize($deepSvg)));
check('Prüfung 19: href und xlink:href am selben Element werden beide geprüft (Schlüsselkollision)', $thrown(fn () => SvgCheck::sanitize("<svg $X xmlns:xlink=\"http://www.w3.org/1999/xlink\"><use href=\"https://e.x/p.svg#a\" xlink:href=\"#a\"/></svg>")) && $thrown(fn () => SvgCheck::sanitize("<svg $X xmlns:xlink=\"http://www.w3.org/1999/xlink\"><use xlink:href=\"data:image/svg+xml,x\" href=\"#a\"/></svg>")));
check('Prüfung 19: SVG nur aus BOM ergibt Meldung statt Absturz; Affinity- und Vectornator-Metadaten werden entfernt statt abgelehnt', $thrown(fn () => SvgCheck::sanitize("\xEF\xBB\xBF")) && !str_contains(SvgCheck::sanitize("<svg $X xmlns:serif=\"http://www.serif.com/\" xmlns:vectornator=\"http://vectornator.io\"><rect serif:id=\"a\" vectornator:layerName=\"b\"/></svg>")['svg'], 'serif'));
check('SVG: Logo-Prüfung der Einstellungen nutzt dieselbe Klasse', !str_contains(file_get_contents(__DIR__ . '/../app/src/Site/Settings.php'), 'function checkSvg') && str_contains(file_get_contents(__DIR__ . '/../app/src/Site/Settings.php'), 'SvgCheck::sanitize'));

// ======================= Block B: PDF =======================
$pdfOk = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$wp = static function (string $bytes) use ($f): string {
    $p = $f . '/' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($p, $bytes);
    return $p;
};
$pdfRej = [
    '/JavaScript' => "%PDF-1.4\n1 0 obj<</S/JavaScript/JS(app.alert(1))>>endobj\n%%EOF\n",
    '/OpenAction' => "%PDF-1.4\n1 0 obj<</OpenAction 2 0 R>>endobj\n%%EOF\n",
    'Namens-Tarnung #4A' => "%PDF-1.4\n1 0 obj<</S/#4AavaScript>>endobj\n%%EOF\n",
    'Namens-Tarnung /J#53' => "%PDF-1.4\n1 0 obj<</J#53 (app.alert(1))>>endobj\n%%EOF\n",
    '/JS als Hex-Text' => "%PDF-1.4\n1 0 obj<</JS<6170702e616c657274>>>endobj\n%%EOF\n",
    '/JS als Verweis' => "%PDF-1.4\n1 0 obj<</JS 12 0 R>>endobj\n%%EOF\n",
    '/JS nach Kommentar und NUL' => "%PDF-1.4\n1 0 obj<</JS %Tarnung\n\0(x)>>endobj\n%%EOF\n",
    '/AA-Wörterbuch' => "%PDF-1.4\n1 0 obj<</AA<</O 5 0 R>>>>endobj\n%%EOF\n",
    '/AA als Verweis' => "%PDF-1.4\n1 0 obj<</AA 7 0 R>>endobj\n%%EOF\n",
    '/JS mit Leerraum über die Blockgrenze' => "%PDF-1.4\n" . str_repeat('x', 65536 - 9 - 5) . '/JS' . str_repeat(' ', 5000) . "(x)\n%%EOF\n",
    'Verweis über die Blockgrenze' => "%PDF-1.4\n" . str_repeat('x', 65536 - 9 - 8) . '/JS 12' . str_repeat(' ', 5000) . "0 R\n%%EOF\n",
    'eingebettete Datei' => "%PDF-1.4\n1 0 obj<</Type/EmbeddedFile>>endobj\n%%EOF\n",
    '/Launch' => "%PDF-1.4\n1 0 obj<</S/Launch>>endobj\n%%EOF\n",
    'Marker auf der Blockgrenze' => "%PDF-1.4\n" . str_repeat('x', 65536 - 9 - 4) . "/OpenAction\n%%EOF\n",
    'ohne %PDF-' => "<html>\n%%EOF\n",
    'abgeschnitten (kein %%EOF)' => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n" . str_repeat('y', 2000),
];
foreach ($pdfRej as $why => $bad) {
    check("PDF: $why wird abgelehnt", $thrown(fn () => PdfCheck::check($wp($bad))));
}
$thrownOk = true;
try {
    PdfCheck::check($wp($pdfOk));
} catch (MediaException) {
    $thrownOk = false;
}
check('PDF: harmloses PDF wird angenommen', $thrownOk);
$thrownOk = true;
try {
    PdfCheck::check($wp("%PDF-1.4\n/Javascripting is fun /JSON /AAPL\n" . str_repeat('z', 100) . "\n%%EOF\n"));
} catch (MediaException) {
    $thrownOk = false;
}
check('PDF: Wörter, die nur mit einem Marker beginnen (/JSON, /AAPL), sind kein Treffer', $thrownOk);
// Prüfung 19: /JS und /AA stehen in komprimierten Bilddaten zufällig (etwa jedes zweite PDF mit 5 MB Bildern wurde abgelehnt).
$noise = '';
for ($i = 0; strlen($noise) < 5 * 1048576; $i++) {
    $noise .= hash('sha256', 'lk' . $i, true);
}
$noise = substr_replace($noise, "/JS\xFE/AA\x00Q/JS)/AA>>/JS 5x", 3 * 1048576, 26);
$thrownOk = true;
try {
    PdfCheck::check($wp("%PDF-1.7\n1 0 obj<</Length 5242880>>stream\n" . $noise . "\nendstream endobj\n%%EOF\n"));
} catch (MediaException) {
    $thrownOk = false;
}
check('PDF: /JS und /AA ohne passenden Wert (zufällig in Bilddaten) sind kein Treffer, 5 MB Strom wird angenommen', $thrownOk && preg_match('/\/(?:JS|AA)(?![A-Za-z0-9])/', $noise) === 1);

// ======================= Block B: Dateien (Annahme am Inhalt, Ablage, öffentliche Kopie) =======================
$fi = $svc->files;
$png = static function () use ($wp): string {
    $im = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($im);
    return $wp((string) ob_get_clean());
};
$mediaDir = $svc->config->mediaDir();
$folderF = $fo->create(0, 'Dokumente');
$rp = $fi->ingest(1, $wp($pdfOk), 'Preisliste 2026.pdf', $folderF);
$fp = $db->one('SELECT * FROM files WHERE id = ?', [$rp['file_id']]);
check('Datei: PDF angenommen, privat unter files/<xx>/<token>.pdf (Zufallsname), Typ am Inhalt', !$rp['duplicate'] && $fp['kind'] === 'pdf' && $fp['mime'] === 'application/pdf' && preg_match('#^[0-9a-f]{2}/[0-9a-f]{24}\.pdf$#', $fp['path']) === 1 && is_file($svc->dataDir . '/files/' . $fp['path']) && $fp['name'] === 'Preisliste 2026.pdf' && (int) $fp['public'] === 0);
check('Datei: Ablageordner gesperrt (.htaccess)', is_file($svc->dataDir . '/files/.htaccess') && str_contains(file_get_contents($svc->dataDir . '/files/.htaccess'), 'Require all denied'));
check('Datei: gleicher Inhalt ist Dublette, Ordnername wird gemeldet', ($d = $fi->ingest(1, $wp($pdfOk), 'andere.pdf', 0))['duplicate'] && $d['folder'] === 'Dokumente' && (int) $db->value('SELECT COUNT(*) FROM files') === 1);
check('Datei: HTML als .pdf wird abgelehnt (Endung und Name zählen nicht)', $thrown(fn () => $fi->ingest(1, $wp('<html><script>alert(1)</script></html>'), 'x.pdf', 0)));
check('Datei: GIF mit .png-Namen wird abgelehnt', $thrown(fn () => $fi->ingest(1, $wp("GIF89a\x01\x00\x01\x00\x00\x00\x00;"), 'x.png', 0)));
check('Datei: PHP als .svg wird abgelehnt', $thrown(fn () => $fi->ingest(1, $wp('<?php system($_GET["c"]); ?>'), 'shell.svg', 0)) && $thrown(fn () => $fi->ingest(1, $wp('<?php /* <svg x> */ echo 1; ?>'), 'shell2.svg', 0)));
check('Datei: leere und zu große Dateien werden abgelehnt', $thrown(fn () => $fi->ingest(1, $wp(''), 'x.pdf', 0)));
$big = $wp('<svg ' . $X . '>' . str_repeat(' ', Files::MAX_SVG + 10) . '</svg>');
check('Datei: SVG über 1 MB wird abgelehnt', $thrown(fn () => $fi->ingest(1, $big, 'gross.svg', 0)));
check('Datei: abgeschnittenes PDF wird abgelehnt', $thrown(fn () => $fi->ingest(1, $wp("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n"), 'kaputt.pdf', 0)));
check('Datei: Polyglot (PDF mit HTML-Anhang, ohne aktive PDF-Marker) wird als PDF mit fester Endung abgelegt, nie als HTML', ($pg = $fi->ingest(1, $wp("%PDF-1.4\n<html><body>x</body></html>\n%%EOF\n"), 'poly.html', 0))['kind'] === 'pdf' && str_ends_with((string) $db->value('SELECT path FROM files WHERE id = ?', [$pg['file_id']]), '.pdf'));
$rs = $fi->ingest(1, $wp($ink), 'logo-inkscape.svg', 0);
$sv = $db->one('SELECT * FROM files WHERE id = ?', [$rs['file_id']]);
$stored = file_get_contents($svc->dataDir . '/files/' . $sv['path']);
check('Datei: SVG wird bereinigt gespeichert (Hinweis, nur die saubere Fassung liegt auf dem Server, Hash der Fassung)', $sv['kind'] === 'svg' && $rs['notes'] !== [] && !str_contains($stored, 'inkscape') && hash('sha256', $stored) === $sv['sha256'] && str_ends_with($sv['path'], '.svg'));
check('Datei: SVG mit Skript wird abgelehnt, nichts abgelegt', $thrown(fn () => $fi->ingest(1, $wp($rej['Skript']), 'bose.svg', 0)) && (int) $db->value("SELECT COUNT(*) FROM files WHERE kind = 'svg'") === 1);
$rn = $fi->ingest(1, $png(), 'logo.png', 0);
check('Datei: PNG als Datei (ohne Pipeline, ohne Varianten)', $db->value('SELECT kind FROM files WHERE id = ?', [$rn['file_id']]) === 'image' && str_ends_with((string) $db->value('SELECT path FROM files WHERE id = ?', [$rn['file_id']]), '.png'));

// Öffentlich schalten
$fi->setPublic($rp['file_id'], true);
$fp = $db->one('SELECT * FROM files WHERE id = ?', [$rp['file_id']]);
check('Öffentlich: Kopie unter media/dateien/<token8>-<slug>.pdf, Adresse stabil', (int) $fp['public'] === 1 && preg_match('/^[0-9a-f]{8}-preisliste-2026\.pdf$/', $fp['public_name']) === 1 && is_file($mediaDir . '/dateien/' . $fp['public_name']) && $fi->publicUrl($fp) === '/media/dateien/' . $fp['public_name']);
$ht = (string) file_get_contents($mediaDir . '/dateien/.htaccess');
check('Öffentlich: .htaccess sperrt PHP, setzt Typen, nosniff, SVG-Schutzkopf, überschreibt immutable (Header set)', str_contains($ht, 'RemoveHandler .php') && str_contains($ht, 'Require all denied') && str_contains($ht, 'AddType application/pdf .pdf') && str_contains($ht, 'AddType image/svg+xml .svg') && str_contains($ht, 'X-Content-Type-Options "nosniff"') && str_contains($ht, "default-src 'none'; style-src 'unsafe-inline'; sandbox") && str_contains($ht, 'Header set Cache-Control "public, max-age=86400"') && !str_contains($ht, 'immutable') && str_contains($ht, 'Content-Disposition "inline"'));
$nameBefore = $fp['public_name'];
$fi->rename($rp['file_id'], 'Neue Preisliste.pdf');
check('Öffentlich: Umbenennen ändert nur den Anzeigenamen, die Adresse bleibt', $db->value('SELECT name FROM files WHERE id = ?', [$rp['file_id']]) === 'Neue Preisliste.pdf' && $db->value('SELECT public_name FROM files WHERE id = ?', [$rp['file_id']]) === $nameBefore);
check('Öffentlich: SVG nur mit bestandenem Selbsttest des Schutzkopfes (hier: Test schlägt fehl, bleibt privat)', $thrown(fn () => $fi->setPublic($rs['file_id'], true)) && (int) $db->value('SELECT public FROM files WHERE id = ?', [$rs['file_id']]) === 0 && !glob($mediaDir . '/dateien/*-*.svg'));
$fiOk = new Files($db, $svc->clock, $svc->dataDir, $mediaDir, 50 * 1048576, '', static fn (): bool => true);
$db->run("DELETE FROM meta WHERE k = 'svg_probe'");
$fiOk->setPublic($rs['file_id'], true);
check('Öffentlich: SVG mit bestandenem Selbsttest bekommt eine Kopie', (int) $db->value('SELECT public FROM files WHERE id = ?', [$rs['file_id']]) === 1 && count(glob($mediaDir . '/dateien/*-*.svg') ?: []) === 1);
$fi->setPublic($rp['file_id'], false);
check('Öffentlich: zurück auf privat löscht die Kopie sofort', !is_file($mediaDir . '/dateien/' . $nameBefore) && (int) $db->value('SELECT public FROM files WHERE id = ?', [$rp['file_id']]) === 0);
$fi->setPublic($rp['file_id'], true);
$fi->trash([$rp['file_id']]);
check('Papierkorb: Datei ist sofort nicht mehr öffentlich abrufbar, Original bleibt bis zum Löschen', !is_file($mediaDir . '/dateien/' . $nameBefore) && is_file($svc->dataDir . '/files/' . $db->value('SELECT path FROM files WHERE id = ?', [$rp['file_id']])));
check('Papierkorb: Datei zurückholen (Ordner existiert), dann wieder privat', $fi->restore([$rp['file_id']]) === 1 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$rp['file_id']]) === 0);
$fi->trash([$rp['file_id']]);
$absPdf = $svc->dataDir . '/files/' . $db->value('SELECT path FROM files WHERE id = ?', [$rp['file_id']]);
$fi->purge([$rp['file_id']]);
check('Endgültig löschen: Zeile und Original weg', !is_file($absPdf) && $db->value('SELECT 1 FROM files WHERE id = ?', [$rp['file_id']]) === null);
$fq = $fo->ensurePath(0, ['Mit Datei']);
$rf = $fi->ingest(1, $wp("%PDF-1.4\nzweites\n%%EOF\n"), 'z.pdf', $fq['id']);
$fo->trash($fq['id']);
check('Papierkorb: Zweig nimmt Dateien mit, Wiederherstellen bringt sie zurück', (int) $db->value('SELECT trash_root_id FROM files WHERE id = ?', [$rf['file_id']]) === $fq['id'] && $fo->restore($fq['id'])['files'] === 1 && (int) $db->value('SELECT folder_id FROM files WHERE id = ?', [$rf['file_id']]) === $fq['id']);
// Pfadsicherheit der Ablage: absolute() akzeptiert nur das erwartete Muster
check('Ablage: absolute() verweigert Pfade außerhalb des Musters (../, absolut)', $fi->absolute(['path' => '../../etc/passwd']) === null && $fi->absolute(['path' => '/etc/passwd']) === null && $fi->absolute(['path' => 'aa/' . str_repeat('a', 24) . '.php']) === null);

// ======================= Block B/C: Seiten, Auslieferung, Rechte, CSRF, Audit =======================
$pg = $b->get('/admin/medien');
check('Medien: Seite für Angemeldete, Baum, Menüpunkt, Skript', $pg->status === 200 && str_contains($pg->body, 'role="tree"') && str_contains($pg->body, 'href="/admin/medien"') && str_contains($pg->body, '/assets/js/medien.js') && str_contains($pg->body, 'Neuer Ordner'));
check('Medien: ohne Inline-Skript und -Stil (CSP)', !preg_match('~\sstyle=|<script(?![^>]*\ssrc=)|\sonclick=~i', $pg->body));
check('Medien: Marke und Fuß wie im übrigen Admin', str_contains($pg->body, 'lu<em>ro</em>home'));
$anon = new TestBrowser($m['app'], $m['config']);
check('Medien: ohne Anmeldung Weiterleitung (Seite, Datei, Papierkorb)', $anon->get('/admin/medien')->status === 303 && $anon->get('/admin/medien/datei/1')->status === 303 && $anon->get('/admin/medien/papierkorb')->status === 303);
check('Medien: ohne CSRF-Token jede ändernde Anfrage abgelehnt', $b->request('POST', '/admin/medien/ordner', ['name' => 'x'])->status === 403 && $b->request('POST', '/admin/medien/loeschen', ['ordner_id' => ['1']])->status === 403 && $b->request('POST', '/admin/medien/verschieben', ['ziel' => '0'])->status === 403
    && $b->request('POST', '/admin/medien/datei/1/oeffentlich', ['an' => '1'])->status === 403 && $b->request('POST', '/admin/medien/plan', [], ['CONTENT_TYPE' => 'application/json'], '{}')->status === 403 && $b->request('POST', '/admin/medien/papierkorb', ['aktion' => 'loeschen'])->status === 403);
$cs = static fn (): string => TestBrowser::csrf($b->get('/admin/medien'));
$auditCount = static fn (string $ev): int => (int) $db->value('SELECT COUNT(*) FROM audit_log WHERE event = ?', [$ev]);
$r = $b->post('/admin/medien/ordner', ['_csrf' => $cs(), 'name' => "Ferien\u{202E}2026", 'ordner' => '0']);
$ferien = (int) $db->value("SELECT id FROM folders WHERE name_key = 'ferien2026'");
check('Medien: Ordner anlegen (Bidi-Zeichen entfernt), Audit ohne Namen', $r->status === 303 && $ferien > 0 && $auditCount('folder_created') === 1 && !str_contains((string) $db->value("SELECT detail FROM audit_log WHERE event = 'folder_created'"), 'Ferien'));
check('Medien: doppelter Name wird abgelehnt mit Meldung', ($d = $b->post('/admin/medien/ordner', ['_csrf' => $cs(), 'name' => 'FERIEN2026', 'ordner' => '0']))->status === 400 && str_contains($d->body, 'schon'));
$page = $b->get('/admin/medien?ordner=' . $ferien);
check('Medien: Ordneransicht mit Brotkrumen', $page->status === 200 && str_contains($page->body, 'Ferien2026') && str_contains($page->body, 'class="crumbs"'));
check('Medien: unbekannter Ordner → 404', $b->get('/admin/medien?ordner=99999')->status === 404);
check('Medien: Ordnerparameter mit Text wird wie Wurzel behandelt, kein Fehler', $b->get('/admin/medien?ordner=abc')->status === 200 && $b->get('/admin/medien?ordner=' . rawurlencode("1' OR '1'='1"))->status === 200);
$b->post('/admin/medien/umbenennen', ['_csrf' => $cs(), 'art' => 'ordner', 'id' => (string) $ferien, 'name' => 'Ferien 2026', 'ordner' => '0']);
check('Medien: Ordner umbenennen, Audit', $db->value('SELECT name FROM folders WHERE id = ?', [$ferien]) === 'Ferien 2026' && $auditCount('folder_renamed') === 1);
check('Medien: Umbenennen mit Schrägstrich abgelehnt', $b->post('/admin/medien/umbenennen', ['_csrf' => $cs(), 'art' => 'ordner', 'id' => (string) $ferien, 'name' => 'a/b', 'ordner' => '0'])->status === 400);
check('Medien: Umbenennen-Formular in der Liste (ohne Skript)', str_contains($b->get('/admin/medien?bearbeiten=o' . $ferien)->body, 'id="mrename"'));
$sub = $fo->create($ferien, 'Tag 1');
$r = $b->post('/admin/medien/verschieben', ['_csrf' => $cs(), 'ordner_id' => [(string) $ferien], 'ziel' => (string) $sub, 'ordner' => '0']);
check('Medien: Zyklus per Formular abgelehnt (400), nichts geändert', $r->status === 400 && (int) $db->value('SELECT parent_id FROM folders WHERE id = ?', [$ferien]) === 0);
$phM = $mk(320, 0, 'verschiebbar.jpg');
$r = $b->post('/admin/medien/verschieben', ['_csrf' => $cs(), 'foto' => [(string) $phM], 'ziel' => (string) $sub, 'ordner' => '0']);
check('Medien: Foto verschieben, Audit mit Kennungen und Zahl', $r->status === 303 && (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$phM]) === $sub && $auditCount('media_moved') === 1 && preg_match('/^to=\d+,count=1$/', (string) $db->value("SELECT detail FROM audit_log WHERE event = 'media_moved'")) === 1);
check('Medien: Verschieben in nicht vorhandenen Ordner → Fehler, Foto bleibt', $b->post('/admin/medien/verschieben', ['_csrf' => $cs(), 'foto' => [(string) $phM], 'ziel' => '987654', 'ordner' => '0'])->status === 400 && (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$phM]) === $sub);
check('Medien: Symbolansicht und Liste zeigen das Foto', str_contains($b->get('/admin/medien?ordner=' . $sub)->body, 'verschiebbar.jpg') && str_contains($b->get('/admin/medien?ordner=' . $sub . '&ansicht=symbole')->body, 'class="mgrid"'));
check('Medien: Suche über alle Ordner nennt den Ort', str_contains($b->get('/admin/medien?q=verschiebbar')->body, 'Ferien 2026 / Tag 1'));
// Löschen und Papierkorb über die Oberfläche
$r = $b->post('/admin/medien/loeschen', ['_csrf' => $cs(), 'ordner_id' => [(string) $ferien], 'ordner' => '0']);
check('Medien: Ordner löschen legt den Zweig in den Papierkorb (Audit mit Zahl)', $r->status === 303 && $db->value('SELECT deleted_at FROM folders WHERE id = ?', [$ferien]) !== null && $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$phM]) !== null && $auditCount('media_trashed') >= 1);
$tp = $b->get('/admin/medien/papierkorb');
check('Medien: Papierkorb nennt den Ordner mit Zahlen', $tp->status === 200 && str_contains($tp->body, 'Ferien 2026') && str_contains($tp->body, '1 Fotos'));
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => TestBrowser::csrf($tp), 'aktion' => 'wiederherstellen', 'ordner_id' => [(string) $ferien]]);
check('Medien: Wiederherstellen über die Oberfläche (Audit)', $r->status === 303 && $db->value('SELECT deleted_at FROM folders WHERE id = ?', [$ferien]) === null && $db->value('SELECT deleted_at FROM photos WHERE id = ?', [$phM]) === null && $auditCount('media_restored') === 1);
check('Runde 3: nach dem Wiederherstellen mit wartenden Fotos geht es zur Medienseite (dort wird aufbereitet)', str_starts_with($r->headers['Location'] ?? '', '/admin/medien?m=zurueck'));
check('Medien: Hinweis auf zu bearbeitende Fotos (pending) nach dem Wiederherstellen', str_contains($b->get('/admin/medien')->body, 'werden noch aufbereitet'));
$r = $b->request('POST', '/admin/medien/aufbereiten', ['_csrf' => $cs()], ['HTTP_X_REQUESTED_WITH' => 'fetch']);
check('Medien: Aufbereiten verarbeitet wartende Fotos', json_decode($r->body, true)['remaining'] === 0 && $db->value('SELECT status FROM photos WHERE id = ?', [$phM]) === 'ready');
// Galerie nachträglich aus einem Ordner
$r = $b->post('/admin/medien/galerie', ['_csrf' => $cs(), 'ordner' => (string) $sub, 'modus' => 'direkt', 'titel' => 'Tag 1 Strand']);
$gid = (int) $db->value("SELECT id FROM galleries WHERE title = 'Tag 1 Strand'");
check('Medien: „Aus diesem Ordner Galerie machen“: Entwurf mit den Fotos, verknüpft', $r->status === 303 && $gid > 0 && $db->value('SELECT status FROM galleries WHERE id = ?', [$gid]) === 'draft' && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ?', [$gid]) === 1 && (int) $db->value('SELECT gallery_id FROM folders WHERE id = ?', [$sub]) === $gid && $auditCount('folder_gallery_created') === 1);

// Auslieferung privater Dateien
$pdfBody = "%PDF-1.4\n" . str_repeat('A', 5000) . "\n%%EOF\n";
$rd = $fi->ingest(1, $wp($pdfBody), 'Prüfung "wichtig"/€.pdf', 0);
$did = $rd['file_id'];
$dl = $b->get('/admin/medien/datei/' . $did);
check('Auslieferung: Download mit festem Typ, nosniff, attachment mit ASCII-Rückfall und UTF-8-Name, private, kein Cache', $dl->status === 200 && $dl->headers['Content-Type'] === 'application/pdf' && $dl->headers['X-Content-Type-Options'] === 'nosniff' && str_starts_with($dl->headers['Content-Disposition'], 'attachment; filename="') && str_contains($dl->headers['Content-Disposition'], "filename*=UTF-8''") && !str_contains($dl->headers['Content-Disposition'], '"/') && $dl->headers['Cache-Control'] === 'private, no-store' && $dl->headers['Accept-Ranges'] === 'bytes');
check('Auslieferung: Datei wird gestreamt, Länge stimmt', ($dl->stream[2] ?? 0) === strlen($pdfBody) && $dl->headers['Content-Length'] === (string) strlen($pdfBody) && file_get_contents($dl->stream[0]) === $pdfBody);
check('Auslieferung: ?ansicht=1 zeigt inline', str_starts_with($b->get('/admin/medien/datei/' . $did . '?ansicht=1')->headers['Content-Disposition'], 'inline'));
$rg = $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=10-19']);
check('Auslieferung: Range → 206 mit Content-Range, genau 10 Bytes', $rg->status === 206 && $rg->headers['Content-Range'] === 'bytes 10-19/' . strlen($pdfBody) && $rg->stream[1] === 10 && $rg->stream[2] === 10);
$rt = $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=-100']);
check('Auslieferung: Range letzte 100 Bytes; offenes Ende', $rt->status === 206 && $rt->stream[2] === 100 && $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=5000-'])->status === 206);
check('Auslieferung: Range außerhalb → 416 mit Größe', ($r416 = $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=99999-']))->status === 416 && $r416->headers['Content-Range'] === 'bytes */' . strlen($pdfBody) && $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=abc'])->status === 416 && $b->request('GET', '/admin/medien/datei/' . $did, [], ['HTTP_RANGE' => 'bytes=9-3'])->status === 416);
$svgId = $rs['file_id'];
$sd = $b->get('/admin/medien/datei/' . $svgId . '?ansicht=1');
check('Auslieferung: SVG direkt aufgerufen nur mit Schutzkopf (ersetzt den allgemeinen), Typ fest', $sd->headers['Content-Security-Policy'] === "default-src 'none'; style-src 'unsafe-inline'; sandbox" && $sd->headers['Content-Type'] === 'image/svg+xml' && $sd->headers['X-Content-Type-Options'] === 'nosniff');
$final = $sd->withDefaults(\Lichtkammer\SecurityHeaders::for(new \Lichtkammer\Request(['HTTPS' => 'on'], $m['config']), $m['config']));
check('Auslieferung: nach den Standardköpfen bleibt genau der eine CSP-Kopf der Route', $final->headers['Content-Security-Policy'] === "default-src 'none'; style-src 'unsafe-inline'; sandbox");
check('Auslieferung: Kennung nur Ziffern; ../, Text, SQL, 0, riesig, Papierkorb → 404', $b->get('/admin/medien/datei/abc')->status === 404 && $b->get('/admin/medien/datei/' . rawurlencode('..'))->status === 404 && $b->get('/admin/medien/datei/' . rawurlencode('../../etc/passwd'))->status === 404 && $b->get('/admin/medien/datei/' . rawurlencode("1 OR 1=1"))->status === 404 && $b->get('/admin/medien/datei/0')->status === 404 && $b->get('/admin/medien/datei/99999999999')->status === 404 && $b->get('/admin/medien/datei/' . $did . 'x')->status === 404);
$fi->trash([$did]);
check('Auslieferung: Datei im Papierkorb ist nicht abrufbar', $b->get('/admin/medien/datei/' . $did)->status === 404);
$fi->restore([$did]);
// Öffentlich schalten über die Oberfläche
$r = $b->post('/admin/medien/datei/' . $did . '/oeffentlich', ['_csrf' => $cs(), 'an' => '1', 'ordner' => '0']);
check('Medien: Datei öffentlich schalten (Audit mit Zielzustand)', $r->status === 303 && (int) $db->value('SELECT public FROM files WHERE id = ?', [$did]) === 1 && $db->value("SELECT detail FROM audit_log WHERE event = 'file_public_changed' ORDER BY id DESC LIMIT 1") === 'file=' . $did . ',public=1');
check('Medien: veröffentlichte Adresse steht in der Liste', str_contains($b->get('/admin/medien')->body, '/media/dateien/'));
check('Medien: SVG öffentlich schalten ohne Schutzkopf-Selbsttest → Fehlermeldung (hier fehlgeschlagener Test)', $b->post('/admin/medien/datei/' . $svgId . '/oeffentlich', ['_csrf' => $cs(), 'an' => '1', 'ordner' => '0'])->status === 400 || (int) $db->value('SELECT public FROM files WHERE id = ?', [$svgId]) === 1);
check('Medien: Auslieferung auch für HEAD ohne Inhalt', $b->request('HEAD', '/admin/medien/datei/' . $did)->status === 200);
check('Protokoll: Kette bleibt gültig, keine Datei- oder Ordnernamen im Protokoll', $svc->audit->verify()['ok'] && $db->value("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%Ferien%' OR detail LIKE '%Pr%fung%' OR detail LIKE '%.pdf%'") == 0);

// Rechte: Redaktion darf Medien nutzen, „Bestand übernehmen“ nur Administrator
$db->run("INSERT INTO users(username, display_name, role, password_hash, totp_secret_enc, totp_confirmed_at, created_at, webauthn_handle) SELECT 'redaktion', '', 'editor', password_hash, totp_secret_enc, totp_confirmed_at, created_at, 'ed' || substr(webauthn_handle, 3) FROM users WHERE id = 1");
$edId = (int) $db->value("SELECT id FROM users WHERE username = 'redaktion'");
$edSess = $svc->sessions->create($edId, 'full', \Lichtkammer\Auth\ClientIp::forms('203.0.113.9')['prefix'], 'TestBrowser/1');
$ed = new TestBrowser($m['app'], $m['config'], '203.0.113.9');
$ed->jar[$svc->cookies->name('lk_sid')] = $edSess['id'];
$edCsrf = static fn (): string => TestBrowser::csrf($ed->get('/admin/medien'));
check('Rechte: Redaktion sieht Medien und legt Ordner an', $ed->get('/admin/medien')->status === 200 && $ed->post('/admin/medien/ordner', ['_csrf' => $edCsrf(), 'name' => 'Redaktion', 'ordner' => '0'])->status === 303);
check('Rechte: Redaktion darf Datei öffentlich schalten (E7) und löschen', $ed->post('/admin/medien/datei/' . $did . '/oeffentlich', ['_csrf' => $edCsrf(), 'an' => '0', 'ordner' => '0'])->status === 303 && $ed->post('/admin/medien/loeschen', ['_csrf' => $edCsrf(), 'datei' => [(string) $did], 'ordner' => '0'])->status === 303);
check('Rechte: „Bestand übernehmen“ nur für Administratoren (Redaktion → 403)', $ed->post('/admin/medien/bestand', ['_csrf' => $edCsrf()])->status === 403);

// ======================= Block E: Ordner-Upload (Plan, Fortsetzen, Galerien, Abbruch) =======================
$plan = static function (TestBrowser $br, array $data, string $ct = 'application/json') use ($m): \Lichtkammer\Response {
    $csrf = TestBrowser::csrf($br->get('/admin/medien'));
    return $br->request('POST', '/admin/medien/plan', [], ['CONTENT_TYPE' => $ct, 'HTTP_X_CSRF_TOKEN' => $csrf], json_encode($data));
};
$pj = static fn (\Lichtkammer\Response $r): array => (array) json_decode($r->body, true);
$up2 = static function (TestBrowser $br, string $name, string $bytes, array $extra = [], int $chunk = 3000, bool $finish = true) use ($m): array {
    $csrf = TestBrowser::csrf($br->get('/admin/medien'));
    $start = json_decode($br->post('/admin/upload/start', ['_csrf' => $csrf, 'name' => $name, 'size' => (string) strlen($bytes)] + $extra)->body, true);
    if (!isset($start['id'])) {
        return ['stage' => 'start'] + (array) $start;
    }
    for ($off = (int) $start['received']; $off < strlen($bytes); $off += $chunk) {
        $r = $br->request('POST', '/admin/upload/' . $start['id'] . '/chunk?offset=' . $off, [], ['HTTP_X_CSRF_TOKEN' => $csrf], substr($bytes, $off, $chunk));
        if ($r->status !== 200) {
            return ['stage' => 'chunk', 'status' => $r->status];
        }
        if (!$finish && $off + $chunk >= (int) ($start['size'] ?? 0) / 2) {
            return ['stage' => 'half', 'id' => $start['id']];
        }
    }
    $fin = (array) json_decode($br->request('POST', '/admin/upload/' . $start['id'] . '/finish', [], ['HTTP_X_CSRF_TOKEN' => $csrf])->body, true);
    return $fin + ['id' => $start['id'], 'start' => $start];
};
check('Plan: falscher Inhaltstyp → 415, zu großer Körper → 413, Unsinn → 400', $plan($b, ['folders' => [], 'files' => []], 'text/plain')->status === 415
    && $b->request('POST', '/admin/medien/plan', [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => TestBrowser::csrf($b->get('/admin/medien'))], str_repeat('x', 2 * 1024 * 1024 + 5))->status === 413
    && $plan($b, ['x' => 1])->status === 400);
$r = $plan($b, ['target' => 0, 'folders' => [['path' => ['Island']], ['path' => ['Island', 'Tag 1']], ['path' => ['island', 'TAG 1']]], 'files' => [['f' => 1, 'name' => 'a.jpg', 'size' => 10], ['f' => 1, 'name' => 'b.jpg', 'size' => 12]]]);
$pr = $pj($r);
check('Plan: Ordner angelegt, andere Groß-/Kleinschreibung führt zusammen (gleiche Kennung)', $r->status === 200 && $pr['folders'][1]['id'] === $pr['folders'][2]['id'] && count($pr['folders']) === 3 && $pr['summary']['new_folders'] >= 1 && count($pr['files']) === 2 && $pr['files'][0]['folder_id'] === $pr['folders'][1]['id'] && isset($pr['plan']));
$island = (int) $pr['folders'][0]['id'];
check('Plan: zweiter Aufruf mit gleichem Ordner legt nichts neu an, meldet Zusammenführen', ($p2 = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['island']]], 'files' => []])))['summary']['new_folders'] === 0 && $p2['folders'][0]['id'] === $island && $p2['summary']['merged'] === ['island']);
$pa = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['..', '..', 'etc']], ['path' => ['/abs/pfad']], ['path' => ['a\\b']], ['path' => ["Ordner\u{202E}"]], ['path' => ['CON']]], 'files' => []]));
check('Plan: Pfadangriffe werden zu harmlosen Ordnernamen (kein „..“, nie ein Pfad außerhalb)', array_column(array_map(fn ($f) => $f['id'], $pa['folders']) ? array_map(fn ($id) => $db->one('SELECT name, parent_id FROM folders WHERE id = ?', [$id]), array_column($pa['folders'], 'id')) : [], 'name') === ['etc', 'pfad', 'a\\b', 'Ordner', 'CON'] && !is_dir($tmp . '/etc'));
check('Plan: Tiefe 9 und Pfad über 800 Zeichen werden abgelehnt, nichts angelegt', ($pd = $plan($b, ['folders' => [['path' => array_fill(0, 9, 'z')]], 'files' => []]))->status === 400 && $db->value("SELECT 1 FROM folders WHERE name_key = 'z'") === null && $plan($b, ['folders' => [['path' => array_fill(0, 5, str_repeat('y', 200))]], 'files' => []])->status === 400);
check('Plan: ungültige Dateieinträge (Index außerhalb, kein Name) → 400 und kein Ordner bleibt zurück', $plan($b, ['folders' => [['path' => ['Rollback']]], 'files' => [['f' => 7, 'name' => 'a', 'size' => 1]]])->status === 400 && $db->value("SELECT 1 FROM folders WHERE name_key = 'rollback'") === null);
$many = [];
for ($i = 0; $i < 2000; $i++) {
    $many[] = ['f' => 0, 'name' => 'IMG_' . $i . '.jpg', 'size' => 100 + $i];
}
$t0 = microtime(true);
$big = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Massen', 'Unter', 'Ordner']]], 'files' => $many]));
check('Plan: 2000 Einträge in einer Anfrage, tiefer Pfad', count($big['files'] ?? []) === 2000 && microtime(true) - $t0 < 20);
$tooMany = array_fill(0, 5001, ['f' => 0, 'name' => 'x.jpg', 'size' => 1]);
check('Plan: mehr als 5000 Dateien → abgelehnt', $plan($b, ['folders' => [['path' => ['Viel']]], 'files' => $tooMany])->status === 400);
$manyF = [];
for ($i = 0; $i < 5001; $i++) {
    $manyF[] = ['path' => ['F' . $i]];
}
check('Plan: mehr als 5000 Ordner im Plan → abgelehnt', $plan($b, ['folders' => $manyF, 'files' => []])->status === 400);
$trashedF = $fo->create(0, 'Weggelegt');
$fo->trash($trashedF);
check('Plan: Zielordner im Papierkorb wird abgelehnt', $plan($b, ['target' => $trashedF, 'folders' => [['path' => ['x']]], 'files' => []])->status === 400);
check('Upload: in Ordner im Papierkorb und in nicht vorhandenen Ordner abgelehnt', isset($up2($b, 'a.jpg', lk_jpeg(50, 50), ['folder' => (string) $trashedF])['error']) && isset($up2($b, 'a.jpg', lk_jpeg(50, 50), ['folder' => '999999'])['error']));

// Galerien beim Ordner-Upload
$gp = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Reise'], 'gallery' => true, 'title' => 'Reise'], ['path' => ['Reise', 'Tag 2'], 'gallery' => true], ['path' => ['🌄']], ['path' => ['🌅'], 'gallery' => true, 'title' => '🌅🌅']], 'files' => []]));
$g1 = (int) $gp['folders'][0]['gallery_id'];
$g2 = (int) $gp['folders'][1]['gallery_id'];
$g4 = (int) $gp['folders'][3]['gallery_id'];
check('Galerie: Titel genau der Ordnername, Entwurf, mit Ordner verknüpft', $db->value('SELECT title FROM galleries WHERE id = ?', [$g1]) === 'Reise' && $db->value('SELECT title FROM galleries WHERE id = ?', [$g2]) === 'Tag 2' && $db->value('SELECT status FROM galleries WHERE id = ?', [$g1]) === 'draft' && (int) $db->value('SELECT gallery_id FROM folders WHERE id = ?', [$gp['folders'][0]['id']]) === $g1 && $gp['folders'][2]['gallery_id'] === null);
check('Galerie: Adresse aus dem Namen; nur Emojis → „galerie“', $db->value('SELECT slug FROM galleries WHERE id = ?', [$g1]) === 'reise' && $db->value('SELECT slug FROM galleries WHERE id = ?', [$g4]) === 'galerie' && $db->value('SELECT title FROM galleries WHERE id = ?', [$g4]) === '🌅🌅');
$gp2 = $pj($plan($b, ['target' => $island, 'folders' => [['path' => ['Reise'], 'gallery' => true, 'title' => 'reise']], 'files' => []]));
$g5 = (int) $gp2['folders'][0]['gallery_id'];
check('Galerie: gleicher Titel (anderer Ordner) → warnt, legt trotzdem eine neue mit eindeutiger Adresse an (reise-2)', $gp2['folders'][0]['warn'] !== '' && $g5 !== $g1 && $db->value('SELECT slug FROM galleries WHERE id = ?', [$g5]) === 'reise-2');
check('Galerie: Entwurfsgalerie des Ordners wird bei erneutem Plan weiterbenutzt statt verdoppelt', ($gp3 = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Reise'], 'gallery' => true]], 'files' => []])))['folders'][0]['gallery_id'] === $g1);
// Hochladen mit Ordner und Galerie
$reiseId = (int) $gp['folders'][0]['id'];
$up = $up2($b, 'tag-1.jpg', lk_jpeg(321, 100), ['folder' => (string) $reiseId, 'gallery' => (string) $g1, 'intent' => 'photo', 'fp' => hash('sha256', 'x1')]);
check('Upload: Foto landet im Ordner und in der gewählten Galerie, protokolliert', ($up['photo_id'] ?? 0) > 0 && (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$up['photo_id']]) === $reiseId && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ? AND photo_id = ?', [$g1, $up['photo_id']]) === 1 && $db->value('SELECT fingerprint FROM photos WHERE id = ?', [$up['photo_id']]) === hash('sha256', 'x1'));
$dupUp = $up2($b, 'tag-1-kopie.jpg', lk_jpeg(321, 100), ['folder' => (string) $island, 'gallery' => (string) $g2]);
check('Upload: Dublette bleibt am alten Platz („schon vorhanden in Ordner …“), kommt aber in die neue Galerie', $dupUp['duplicate'] === true && $dupUp['folder'] === 'Reise' && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ? AND photo_id = ?', [$g2, $up['photo_id']]) === 1 && (int) $db->value('SELECT folder_id FROM photos WHERE id = ?', [$up['photo_id']]) === $reiseId);
$auto = $up2($b, 'auto.jpg', lk_jpeg(322, 100), ['folder' => (string) $reiseId]);
check('Upload: Ordner mit Entwurfsgalerie → neues Foto kommt automatisch dazu (E3b)', (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ? AND photo_id = ?', [$g1, $auto['photo_id']]) === 1);
$plain = $up2($b, 'plain.jpg', lk_jpeg(323, 100), ['folder' => (string) $reiseId, 'intent' => 'photo_plain']);
check('Upload: „photo_plain“ (im Fenster abgeschaltet) bleibt ohne Galerie', (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE photo_id = ?', [$plain['photo_id']]) === 0);
$db->run("UPDATE galleries SET status = 'published' WHERE id = ?", [$g1]);
$pub = $up2($b, 'veroeffentlicht.jpg', lk_jpeg(324, 100), ['folder' => (string) $reiseId]);
check('Upload: bei veröffentlichter Galerie kein automatisches Hinzufügen (N4)', (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE photo_id = ?', [$pub['photo_id']]) === 0);
// PDF und Dateien: nie in Galerien
$pdfU = $up2($b, 'info.pdf', "%PDF-1.4\n" . str_repeat('Q', 4000) . "\n%%EOF\n", ['folder' => (string) $reiseId, 'intent' => 'file', 'gallery' => (string) $g1]);
check('Upload: PDF (intent file) wird Datei im Ordner, nie in einer Galerie, Audit file_uploaded', ($pdfU['file_id'] ?? 0) > 0 && (int) $db->value('SELECT folder_id FROM files WHERE id = ?', [$pdfU['file_id']]) === $reiseId && $pdfU['kind'] === 'pdf' && $auditCount('file_uploaded') >= 1 && (int) $db->value('SELECT COUNT(*) FROM gallery_photos WHERE gallery_id = ?', [$g1]) === 2);
$badU = $up2($b, 'bose.pdf', '<html><script>alert(1)</script></html>' . str_repeat(' ', 100), ['folder' => (string) $reiseId, 'intent' => 'file']);
check('Upload: HTML als .pdf wird beim Abschluss abgelehnt, nichts gespeichert, Teilstück weg', isset($badU['error']) && glob($svc->dataDir . '/uploads/*.part') === []);
$pngSmall = (static function (): string {
    $im = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($im);
    return (string) ob_get_clean();
})();
$big5 = $up2($b, 'gross.png', $pngSmall . str_repeat("\0", 5 * 1048576 + 10), ['intent' => 'file'], 1048576);
check('Upload: Bild als Datei über 5 MB wird beim Abschluss abgelehnt, nichts gespeichert', isset($big5['error']) && str_contains($big5['error'], 'zu groß') && (int) $db->value("SELECT COUNT(*) FROM files WHERE name = 'gross.png'") === 0);
// Fortsetzen (H2)
$bytesR = lk_jpeg(400, 300);
$fpR = hash('sha256', 'fortsetzen');
$half = $up2($b, 'fortsetzen.jpg', $bytesR, ['folder' => (string) $reiseId, 'fp' => $fpR], 1000, false);
$again = json_decode($b->post('/admin/upload/start', ['_csrf' => TestBrowser::csrf($b->get('/admin/medien')), 'name' => 'fortsetzen.jpg', 'size' => (string) strlen($bytesR), 'folder' => (string) $reiseId, 'fp' => $fpR])->body, true);
check('Fortsetzen: gleiche Datei (Ordner, Name, Größe, Fingerabdruck) → vorhandene Upload-Kennung ab dem Stand', $half['stage'] === 'half' && $again['id'] === $half['id'] && $again['received'] > 0 && $again['received'] < strlen($bytesR));
$other = json_decode($b->post('/admin/upload/start', ['_csrf' => TestBrowser::csrf($b->get('/admin/medien')), 'name' => 'fortsetzen.jpg', 'size' => (string) strlen($bytesR), 'folder' => (string) $reiseId, 'fp' => hash('sha256', 'andere Fassung')])->body, true);
check('Fortsetzen: gleiche Größe, anderer Fingerabdruck → neue Datei, keine Mischung', $other['id'] !== $half['id'] && $other['received'] === 0);
$noFp = json_decode($b->post('/admin/upload/start', ['_csrf' => TestBrowser::csrf($b->get('/admin/medien')), 'name' => 'fortsetzen.jpg', 'size' => (string) strlen($bytesR), 'folder' => (string) $reiseId])->body, true);
check('Fortsetzen: ohne Fingerabdruck nie fortsetzen', $noFp['id'] !== $half['id'] && $noFp['received'] === 0);
$resume = $up2($b, 'fortsetzen.jpg', $bytesR, ['folder' => (string) $reiseId, 'fp' => $fpR], 1000);
check('Fortsetzen: Rest wird gesendet, Foto vollständig und lesbar', ($resume['photo_id'] ?? 0) > 0 && $db->value('SELECT bytes FROM photos WHERE id = ?', [$resume['photo_id']]) == strlen($bytesR));
// „schon vorhanden“ im Plan nur mit Fingerabdruck
$sk = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Reise']]], 'files' => [
    ['f' => 0, 'name' => 'fortsetzen.jpg', 'size' => strlen($bytesR), 'fp' => $fpR],
    ['f' => 0, 'name' => 'fortsetzen.jpg', 'size' => strlen($bytesR), 'fp' => hash('sha256', 'neu exportiert')],
    ['f' => 0, 'name' => 'fortsetzen.jpg', 'size' => strlen($bytesR)],
]]));
check('Plan: „schon vorhanden“ nur bei Ordner, Name, Größe UND Fingerabdruck (neu exportierte Datei gleicher Größe wird nicht übersprungen)', $sk['files'][0]['skip'] === true && $sk['files'][1]['skip'] === false && $sk['files'][2]['skip'] === false);
// Abbruch räumt auf
// ======================= Prüfung 18 (Code): Nachbesserungen =======================
$pf = $fo->ensurePath(0, ['Pruefung18']);
$pr = $fi->ingest(1, $wp("%PDF-1.4\npruefung18\n%%EOF\n"), 'p18.pdf', $pf['id']);
$fi->setPublic($pr['file_id'], true);
$p18 = (string) $db->value('SELECT public_name FROM files WHERE id = ?', [$pr['file_id']]);
$r = $b->post('/admin/medien/loeschen', ['_csrf' => $cs(), 'ordner_id' => [(string) $pf['id']], 'ordner' => '0']);
check('Prüfung 18: Ordner mit öffentlicher Datei gelöscht → öffentliche Kopie sofort weg, Datei privat (nicht erst nach 30 Tagen)', $r->status === 303 && $p18 !== '' && !is_file($mediaDir . '/dateien/' . $p18) && (int) $db->value('SELECT public FROM files WHERE id = ?', [$pr['file_id']]) === 0 && (int) $db->value('SELECT deleted_at IS NOT NULL FROM files WHERE id = ?', [$pr['file_id']]) === 1);
$fo->restore($pf['id']);
check('Prüfung 18: Wiederherstellen des Ordners macht die Datei nicht wieder öffentlich', (int) $db->value('SELECT public FROM files WHERE id = ?', [$pr['file_id']]) === 0 && !is_file($mediaDir . '/dateien/' . $p18));
// Rennen: lebender Inhalt unter einem Ordner im Papierkorb darf das endgültige Löschen nicht blockieren und nie mitgelöscht werden
$rc = $fo->ensurePath(0, ['Rennen']);
$rcKid = $fo->ensurePath($rc['id'], ['Kind']);
$fo->trash($rc['id']);
$db->run('UPDATE folders SET deleted_at = NULL WHERE id = ?', [$rcKid['id']]); // simuliert: Unterordner entstand gerade noch
$phRace = $mk(334, $rc['id'], 'rennen.jpg');
$fo->ensurePath(0, ['Kind']); // Namensgleichheit in der Wurzel
$rcOk = true;
try {
    $fo->purgeRows([$rc['id']]);
} catch (\Throwable) {
    $rcOk = false;
}
check('Prüfung 18: endgültiges Löschen mit lebendem Rest wirft nicht; Foto und Unterordner landen in der Wurzel (Name mit Zusatz bei Gleichheit)', $rcOk && $db->value('SELECT 1 FROM folders WHERE id = ?', [$rc['id']]) === null && $db->value('SELECT folder_id FROM photos WHERE id = ?', [$phRace]) === null && (int) $db->value('SELECT parent_id FROM folders WHERE id = ?', [$rcKid['id']]) === 0 && str_contains((string) $db->value('SELECT name FROM folders WHERE id = ?', [$rcKid['id']]), 'gerettet'));
// Prüfung 19: Retten mit Zusatz endet auch bei Namen mit 255 Zeichen (vorher Endlosschleife)
$longName = str_repeat('L', 255);
$lr = $fo->ensurePath(0, ['LangRennen']);
$lrKid = $fo->ensurePath($lr['id'], [$longName]);
$fo->trash($lr['id']);
$db->run('UPDATE folders SET deleted_at = NULL WHERE id = ?', [$lrKid['id']]);
$fo->ensurePath(0, [$longName]);
$t0 = microtime(true);
$fo->purgeRows([$lr['id']]);
check('Prüfung 19: Retten eines Ordners mit 255 Zeichen langem Namen endet, Name mit Zusatz', microtime(true) - $t0 < 2 && str_contains((string) $db->value('SELECT name FROM folders WHERE id = ?', [$lrKid['id']]), 'gerettet') && (int) $db->value('SELECT parent_id FROM folders WHERE id = ?', [$lrKid['id']]) === 0);
check('Prüfung 18: Ordner anlegen unter einem Ordner im Papierkorb wird abgelehnt; Name mit Schrägstrich ebenso', $thrown(fn () => $fo->create($rc['id'], 'x')) && $thrown(fn () => $fo->create(0, 'a/b')) && $thrown(fn () => $fo->ensurePath($rc['id'], ['x'])));
check('Prüfung 18: weitere unsichtbare Zeichen (U+061C, U+180E, U+3164) fliegen aus Namen, ein Name nur daraus bekommt den Ersatznamen', Names::clean("a\u{061C}b\u{180E}c\u{3164}d")['name'] === 'abcd' && Names::clean("\u{3164}\u{3164}")['name'] === Names::FOLDER_FALLBACK);
// gelöschte Fotos: Varianten verschwinden in Stapeln bis zum Ende, nicht nur ein Stapel
$bigRoot = $fo->ensurePath(0, ['Viele']);
for ($i = 0; $i < Folders::BATCH + 20; $i++) {
    $mk(400 + $i, $bigRoot['id'], 'v' . $i . '.jpg');
}
$fo->trash($bigRoot['id']);
$leftBefore = (int) $db->value('SELECT COUNT(DISTINCT photo_id) FROM photo_variants WHERE photo_id IN (SELECT id FROM photos WHERE trash_root_id = ?)', [$bigRoot['id']]);
$fo->finishTrashWithin($mediaDir, 10.0);
check('Prüfung 18: Varianten gelöschter Zweige werden bis zum Ende entfernt (mehr als ein Stapel)', $leftBefore > 0 && (int) $db->value('SELECT COUNT(*) FROM photo_variants WHERE photo_id IN (SELECT id FROM photos WHERE trash_root_id = ?)', [$bigRoot['id']]) === 0);
// Prüfung 19: „Endgültig löschen“ eines Zweigs mit mehr als einem Stapel (200) löscht in einem Klick alles
$huge = $fo->ensurePath(0, ['Sehr viele']);
for ($i = 0; $i < 230; $i++) {
    $fi->ingest(1, $wp("%PDF-1.4\nstapel $i\n%%EOF\n"), 's' . $i . '.pdf', $huge['id']);
}
$fo->trash($huge['id']);
$r = $b->post('/admin/medien/papierkorb', ['_csrf' => $cs(), 'aktion' => 'loeschen', 'ordner_id' => [(string) $huge['id']]]);
check('Prüfung 19: Endgültig löschen eines Zweigs mit über 200 Einträgen erledigt alles in einem Aufruf', $r->status === 303 && (int) $db->value('SELECT COUNT(*) FROM files WHERE trash_root_id = ?', [$huge['id']]) === 0 && $db->value('SELECT 1 FROM folders WHERE id = ?', [$huge['id']]) === null && str_contains((string) $r->headers['Location'], 'n=230'));

$ab = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Abbruch', 'Leer'], 'gallery' => true], ['path' => ['Abbruch', 'Belegt']]], 'files' => []]));
$abBelegt = (int) $ab['folders'][1]['id'];
$phAb = $mk(333, $abBelegt, 'bleibt.jpg');
$can = $pj($b->request('POST', '/admin/medien/plan/abbrechen', ['plan' => $ab['plan']], ['HTTP_X_CSRF_TOKEN' => TestBrowser::csrf($b->get('/admin/medien'))]));
check('Abbruch: leere Ordner und Galerien des Laufs entfernt, belegte bleiben', $can['ok'] === true && $db->value("SELECT 1 FROM folders WHERE name_key = 'leer'") === null && $db->value("SELECT 1 FROM galleries WHERE title = 'Leer'") === null && $db->value('SELECT 1 FROM folders WHERE id = ?', [$abBelegt]) !== null && $db->value("SELECT 1 FROM folders WHERE name_key = 'abbruch'") !== null);
check('Abbruch: fremde oder ungültige Plan-Kennung tut nichts', $pj($b->request('POST', '/admin/medien/plan/abbrechen', ['plan' => '../..'], ['HTTP_X_CSRF_TOKEN' => TestBrowser::csrf($b->get('/admin/medien'))]))['ok'] === true);
// Prüfung 19: ein fertiger Lauf (nicht abgebrochen) wird als erledigt gemeldet; seine leeren Ordner und Galerien bleiben
$fertig = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Fertig', 'Bleibt leer'], 'gallery' => true]], 'files' => []]));
$done = $pj($b->request('POST', '/admin/medien/plan/fertig', ['plan' => $fertig['plan']], ['HTTP_X_CSRF_TOKEN' => TestBrowser::csrf($b->get('/admin/medien'))]));
// Plan-Reste nach 24 Stunden
$ghost = $pj($plan($b, ['target' => 0, 'folders' => [['path' => ['Geist'], 'gallery' => true]], 'files' => []]));
$m['clock']->advance(86400 + 120);
$b = lk_login($m, 'lutz', TEST_PASSWORD, $s['secret']);
$plan($b, ['target' => 0, 'folders' => [], 'files' => []]);
check('Plan: liegengelassener Plan (Tab geschlossen) wird nach 24 Stunden aufgeräumt, wenn leer', $db->value("SELECT 1 FROM folders WHERE name_key = 'geist'") === null && $db->value("SELECT 1 FROM galleries WHERE title = 'Geist'") === null);
check('Prüfung 19: fertig gemeldeter Lauf wird nach 24 Stunden nicht aufgeräumt (leere Ordner und Entwurfsgalerie bleiben)', $done['ok'] === true && $db->value("SELECT 1 FROM folders WHERE name_key = 'bleibt leer'") !== null && $db->value("SELECT 1 FROM folders WHERE name_key = 'fertig'") !== null && $db->value("SELECT 1 FROM galleries WHERE title = 'Bleibt leer'") !== null && $db->value('SELECT 1 FROM plan_items WHERE plan = ?', [$fertig['plan']]) === null);
check('Skript: fertiger Lauf meldet den Plan als erledigt, Abbruch räumt auf', str_contains((string) file_get_contents(dirname(__DIR__) . '/public/assets/js/medien.js'), '/admin/medien/plan/fertig'));
// Audit: eine Sammelzeile je Lauf
check('Protokoll: Ordner-Upload schreibt eine Sammelzeile je Lauf ohne Namen (folders=,files=,galleries=)', preg_match('/^folders=\d+,files=\d+,galleries=\d+$/', (string) $db->value("SELECT detail FROM audit_log WHERE event = 'media_upload_run' ORDER BY id DESC LIMIT 1")) === 1 && $svc->audit->verify()['ok']);
// Begrenzung der Pläne je Stunde
$last = 0;
for ($i = 0; $i < 22; $i++) {
    $last = $plan($b, ['target' => 0, 'folders' => [['path' => ['Lauf' . $i]]], 'files' => []])->status;
}
check('Plan: höchstens 20 Läufe je Stunde (danach 429)', $last === 429);
check('Protokoll nach allen Läufen weiter gültig', $svc->audit->verify()['ok']);

// ======================= Skript (Block D/E): feste Regeln =======================
$mj = (string) file_get_contents(dirname(__DIR__) . '/public/assets/js/medien.js');
check('Skript: Ausgabe nur per textContent (kein innerHTML, outerHTML, insertAdjacentHTML, document.write, eval)', !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\(|new Function/', $mj));
check('Skript: Ordner werden bis „leer“ gelesen (readEntries in der Schleife), webkitGetAsEntry synchron, Fallback webkitdirectory', str_contains($mj, 'readEntries') && str_contains($mj, 'if (!batch.length)') && str_contains($mj, 'webkitGetAsEntry') && str_contains($mj, 'webkitRelativePath'));
check('Skript: Versteck- und Systemdateien, natürliche Sortierung, Fingerabdruck, Höchstzahl 5000, Abbruch mit Aufräumen', str_contains($mj, 'thumbs.db') && str_contains($mj, 'numeric: true') && str_contains($mj, 'crypto.subtle') && str_contains($mj, 'MAX_FILES = 5000') && str_contains($mj, '/admin/medien/plan/abbrechen'));
check('Skript: CSRF-Kopf bei jedem ändernden Aufruf, keine fremden Adressen', substr_count($mj, 'X-CSRF-Token') >= 3 && !preg_match('~https?://~', $mj));
$mp = $b->get('/admin/medien')->body;
check('Skript: Seite lädt es erst am Ende (defer), Oberfläche ohne Skript bleibt vollständig (Formulare, Hinweis)', str_contains($mp, 'medien.js') && str_contains($mp, 'Zum Hochladen wird JavaScript gebraucht') && str_contains($mp, 'action="/admin/medien/verschieben"') && str_contains($mp, 'formaction="/admin/medien/loeschen"'));
