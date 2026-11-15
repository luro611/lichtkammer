<?php /** @var callable $e @var callable $asset @var array<string,mixed> $result @var array<string,mixed> $f @var int $total @var list<array<string,mixed>> $photographers @var list<int> $years @var list<array<string,mixed>> $galleries @var ?string $engine @var list<string> $formats @var int $maxMb @var string $csrf @var int $trashCount @var string $back */ ?>
<section class="hero">
  <p class="eyebrow">Mediathek</p>
  <h1>Fotos <em>verwalten</em></h1>
  <p class="intro">Bilder hierher ziehen oder auswählen, es dürfen viele auf einmal sein. Jedes Foto wird einzeln hochgeladen und danach verkleinert. Das Original bleibt unverändert gespeichert.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php $galleryId = null; include __DIR__ . '/../partials/uploader.php'; ?>
</section>
<section>
  <h2>Alle <em>Fotos</em></h2>
  <p class="muted mono"><?= (int) $total ?> Fotos insgesamt · <?= (int) $result['total'] ?> gefunden · <a href="/admin/papierkorb">Papierkorb<?= $trashCount > 0 ? ' (' . (int) $trashCount . ')' : '' ?></a> · <a href="/admin/fotografen">Fotografen</a></p>
  <form class="filters" method="get" action="/admin/fotos" role="search">
    <label class="field"><span>Suche</span><input type="search" name="q" value="<?= $e((string) $f['q']) ?>" placeholder="Titel, Ort, Jahr, Galerie, Dateiname"></label>
    <label class="field"><span>Zeige</span>
      <select name="filter">
        <option value="">alle</option>
        <option value="ohne_galerie"<?= $f['filter'] === 'ohne_galerie' ? ' selected' : '' ?>>ohne Galerie</option>
        <option value="ohne_alt"<?= $f['filter'] === 'ohne_alt' ? ' selected' : '' ?>>Alt-Text fehlt</option>
        <option value="fehler"<?= $f['filter'] === 'fehler' ? ' selected' : '' ?>>mit Fehler</option>
      </select></label>
    <label class="field"><span>Fotograf</span>
      <select name="fotograf"><option value="">alle</option>
<?php foreach ($photographers as $ph): ?>
        <option value="<?= (int) $ph['id'] ?>"<?= (int) $f['photographer'] === (int) $ph['id'] ? ' selected' : '' ?>><?= $e((string) $ph['name']) ?></option>
<?php endforeach; ?>
      </select></label>
    <label class="field"><span>Jahr</span>
      <select name="jahr"><option value="">alle</option>
<?php foreach ($years as $y): ?>
        <option value="<?= (int) $y ?>"<?= (int) $f['year'] === $y ? ' selected' : '' ?>><?= (int) $y ?></option>
<?php endforeach; ?>
      </select></label>
    <label class="field"><span>Galerie</span>
      <select name="galerie"><option value="">alle</option>
<?php foreach ($galleries as $g): ?>
        <option value="<?= (int) $g['id'] ?>"<?= (int) $f['gallery'] === (int) $g['id'] ? ' selected' : '' ?>><?= $e((string) $g['title']) ?></option>
<?php endforeach; ?>
      </select></label>
    <div class="field"><span>&nbsp;</span><button class="btn" type="submit">Suchen</button></div>
  </form>
<?php if ($result['rows'] === []): ?>
  <p class="muted">Keine Fotos gefunden.</p>
<?php else: ?>
  <form id="bulk" method="post" action="/admin/fotos/mehrfach" class="bulkbar">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="zurueck" value="<?= $e($back) ?>">
    <label class="small"><input type="checkbox" data-select-all="bulk"> alle auf dieser Seite</label>
    <button class="btn small" type="submit" name="aktion" value="bearbeiten">Auswahl bearbeiten</button>
<?php if ($galleries !== []): ?>
    <select name="galerie_add" aria-label="Galerie wählen">
<?php foreach ($galleries as $g): ?>
      <option value="<?= (int) $g['id'] ?>"><?= $e((string) $g['title']) ?></option>
<?php endforeach; ?>
    </select>
    <button class="btn small ghost" type="submit" name="aktion" value="zur_galerie">Zur Galerie hinzufügen</button>
<?php endif; ?>
    <button class="btn small ghost" type="submit" name="aktion" value="papierkorb" data-confirm="Die ausgewählten Fotos in den Papierkorb legen? Sie sind dort 30 Tage wiederherstellbar.">In den Papierkorb</button>
  </form>
  <ul class="thumbs">
<?php foreach ($result['rows'] as $ph): $formId = 'bulk'; include __DIR__ . '/../partials/tile.php'; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php $pageBase = '/admin/fotos?' . http_build_query(array_filter(['q' => $f['q'], 'filter' => $f['filter'], 'fotograf' => $f['photographer'], 'jahr' => $f['year'], 'galerie' => $f['gallery']])); ?>
<?php if ($result['pages'] > 1): ?>
  <nav class="pager" aria-label="Seiten">
<?php if ($result['page'] > 1): ?><a class="btn small ghost" href="<?= $e($pageBase . '&seite=' . ($result['page'] - 1)) ?>">Zurück</a><?php endif; ?>
    <span class="mono small">Seite <?= (int) $result['page'] ?> von <?= (int) $result['pages'] ?></span>
<?php if ($result['page'] < $result['pages']): ?><a class="btn small ghost" href="<?= $e($pageBase . '&seite=' . ($result['page'] + 1)) ?>">Weiter</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</section>
