<?php /** @var callable $e @var list<array<string,mixed>> $rows @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/fotos">Fotos</a> · Fotografen</p>
  <h1><em>Fotografen</em></h1>
  <p class="intro">Die Personen, die im Foto als Fotograf gewählt werden können. Wer entfernt wird, bleibt an den Fotos nicht stehen; die Fotos selbst bleiben unverändert.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <ul class="rows">
<?php foreach ($rows as $r): ?>
    <li class="row">
      <form class="inline" method="post" action="/admin/fotografen">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aktion" value="umbenennen">
        <label class="field"><span>Name (<?= (int) $r['photo_count'] ?> Fotos)</span><input name="name" value="<?= $e((string) $r['name']) ?>" maxlength="80" required></label>
        <button class="btn small" type="submit">Umbenennen</button>
      </form>
      <form method="post" action="/admin/fotografen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="aktion" value="loeschen">
        <button class="btn small ghost" type="submit" data-confirm="<?= $e((string) $r['name']) ?> aus der Liste entfernen?">Entfernen</button></form>
    </li>
<?php endforeach; ?>
  </ul>
  <form class="form card" method="post" action="/admin/fotografen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="aktion" value="neu">
    <label class="field"><span>Neue Person</span><input name="name" maxlength="80" required></label>
    <div class="actions"><button class="btn" type="submit">Hinzufügen</button></div>
  </form>
</section>
