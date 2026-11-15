<?php /** @var callable $e @var array<string,mixed> $g @var array<string,mixed> $result @var string $q @var string $csrf */
$gid = (int) $g['id']; ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/galerien">Galerien</a> · <a href="/admin/galerien/<?= $gid ?>"><?= $e((string) $g['title']) ?></a></p>
  <h1>Fotos <em>hinzufügen</em></h1>
  <form class="filters" method="get" action="/admin/galerien/<?= $gid ?>/hinzufuegen" role="search">
    <label class="field"><span>Suche</span><input type="search" name="q" value="<?= $e($q) ?>" placeholder="Titel, Ort, Jahr, Galerie, Dateiname"></label>
    <div class="field"><span>&nbsp;</span><button class="btn" type="submit">Suchen</button></div>
  </form>
<?php if ($result['rows'] === []): ?>
  <p class="muted">Keine Fotos gefunden.</p>
<?php else: ?>
  <form id="add" method="post" action="/admin/galerien/<?= $gid ?>/hinzufuegen" class="bulkbar">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="small"><input type="checkbox" data-select-all="add"> alle auf dieser Seite</label>
    <button class="btn small" type="submit">Ausgewählte hinzufügen</button>
    <a class="btn small ghost" href="/admin/galerien/<?= $gid ?>">Fertig</a>
  </form>
  <ul class="thumbs">
<?php foreach ($result['rows'] as $ph): $formId = 'add'; include __DIR__ . '/../partials/tile.php'; ?>
<?php if ($ph['in_gallery']): ?>      <span class="small badge">schon in der Galerie</span>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php if ($result['pages'] > 1): $base = '/admin/galerien/' . $gid . '/hinzufuegen?q=' . rawurlencode($q); ?>
  <nav class="pager" aria-label="Seiten">
<?php if ($result['page'] > 1): ?><a class="btn small ghost" href="<?= $e($base . '&seite=' . ($result['page'] - 1)) ?>">Zurück</a><?php endif; ?>
    <span class="mono small">Seite <?= (int) $result['page'] ?> von <?= (int) $result['pages'] ?></span>
<?php if ($result['page'] < $result['pages']): ?><a class="btn small ghost" href="<?= $e($base . '&seite=' . ($result['page'] + 1)) ?>">Weiter</a><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>
</section>
