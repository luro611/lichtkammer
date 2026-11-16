<?php /** @var callable $e @var string $rules @var bool $setAlt @var bool $overwriteAlt @var ?string $error @var ?array $plan @var ?string $ok @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow"><a href="/admin/galerien">Galerien</a></p>
  <h1>Zuordnung <em>nach Galerie</em></h1>
  <p class="intro">Setzt für alle Fotos einer Galerie den Fotografen und füllt den Alt-Text mit dem Namen der Galerie. Zuerst „Vorschau“: Sie zeigt, was geschehen würde, und ändert nichts.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($ok !== null): ?><p class="notice ok" role="status"><?= $e($ok) ?></p><?php endif; ?>
  <form class="form card" method="post" action="/admin/galerien/zuordnung">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Regeln (eine Zeile je Fotograf)</span><textarea name="regeln" rows="7" placeholder="Lutz: Berlin, Heidelberg, Tokio&#10;Mathias und Lutz: Ausstellung&#10;Rest: Mathias"><?= $e($rules) ?></textarea></label>
    <p class="small muted">Format „Fotograf: Stichwort, Stichwort“. Ein Stichwort passt, wenn es im Titel der Galerie vorkommt (Groß-/Kleinschreibung egal). Die erste passende Zeile gilt, also Besonderes zuerst. „Rest: Name“ gilt für alles andere, auch für Fotos ohne Galerie. Fehlende Fotografen werden angelegt.</p>
    <label class="field"><span><input type="checkbox" name="alt" value="1"<?= $setAlt ? ' checked' : '' ?>> Alt-Text aus dem Namen der Galerie setzen („Foto aus der Galerie …“)</span></label>
    <label class="field"><span><input type="checkbox" name="alt_ueberschreiben" value="1"<?= $overwriteAlt ? ' checked' : '' ?>> Auch vorhandene Alt-Texte ersetzen (sonst nur leere)</span></label>
    <div class="actions"><button class="btn ghost" type="submit" name="aktion" value="vorschau">Vorschau</button>
<?php if ($plan !== null && $plan['errors'] === []): ?>      <button class="btn" type="submit" name="aktion" value="anwenden" data-confirm="Zuordnung jetzt für alle Fotos anwenden? Der bisherige Fotograf wird dabei ersetzt.">Jetzt anwenden</button>
<?php endif; ?></div>
  </form>
<?php if ($plan !== null): ?>
<?php foreach ($plan['errors'] as $err): ?><p class="notice error"><?= $e($err) ?></p>
<?php endforeach; ?>
  <h2 class="h2s">Vorschau</h2>
<?php if ($plan['newNames'] !== []): ?><p class="small">Neu angelegt würde: <?= $e(implode(', ', $plan['newNames'])) ?></p><?php endif; ?>
  <ul class="rows">
<?php foreach ($plan['rows'] as $r): ?>
    <li class="row"><div class="rowmain"><strong><?= $e($r['gallery']) ?></strong> <span class="mono small muted"><?= (int) $r['photos'] ?> Foto(s)</span></div>
      <div class="row-actions"><?= $r['photographer'] === null ? '<span class="warn small">keine Regel trifft zu: bleibt unverändert</span>' : $e($r['photographer']) . ($r['isNew'] ? ' <span class="small muted">(neu)</span>' : '') ?></div></li>
<?php endforeach; ?>
  </ul>
  <p class="small muted">Fotos ohne Galerie: <?= (int) $plan['restPhotos'] ?>.</p>
<?php endif; ?>
</section>
