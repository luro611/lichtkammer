<?php /** @var callable $e @var list<int> $ids @var int $count @var string $back @var ?Lichtkammer\Request $old @var list<array<string,mixed>> $photographers @var list<array<string,mixed>> $galleries @var string $csrf */
$o = static fn (string $k): string => $old !== null ? $old->input($k) : '';
$on = static fn (string $k): string => $old !== null && $old->input('set_' . $k) === '1' ? ' checked' : ''; ?>
<section class="hero">
  <p class="eyebrow"><a href="<?= $e($back) ?>">Zurück</a></p>
  <h1>Mehrere Fotos <em>bearbeiten</em></h1>
  <p class="intro">Das betrifft <strong><?= (int) $count ?></strong> Foto(s). Nur Felder mit gesetztem Häkchen werden geändert, alle anderen bleiben, wie sie sind.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <form class="form card" method="post" action="/admin/fotos/mehrfach">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="aktion" value="speichern"><input type="hidden" name="zurueck" value="<?= $e($back) ?>">
<?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?= (int) $id ?>">
<?php endforeach; ?>
    <label class="field"><span><input type="checkbox" name="set_photographer_id" value="1"<?= $on('photographer_id') ?>> Fotograf setzen</span>
      <select name="photographer_id"><option value="">– (Angabe entfernen)</option>
<?php foreach ($photographers as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $o('photographer_id') === (string) $p['id'] ? ' selected' : '' ?>><?= $e((string) $p['name']) ?></option>
<?php endforeach; ?>
      </select></label>
    <label class="field"><span><input type="checkbox" name="set_place" value="1"<?= $on('place') ?>> Ort setzen</span><input name="place" value="<?= $e($o('place')) ?>" maxlength="120"></label>
    <label class="field"><span><input type="checkbox" name="set_year" value="1"<?= $on('year') ?>> Jahr setzen</span><input name="year" value="<?= $e($o('year')) ?>" inputmode="numeric" maxlength="4"></label>
    <label class="field"><span><input type="checkbox" name="set_title" value="1"<?= $on('title') ?>> Titel setzen</span><input name="title" value="<?= $e($o('title')) ?>" maxlength="200"></label>
    <label class="field"><span><input type="checkbox" name="set_caption" value="1"<?= $on('caption') ?>> Bildunterschrift setzen</span><textarea name="caption" rows="2" maxlength="1000"><?= $e($o('caption')) ?></textarea></label>
    <label class="field"><span><input type="checkbox" name="set_alt_text" value="1"<?= $on('alt_text') ?>> Alt-Text setzen (gilt dann für alle gleich, z. B. bei einer Serie)</span><textarea name="alt_text" rows="2" maxlength="500"><?= $e($o('alt_text')) ?></textarea></label>
<?php if ($galleries !== []): ?>
    <label class="field"><span>Zusätzlich zu einer Galerie hinzufügen</span>
      <select name="galerie_add"><option value="">–</option>
<?php foreach ($galleries as $g): ?><option value="<?= (int) $g['id'] ?>"><?= $e((string) $g['title']) ?></option>
<?php endforeach; ?>
      </select></label>
<?php endif; ?>
    <div class="actions"><button class="btn" type="submit">Für <?= (int) $count ?> Foto(s) speichern</button><a class="btn ghost" href="<?= $e($back) ?>">Abbrechen</a></div>
  </form>
</section>
