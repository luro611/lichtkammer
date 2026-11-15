<?php /** @var callable $e @var list<array<string,mixed>> $pages @var array $templates @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Öffentliche Seite</p>
  <h1><em>Seiten</em></h1>
  <p class="intro">Einfache Textseiten wie „Über uns“, Impressum und Datenschutz. Sichtbar werden sie erst mit dem <a href="/admin/veroeffentlichen">Veröffentlichen</a>. Die <a href="/admin/einstellungen">Einstellungen</a> (Seitenname, Starttext, Titelfoto) stehen separat.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($templates !== []): ?>
  <form class="inline" method="post" action="/admin/seiten">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Mit Vorlage anlegen</span><select name="vorlage"><?php foreach ($templates as $k => $t): ?><option value="<?= $e((string) $k) ?>"><?= $e($t[0]) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Anlegen</button>
  </form>
<?php endif; ?>
  <form class="inline" method="post" action="/admin/seiten">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Neue leere Seite</span><input name="title" maxlength="120" required placeholder="Titel"></label>
    <button class="btn ghost" type="submit">Anlegen</button>
  </form>
</section>
<section>
<?php if ($pages === []): ?>
  <p class="muted">Noch keine Seiten.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($pages as $i => $pg): ?>
    <li class="row">
      <div class="rowmain">
        <a href="/admin/seiten/<?= (int) $pg['id'] ?>"><strong><?= $e((string) $pg['title']) ?></strong></a>
        <span class="mono small muted">/<?= $e((string) $pg['slug']) ?>/ · <?= $pg['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?> · <?= ['main' => 'im Menü', 'footer' => 'in der Fußzeile', 'none' => 'nicht verlinkt'][$pg['nav']] ?></span>
      </div>
      <div class="row-actions">
        <a class="btn small ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau</a>
        <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="hoch"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $pg['title']) ?> nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
        <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="runter"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $pg['title']) ?> nach unten"<?= $i === count($pages) - 1 ? ' disabled' : '' ?>>↓</button></form>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
