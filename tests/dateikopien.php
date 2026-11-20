<?php
declare(strict_types=1);

// Entwurf 44, Stufe 1b: fehlende öffentliche Dateikopien nachziehen (Files::repairPublicCopies). Ändert nur Dateien, nie Zeilen.

use Lichtkammer\Admin\SystemCheck;
use Lichtkammer\Media\Files;

require_once __DIR__ . '/support.php';

$fk = lk_new_tmp($tmp, 'dateikopien');
$m = lk_make($fk);
$s = lk_setup($m);
$b = $s['b'];
$svc = $m['app']->services();
$db = $svc->db;
$mediaDir = $svc->config->mediaDir();
$pdf = static fn (string $tag): string => "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n% $tag\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$put = static function (string $bytes, string $name) use ($svc, $fk): int {
    $p = $fk . '/' . bin2hex(random_bytes(4)) . '.bin';
    file_put_contents($p, $bytes);
    return $svc->files->ingest(1, $p, $name, 0)['file_id'];
};
$row = static fn (int $id): array => (array) $db->one('SELECT * FROM files WHERE id = ?', [$id]);
$tmpLeft = static fn (): array => array_merge(glob($mediaDir . '/dateien/.tmp*') ?: [], glob($mediaDir . '/dokumente/.tmp*') ?: []);

$a = $put($pdf('a'), 'Anna.pdf');
$c = $put($pdf('c'), 'Carl.pdf');
$svc->files->setPublic($a, true);
$svc->files->setPublic($c, true);
$ra = $row($a);
$copyA = $mediaDir . '/dateien/' . $ra['public_name'];

// Normalfall: Kopie fehlt (wie nach einer Wiederherstellung auf neuem Server)
unlink($copyA);
check('Kopien: Status zählt die fehlende Kopie (nur Lesen, nichts ändert sich)', $svc->files->publicCopyStatus() === ['total' => 2, 'missing' => 1, 'no_original' => 0, 'bad_name' => 0] && !is_file($copyA));
$before = $row($a);
$res = $svc->files->repairPublicCopies();
check('Kopien: Reparatur legt die Kopie wieder an (gleicher Inhalt, Rechte 0644, keine Temporärdatei übrig)', $res['repaired'] === 1 && is_file($copyA) && sha1_file($copyA) === sha1($pdf('a')) && (fileperms($copyA) & 0777) === 0644 && $tmpLeft() === []);
check('Kopien: die Zeile in der Datenbank bleibt vollständig unverändert', $row($a) === $before);
check('Kopien: zweiter Lauf tut nichts', $svc->files->repairPublicCopies()['repaired'] === 0);

// Ordner samt .htaccess weg
$dirName = $mediaDir . '/dateien';
foreach (array_diff(scandir($dirName) ?: [], ['.', '..']) as $leftover) {
    unlink($dirName . '/' . $leftover);
}
rmdir($dirName);
$res = $svc->files->repairPublicCopies();
check('Kopien: fehlt der ganze Ordner, wird er samt .htaccess (kein PHP, Schutzköpfe) neu angelegt, beide Dateien sind wieder da', $res['repaired'] === 2 && is_file($copyA) && is_file($mediaDir . '/dateien/' . $row($c)['public_name'])
    && (string) file_get_contents($dirName . '/.htaccess') === Files::HTACCESS);

// Fehlendes Original: nur zählen, Zeile bleibt
unlink($copyA);
$orig = $svc->files->absolute($ra);
rename((string) $orig, $orig . '.weg');
$before = $row($a);
$res = $svc->files->repairPublicCopies();
check('Kopien: fehlt auch das private Original, bleibt „public“ unverändert (Prüfung 45, B1), nur gezählt, nichts geschrieben', $res['no_original'] === 1 && $res['repaired'] === 0 && $row($a) === $before && !is_file($copyA));
check('Kopien: Status nennt es', $svc->files->publicCopyStatus()['no_original'] === 1);
$items = SystemCheck::run($svc->config, $db, $svc->clock, $svc->dataDir, $svc->files);
$line = array_values(array_filter($items, static fn (array $i): bool => $i['title'] === 'Öffentliche Dateikopien'))[0] ?? null;
check('Kopien: Systemprüfung zeigt die Zeile „Öffentliche Dateikopien“ mit Warnung und Hinweis auf das fehlende Original', $line !== null && $line['level'] === 'warn' && str_contains($line['text'], 'fehlt auch das private Original'));
rename($orig . '.weg', (string) $orig);
$page = $b->get('/admin/system');
check('Kopien: /admin/system zeigt die Zeile', $page->status === 200 && str_contains($page->body, 'Öffentliche Dateikopien'));
// Nachziehen beim Öffnen von Medien (Housekeeping)
$b->get('/admin/medien');
check('Kopien: Öffnen von Medien zieht fehlende Kopien nach', is_file($copyA) && $svc->files->publicCopyStatus()['missing'] === 0);
$line = array_values(array_filter(SystemCheck::run($svc->config, $db, $svc->clock, $svc->dataDir, $svc->files), static fn (array $i): bool => $i['title'] === 'Öffentliche Dateikopien'))[0];
check('Kopien: danach steht die Zeile auf ok', $line['level'] === 'ok');

// Namen aus der Datenbank: nur nach Muster
foreach (['../../x.pdf' => '../x.pdf', "ab12cd34-x.pdf\n" => null, 'sub/ab12cd34-x.pdf' => null, 'AB12CD34-X.PDF' => null] as $bad => $_) {
    $db->run('UPDATE files SET public_name = ? WHERE id = ?', [$bad, $c]);
    $res = $svc->files->repairPublicCopies();
    $stray = glob($mediaDir . '/*') ?: [];
    check('Kopien: ungültiger Name „' . addcslashes($bad, "\n") . '“ wird nicht angefasst (nur gezählt), nichts außerhalb von media/dateien', $res['bad_name'] === 1 && $res['repaired'] === 0 && $svc->files->publicCopyStatus()['bad_name'] === 1 && !is_file($mediaDir . '/x.pdf') && !is_file(dirname($mediaDir) . '/x.pdf') && !is_file($mediaDir . '/dateien/' . $bad) && $stray !== null);
}
$db->run('UPDATE files SET public_name = ? WHERE id = ?', [$row($a)['public_name'], $c]); // gültiger, aber anderer Name
$db->run("UPDATE files SET public_name = ? WHERE id = ?", [substr((string) $row($c)['token'], 0, 8) . '-carl.pdf', $c]);
@unlink($mediaDir . '/dateien/' . $row($c)['public_name']);
$db->run('UPDATE files SET deleted_at = 5 WHERE id = ?', [$c]);
$resT = $svc->files->repairPublicCopies();
check('Kopien: Papierkorb und privat werden nicht repariert', $resT['repaired'] === 0 && !is_file($mediaDir . '/dateien/' . $row($c)['public_name']));
$db->run('UPDATE files SET deleted_at = NULL WHERE id = ?', [$c]);

// Altdatei (media/dokumente)
$l = $put($pdf('alt'), 'alt.pdf');
$db->run("UPDATE files SET public = 1, public_name = 'alt.pdf', legacy_public_path = 'dokumente/alt.pdf' WHERE id = ?", [$l]);
check('Kopien: Altdatei ohne Kopie wird nach media/dokumente/ ergänzt', $svc->files->repairPublicCopies(100, [$l])['repaired'] === 1 && sha1_file($mediaDir . '/dokumente/alt.pdf') === sha1($pdf('alt')));
$db->run("UPDATE files SET legacy_public_path = 'dokumente/../dateien/evil.pdf' WHERE id = ?", [$l]);
$res = $svc->files->repairPublicCopies(100, [$l]);
check('Kopien: ungültiger Altpfad wird nie beschrieben', $res['bad_name'] === 1 && !is_file($mediaDir . '/dateien/evil.pdf'));
$db->run("UPDATE files SET public = 0, legacy_public_path = '', public_name = '' WHERE id = ?", [$l]);

// SVG nur mit Schutzkopf
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 2 2"><rect width="1" height="1"/></svg>';
$sv = $put($svg, 'logo.svg');
$fiOk = new Files($db, $svc->clock, $svc->dataDir, $mediaDir, 50 * 1048576, '', static fn (): bool => true);
$fiNo = new Files($db, $svc->clock, $svc->dataDir, $mediaDir, 50 * 1048576, '', static fn (): bool => false);
$fiOk->setPublic($sv, true);
$svgCopy = $mediaDir . '/dateien/' . $row($sv)['public_name'];
unlink($svgCopy);
$db->run("DELETE FROM meta WHERE k = 'svg_probe'");
$res = $fiNo->repairPublicCopies(100, [$sv]);
check('Kopien: SVG ohne bestätigten Schutzkopf wird nicht repariert (nur gezählt), Zeile bleibt öffentlich', $res['svg_skipped'] === 1 && $res['repaired'] === 0 && !is_file($svgCopy) && (int) $row($sv)['public'] === 1);
$db->run("DELETE FROM meta WHERE k = 'svg_probe'");
check('Kopien: SVG mit bestätigtem Schutzkopf wird repariert', $fiOk->repairPublicCopies(100, [$sv])['repaired'] === 1 && is_file($svgCopy));

$db->run('UPDATE files SET deleted_at = NULL WHERE id = ?', [$c]);
$fiOk->repairPublicCopies();
// Grenzen
unlink($svgCopy);
unlink($copyA);
$db->run("DELETE FROM meta WHERE k = 'svg_probe'");
$res = $fiOk->repairPublicCopies(1);
check('Kopien: das Limit begrenzt die Zahl der Reparaturen je Lauf', $res['repaired'] === 1 && $fiOk->publicCopyStatus()['missing'] === 1);
check('Kopien: onlyIds beschränkt auf die genannten Dateien (leere Liste: nichts)', $fiOk->repairPublicCopies(100, [])['repaired'] === 0 && $fiOk->publicCopyStatus()['missing'] === 1);
check('Kopien: der Rest folgt im nächsten Lauf, keine Temporärdateien', $fiOk->repairPublicCopies()['repaired'] === 1 && $fiOk->publicCopyStatus()['missing'] === 0 && $tmpLeft() === []);

// Veröffentlichen zieht selbst nach
$pid = $svc->pages->save(null, ['title' => 'Liste', 'body' => '', 'status' => 'published', 'nav' => 'main', 'slug' => 'liste', 'blocks' => json_encode(['v' => 1, 'blocks' => [['t' => 'download', 'file' => $a]]])]);
unlink($copyA);
check('Kopien: Probelauf meldet die fehlende Kopie als Fehler (Seite „Liste“, Block 1)', str_contains(implode(' ', $svc->builder->plan()['errors']), 'Seite „Liste“, Block 1: Datei „Anna.pdf“ fehlt auf dem Server'));
$page = $b->get('/admin/veroeffentlichen');
check('Kopien: die Seite „Veröffentlichen“ zieht die Kopie vorher nach, kein Fehler mehr', $page->status === 200 && is_file($copyA) && $svc->builder->plan()['errors'] === []);
unlink($copyA);
$pub = $svc->builder->publish(1);
check('Kopien: Veröffentlichen zieht die Kopie selbst nach und klappt', $pub['ok'] === true && is_file($copyA));

// Fable 48: Endung der Kopie passt nicht zur Art des Originals (handveränderte Zeile): nie schreiben
$db->run("UPDATE files SET public_name = 'aaaaaaaa-falsch.png', public = 1 WHERE id = ?", [$c]);
$res = $fiOk->repairPublicCopies(100, [$c]);
check('Kopien: Endung der Kopie passt nicht zum Original (png statt pdf): nichts geschrieben, als „bad_name“ gezählt', $res['bad_name'] === 1 && $res['repaired'] === 0 && !is_file($mediaDir . '/dateien/aaaaaaaa-falsch.png'));
