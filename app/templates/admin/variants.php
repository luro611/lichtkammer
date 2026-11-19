<?php /** @var callable $e @var callable $asset @var string $csrf @var ?string $error @var array<string,mixed> $st @var ?array<string,mixed> $res @var ?array<string,mixed> $run @var bool $engine */
$all = $st['total'] > 0 && $st['ok'] === $st['total'];
?>
<section class="hero">
  <p class="eyebrow">Verwaltung</p>
  <h1>Bildvarianten <em>neu erzeugen</em></h1>
  <p class="intro">Die Seite zeigt nicht die Originale, sondern kleinere Bildvarianten (ohne Aufnahmedaten und ohne Standort). Eine Sicherung enthält nur die Originale. Nach dem Einspielen einer Sicherung müssen die Varianten deshalb neu erzeugt werden, sonst zeigt die veröffentlichte Seite keine Bilder. Die Originale werden dabei nur gelesen, nie verändert. Der Vorgang arbeitet in kleinen Schritten und lässt sich jederzeit fortsetzen.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($error !== null): ?>
  <div class="notice error" role="alert"><?= $e($error) ?></div>
<?php endif; ?>
  <div class="card form" id="var-box" data-csrf="<?= $e($csrf) ?>" data-total="<?= (int) $st['total'] ?>" data-done="<?= (int) $st['position'] ?>">
    <p id="var-summary" class="notice<?= $all ? ' ok' : ($st['total'] === 0 ? '' : ' error') ?>" role="status"><strong><?= (int) $st['ok'] ?> von <?= (int) $st['total'] ?> Fotos haben Bildvarianten.</strong><?= $all ? '' : ($st['total'] === 0 ? ' Es gibt noch keine Fotos.' : ' Die übrigen Fotos erscheinen erst auf der Seite, wenn ihre Varianten erzeugt sind.') ?></p>
<?php if ($res !== null): ?>
    <p class="notice ok" role="status">Dieser Durchgang: <?= (int) $res['created'] ?> Foto(s) neu erzeugt, <?= (int) $res['skipped'] ?> schon fertig<?= $res['failed'] + $res['noorig'] > 0 ? ', <strong>' . (int) ($res['failed'] + $res['noorig']) . ' mit Problem</strong>' : '' ?>. <?= $res['done'] ? 'Fertig.' : 'Es geht noch weiter: unten „Weiter“.' ?></p>
<?php elseif ($run !== null && !$st['running']): ?>
    <p class="small muted">Letzter Durchgang: <?= (int) $run['created'] ?> Foto(s) neu erzeugt, <?= (int) $run['skipped'] ?> schon fertig, <?= (int) ($run['failed'] + $run['noorig']) ?> mit Problem.</p>
<?php endif; ?>
    <p><progress id="var-bar" max="<?= max(1, (int) $st['total']) ?>" value="<?= (int) $st['position'] ?>"></progress>
      <span id="var-text" class="small muted" role="status"><?= $st['running'] ? (int) $st['position'] . ' von ' . (int) $st['total'] . ' Fotos geprüft. Der Durchgang ist angefangen und lässt sich fortsetzen.' : 'Noch kein Durchgang läuft.' ?></span></p>
<?php if (!$engine): ?>
    <div class="notice error" role="alert">Auf diesem Server ist keine Bildbibliothek (Imagick oder GD) verfügbar. Siehe Systemprüfung.</div>
<?php else: ?>
    <div class="actions">
<?php if ($st['running']): ?>
      <form method="post" action="/admin/varianten/weiter" class="var-form"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn" id="var-run" type="submit">Weiter</button></form>
<?php else: ?>
      <form method="post" action="/admin/varianten/weiter" class="var-form"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="neu" value="1">
        <button class="btn" id="var-run" type="submit">Fehlende Bildvarianten erzeugen</button></form>
<?php endif; ?>
      <form method="post" action="/admin/varianten/weiter" class="var-form" id="var-force-form"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="neu" value="1"><input type="hidden" name="erzwingen" value="1">
        <button class="btn ghost" id="var-force" type="submit" data-confirm="Wirklich für alle Fotos neu erzeugen? Das dauert länger. Vorhandene Varianten werden ersetzt, die Originale bleiben unberührt.">Alle neu erzeugen (erzwingen)</button></form>
    </div>
    <p class="small muted">Das Fenster darf geöffnet bleiben, bis „Fertig“ steht. Bricht die Verbindung ab oder wird die Seite neu geladen, geht es mit „Weiter“ an derselben Stelle weiter. Ohne Skript im Browser kommt je Klick ein Stück.</p>
<?php endif; ?>
    <ul class="rows" id="var-log" aria-live="polite"></ul>
  </div>
<?php if ($st['problems'] !== []): ?>
  <h2 class="h-sec">Probleme</h2>
  <div class="card form">
    <p class="muted">Diese Fotos wurden übersprungen. Fehlt ein Original, hilft nur die Sicherung: Verwaltung → Sicherung (Originale zurückholen).</p>
    <ul class="rows">
<?php foreach (array_slice($st['problems'], 0, 200) as $pr): ?>
      <li class="row"><span class="mono small">#<?= (int) $pr['id'] ?></span> <?= $e($pr['name']) ?>: <?= $e($pr['text']) ?></li>
<?php endforeach; ?>
<?php if (count($st['problems']) > 200): ?>
      <li class="row muted">… und <?= count($st['problems']) - 200 ?> weitere.</li>
<?php endif; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($all): ?>
  <p>Als Nächstes: <a href="/admin/veroeffentlichen">Veröffentlichen</a>, damit die Seite die Bilder zeigt.</p>
<?php endif; ?>
  <script src="<?= $e($asset('/assets/js/varianten.js')) ?>" defer></script>
</section>
