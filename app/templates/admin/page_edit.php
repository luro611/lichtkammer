<?php /** @var callable $e @var array<string,mixed> $pg @var ?string $error @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/seiten">Seiten</a></p>
  <h1><em><?= $e((string) $pg['title']) ?></em></h1>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if (!empty($flash) && empty($flashError)): ?>
  <p class="after-save small"><?php if ($pg['status'] === 'published' && ($flashCode ?? '') === 'savedpub'): ?><a href="/<?= $e((string) $pg['slug']) ?>/" target="_blank" rel="noopener">Seite ansehen (online)</a><?php else: ?><a href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau ansehen</a><?php endif; ?> · <a href="/admin/seiten">Zur Liste der Seiten</a><?= $pg['status'] === 'draft' ? ' · <span class="muted">Status ist „Entwurf (nicht online)“: die Seite erscheint nicht auf der öffentlichen Seite.</span>' : '' ?></p>
<?php endif; ?>
  <form class="form card" method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/speichern">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Titel</span><input name="title" value="<?= $e((string) $pg['title']) ?>" maxlength="120" required></label>
    <input type="hidden" name="titel_form" value="1">
    <label class="field"><span><input type="checkbox" name="titel_zeigen" value="1"<?= (int) ($pg['show_title'] ?? 1) === 1 ? ' checked' : '' ?>> Seitentitel oben auf der Seite anzeigen</span></label>
    <p class="small muted">Ohne Häkchen ist der Titel nicht sichtbar. Für Screenreader und Suchmaschinen bleibt er trotzdem erhalten.</p>
    <label class="field"><span>Adresse (Teil der Web-Adresse)</span><input name="slug" value="<?= $e((string) $pg['slug']) ?>" maxlength="60"><small class="small muted">Wird die Adresse einer online stehenden Seite geändert, leitet die alte Adresse nach dem Veröffentlichen automatisch hierher weiter.</small></label>
    <input type="hidden" name="blocks" id="blocks-field" value="<?= $e($blocksField) ?>">
    <div class="field"><span id="editor-label">Inhalt</span>
      <div id="page-editor" class="editor" role="group" aria-labelledby="editor-label" data-pick-url="/admin/fotos/auswahl" data-gal-url="/admin/fotos/auswahl-galerien" data-gal-photos-url="/admin/fotos/auswahl-galerie"></div>
      <noscript><p class="notice error">Der Seiten-Editor braucht JavaScript. Ohne JavaScript lässt sich der Inhalt nicht bearbeiten. Speichern ändert nur Titel, Adresse, Status und Verlinkung; ein alter Text wird dabei in Blöcke übernommen.</p></noscript>
      <p class="small muted">Jeder Block ist ein Baustein der Seite. Neue Blöcke kommen aus der Bausteinleiste (ziehen) oder über „+ hier einfügen“ an die gewünschte Stelle. Mit den Pfeilen, „Verschieben nach …“ oder durch Ziehen am Griff ändert sich die Reihenfolge, auch zwischen Seite und Spalten. „Rückgängig“ holt Gelöschtes und Verschobenes zurück, solange du nicht gespeichert hast. Leere Blöcke werden beim Speichern entfernt.</p>
    </div>
    <label class="field"><span>Status</span><select name="status"><option value="draft"<?= $pg['status'] === 'draft' ? ' selected' : '' ?>>Entwurf (nicht online)</option><option value="published"<?= $pg['status'] === 'published' ? ' selected' : '' ?>>Online sichtbar</option></select></label>
    <label class="field"><span>Verlinkt</span><select name="nav"><?php foreach (['main' => 'im Menü oben', 'footer' => 'in der Fußzeile', 'none' => 'nirgends'] as $k => $t): ?><option value="<?= $k ?>"<?= $pg['nav'] === $k ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?></select></label>
<?php if ($pg['status'] === 'published'): ?>
    <div class="actions">
      <button class="btn" type="submit" name="veroeffentlichen" value="1" data-busy="Wird veröffentlicht …">Speichern und veröffentlichen</button>
      <button class="btn ghost" type="submit">Nur speichern (noch nicht online)</button>
      <button class="btn ghost" type="submit" name="weiter" value="liste">Nur speichern, dann zur Liste</button>
      <a class="btn ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>" target="_blank" rel="noopener">Vorschau (gespeicherter Stand)</a>
    </div>
    <p class="small muted">Online ist weiterhin die letzte veröffentlichte Fassung, bis du veröffentlichst.</p>
<?php else: ?>
    <div class="actions">
      <button class="btn" type="submit" name="online" value="1" data-busy="Wird veröffentlicht …">Speichern und online stellen</button>
      <button class="btn ghost" type="submit">Nur speichern (noch nicht online)</button>
      <button class="btn ghost" type="submit" name="weiter" value="liste">Speichern, dann zur Liste</button>
      <a class="btn ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>" target="_blank" rel="noopener">Vorschau (gespeicherter Stand)</a>
    </div>
<?php endif; ?>
<?php include __DIR__ . '/../partials/also.php'; ?>
    <details class="notice"><summary>Erweitert</summary>
      <p class="small">Nur Überschriften, Texte, Zitate und Trennlinien? Dann lässt sich die Seite wieder als einfacher Text speichern.</p>
      <button class="btn small ghost" type="submit" name="modus" value="text" data-confirm="Als einfachen Text speichern? Das geht nur, wenn die Seite keine Bilder, Knöpfe oder Spalten enthält.">Als einfachen Text speichern</button>
    </details>
  </form>
  <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/loeschen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn small ghost" type="submit" data-confirm="Seite „<?= $e((string) $pg['title']) ?>“ löschen?">Seite löschen</button></form>
</section>
<script type="application/json" id="editor-data"><?= $editor ?></script>
<script src="<?= $e($asset('/assets/js/page-material.js')) ?>" defer></script>
<script src="<?= $e($asset('/assets/js/page-editor.js')) ?>" defer></script>
