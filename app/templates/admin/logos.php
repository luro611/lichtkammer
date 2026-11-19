<?php /** @var callable $e @var callable $asset @var list<array<string,mixed>> $logos @var array<string,string> $choice @var array<string,string> $roles @var ?string $error @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Öffentliche Seite</p>
  <h1><em>Logos</em></h1>
  <p class="intro">Lade ein oder mehrere Logos hoch und wähle, welches wo erscheint: in der Kopfzeile der öffentlichen Seite und hier in der Verwaltung, getrennt für den hellen und den dunklen Modus, dazu das Browser-Symbol (Favicon). Geeignet sind SVG (am besten), PNG, WebP und JPEG bis 300 KB. Auf der öffentlichen Seite wird es mit dem nächsten <a href="/admin/veroeffentlichen">Veröffentlichen</a> sichtbar.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <form class="form card" method="post" action="/admin/logos" id="logo-upload">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="name" id="logo-name"><input type="hidden" name="daten" id="logo-data">
    <label class="field"><span>Logo hochladen</span><input type="file" id="logo-file" accept=".svg,image/svg+xml,.png,image/png,.webp,image/webp,.jpg,.jpeg,image/jpeg" multiple></label>
    <p class="small muted">Mehrere Dateien auf einmal sind möglich (z. B. die helle und die dunkle Fassung). Sie werden nacheinander hochgeladen.</p>
    <p class="small" id="logo-status" role="status"></p>
    <noscript><p class="notice error">Das Hochladen braucht JavaScript.</p></noscript>
  </form>
</section>
<section>
<?php if ($logos === []): ?>
  <p class="muted">Noch keine Logos hochgeladen.</p>
<?php else: ?>
  <h2 class="h2s">Hochgeladene Logos</h2>
  <ul class="logogrid">
<?php foreach ($logos as $l): ?>
    <li class="logocard">
      <div class="logoprev on-light"><img src="<?= $e((string) $l['uri']) ?>" alt="<?= $e((string) $l['name']) ?> auf hellem Grund"></div>
      <div class="logoprev on-dark"><img src="<?= $e((string) $l['uri']) ?>" alt="<?= $e((string) $l['name']) ?> auf dunklem Grund"></div>
      <strong><?= $e((string) $l['name']) ?></strong>
      <form method="post" action="/admin/logos/<?= $e((string) $l['id']) ?>/loeschen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn small ghost" type="submit" data-confirm="Logo „<?= $e((string) $l['name']) ?>“ löschen?">Löschen</button></form>
    </li>
<?php endforeach; ?>
  </ul>
  <form class="form card" method="post" action="/admin/logos/zuordnen" data-guard>
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php foreach ($roles as $role => $label): ?>
    <label class="field"><span><?= $e($label) ?></span><select name="<?= $e($role) ?>"><option value="">– keines –</option>
<?php foreach ($logos as $l): ?>      <option value="<?= $e((string) $l['id']) ?>"<?= $choice[$role] === $l['id'] ? ' selected' : '' ?>><?= $e((string) $l['name']) ?></option>
<?php endforeach; ?></select></label>
<?php endforeach; ?>
    <p class="small muted">Ist nur ein Logo für hell oder dunkel gewählt, gilt es für beide Modi. Ohne Auswahl erscheint der Seitenname als Schrift.</p>
    <div class="actions"><button class="btn" type="submit" name="veroeffentlichen" value="1" data-busy="Wird veröffentlicht …">Auswahl speichern und veröffentlichen</button> <button class="btn ghost" type="submit">Nur speichern (noch nicht online)</button></div>
<?php include __DIR__ . '/../partials/also.php'; ?>
  </form>
<?php endif; ?>
</section>
<script src="<?= $e($asset('/assets/js/logos.js')) ?>" defer></script>
