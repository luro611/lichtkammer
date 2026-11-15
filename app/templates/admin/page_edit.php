<?php /** @var callable $e @var array<string,mixed> $pg @var ?string $error @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/seiten">Seiten</a></p>
  <h1><em><?= $e((string) $pg['title']) ?></em></h1>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if (!empty($flash)): ?>
  <p class="after-save small"><a href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau ansehen</a> · <a href="/admin/seiten">Zur Liste der Seiten</a> · <a href="/admin/veroeffentlichen">Veröffentlichen</a><?= $pg['status'] === 'draft' ? ' · <span class="muted">Status ist noch „Entwurf“, die Seite erscheint nicht online.</span>' : '' ?></p>
<?php endif; ?>
  <form class="form card" method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/speichern">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Titel</span><input name="title" value="<?= $e((string) $pg['title']) ?>" maxlength="120" required></label>
    <label class="field"><span>Adresse (Teil der Web-Adresse)</span><input name="slug" value="<?= $e((string) $pg['slug']) ?>" maxlength="60"></label>
    <label class="field"><span>Text</span><textarea name="body" rows="18" maxlength="50000"><?= $e((string) $pg['body']) ?></textarea></label>
    <details class="notice"><summary>Hilfe zur Gestaltung</summary>
      <p class="small mono">## Überschrift · ### kleinere Überschrift · **fett** · *kursiv* · - Listenpunkt · 1. nummeriert · &gt; Zitat · --- Trennlinie · [Linktext](https://adresse.de) · [Mail](mailto:info@beispiel.de)</p>
      <p class="small">Absätze trennst du mit einer Leerzeile. Anderes (z. B. eingefügtes HTML) wird nicht übernommen.</p>
    </details>
    <label class="field"><span>Status</span><select name="status"><option value="draft"<?= $pg['status'] === 'draft' ? ' selected' : '' ?>>Entwurf</option><option value="published"<?= $pg['status'] === 'published' ? ' selected' : '' ?>>veröffentlichen (mit dem nächsten Veröffentlichen)</option></select></label>
    <label class="field"><span>Verlinkt</span><select name="nav"><?php foreach (['main' => 'im Menü oben', 'footer' => 'in der Fußzeile', 'none' => 'nirgends'] as $k => $t): ?><option value="<?= $k ?>"<?= $pg['nav'] === $k ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?></select></label>
    <div class="actions">
      <button class="btn" type="submit">Speichern</button>
      <button class="btn ghost" type="submit" name="weiter" value="liste">Speichern und zur Liste</button>
      <a class="btn ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau (gespeicherter Stand)</a>
    </div>
  </form>
  <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/loeschen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn small ghost" type="submit" data-confirm="Seite „<?= $e((string) $pg['title']) ?>“ löschen?">Seite löschen</button></form>
</section>
