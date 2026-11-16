<?php /** @var callable $e @var array<string,mixed> $plan @var list<array<string,mixed>> $releases @var ?string $current @var ?string $error @var ?string $ok @var string $csrf @var list<array<string,mixed>> $drafts @var list<array<string,mixed>> $draftGalleries */
$fmt = static fn (string $id): string => preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})/', $id, $m) === 1 ? "$m[3].$m[2].$m[1], $m[4]:$m[5] UTC" : $id;
?>
<section class="hero">
  <p class="eyebrow">Öffentliche Seite</p>
  <h1><em>Veröffentlichen</em></h1>
  <p class="intro">Erst mit dem Veröffentlichen wird die Besucher-Seite neu erzeugt. Bis dahin sieht man Änderungen nur in der <a href="/admin/vorschau">Vorschau</a>. Die vorherigen Fassungen bleiben erhalten; eine davon lässt sich mit einem Klick zurückholen.</p>
<?php if ($ok): ?><div class="notice" role="status"><?= $e($ok) ?> <a href="/" target="_blank" rel="noopener">Seite ansehen</a></div><?php endif; ?>
<?php if ($error): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<?php if ($plan['errors'] !== []): ?>
  <div class="notice error" role="alert"><strong>Das muss vorher behoben werden:</strong><ul><?php foreach ($plan['errors'] as $m): ?><li><?= $e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($plan['warnings'] !== []): ?>
  <div class="notice" role="note"><strong>Hinweise:</strong><ul><?php foreach ($plan['warnings'] as $m): ?><li><?= $e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
  <h2 class="h2s">Änderungen</h2>
<?php if ($plan['changes'] === []): ?>
  <p class="muted"><?= $current === null ? 'Noch nichts veröffentlicht.' : 'Gegenüber der Live-Seite gibt es keine Änderungen.' ?></p>
<?php else: ?>
  <ul class="changes">
<?php foreach ($plan['changes'] as $c): ?>    <li><span class="badge"><?= $e($c['kind']) ?></span> <?= $e($c['label']) ?></li>
<?php endforeach; ?>  </ul>
<?php endif; ?>
<?php if ($drafts !== [] || $draftGalleries !== []): ?>
  <div class="notice" role="note"><strong>Noch nicht online, weil der Status „Entwurf“ ist:</strong>
    <ul>
<?php foreach ($drafts as $d): ?>      <li>Seite <a href="/admin/seiten/<?= (int) $d['id'] ?>"><?= $e((string) $d['title']) ?></a></li>
<?php endforeach; ?>
<?php foreach ($draftGalleries as $d): ?>      <li>Galerie <a href="/admin/galerien/<?= (int) $d['id'] ?>"><?= $e((string) $d['title']) ?></a></li>
<?php endforeach; ?>
    </ul>
    <span class="small">Zum Veröffentlichen dort den Status auf „veröffentlichen“ stellen und speichern.</span></div>
<?php endif; ?>
  <p class="mono small muted"><?= (int) $plan['galleries'] ?> Galerie(n), <?= (int) $plan['photos'] ?> Foto(s), <?= (int) $plan['files'] ?> Dateien</p>
  <form method="post" action="/admin/veroeffentlichen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<?php if ($plan['changes'] === [] && $current !== null && $plan['errors'] === []): ?>
    <button class="btn ghost" type="button" disabled aria-disabled="true">✓ Alles veröffentlicht, keine Änderungen</button>
<?php else: ?>
    <button class="btn" type="submit"<?= $plan['errors'] !== [] ? ' disabled' : '' ?>>Jetzt veröffentlichen</button>
<?php endif; ?>
  </form>
</section>
<section>
  <h2 class="h2s">Fassungen</h2>
<?php if ($releases === []): ?>
  <p class="muted">Noch keine Fassung.</p>
<?php else: ?>
  <ul class="rows">
<?php foreach ($releases as $r): ?>
    <li class="row">
      <div class="rowmain"><strong><?= $e($fmt($r['id'])) ?></strong>
        <span class="mono small muted"><?= (int) $r['galleries'] ?> Galerie(n) · <?= (int) $r['photos'] ?> Foto(s) · <?= (int) $r['files'] ?> Dateien<?= $r['active'] ? ' · live' : '' ?></span></div>
<?php if (!$r['active']): ?>
      <form method="post" action="/admin/veroeffentlichen/zuruecksetzen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="fassung" value="<?= $e($r['id']) ?>"><button class="btn small ghost" type="submit" data-confirm="Diese Fassung wieder live schalten?">Diese Fassung zurückholen</button></form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
