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
<?php if (!empty($favicon)): ?><link rel="icon" href="<?= $e($favicon) ?>" type="<?= $e(preg_match('~^data:([a-z+/]+);~', $favicon, $fm) === 1 ? $fm[1] : 'image/svg+xml') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?= $e($asset('/assets/css/app.css')) ?>">
<script src="<?= $e($asset('/assets/js/design.js')) ?>"></script>
</head>
<body<?= $user !== null ? ' class="app"' : '' ?>>
<?php if ($user === null): ?>
<header class="bar"><div class="shell">
  <a class="brand" href="/"><?= $e($siteName ?? 'Lichtkammer') ?></a>
  <?php include __DIR__ . '/partials/designtools.php'; ?>
</div></header>
<?php else:
    $path = (string) ($navPath ?? '');
    $on = static fn (string ...$prefixes): bool => array_reduce($prefixes, static fn (bool $c, string $p): bool => $c || $path === $p || str_starts_with($path, $p . '/'), false);
    $cur = static fn (bool $active): string => $active ? ' aria-current="page"' : '';
    $groups = [
        'Inhalte' => [['/admin/medien', 'Medien'], ['/admin/fotos', 'Fotos'], ['/admin/galerien', 'Galerien'], ['/admin/fotografen', 'Fotografen']],
        'Seiten' => [['/admin/startseite', 'Startseite'], ['/admin/seiten', 'Alle Seiten', ['/admin/seiten/neu']], ['/admin/seiten/neu', 'Neue Seite'], ['/admin/menue', 'Menü & Fußzeile']],
        'Verwaltung' => array_merge([['/admin/logos', 'Logos'], ['/admin/einstellungen', 'Einstellungen'], ['/admin/papierkorb', 'Papierkorb']], ($user['role'] ?? '') === 'admin' ? [['/admin/users', 'Konten'], ['/admin/import', 'Import']] : [], [['/admin/log', 'Protokoll'], ['/admin/account', 'Mein Konto']]),
    ];
?>
<div class="frame">
<aside class="side">
  <a class="brand" href="/admin"<?= !empty($logoPair['light']) ? ' aria-label="' . $e($siteName ?? 'Lichtkammer') . ', Übersicht"' : '' ?>><?php if (!empty($logoPair['light'])): ?><img class="logo logo-light" src="<?= $e($logoPair['light']) ?>" alt="" height="44"><img class="logo logo-dark" src="<?= $e($logoPair['dark']) ?>" alt="" height="44"><?php else: ?><?= $e($siteName ?? 'Lichtkammer') ?><?php endif; ?></a>
  <a class="btn small <?= !empty($publishDirty) ? 'publish' : 'ghost published' ?>" href="/admin/veroeffentlichen"<?= $cur($on('/admin/veroeffentlichen')) ?><?= empty($publishDirty) ? ' title="Die öffentliche Seite ist auf dem aktuellen Stand."' : '' ?>><?= empty($publishDirty) ? '✓ Veröffentlicht' : 'Veröffentlichen' ?></a>
  <nav class="sidenav" aria-label="Verwaltung">
    <a href="/admin"<?= $cur($path === '/admin') ?>>Übersicht</a>
<?php foreach ($groups as $gname => $items): ?>
    <p class="sidehead"><?= $e($gname) ?></p>
<?php foreach ($items as $item): [$href, $label] = $item; $not = $item[2] ?? []; ?>    <a class="sub" href="<?= $e($href) ?>"<?= $cur($on($href) && !$on(...$not)) ?>><?= $e($label) ?></a>
<?php endforeach; endforeach; ?>
  </nav>
  <div class="sidefoot">
    <?php include __DIR__ . '/partials/designtools.php'; ?>
    <form class="logout" method="post" action="/admin/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn ghost small" type="submit">Abmelden</button></form>
  </div>
</aside>
<div class="content">
<?php endif; ?>
<?php foreach ($banners as $b): ?>
<div class="banner" role="alert"><div class="shell"><?= $e($b) ?></div></div>
<?php endforeach; ?>
<?php if ($user !== null && !empty($publishDirty) && !str_starts_with((string) ($navPath ?? ''), '/admin/veroeffentlichen')): ?>
<div class="pubband" role="status"><div class="shell">
  <span><strong><?= (int) ($publishN ?? 1) ?> Änderung<?= (int) ($publishN ?? 1) === 1 ? '' : 'en' ?></strong> <?= (int) ($publishN ?? 1) === 1 ? 'ist' : 'sind' ?> noch nicht online.</span>
  <form method="post" action="/admin/veroeffentlichen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn small" type="submit" data-busy="Wird veröffentlicht …">Jetzt veröffentlichen</button></form>
  <a class="btn small ghost" href="/admin/vorschau">Ansehen</a>
</div></div>
<?php endif; ?>
<main class="shell"><?= $content ?></main>
<footer class="foot"><div class="shell">
  <span class="mono"><a href="https://lurohome.de/lichtkammer/" target="_blank" rel="noopener">Lichtkammer</a> <?= $e(\Lichtkammer\Config::VERSION) ?></span>
  <span><a class="luro" href="https://lurohome.de" target="_blank" rel="noopener" aria-label="lurohome.de (öffnet in neuem Fenster)">lu<em>ro</em>home</a></span>
</div></footer>
<?php if ($user !== null): ?></div></div>
<script src="<?= $e($asset('/assets/js/admin.js')) ?>" defer></script>
<?php endif; ?>
</body>
</html>
