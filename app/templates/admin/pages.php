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
<?php
$groups = ['main' => ['Im Menü oben', 'Reihenfolge im Hauptmenü. „Galerien“ steht dort immer zuerst.'], 'footer' => ['In der Fußzeile', 'Nur ein Link zur Seite, getrennt vom Hauptmenü (z. B. Impressum, Datenschutz).'], 'none' => ['Nicht verlinkt', 'Erreichbar nur über die Adresse oder über Knöpfe und Links in Texten.']];
$titles = [];
foreach ($pages as $pg) {
    $titles[mb_strtolower(trim((string) $pg['title']))][] = (string) $pg['slug'];
}
?>
  <p class="muted"><a href="/admin/startseite">Startseite bearbeiten</a> (Titelbild, Karussell, Texte, Galerien)</p>
<?php if ($pages === []): ?>
  <p class="muted">Noch keine Seiten.</p>
<?php else: foreach ($groups as $nav => [$gt, $gd]):
    $list = array_values(array_filter($pages, static fn (array $p): bool => $p['nav'] === $nav));
    if ($list === [] && $nav === 'none') { continue; } ?>
  <h2 class="h2s"><?= $e($gt) ?></h2>
  <p class="small muted"><?= $e($gd) ?></p>
<?php if ($list === []): ?>
  <p class="small muted">Keine Seite. Die Verlinkung ändert man in der Zeile einer Seite („Verlinkt“).</p>
<?php endif; ?>
  <ul class="rows">
<?php foreach ($list as $i => $pg):
    $dup = count($titles[mb_strtolower(trim((string) $pg['title']))] ?? []) > 1; ?>
    <li class="row">
      <div class="rowmain">
        <a href="/admin/seiten/<?= (int) $pg['id'] ?>"><strong><?= $e((string) $pg['title']) ?></strong></a>
        <span class="mono small muted">/<?= $e((string) $pg['slug']) ?>/ · <?= $pg['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?></span>
<?php if ($dup): ?>        <span class="small warn">Doppelt? Es gibt noch eine Seite mit dem Titel „<?= $e((string) $pg['title']) ?>“ (<?= $e(implode(', ', array_map(static fn (string $s): string => '/' . $s . '/', array_diff($titles[mb_strtolower(trim((string) $pg['title']))], [(string) $pg['slug']])))) ?>). Die richtige behalten, die andere im Bearbeiten-Formular löschen.</span>
<?php endif; ?>
      </div>
      <div class="row-actions">
        <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/verlinken" class="inline">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="sr" for="nav-<?= (int) $pg['id'] ?>">Verlinkt: <?= $e((string) $pg['title']) ?></label>
          <select id="nav-<?= (int) $pg['id'] ?>" name="nav" data-autosubmit><?php foreach (['main' => 'Menü oben', 'footer' => 'Fußzeile', 'none' => 'nirgends'] as $k => $t): ?><option value="<?= $k ?>"<?= $pg['nav'] === $k ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?></select>
          <noscript><button class="btn small ghost" type="submit">Ändern</button></noscript>
        </form>
        <a class="btn small ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau</a>
        <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="hoch"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $pg['title']) ?> nach oben"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
        <form method="post" action="/admin/seiten/<?= (int) $pg['id'] ?>/bewegen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="richtung" value="runter"><button class="btn small ghost" type="submit" aria-label="<?= $e((string) $pg['title']) ?> nach unten"<?= $i === count($list) - 1 ? ' disabled' : '' ?>>↓</button></form>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endforeach; endif; ?>
</section>
