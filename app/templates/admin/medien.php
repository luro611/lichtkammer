<?php /** @var callable $e @var callable $asset @var string $csrf @var int $folder @var ?array $folderRow @var ?array $linkedGallery @var list<array{id:int,name:string}> $crumbs
 * @var list<array<string,mixed>> $folders @var array{rows:list<array<string,mixed>>,total:int,page:int,pages:int} $contents @var list<array<string,mixed>> $tree
 * @var list<array{id:int,label:string,depth:int}> $options @var string $sort @var bool $desc @var string $view @var string $q @var ?array $edit @var int $trashCount
 * @var int $pending @var array{photos:int,files:int} $usage @var array<int,string> $folderNames @var ?string $engine @var int $maxMb @var int $maxFileMb @var \Lichtkammer\Media\Files $files @var bool $isAdmin @var bool $legacyOpen @var ?array<string,mixed> $detail */
$by = [];
foreach ($tree as $t) {
    $by[(int) $t['parent_id']][] = $t;
}
$open = array_flip(array_column($crumbs, 'id'));
$url = static function (array $extra = []) use ($folder, $view, $q): string {
    $qs = ['ordner' => $folder > 0 ? $folder : null, 'ansicht' => $view === 'symbole' ? 'symbole' : null, 'q' => $q !== '' ? $q : null];
    foreach ($extra as $k => $v) {
        $qs[$k] = $v;
    }
    $qs = array_filter($qs, static fn ($v): bool => $v !== null && $v !== '');
    return '/admin/medien' . ($qs !== [] ? '?' . http_build_query($qs) : '');
};
$size = static function (int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : ($b >= 1024 ? number_format($b / 1024, 0, ',', '.') . ' KB' : $b . ' B');
};
$kindLabel = ['photo' => 'Foto', 'pdf' => 'PDF', 'svg' => 'SVG-Grafik', 'image' => 'Bild (Datei)'];
$sortLink = static function (string $key, string $label) use ($url, $sort, $desc): string {
    $on = $sort === $key;
    $href = $url(['sort' => $key, 'dir' => ($on && !$desc) ? 'ab' : null]);
    return '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '"' . ($on ? ' aria-sort="' . ($desc ? 'descending' : 'ascending') . '"' : '') . '>' . htmlspecialchars($label, ENT_QUOTES) . ($on ? ($desc ? ' ↓' : ' ↑') : '') . '</a>';
};
$folderIcon = '<svg class="ico" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
$renderTree = static function (int $parent, int $depth) use (&$renderTree, $by, $folder, $open, $e): string {
    $out = '';
    foreach ($by[$parent] ?? [] as $t) {
        $id = (int) $t['id'];
        $kids = isset($by[$id]);
        $isOpen = isset($open[$id]);
        $out .= '<li role="none"><a role="treeitem" class="titem' . ($id === $folder ? ' current' : '') . '" href="/admin/medien?ordner=' . $id . '" data-drop-folder="' . $id . '"'
            . ($kids ? ' aria-expanded="' . ($isOpen ? 'true' : 'false') . '"' : '') . ($id === $folder ? ' aria-current="page"' : '') . '>' . $e((string) $t['name']) . '</a>';
        if ($kids && $isOpen && $depth < 8) {
            $out .= '<ul role="group">' . $renderTree($id, $depth + 1) . '</ul>';
        }
        $out .= '</li>';
    }
    return $out;
};
$total = $usage['photos'] + $usage['files'];
?>
<section class="hero compact">
  <p class="eyebrow">Medien</p>
  <h1>Medien <em>verwalten</em></h1>
  <p class="intro">Ordner wie im Finder: Fotos, PDFs und Logos an einem Ort. Ordner sind nur Ablage; eine Galerie entsteht erst, wenn du es willst.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($pending > 0): ?>
  <div class="notice" role="status" id="pendingbar" data-csrf="<?= $e($csrf) ?>"><?= (int) $pending ?> Foto(s) werden noch aufbereitet (Vorschaubilder).
    <form class="inline" method="post" action="/admin/medien/aufbereiten"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>"><button class="btn small" type="submit">Jetzt aufbereiten</button></form></div>
<?php endif; ?>
</section>

<div class="medien">
  <nav class="mtree" aria-label="Ordner">
    <ul role="tree" aria-label="Ordnerbaum">
      <li role="none"><a role="treeitem" class="titem root<?= $folder === 0 ? ' current' : '' ?>" href="/admin/medien" data-drop-folder="0"<?= $folder === 0 ? ' aria-current="page"' : '' ?>>Medien</a>
        <ul role="group"><?= $renderTree(0, 1) ?></ul></li>
    </ul>
    <p class="small"><a href="/admin/medien/papierkorb">Papierkorb<?= $trashCount > 0 ? ' (' . (int) $trashCount . ')' : '' ?></a></p>
    <p class="small muted mono"><?= $e($size($total)) ?> belegt</p>
  </nav>

  <div class="mmain">
    <nav class="crumbs" aria-label="Pfad"><a href="/admin/medien">Medien</a><?php foreach ($crumbs as $c): ?> <span aria-hidden="true">/</span> <?php if ((int) $c['id'] === $folder): ?><strong><?= $e($c['name']) ?></strong><?php else: ?><a href="/admin/medien?ordner=<?= (int) $c['id'] ?>"><?= $e($c['name']) ?></a><?php endif; ?><?php endforeach; ?></nav>

    <div class="mtools">
      <form class="inline" method="post" action="/admin/medien/ordner">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>">
        <label class="visually-hidden" for="newfolder">Name des neuen Ordners</label>
        <input id="newfolder" name="name" type="text" maxlength="255" placeholder="Neuer Ordner" autocomplete="off" required>
        <button class="btn small ghost" type="submit">Anlegen</button>
      </form>
      <form class="inline" method="get" action="/admin/medien" role="search">
<?php if ($folder > 0): ?><input type="hidden" name="ordner" value="<?= (int) $folder ?>"><?php endif; ?>
<?php if ($view === 'symbole'): ?><input type="hidden" name="ansicht" value="symbole"><?php endif; ?>
        <label class="visually-hidden" for="mq">Suche in allen Medien</label>
        <input id="mq" type="search" name="q" value="<?= $e($q) ?>" placeholder="Suche in allen Medien">
        <button class="btn small ghost" type="submit">Suchen</button>
      </form>
      <span class="viewtoggle" role="group" aria-label="Ansicht">
        <a class="btn small <?= $view === 'liste' ? '' : 'ghost' ?>" href="<?= $e($url(['ansicht' => null])) ?>"<?= $view === 'liste' ? ' aria-current="true"' : '' ?>>Liste</a>
        <a class="btn small <?= $view === 'symbole' ? '' : 'ghost' ?>" href="<?= $e($url(['ansicht' => 'symbole'])) ?>"<?= $view === 'symbole' ? ' aria-current="true"' : '' ?>>Symbole</a>
      </span>
    </div>

    <div id="mupload" class="card dropzone mupload" hidden data-csrf="<?= $e($csrf) ?>" data-folder="<?= (int) $folder ?>" data-max-mb="<?= (int) $maxMb ?>" data-max-file-mb="<?= (int) $maxFileMb ?>" data-photos="<?= $engine === null ? '0' : '1' ?>">
      <p><strong>Dateien oder ganze Ordner hier ablegen</strong></p>
      <p>
        <label class="btn small" for="mfiles">Dateien auswählen</label><input id="mfiles" class="visually-hidden" type="file" multiple>
        <label class="btn small ghost" for="mdir">Ordner hochladen</label><input id="mdir" class="visually-hidden" type="file" webkitdirectory multiple>
      </p>
      <p class="muted mono small">Fotos (JPEG, PNG, WebP, HEIC) bis <?= (int) $maxMb ?> MB · PDF bis <?= (int) $maxFileMb ?> MB · SVG bis 1 MB · Logos und Grafiken als PNG, JPEG, WebP bis 5 MB</p>
    </div>
    <ul id="mqueue" class="queue" aria-live="polite"></ul>
    <p class="small muted" id="noscriptnote">Zum Hochladen wird JavaScript gebraucht. Ordnen, Umbenennen, Verschieben und Löschen funktionieren auch ohne.</p>

<?php if ($folderRow !== null): ?>
<?php if ($linkedGallery !== null): ?>
    <p class="small muted">Galerie zu diesem Ordner: <a href="/admin/galerien/<?= (int) $linkedGallery['id'] ?>"><?= $e((string) $linkedGallery['title']) ?></a> (<?= $linkedGallery['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?>). Neue Fotos, die du hier hochlädst, kommen bei einer Entwurfs-Galerie automatisch dazu.</p>
<?php endif; ?>
    <form class="inline small" method="post" action="/admin/medien/galerie">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>">
      <label class="visually-hidden" for="gtitel">Titel der Galerie</label>
      <input id="gtitel" type="text" name="titel" value="<?= $e((string) $folderRow['name']) ?>" maxlength="200">
      <select name="modus" aria-label="Welche Fotos"><option value="direkt">nur Fotos direkt in diesem Ordner</option><option value="alle">auch aus allen Unterordnern</option></select>
      <button class="btn small ghost" type="submit">Aus diesem Ordner Galerie machen</button>
    </form>
<?php endif; ?>

<?php if ($q !== ''): ?><p class="muted"><?= (int) $contents['total'] ?> Treffer für „<?= $e($q) ?>“ in allen Medien · <a href="<?= $e($url(['q' => null])) ?>">Suche beenden</a></p><?php endif; ?>

<?php if ($folders === [] && $contents['rows'] === []): ?>
    <p class="muted empty"><?= $q !== '' ? 'Nichts gefunden.' : 'Dieser Ordner ist leer. Lege Dateien oder Ordner hier ab.' ?></p>
<?php else: ?>
    <form id="msel" method="post" action="/admin/medien/verschieben" class="msel">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>">
      <div class="bulkbar">
        <label class="small"><input type="checkbox" id="mall"> alle</label>
        <label class="visually-hidden" for="mziel">Zielordner</label>
        <select id="mziel" name="ziel"><option value="0">Medien (oberste Ebene)</option>
<?php foreach ($options as $o): ?>
          <option value="<?= (int) $o['id'] ?>"><?= $e(str_repeat('– ', $o['depth'] - 1) . $o['label']) ?></option>
<?php endforeach; ?>
        </select>
        <button class="btn small" type="submit">Verschieben nach …</button>
        <button class="btn small ghost" type="submit" formaction="/admin/medien/loeschen" data-confirm="Die Auswahl in den Papierkorb legen? Ordner nehmen alles darin mit. Wiederherstellen ist 30 Tage lang möglich.">In den Papierkorb</button>
        <span class="small muted" id="mcount" aria-live="polite"></span>
      </div>

<?php if ($view === 'symbole'): ?>
      <ul class="mgrid" role="list">
<?php foreach ($folders as $fo): ?>
        <li class="mcell folder" data-kind="folder" data-id="<?= (int) $fo['id'] ?>" data-drop-folder="<?= (int) $fo['id'] ?>">
          <label class="pick"><input type="checkbox" name="ordner_id[]" value="<?= (int) $fo['id'] ?>" aria-label="Ordner <?= $e((string) $fo['name']) ?> auswählen"></label>
          <a class="tilelink" href="/admin/medien?ordner=<?= (int) $fo['id'] ?>"><span class="bigicon"><?= $folderIcon ?></span><span class="wrap"><?= $e((string) $fo['name']) ?></span></a>
          <span class="small muted mono"><?= (int) $fo['photos'] + (int) $fo['files'] ?> Dateien<?= (int) $fo['folders'] > 0 ? ', ' . (int) $fo['folders'] . ' Ordner' : '' ?></span>
        </li>
<?php endforeach; ?>
<?php foreach ($contents['rows'] as $r): $isPhoto = $r['type'] === 'photo'; ?>
        <li class="mcell" data-kind="<?= $isPhoto ? 'photo' : 'file' ?>" data-id="<?= (int) $r['id'] ?>">
          <label class="pick"><input type="checkbox" name="<?= $isPhoto ? 'foto' : 'datei' ?>[]" value="<?= (int) $r['id'] ?>" aria-label="<?= $e((string) $r['name']) ?> auswählen"></label>
<?php if ($isPhoto): ?>
          <a class="tilelink" href="/admin/fotos/<?= (int) $r['id'] ?>"><?php if ($r['thumb']): ?><img src="/media/<?= $e((string) $r['thumb']) ?>" alt="" loading="lazy" width="240"><?php else: ?><span class="ph"><?= $r['status'] === 'failed' ? 'Fehler' : 'wird aufbereitet' ?></span><?php endif; ?></a>
<?php else: ?>
          <a class="tilelink" href="/admin/medien/datei/<?= (int) $r['id'] ?>?ansicht=1" target="_blank" rel="noopener"><?php if ($r['kind'] === 'pdf'): ?><span class="ph kind">PDF</span><?php else: ?><img src="/admin/medien/datei/<?= (int) $r['id'] ?>" alt="" loading="lazy" width="240"><?php endif; ?></a>
<?php endif; ?>
          <span class="mono small wrap"><?= $e((string) $r['name']) ?></span>
          <a class="small mdlink" href="<?= $e($url(['detail' => ($isPhoto ? 'f' : 'd') . (int) $r['id']])) ?>#mdetail" data-detail="<?= $isPhoto ? 'foto' : 'datei' ?>:<?= (int) $r['id'] ?>" aria-label="Details zu <?= $e((string) $r['name']) ?>">Details</a>
        </li>
<?php endforeach; ?>
      </ul>
<?php else: ?>
      <div class="tablewrap"><table class="mlist">
        <thead><tr><th class="c"><span class="visually-hidden">Auswahl</span></th><th><?= $sortLink('name', 'Name') ?></th><th><?= $sortLink('art', 'Art') ?></th><th><?= $sortLink('groesse', 'Größe') ?></th><th><?= $sortLink('datum', 'Datum') ?></th><th>Details</th></tr></thead>
        <tbody>
<?php foreach ($folders as $fo): $editing = $edit !== null && $edit[0] === 'o' && $edit[1] === (int) $fo['id']; ?>
          <tr class="mrow folder" data-kind="folder" data-id="<?= (int) $fo['id'] ?>" data-drop-folder="<?= (int) $fo['id'] ?>">
            <td class="c"><input type="checkbox" name="ordner_id[]" value="<?= (int) $fo['id'] ?>" aria-label="Ordner <?= $e((string) $fo['name']) ?> auswählen"></td>
            <td class="wrap"><?php if ($editing): ?>
              <input type="text" name="name" form="mrename" value="<?= $e((string) $fo['name']) ?>" maxlength="255" aria-label="Neuer Name" autofocus> <button class="btn small" type="submit" form="mrename">Speichern</button> <a class="small" href="<?= $e($url()) ?>">Abbrechen</a>
              <?php else: ?><a class="mname" href="/admin/medien?ordner=<?= (int) $fo['id'] ?>"><?= $folderIcon ?> <?= $e((string) $fo['name']) ?></a> <a class="small muted" href="<?= $e($url(['bearbeiten' => 'o' . (int) $fo['id']])) ?>" aria-label="Ordner <?= $e((string) $fo['name']) ?> umbenennen">Umbenennen</a><?php endif; ?></td>
            <td>Ordner</td><td class="mono small"><?= (int) $fo['photos'] + (int) $fo['files'] ?> Dateien</td><td class="mono small"><?= $e(gmdate('Y-m-d', (int) $fo['created_at'])) ?></td>
            <td class="small muted"><?= (int) $fo['folders'] > 0 ? (int) $fo['folders'] . ' Unterordner' : '' ?><?= $fo['gallery_id'] !== null ? ' · Galerie verknüpft' : '' ?></td>
          </tr>
<?php endforeach; ?>
<?php foreach ($contents['rows'] as $r): $isPhoto = $r['type'] === 'photo'; $editing = !$isPhoto && $edit !== null && $edit[0] === 'd' && $edit[1] === (int) $r['id'];
    $fr = !$isPhoto ? $files->get((int) $r['id']) : null; ?>
          <tr class="mrow" data-kind="<?= $isPhoto ? 'photo' : 'file' ?>" data-id="<?= (int) $r['id'] ?>">
            <td class="c"><input type="checkbox" name="<?= $isPhoto ? 'foto' : 'datei' ?>[]" value="<?= (int) $r['id'] ?>" aria-label="<?= $e((string) $r['name']) ?> auswählen"></td>
            <td class="wrap"><?php if ($editing): ?>
              <input type="text" name="name" form="mrename" value="<?= $e((string) $r['name']) ?>" maxlength="255" aria-label="Neuer Name" autofocus> <button class="btn small" type="submit" form="mrename">Speichern</button> <a class="small" href="<?= $e($url()) ?>">Abbrechen</a>
              <?php elseif ($isPhoto): ?>
              <?php if ($r['thumb']): ?><img class="mthumb" src="/media/<?= $e((string) $r['thumb']) ?>" alt="" loading="lazy" width="40" height="40"><?php endif; ?><a class="mname" href="/admin/fotos/<?= (int) $r['id'] ?>"><?= $e((string) $r['name']) ?></a>
              <?php else: ?><a class="mname" href="/admin/medien/datei/<?= (int) $r['id'] ?>?ansicht=1" target="_blank" rel="noopener"><?= $e((string) $r['name']) ?></a> <a class="small muted" href="<?= $e($url(['bearbeiten' => 'd' . (int) $r['id']])) ?>" aria-label="<?= $e((string) $r['name']) ?> umbenennen">Umbenennen</a><?php endif; ?>
<?php if ($q !== ''): ?><br><span class="small muted">in <?= $e($folderNames[(int) $r['folder_id']] ?? 'Medien') ?></span><?php endif; ?></td>
            <td><?= $e($kindLabel[(string) $r['kind']] ?? 'Datei') ?></td><td class="mono small"><?= $e($size((int) $r['bytes'])) ?></td><td class="mono small"><?= $e(gmdate('Y-m-d', (int) $r['at'])) ?></td>
            <td class="small">
<?php if ($isPhoto): ?><a class="mdlink" href="<?= $e($url(['detail' => 'f' . (int) $r['id']])) ?>#mdetail" data-detail="foto:<?= (int) $r['id'] ?>" aria-label="Details zu <?= $e((string) $r['name']) ?>">Details</a>
<?php elseif ($fr !== null): ?>
              <?= (int) $fr['public'] === 1 ? 'öffentlich: <a class="mono" href="' . $e($files->publicUrl($fr)) . '" target="_blank" rel="noopener">' . $e($files->publicUrl($fr)) . '</a>' : '<span class="muted">privat</span>' ?>
              <?php if ($fr['legacy_public_path'] === ''): ?><button class="btn small ghost" type="submit" form="mpublic-<?= (int) $r['id'] ?>"><?= (int) $fr['public'] === 1 ? 'Wieder privat' : 'Öffentlich schalten' ?></button><?php endif; ?>
              <a href="/admin/medien/datei/<?= (int) $r['id'] ?>">Herunterladen</a>
              <a class="mdlink" href="<?= $e($url(['detail' => 'd' . (int) $r['id']])) ?>#mdetail" data-detail="datei:<?= (int) $r['id'] ?>" aria-label="Details zu <?= $e((string) $r['name']) ?>">Details</a>
              <?php if (($fileUse[(int) $r['id']] ?? 0) > 0): ?><span class="small muted">· in <?= (int) $fileUse[(int) $r['id']] ?> <?= $fileUse[(int) $r['id']] === 1 ? 'Seite' : 'Seiten' ?></span><?php endif; ?>
<?php endif; ?>
            </td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
<?php endif; ?>
    </form>
<?php if ($detail !== null): $d = $detail; ?>
    <section class="mdetail" id="mdetail" aria-labelledby="mdt" tabindex="-1">
      <h2 id="mdt"><?= $e((string) $d['name']) ?></h2>
<?php if ($d['preview'] === 'image' && $d['previewUrl'] !== ''): ?>      <p class="mdprev"><img src="<?= $e((string) $d['previewUrl']) ?>" alt="Vorschau: <?= $e((string) $d['name']) ?>" width="480"></p>
<?php elseif ($d['preview'] === 'pdf'): ?>      <p class="mdprev"><span class="ph kind">PDF</span> <a href="<?= $e((string) $d['openUrl']) ?>" target="_blank" rel="noopener">Öffnen</a></p>
<?php endif; ?>
      <dl class="mdl">
        <dt>Art</dt><dd><?= $e((string) $d['type']) ?> <span class="muted mono small"><?= $e((string) $d['mime']) ?></span></dd>
        <dt>Größe</dt><dd><?= $e((string) $d['size']) ?><?= $d['dims'] !== '' ? ' · ' . $e((string) $d['dims']) : '' ?></dd>
        <dt>Datum</dt><dd><?= $e((string) $d['date']) ?></dd>
<?php if ($d['by'] !== ''): ?>        <dt>Hochgeladen von</dt><dd><?= $e((string) $d['by']) ?></dd>
<?php endif; ?>
        <dt>Ort</dt><dd><a href="/admin/medien<?= (int) $d['folder'] > 0 ? '?ordner=' . (int) $d['folder'] : '' ?>"><?= $e((string) $d['place']) ?></a></dd>
<?php if ($d['art'] === 'datei'): ?>
        <dt>Sichtbarkeit</dt><dd><?= $d['public'] ? 'öffentlich' : 'privat' ?><?= $d['live'] ? ' · steht auf der Live-Seite' : '' ?>
<?php if (!$d['legacy']): ?>          <form class="inline" method="post" action="/admin/medien/datei/<?= (int) $d['id'] ?>/oeffentlich"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>"><input type="hidden" name="an" value="<?= $d['public'] ? '0' : '1' ?>"><button class="btn small ghost" type="submit"><?= $d['public'] ? 'Wieder privat' : 'Öffentlich schalten' ?></button></form>
<?php endif; ?></dd>
<?php if ($d['public']): ?>        <dt>Adresse</dt><dd><input class="mdaddr" type="text" readonly value="<?= $e((string) $d['address']) ?>" aria-label="Adresse der Datei zum Markieren und Kopieren"></dd>
<?php endif; ?>
<?php endif; ?>
        <dt>Verwendet in</dt><dd>
<?php if ($d['uses'] === []): ?>          <span class="muted">nirgends</span>
<?php else: ?>          <ul class="mduses"><?php foreach ($d['uses'] as $u): ?><li><a href="<?= $e((string) $u['link']) ?>"><?= $e((string) $u['label']) ?></a><?= $u['pos'] !== '' ? ', ' . $e((string) $u['pos']) : '' ?><?= !$u['linked'] ? ' <span class="muted">(Adresse, nicht verknüpft)</span>' : '' ?></li><?php endforeach; ?></ul>
<?php endif; ?></dd>
      </dl>
      <p class="mdactions"><?php if ($d['art'] === 'datei'): ?><a class="btn small ghost" href="<?= $e((string) $d['openUrl']) ?>" target="_blank" rel="noopener">Öffnen</a> <a class="btn small ghost" href="<?= $e((string) $d['downloadUrl']) ?>">Herunterladen</a><?php else: ?><a class="btn small ghost" href="<?= $e((string) $d['editUrl']) ?>">Foto bearbeiten</a><?php endif; ?> <a class="btn small ghost" href="<?= $e($url(['detail' => null])) ?>">Schließen</a></p>
    </section>
<?php endif; ?>
<?php if ($edit !== null): ?>
    <form id="mrename" method="post" action="/admin/medien/umbenennen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>"><input type="hidden" name="art" value="<?= $edit[0] === 'o' ? 'ordner' : 'datei' ?>"><input type="hidden" name="id" value="<?= (int) $edit[1] ?>"></form>
<?php endif; ?>
<?php foreach ($contents['rows'] as $r): if ($r['type'] !== 'file') { continue; } $fr = $files->get((int) $r['id']); if ($fr === null || $fr['legacy_public_path'] !== '') { continue; } ?>
    <form id="mpublic-<?= (int) $r['id'] ?>" method="post" action="/admin/medien/datei/<?= (int) $r['id'] ?>/oeffentlich"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ordner" value="<?= (int) $folder ?>"><input type="hidden" name="an" value="<?= (int) $fr['public'] === 1 ? '0' : '1' ?>"></form>
<?php endforeach; ?>
<?php if ($contents['pages'] > 1): ?>
    <nav class="pager" aria-label="Seiten">
<?php if ($contents['page'] > 1): ?><a class="btn small ghost" href="<?= $e($url(['seite' => $contents['page'] - 1, 'sort' => $sort, 'dir' => $desc ? 'ab' : null])) ?>">Zurück</a><?php endif; ?>
      <span class="mono small">Seite <?= (int) $contents['page'] ?> von <?= (int) $contents['pages'] ?></span>
<?php if ($contents['page'] < $contents['pages']): ?><a class="btn small ghost" href="<?= $e($url(['seite' => $contents['page'] + 1, 'sort' => $sort, 'dir' => $desc ? 'ab' : null])) ?>">Weiter</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php endif; ?>

<?php if ($isAdmin && $legacyOpen): ?>
    <form class="inline small" method="post" action="/admin/medien/bestand">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <span class="muted">Im alten Ordner „dokumente“ liegen PDFs aus dem WordPress-Import.</span>
      <button class="btn small ghost" type="submit" data-confirm="Die PDFs aus media/dokumente als öffentliche Dateien in den Ordner „Dokumente“ eintragen? Sie werden nicht verschoben, ihre Adressen bleiben.">Bestand übernehmen</button>
    </form>
<?php endif; ?>
  </div>
</div>
<dialog id="msummary" class="msummary" aria-labelledby="msumtitle"></dialog>
<script src="<?= $e($asset('/assets/js/medien.js')) ?>" defer></script>
