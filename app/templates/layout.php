<?php /** @var string $title @var string $content @var \Lichtkammer\Config $config @var callable $e */
$user = $user ?? null;
$banners = $banners ?? [];
$csrf = $csrf ?? '';
?>
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
<?php if ($user !== null): ?>
  <nav class="nav" aria-label="Verwaltung">
    <a href="/admin">Übersicht</a>
<?php if (($user['role'] ?? '') === 'admin'): ?>    <a href="/admin/users">Konten</a>
<?php endif; ?>
    <a href="/admin/log">Protokoll</a>
    <a href="/admin/account">Mein Konto</a>
  </nav>
  <form class="logout" method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn ghost" type="submit">Abmelden</button></form>
<?php endif; ?>
</div></header>
<?php foreach ($banners as $b): ?>
<div class="banner" role="alert"><div class="shell"><?= $e($b) ?></div></div>
<?php endforeach; ?>
<main class="shell"><?= $content ?></main>
<footer class="foot"><div class="shell">
  <span class="mono">Version <?= $e(\Lichtkammer\Config::VERSION) ?></span>
  <span>Website: <a class="luro" href="https://lurohome.de" aria-label="luro · lurohome.de">lu<em>ro</em></a></span>
</div></footer>
</body>
</html>
