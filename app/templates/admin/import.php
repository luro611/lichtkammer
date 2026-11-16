<?php /** @var callable $e @var string $csrf @var array<string,mixed> $pack @var ?array<string,mixed> $counts @var string $dir @var ?array<string,mixed> $report @var ?string $error */
$m = $pack['m'];
$running = $counts !== null && $counts['done'] < $counts['photos'];
$ready = $counts !== null && $counts['done'] >= $counts['photos'] && !$counts['finished'];
?>
<section class="hero">
  <p class="eyebrow">Umzug</p>
  <h1>Import aus <em>WordPress</em></h1>
  <p class="intro">Hier wird ein Importpaket übernommen, das mit <span class="mono">tools/wp-export/wp_export.py</span> auf dem eigenen Rechner erzeugt wurde. Fotos laufen Stück für Stück durch dieselbe Bildverarbeitung wie jeder Upload. Galerien und Seiten entstehen als <strong>Entwurf</strong>; veröffentlicht wird erst nach der Durchsicht. Das Paket lässt sich beliebig oft erneut starten: Fertiges wird nicht doppelt angelegt.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($pack['state'] === 'none'): ?>
  <div class="card form">
    <h2 class="h2s">Noch kein Paket</h2>
    <p>Den Ordner mit <span class="mono">manifest.json</span>, <span class="mono">bilder/</span> und <span class="mono">dateien/</span> per FTPS in diesen Ordner auf dem Server legen:</p>
    <p class="mono wrap"><?= $e($dir) ?></p>
    <p class="small muted">Der Ordner ist für Besucher gesperrt. Liegt er im Webroot, genügt <span class="mono">LK_IMPORT_DIR</span> in <span class="mono">app/.env</span> (Beispiel: <span class="mono">…/import</span>). Danach diese Seite neu laden.</p>
  </div>
<?php elseif ($pack['state'] === 'invalid'): ?>
  <div class="notice error" role="alert"><strong>Das Paket ist nicht lesbar:</strong> <?= $e((string) $pack['error']) ?></div>
<?php else: ?>
  <div class="card form" id="import-box" data-csrf="<?= $e($csrf) ?>" data-total="<?= (int) $counts['photos'] ?>" data-done="<?= (int) $counts['done'] ?>">
    <h2 class="h2s">Paket</h2>
    <p class="mono small muted"><?= (int) $counts['photos'] ?> Fotos · <?= (int) $counts['galleries'] ?> Galerien · <?= (int) $counts['pages'] ?> Seiten · <?= count($m['files']) ?> Dateien · Quelle <?= $e((string) $m['source']) ?></p>
    <p><progress id="import-bar" max="<?= max(1, (int) $counts['photos']) ?>" value="<?= (int) $counts['done'] ?>"></progress>
      <span id="import-text" class="small muted" role="status"><?= (int) $counts['done'] ?> von <?= (int) $counts['photos'] ?> Fotos übernommen</span></p>
<?php if ($counts['finished']): ?>
    <p class="notice ok" role="status">Der Import ist abgeschlossen. Galerien und Seiten stehen als Entwurf bereit.</p>
<?php endif; ?>
    <div class="actions">
<?php if (!$counts['finished']): ?>
      <button class="btn" id="import-run" type="button"><?= $counts['done'] > 0 ? 'Fortsetzen' : 'Import starten' ?></button>
<?php else: ?>
      <button class="btn ghost" id="import-run" type="button">Noch einmal prüfen und ergänzen</button>
<?php endif; ?>
    </div>
    <ul class="rows" id="import-log" aria-live="polite"></ul>
    <noscript><p class="small">Für den Import wird JavaScript gebraucht.</p></noscript>
  </div>
  <script src="<?= $e($asset('/assets/js/import.js')) ?>" defer></script>
<?php endif; ?>
</section>
<?php if ($report !== null && $counts !== null && $counts['done'] > 0): ?>
<section>
  <h2 class="h2s">Durchsicht</h2>
  <ul class="stats">
    <li><strong><?= (int) $report['photos'] ?></strong> Fotos übernommen</li>
    <li><strong><?= (int) $report['missing_alt'] ?></strong> ohne Alt-Text<?php if ($report['missing_alt'] > 0): ?> (<a href="/admin/fotos?filter=ohne_alt">anzeigen</a>)<?php endif; ?></li>
    <li><strong><?= (int) $report['no_photographer'] ?></strong> ohne Fotograf</li>
    <li><strong><?= (int) $report['unused'] ?></strong> in keiner Galerie (<a href="/admin/fotos?filter=ohne_galerie">anzeigen</a>)</li>
    <li><strong><?= (int) $report['duplicates'] ?></strong> doppelt im Paket (gleicher Inhalt)</li>
    <li><strong><?= (int) $report['redirects'] ?></strong> Weiterleitungen alter Adressen</li>
  </ul>
<?php if ($report['missing_alt'] > 0): ?>
  <div class="notice" role="note">Galerien lassen sich erst veröffentlichen, wenn jedes Foto einen Alt-Text hat. In den Galerien unten ist sichtbar, wo noch Lücken sind. Mehrere Fotos auf einmal: in der Galerie auswählen, dann „Auswahl bearbeiten“.</div>
<?php endif; ?>
<?php if ($report['failed'] !== []): ?>
  <div class="notice error" role="alert"><strong>Diese Fotos konnten nicht übernommen werden:</strong>
    <ul><?php foreach ($report['failed'] as $f): ?><li>WordPress-Nr. <?= (int) $f['wp'] ?>: <?= $e(preg_replace('/^Fehler: /', '', (string) $f['note'])) ?><?php if ($f['photo'] > 0): ?> (<a href="/admin/fotos/<?= (int) $f['photo'] ?>">Foto</a>)<?php endif; ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
  <h3 class="h2s">Galerien</h3>
<?php if ($report['galleries'] === []): ?>
  <p class="muted">Noch keine Galerien angelegt.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($report['galleries'] as $g): ?>
    <li class="row"><div class="rowmain"><a href="/admin/galerien/<?= (int) $g['id'] ?>"><strong><?= $e((string) $g['title']) ?></strong></a>
      <span class="mono small muted"><?= (int) $g['n'] ?> Fotos · <?= $g['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?> · <?= (int) $g['noalt'] ?> ohne Alt-Text · <?= (int) $g['noph'] ?> ohne Fotograf · /galerien/<?= $e((string) $g['slug']) ?>/</span></div></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
  <h3 class="h2s">Seiten</h3>
<?php if ($report['pages'] === []): ?>
  <p class="muted">Noch keine Seiten angelegt.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($report['pages'] as $pg): ?>
    <li class="row"><div class="rowmain"><a href="/admin/seiten/<?= (int) $pg['id'] ?>"><strong><?= $e((string) $pg['title']) ?></strong></a>
      <span class="mono small muted"><?= $pg['status'] === 'published' ? 'veröffentlicht' : 'Entwurf' ?><?= $pg['empty'] ? ' · leer, bitte prüfen' : '' ?><?= $pg['noalt'] > 0 ? ' · ' . (int) $pg['noalt'] . ' Bild(er) ohne Alt-Text' : '' ?> · /<?= $e((string) $pg['slug']) ?>/</span></div></li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
<?php if ($counts['finished'] ?? false): ?>
  <form class="form card" method="post" action="/admin/import/loeschen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <p>Wenn alles geprüft ist, kann das Paket auf dem Server gelöscht werden (die Bilddateien liegen nur noch doppelt dort). Die übernommenen Fotos bleiben unverändert.</p>
    <div class="actions"><button class="btn ghost" type="submit" data-confirm="Importpaket auf dem Server löschen? Die übernommenen Fotos bleiben.">Importpaket löschen</button></div>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>
