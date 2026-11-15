<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $d @var array $years @var array $places @var array $people */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
?>
<div class="wrap block">
  <h1 class="h-page">Galerien</h1>
  <form class="filters" data-filters hidden aria-label="Galerien filtern">
<?php foreach ([['jahre', 'Jahr', $years], ['orte', 'Ort', $places], ['fotografen', 'Fotograf', $people]] as [$key, $label, $opts]): if ($opts === []) { continue; } ?>
    <label class="f"><span><?= $e($label) ?></span>
      <select data-filter="<?= $e($key) ?>"><option value="">Alle</option>
<?php foreach ($opts as $val => $text): ?>        <option value="<?= $e((string) $val) ?>"><?= $e((string) $text) ?></option>
<?php endforeach; ?>      </select></label>
<?php endforeach; ?>
    <button class="btn ghost" type="button" data-filter-reset>Zurücksetzen</button>
    <p class="count mono" data-filter-count aria-live="polite"></p>
  </form>
<?php if ($d['galleries'] === []): ?>
  <p class="empty">Noch keine Galerien.</p>
<?php else: ?>
  <ul class="cards" data-cards>
<?php foreach ($d['galleries'] as $g): ?><?= $h->card($g) ?>
<?php endforeach; ?>  </ul>
  <p class="empty" data-filter-none hidden>Keine Galerie passt zu dieser Auswahl.</p>
<?php endif; ?>
</div>
