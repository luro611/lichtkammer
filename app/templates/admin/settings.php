<?php /** @var callable $e @var array<string,mixed> $set @var array $heroes @var ?string $error @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Öffentliche Seite</p>
  <h1><em>Einstellungen</em></h1>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <p class="notice" role="note"><strong>Titelbild, Karussell, Slider:</strong> Das Bild der Startseite stellst du unter <a href="/admin/startseite">Seiten → Startseite</a> ein (mehrere Bilder, volle Breite, Ausschnitt). Die Felder hier gelten nur, solange die Startseite dort nicht gespeichert wurde.</p>
  <form class="form card" method="post" action="/admin/einstellungen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Seitenname</span><input name="site_name" value="<?= $e((string) $set['site_name']) ?>" maxlength="60" required></label>
    <label class="field"><span>Text auf dem Titelbild der Startseite</span><input name="tagline" value="<?= $e((string) $set['tagline']) ?>" maxlength="200" placeholder="leer: Seitenname"></label>
    <input type="hidden" name="hero_form" value="1">
    <label class="field"><span><input type="checkbox" name="hero_show" value="1"<?= $set['hero_show'] ? ' checked' : '' ?>> Text auf dem Titelbild der Startseite anzeigen</span></label>
    <label class="field"><span>Titelfoto der Startseite</span><select name="hero_photo_id"><option value="0">automatisch: Titelbild der ersten Galerie</option>
<?php foreach ($heroes as $h): ?>      <option value="<?= (int) $h['id'] ?>"<?= (int) $set['hero_photo_id'] === (int) $h['id'] ? ' selected' : '' ?>><?= $e($h['gallery'] . ' – ' . $h['label']) ?></option>
<?php endforeach; ?></select></label>
    <p class="small muted">Zur Auswahl stehen Fotos aus Galerien. Erscheint das Foto in keiner veröffentlichten Galerie, nimmt die Seite das automatische Titelbild.</p>
    <p class="small muted">Diese beiden Angaben gelten für die feste Startseite. Sobald die <a href="/admin/startseite">Startseite</a> im Block-Editor gespeichert ist, bestimmen dort Titelbild-Block und Karussell das Aussehen.</p>
    <fieldset class="field designwahl"><legend>Design der öffentlichen Seite</legend>
<?php $beschr = ['warm' => 'Papierfarben, Terrakotta, Serifenüberschriften (so sieht die Seite heute aus).', 'klar' => 'Neutral und rund, Überschriften in Inter, Terrakotta als Akzent.', 'studio' => 'Hell und luftig, tiefes Schwarz im Dunkel, blaue Knöpfe und Links, sehr runde Formen.', 'blau' => 'Marine- und Himmelblau, kleine Radien, Serifenüberschriften.']; ?>
<?php foreach (\Lichtkammer\Site\Settings::DESIGNS as $key => $name): ?>      <label><input type="radio" name="design" value="<?= $e($key) ?>"<?= $set['design'] === $key ? ' checked' : '' ?>> <strong><?= $e($name) ?></strong> <span class="muted small"><?= $e($beschr[$key]) ?></span></label>
<?php endforeach; ?>
      <p class="small muted">Besucher sehen immer dieses eine Design, hell oder dunkel nach ihrer Geräteeinstellung. Nach dem Speichern zeigt die <a href="/admin/vorschau">Vorschau</a> es sofort. Live geht es erst beim nächsten Veröffentlichen, dann werden alle Seiten neu erzeugt.</p>
    </fieldset>
    <div class="actions"><button class="btn" type="submit">Speichern</button> <a class="btn ghost" href="/admin/vorschau">Vorschau</a></div>
  </form>
</section>
