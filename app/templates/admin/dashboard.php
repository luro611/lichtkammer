<?php /** @var bool $live @var int $changes @var callable $e @var array<string,mixed> $user @var int $remaining @var ?array<string,mixed> $chain */ ?>
<section class="hero">
  <p class="eyebrow">Verwaltung</p>
  <h1>Willkommen, <em><?= $e((string) ($user['display_name'] !== '' ? $user['display_name'] : $user['username'])) ?></em></h1>
  <p class="intro">Angemeldet als <span class="mono"><?= $e((string) $user['username']) ?></span> (<?= $user['role'] === 'admin' ? 'Administrator' : 'Redaktion' ?>). Fotos und Galerien verwaltest du unter <a href="/admin/fotos">Fotos</a> und <a href="/admin/galerien">Galerien</a>. Seiten und Einstellungen findest du unter <a href="/admin/seiten">Seiten</a>; sichtbar wird alles erst mit dem <a href="/admin/veroeffentlichen">Veröffentlichen</a>.</p>
  <?php if (!$live): ?><div class="notice" role="note">Die öffentliche Seite ist noch nicht veröffentlicht. <a href="/admin/veroeffentlichen">Zum Veröffentlichen</a></div>
  <?php elseif ($changes > 0): ?><div class="notice" role="note"><?= (int) $changes ?> unveröffentlichte Änderung(en). <a href="/admin/veroeffentlichen">Ansehen und veröffentlichen</a></div><?php endif; ?>
  <?php if ($remaining <= 3): ?><div class="notice" role="note">Nur noch <?= (int) $remaining ?> Wiederherstellungscode(s) übrig. Neue erzeugst du unter „Mein Konto“.</div><?php endif; ?>
  <?php if ($chain !== null): ?>
  <p class="muted mono">Protokoll: <?= $chain['ok'] ? 'unversehrt' : 'Lücke ab Eintrag ' . (int) $chain['broken_at'] ?> · <?= (int) $chain['count'] ?> Einträge<?= $chain['last_at'] ? ', letzter ' . $e(gmdate('Y-m-d H:i', (int) $chain['last_at'])) . ' UTC' : '' ?></p>
  <?php endif; ?>
</section>
