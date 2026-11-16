<?php /** @var callable $e @var callable $asset @var ?string $error @var string $csrf @var string $editor @var string $blocksField @var bool $custom */ ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/seiten">Seiten</a></p>
  <h1><em>Startseite</em></h1>
  <p class="intro">Die Startseite besteht wie jede Seite aus Blöcken: Titelbild, Karussell, Text, Galerien und mehr. Beim ersten Öffnen ist sie so vorbefüllt, dass sie der bisherigen Startseite entspricht.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if (!empty($flash)): ?>
  <p class="after-save small"><a href="/admin/vorschau">Vorschau ansehen</a> · <a href="/admin/veroeffentlichen">Veröffentlichen</a></p>
<?php endif; ?>
  <form class="form card" method="post" action="/admin/startseite">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="blocks" id="blocks-field" value="<?= $e($blocksField) ?>">
    <div class="field"><span id="editor-label">Inhalt der Startseite</span>
      <div id="page-editor" class="editor" role="group" aria-labelledby="editor-label" data-pick-url="/admin/fotos/auswahl"></div>
      <noscript><p class="notice error">Der Editor braucht JavaScript.</p></noscript>
      <p class="small muted">Mit „Block hinzufügen“ kommt Neues dazu, mit den Pfeilen oder durch Ziehen am Griff ändert sich die Reihenfolge. Das Titelbild gibt es nur auf der Startseite.</p>
    </div>
    <div class="actions">
      <button class="btn" type="submit">Speichern</button>
      <a class="btn ghost" href="/admin/vorschau">Vorschau (gespeicherter Stand)</a>
    </div>
  </form>
<?php if ($custom): ?>
  <form method="post" action="/admin/startseite"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="zuruecksetzen" value="1">
    <button class="btn small ghost" type="submit" data-confirm="Zur festen Startseite zurückkehren? Die Blöcke der Startseite werden dabei entfernt.">Zur festen Startseite zurückkehren</button></form>
<?php endif; ?>
</section>
<script type="application/json" id="editor-data"><?= $editor ?></script>
<script src="<?= $e($asset('/assets/js/page-editor.js')) ?>" defer></script>
