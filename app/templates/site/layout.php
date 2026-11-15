<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $page @var array $nav @var string $site @var \Lichtkammer\Config $config @var callable $asset @var string $canonical @var string $ogImage @var bool $hasGalleries */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
$fav = $h->favicon();
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($page['title']) ?></title>
<?php if (($page['desc'] ?? '') !== ''): ?><meta name="description" content="<?= $e($page['desc']) ?>">
<?php endif; ?>
<?php if ($config->noindex() || $h->isPreview() || ($page['robots'] ?? '') !== ''): ?><meta name="robots" content="noindex,nofollow">
<?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= $e($canonical) ?>">
<meta property="og:title" content="<?= $e($page['title']) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $e($canonical) ?>">
<?php if ($ogImage !== ''): ?><meta property="og:image" content="<?= $e($ogImage) ?>">
<?php endif; endif; ?>
<?php if ($fav !== ''): ?><link rel="icon" href="<?= $e($fav) ?>" type="image/svg+xml">
<?php endif; ?>
<link rel="stylesheet" href="<?= $e($asset('/assets/css/site.css')) ?>">
<script src="<?= $e($asset('/assets/js/site-theme.js')) ?>"></script>
<script src="<?= $e($asset('/assets/js/site.js')) ?>" defer></script>
</head>
<body class="<?= $e($page['class'] ?? '') ?>">
<a class="skip" href="#inhalt">Zum Inhalt</a>
<?php if ($h->isPreview()): ?><div class="previewbar" role="status">Vorschau, noch nicht veröffentlicht. <a href="/admin">Zurück zur Verwaltung</a></div>
<?php endif; ?>
<header class="top"><div class="wrap head">
  <a class="brand" href="<?= $e($h->url('/')) ?>" aria-label="<?= $e($site) ?>, Startseite"><?= $h->brand() ?></a>
  <nav class="menu" aria-label="Hauptmenü">
<?php if ($hasGalleries || $h->isPreview()): ?>    <a href="<?= $e($h->url('/galerien/')) ?>">Galerien</a>
<?php endif; foreach ($nav['main'] as $pg): ?>    <a href="<?= $e($h->url('/' . $pg['slug'] . '/')) ?>"><?= $e($pg['title']) ?></a>
<?php endforeach; ?>  </nav>
  <button class="themebtn" type="button" data-theme-toggle aria-label="Zwischen hellem und dunklem Erscheinungsbild wechseln"><span aria-hidden="true" class="ico-sun">☀</span><span aria-hidden="true" class="ico-moon">☾</span></button>
</div></header>
<main id="inhalt" tabindex="-1">
<?= $page['body'] ?>
</main>
<footer class="foot"><div class="wrap foot-in">
  <span class="mono">© <?= $e(gmdate('Y')) ?> <?= $e($site) ?></span>
<?php if ($nav['footer'] !== []): ?>  <nav aria-label="Rechtliches und mehr">
<?php foreach ($nav['footer'] as $pg): ?>    <a href="<?= $e($h->url('/' . $pg['slug'] . '/')) ?>"><?= $e($pg['title']) ?></a>
<?php endforeach; ?>  </nav>
<?php endif; ?>
  <span class="mono">Powered by Lichtkammer</span>
  <span>Website: <a class="luro" href="https://lurohome.de" aria-label="luro · lurohome.de">lu<em>ro</em></a></span>
</div></footer>
</body>
</html>
