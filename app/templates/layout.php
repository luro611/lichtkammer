<?php /** @var string $title @var string $content @var \Lichtkammer\Config $config @var callable $e */ ?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($title) ?></title>
<?php if ($config->noindex()): ?><meta name="robots" content="noindex,nofollow">
<?php endif; ?>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="bar"><div class="shell">
  <a class="brand" href="/"><?= $e($config->siteName()) ?></a>
</div></header>
<main class="shell"><?= $content ?></main>
<footer class="foot"><div class="shell">
  <span class="mono">Version <?= $e(\Lichtkammer\Config::VERSION) ?></span>
  <span>Website: <a class="luro" href="https://lurohome.de" aria-label="luro · lurohome.de">lu<em>ro</em></a></span>
</div></footer>
</body>
</html>
