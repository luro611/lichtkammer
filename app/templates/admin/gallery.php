<?php /** @var callable $e @var callable $asset @var array<string,mixed> $g @var list<array<string,mixed>> $photos @var string $csrf @var ?array<string,string> $draft @var list<array<string,mixed>> $blockers @var ?string $engine @var list<string> $formats @var int $maxMb */
$gid = (int) $g['id'];
$title = $draft['title'] ?? (string) $g['title'];
$desc = $draft['description'] ?? (string) $g['description'];
$pub = $g['status'] === 'published';
$bulkBack = '/admin/galerien/' . $gid; ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/galerien">Galerien</a> · <?= $pub ? 'online sichtbar' : 'Entwurf (nicht online)' ?></p>
  <h1><?= $e($title) ?></h1>
  <p><a class="btn small ghost" href="/admin/vorschau/galerien/<?= $e((string) $g['slug']) ?>">Vorschau der Galerie</a></p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($blockers !== []): ?>
  <div class="notice error" role="alert">
    <strong>Das hindert das Veröffentlichen:</strong>
    <ul>
<?php foreach ($blockers as $b): ?>
      <li><a href="/admin/fotos/<?= (int) $b['id'] ?>"><?= $e((string) ($b['title'] !== '' ? $b['title'] : $b['original_name'])) ?></a> –
        <?= $b['status'] !== 'ready' ? ($b['status'] === 'failed' ? 'Verarbeitung fehlgeschlagen' : 'wird noch verarbeitet') : 'Alt-Text fehlt' ?></li>
<?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
  <div class="split">
    <form class="form card" method="post" action="/admin/galerien/<?= $gid ?>/speichern" data-guard>
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field"><span>Titel</span><input name="title" value="<?= $e($title) ?>" maxlength="200" required></label>
      <label class="field"><span>Beschreibung</span><textarea name="description" rows="4" maxlength="5000"><?= $e($desc) ?></textarea></label>
      <label class="field"><span>Adresse (Kurzname)</span><input name="slug" class="mono" value="<?= $e((string) $g['slug']) ?>" maxlength="60"></label>
      <label class="check"><input type="checkbox" name="show_exif" value="1"<?= (int) $g['show_exif'] === 1 ? ' checked' : '' ?>> Kamera, Objektiv und Belichtung öffentlich zeigen (GPS nie)</label>
      <div class="actions">
<?php if ($pub): ?>
        <button class="btn" type="submit" name="veroeffentlichen" value="1" data-busy="Wird veröffentlicht …">Speichern und veröffentlichen</button>
        <button class="btn ghost" type="submit">Nur speichern (noch nicht online)</button>
<?php else: ?>
        <button class="btn" type="submit">Speichern (Entwurf)</button>
<?php endif; ?>
      </div>
    </form>
    <div class="card form">
      <p class="muted">Status: <strong><?= $pub ? 'Online sichtbar' : 'Entwurf (nicht online)' ?></strong>. Zum Online-Stellen braucht jedes Foto einen Alt-Text. Der Knopf setzt den Status und erzeugt die Seite sofort neu.</p>
      <form method="post" action="/admin/galerien/<?= $gid ?>/status"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="status" value="<?= $pub ? 'draft' : 'published' ?>">
        <button class="btn" type="submit" data-busy="Wird veröffentlicht …"><?= $pub ? 'Galerie auf Entwurf setzen' : 'Galerie online stellen' ?></button></form>
      <form method="post" action="/admin/galerien/<?= $gid ?>/sortieren" class="inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="field"><span>Einmal sortieren nach</span>
          <select name="modus"><option value="date_asc">Aufnahmedatum, alt → neu</option><option value="date_desc">Aufnahmedatum, neu → alt</option><option value="name">Dateiname</option></select></label>
        <button class="btn small ghost" type="submit" data-confirm="Die Reihenfolge der Fotos wird neu gesetzt. Fortfahren?">Sortieren</button></form>
      <form method="post" action="/admin/galerien/<?= $gid ?>/loeschen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn small ghost" type="submit" data-confirm="Galerie „<?= $e((string) $g['title']) ?>“ löschen? Die <?= count($photos) ?> Fotos bleiben in der Mediathek, nur Titel, Text und Reihenfolge der Galerie gehen verloren.">Galerie löschen</button></form>
    </div>
  </div>
</section>
<section>
  <h2>Fotos <em>in der Galerie</em></h2>
  <p class="actions"><a class="btn small" href="/admin/galerien/<?= $gid ?>/hinzufuegen">Fotos aus der Mediathek hinzufügen</a></p>
<?php $galleryId = $gid; include __DIR__ . '/../partials/uploader.php'; ?>
<?php if ($photos === []): ?>
  <p class="muted">Diese Galerie hat noch keine Fotos.</p>
<?php else: ?>
  <form id="bulk" method="post" action="/admin/fotos/mehrfach" class="bulkbar">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="zurueck" value="<?= $e($bulkBack) ?>">
    <label class="small"><input type="checkbox" data-select-all="bulk"> alle</label>
    <button class="btn small" type="submit" name="aktion" value="bearbeiten">Auswahl bearbeiten</button>
    <button class="btn small ghost" type="submit" formaction="/admin/galerien/<?= $gid ?>/entfernen">Aus der Galerie entfernen</button>
    <button class="btn small ghost" type="submit" name="aktion" value="papierkorb" data-confirm="Die ausgewählten Fotos in den Papierkorb legen? Sie verschwinden damit aus allen Galerien.">In den Papierkorb</button>
  </form>
  <p class="muted small">Reihenfolge ändern: Foto mit der Maus ziehen oder die Pfeile benutzen. Das Foto mit der Marke „Titelbild“ ist das Titelbild der Galerie; ohne Wahl gilt das erste. Mit „Startseite“ markierte Fotos zeigt der Block „Startseiten-Fotos“ auf der Startseite (Seiten → Startseite).</p>
  <span class="visually-hidden" id="sortstatus" role="status" aria-live="polite"></span>
  <ul class="thumbs" data-sortable data-axis="x" data-url="/admin/galerien/<?= $gid ?>/reihenfolge" data-csrf="<?= $e($csrf) ?>">
<?php foreach ($photos as $i => $ph): $formId = 'bulk'; $isCover = (int) $g['cover_effective'] === (int) $ph['id']; ?>
<?php include __DIR__ . '/../partials/tile.php'; ?>
<?php if ($isCover): ?>      <span class="small badge cover">Titelbild</span>
<?php endif; ?>
<?php if ((int) $ph['on_home'] === 1): ?>      <span class="small badge cover">Startseite</span>
<?php endif; ?>
      <div class="row-actions">
        <form method="post" action="/admin/galerien/<?= $gid ?>/foto-bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="foto" value="<?= (int) $ph['id'] ?>"><input type="hidden" name="richtung" value="hoch"><button class="btn small ghost" type="submit" aria-label="nach vorn"<?= $i === 0 ? ' disabled' : '' ?>>←</button></form>
        <form method="post" action="/admin/galerien/<?= $gid ?>/foto-bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="foto" value="<?= (int) $ph['id'] ?>"><input type="hidden" name="richtung" value="runter"><button class="btn small ghost" type="submit" aria-label="nach hinten"<?= $i === count($photos) - 1 ? ' disabled' : '' ?>>→</button></form>
<?php if (!$isCover): ?>
        <form method="post" action="/admin/galerien/<?= $gid ?>/titelbild"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="foto" value="<?= (int) $ph['id'] ?>"><button class="btn small ghost" type="submit">Titelbild</button></form>
<?php endif; ?>
        <form method="post" action="/admin/galerien/<?= $gid ?>/startseite"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="foto" value="<?= (int) $ph['id'] ?>"><input type="hidden" name="an" value="<?= (int) $ph['on_home'] === 1 ? '0' : '1' ?>"><button class="btn small ghost" type="submit" aria-pressed="<?= (int) $ph['on_home'] === 1 ? 'true' : 'false' ?>"><?= (int) $ph['on_home'] === 1 ? 'Nicht mehr Startseite' : 'Startseite' ?></button></form>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
