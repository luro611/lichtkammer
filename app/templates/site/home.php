<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $d */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
$hero = $d['hero'];
$title = (string) ($d['settings']['tagline'] !== '' ? $d['settings']['tagline'] : $d['settings']['site_name']);
?>
<section class="hero<?= $hero === null ? ' nohero' : '' ?>">
<?php if ($hero !== null): ?>
  <div class="hero-img">
<?php if ((string) $hero['blur_data_uri'] !== ''): ?>    <img class="blur" src="<?= $e((string) $hero['blur_data_uri']) ?>" alt="" aria-hidden="true">
<?php endif; ?>
    <?= $h->picture($hero, ['sizes' => '(min-width:1200px) 1160px, 100vw', 'maxw' => 2400, 'loading' => 'eager', 'priority' => true, 'alt' => '', 'class' => 'hero-pic']) ?>
  </div>
<?php endif; ?>
  <div class="hero-text"><h1<?= $d['settings']['hero_show'] ? '' : ' class="sr"' ?>><?= $e($title) ?></h1></div>
</section>
<?php if ($d['galleries'] !== []): ?>
<section class="wrap block" aria-labelledby="gal-h">
  <div class="block-head"><h2 id="gal-h">Galerien</h2><a class="more" href="<?= $e($h->url('/galerien/')) ?>">Alle Galerien</a></div>
  <ul class="cards">
<?php foreach ($d['galleries'] as $g): ?><?= $h->card($g) ?>
<?php endforeach; ?>  </ul>
</section>
<?php endif; ?>
