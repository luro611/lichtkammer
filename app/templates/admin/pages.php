<?php /** @var callable $e @var list<array<string,mixed>> $pages @var string $csrf */
$where = ['main' => 'Menü oben', 'footer' => 'Fußzeile', 'none' => 'nicht verlinkt'];
$titles = [];
foreach ($pages as $pg) {
    $titles[mb_strtolower(trim((string) $pg['title']))][] = (string) $pg['slug'];
}
?>
<section class="hero">
  <p class="eyebrow">Seiten</p>
  <h1>Alle <em>Seiten</em></h1>
  <p class="intro">Alle Seiten der öffentlichen Seite, auch Impressum, Datenschutz und importierte. Menü und Fußzeile stellst du unter <a href="/admin/menue">Menü &amp; Fußzeile</a> ein, eine neue Seite legst du unter <a href="/admin/seiten/neu">Neue Seite</a> an. Die <a href="/admin/startseite">Startseite</a> hat einen eigenen Editor.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
</section>
<section>
<?php if ($pages === []): ?>
  <p class="muted">Noch keine Seiten. <a href="/admin/seiten/neu">Neue Seite anlegen</a>.</p>
<?php else: ?>
  <p class="small muted"><?= count($pages) ?> Seite<?= count($pages) === 1 ? '' : 'n' ?>, nach Titel geordnet.</p>
  <ul class="rows">
<?php foreach ($pages as $pg):
    $dup = count($titles[mb_strtolower(trim((string) $pg['title']))] ?? []) > 1; ?>
    <li class="row">
      <div class="rowmain">
        <a href="/admin/seiten/<?= (int) $pg['id'] ?>"><strong><?= $e((string) $pg['title']) ?></strong></a>
        <span class="mono small muted">/<?= $e((string) $pg['slug']) ?>/ · <?= $pg['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?> · <?= $e($where[$pg['nav']] ?? 'nicht verlinkt') ?></span>
<?php if ($dup): ?>        <span class="small warn">Doppelt? Es gibt noch eine Seite mit dem Titel „<?= $e((string) $pg['title']) ?>“ (<?= $e(implode(', ', array_map(static fn (string $s): string => '/' . $s . '/', array_diff($titles[mb_strtolower(trim((string) $pg['title']))], [(string) $pg['slug']])))) ?>). Die richtige behalten, die andere im Bearbeiten-Formular löschen.</span>
<?php endif; ?>
      </div>
      <div class="row-actions">
        <a class="btn small ghost" href="/admin/seiten/<?= (int) $pg['id'] ?>">Bearbeiten</a>
        <a class="btn small ghost" href="/admin/vorschau/<?= $e((string) $pg['slug']) ?>">Vorschau</a>
      </div>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
