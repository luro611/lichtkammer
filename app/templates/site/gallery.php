<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $g */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
$n = count($g['photos']);
?>
<div class="wrap block">
  <p class="eyebrow"><a href="<?= $e($h->url('/galerien/')) ?>">Galerien</a></p>
  <h1 class="h-page"><?= $e((string) $g['title']) ?></h1>
  <p class="meta mono"><?= $e(trim($h->yearLabel($g) . ' · ' . $h::count($n), ' ·')) ?></p>
<?php if (trim((string) $g['description']) !== ''): ?>  <div class="prose lead"><?= \Lichtkammer\Site\Markdown::render((string) $g['description']) ?></div>
<?php endif; ?>
  <ul class="grid" data-lightbox aria-label="Fotos in dieser Galerie">
<?php foreach ($g['photos'] as $i => $p): $x = $h->exif($g, $p);
    $meta = trim(implode(' · ', array_filter([(string) $p['place'], $p['year'] ? (string) $p['year'] : ''])), ' ·'); ?>
    <li class="t <?= $e($h::ratioClass($p)) ?>"><a id="foto-<?= (int) $p['id'] ?>" href="<?= $e($h->fullUrl($p)) ?>"
       data-id="<?= (int) $p['id'] ?>" data-srcset="<?= $e($h->lightboxSet($p)) ?>" data-w="<?= (int) $p['width'] ?>" data-h="<?= (int) $p['height'] ?>"
       data-alt="<?= $e((string) $p['alt_text']) ?>" data-title="<?= $e((string) $p['title']) ?>" data-caption="<?= $e((string) $p['caption']) ?>"
       data-meta="<?= $e($meta) ?>" data-photographer="<?= $e((string) $p['photographer']) ?>"
       data-gear="<?= $e($x['gear']) ?>" data-settings="<?= $e($x['settings']) ?>" data-taken="<?= $e($x['taken']) ?>"><?= $h->picture($p, ['sizes' => '(min-width:1200px) 480px, (min-width:700px) 33vw, 50vw', 'maxw' => 960, 'loading' => $i < 6 ? 'eager' : 'lazy']) ?></a></li>
<?php endforeach; ?>
  </ul>
</div>
