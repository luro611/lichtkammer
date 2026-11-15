<?php /** @var callable $e @var list<array<string,mixed>> $galleries @var string $csrf @var string $oldTitle */ ?>
<section class="hero">
  <p class="eyebrow">Galerien</p>
  <h1>Galerien <em>ordnen</em></h1>
  <p class="intro">Eine Galerie ist eine geordnete Auswahl aus der Mediathek. Ein Foto darf in mehreren Galerien liegen. Reihenfolge der Kacheln auf der Seite: hier per Ziehen oder mit den Pfeilen.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <form class="form card" method="post" action="/admin/galerien">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Neue Galerie</span><input name="title" value="<?= $e($oldTitle) ?>" maxlength="200" required placeholder="z. B. Island 2022"></label>
    <div class="actions"><button class="btn" type="submit">Galerie anlegen</button></div>
  </form>
</section>
<section>
<?php if ($galleries === []): ?>
  <p class="muted">Noch keine Galerien.</p>
<?php else: ?>
  <ul class="rows" data-sortable data-axis="y" data-url="/admin/galerien/reihenfolge" data-csrf="<?= $e($csrf) ?>">
<?php foreach ($galleries as $i => $g): ?>
    <li class="row" data-id="<?= (int) $g['id'] ?>">
      <a class="rowthumb" href="/admin/galerien/<?= (int) $g['id'] ?>"><?php if ($g['cover']): ?><img src="/media/<?= $e((string) $g['cover']) ?>" alt="" width="120" loading="lazy"><?php else: ?><span class="ph">leer</span><?php endif; ?></a>
      <div class="rowmain">
        <a href="/admin/galerien/<?= (int) $g['id'] ?>"><strong><?= $e((string) $g['title']) ?></strong></a>
        <span class="mono small muted"><?= (int) $g['photo_count'] ?> Fotos · <?= $g['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?> · /<?= $e((string) $g['slug']) ?></span>
      </div>
      <div class="row-actions">
        <form method="post" action="/admin/galerien/<?= (int) $g['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="hoch"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $g['title']) ?> nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
        <form method="post" action="/admin/galerien/<?= (int) $g['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="runter"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $g['title']) ?> nach unten"<?= $i === count($galleries) - 1 ? ' disabled' : '' ?>>↓</button></form>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
