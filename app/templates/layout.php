<?php /** @var string $title @var string $content @var \Lichtkammer\Config $config @var callable $e @var callable $asset */
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
<link rel="stylesheet" href="<?= $e($asset('/assets/css/app.css')) ?>">
<script src="<?= $e($asset('/assets/js/design.js')) ?>"></script>
</head>
<body>
<header class="bar"><div class="shell">
  <a class="brand" href="/">Lichtkammer</a>
<?php if ($user !== null): ?>
  <nav class="nav" aria-label="Verwaltung">
    <a href="/admin">Übersicht</a>
    <a href="/admin/fotos">Fotos</a>
    <a href="/admin/galerien">Galerien</a>
    <a href="/admin/seiten">Seiten</a>
    <a href="/admin/veroeffentlichen">Veröffentlichen</a>
    <a href="/admin/papierkorb">Papierkorb</a>
<?php if (($user['role'] ?? '') === 'admin'): ?>    <a href="/admin/users">Konten</a>
<?php endif; ?>
    <a href="/admin/log">Protokoll</a>
    <a href="/admin/account">Mein Konto</a>
  </nav>
<?php endif; ?>
  <div class="tools">
    <div class="pill" role="group" aria-label="Design"><button type="button" data-design-set="lurohome" aria-pressed="true">luro</button><button type="button" data-design-set="rothe" aria-pressed="false">rothe.it</button></div>
    <div class="pill" role="group" aria-label="Farbschema"><button type="button" data-theme-set="light" aria-label="Hell" aria-pressed="true">☀</button><button type="button" data-theme-set="dark" aria-label="Dunkel" aria-pressed="false">☾</button></div>
  </div>
<?php if ($user !== null): ?>
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
<?php if ($user !== null): ?><script src="<?= $e($asset('/assets/js/admin.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
