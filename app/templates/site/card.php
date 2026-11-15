<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $g */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
$n = count($g['photos']);
?>
<li class="card" data-jahre="<?= $e(implode(' ', $g['years'])) ?>" data-orte="<?= $e(implode(' ', array_keys($g['places']))) ?>" data-fotografen="<?= $e(implode(' ', array_keys($g['people']))) ?>">
  <a href="<?= $e($h->url('/galerien/' . $g['slug'] . '/')) ?>">
    <span class="card-img"><?php if ($g['cover'] !== null): ?><?= $h->picture($g['cover'], ['sizes' => '(min-width:900px) 360px, (min-width:600px) 50vw, 100vw', 'maxw' => 960, 'alt' => '']) ?><?php endif; ?></span>
    <span class="card-text">
      <span class="card-title"><?= $e((string) $g['title']) ?></span>
      <span class="card-meta mono"><?= $e(trim($h->yearLabel($g) . ' · ' . $h::count($n), ' ·')) ?></span>
    </span>
  </a>
</li>
