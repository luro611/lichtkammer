<?php /** @var callable $e @var array<string,mixed> $set @var array $heroes @var ?string $error @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Öffentliche Seite</p>
  <h1><em>Einstellungen</em></h1>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <form class="form card" method="post" action="/admin/einstellungen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Seitenname</span><input name="site_name" value="<?= $e((string) $set['site_name']) ?>" maxlength="60" required></label>
    <label class="field"><span>Text auf dem Titelbild der Startseite</span><input name="tagline" value="<?= $e((string) $set['tagline']) ?>" maxlength="200" placeholder="leer: Seitenname"></label>
    <label class="field"><span>Titelfoto der Startseite</span><select name="hero_photo_id"><option value="0">automatisch: Titelbild der ersten Galerie</option>
<?php foreach ($heroes as $h): ?>      <option value="<?= (int) $h['id'] ?>"<?= (int) $set['hero_photo_id'] === (int) $h['id'] ? ' selected' : '' ?>><?= $e($h['gallery'] . ' – ' . $h['label']) ?></option>
<?php endforeach; ?></select></label>
    <p class="small muted">Zur Auswahl stehen Fotos aus Galerien. Erscheint das Foto in keiner veröffentlichten Galerie, nimmt die Seite das automatische Titelbild.</p>
    <p class="small muted">Diese beiden Angaben gelten für die feste Startseite. Sobald die <a href="/admin/startseite">Startseite</a> im Block-Editor gespeichert ist, bestimmen dort Titelbild-Block und Karussell das Aussehen.</p>
    <div class="actions"><button class="btn" type="submit">Speichern</button> <a class="btn ghost" href="/admin/vorschau">Vorschau</a></div>
  </form>
</section>
