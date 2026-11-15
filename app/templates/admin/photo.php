<?php /** @var callable $e @var array<string,mixed> $ph @var ?array<string,string> $draft @var list<array<string,mixed>> $photographers @var list<array<string,mixed>> $inGalleries @var list<array<string,mixed>> $galleries @var string $csrf */
$id = (int) $ph['id'];
$v = static fn (string $k): string => (string) ($draft[$k] ?? ($k === 'year' ? ($ph['year'] ?? '') : ($ph[$k] ?? '')));
$self = '/admin/fotos/' . $id;
$inIds = array_map(static fn (array $g): int => (int) $g['id'], $inGalleries);
$exif = (array) $ph['exif'];
$exifLabels = ['camera' => 'Kamera', 'lens' => 'Objektiv', 'aperture' => 'Blende', 'exposure' => 'Belichtungszeit', 'iso' => 'ISO', 'focal_length' => 'Brennweite']; ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/fotos">Fotos</a> · <?= $e((string) $ph['original_name']) ?></p>
  <h1><?= $e($v('title') !== '' ? $v('title') : 'Foto bearbeiten') ?></h1>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <div class="split">
    <div>
<?php if ($ph['preview']): ?>
      <img class="preview" src="/media/<?= $e((string) $ph['preview']) ?>" alt="<?= $e((string) $ph['alt_text']) ?>" width="<?= (int) $ph['width'] ?>" height="<?= (int) $ph['height'] ?>">
<?php else: ?>
      <div class="ph big"><?= $ph['status'] === 'failed' ? $e((string) $ph['error']) : 'wird verarbeitet' ?></div>
<?php endif; ?>
      <p class="mono small muted"><?= (int) $ph['width'] ?> × <?= (int) $ph['height'] ?> px<?= $ph['taken_at'] ? ' · aufgenommen ' . $e((string) $ph['taken_at']) : '' ?><?= (int) $ph['has_gps'] === 1 ? ' · enthält GPS im Original (wird nie veröffentlicht)' : '' ?></p>
<?php if ($exif !== []): ?>
      <dl class="exif mono small">
<?php foreach ($exifLabels as $k => $label): if (!empty($exif[$k])): ?>
        <dt><?= $e($label) ?></dt><dd><?= $e((string) $exif[$k]) ?></dd>
<?php endif; endforeach; ?>
      </dl>
<?php endif; ?>
    </div>
    <form class="form card" method="post" action="<?= $e($self) ?>/speichern">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field"><span>Titel</span><input name="title" value="<?= $e($v('title')) ?>" maxlength="200"></label>
      <label class="field"><span>Bildunterschrift</span><textarea name="caption" rows="2" maxlength="1000"><?= $e($v('caption')) ?></textarea></label>
      <label class="field"><span>Alt-Text (Pflicht vor dem Veröffentlichen)</span><textarea name="alt_text" rows="2" maxlength="500"><?= $e($v('alt_text')) ?></textarea></label>
      <label class="field"><span>Fotograf</span>
        <select name="photographer_id"><option value="">–</option>
<?php foreach ($photographers as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $v('photographer_id') === (string) $p['id'] || ($draft === null && (int) $ph['photographer_id'] === (int) $p['id']) ? ' selected' : '' ?>><?= $e((string) $p['name']) ?></option>
<?php endforeach; ?>
        </select></label>
      <label class="field"><span>Ort</span><input name="place" value="<?= $e($v('place')) ?>" maxlength="120"></label>
      <label class="field"><span>Jahr</span><input name="year" value="<?= $e($v('year')) ?>" inputmode="numeric" maxlength="4" pattern="[0-9]{4}"></label>
      <div class="actions"><button class="btn" type="submit">Speichern</button></div>
    </form>
  </div>
</section>
<section>
  <h2><em>Galerien</em></h2>
<?php if ($inGalleries === []): ?><p class="muted">Dieses Foto liegt in keiner Galerie.</p><?php else: ?>
  <ul class="rows">
<?php foreach ($inGalleries as $g): ?>
    <li class="row"><div class="rowmain"><a href="/admin/galerien/<?= (int) $g['id'] ?>"><?= $e((string) $g['title']) ?></a> <span class="mono small muted"><?= $g['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?></span></div>
      <form method="post" action="/admin/galerien/<?= (int) $g['id'] ?>/entfernen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="zurueck" value="<?= $e($self) ?>"><button class="btn small ghost" type="submit">Aus dieser Galerie entfernen</button></form></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php $free = array_values(array_filter($galleries, static fn (array $g): bool => !in_array((int) $g['id'], $inIds, true))); if ($free !== []): ?>
  <form class="inline" method="post" action="/admin/fotos/mehrfach">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="zurueck" value="<?= $e($self) ?>"><input type="hidden" name="aktion" value="zur_galerie">
    <label class="field"><span>Zu einer Galerie hinzufügen</span>
      <select name="galerie_add">
<?php foreach ($free as $g): ?><option value="<?= (int) $g['id'] ?>"><?= $e((string) $g['title']) ?></option>
<?php endforeach; ?>
      </select></label>
    <button class="btn small" type="submit">Hinzufügen</button>
  </form>
<?php endif; ?>
  <form class="inline" method="post" action="/admin/fotos/mehrfach">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="zurueck" value="/admin/fotos"><input type="hidden" name="aktion" value="papierkorb">
    <button class="btn small ghost" type="submit" data-confirm="Dieses Foto in den Papierkorb legen? Es verschwindet aus allen Galerien und ist 30 Tage wiederherstellbar.">In den Papierkorb</button>
  </form>
</section>
