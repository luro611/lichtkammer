<?php /** @var callable $e @var array<string,mixed> $user @var int $remaining @var ?array<string,mixed> $chain */ ?>
<section class="hero">
  <p class="eyebrow">Verwaltung</p>
  <h1>Willkommen, <em><?= $e((string) ($user['display_name'] !== '' ? $user['display_name'] : $user['username'])) ?></em></h1>
  <p class="intro">Angemeldet als <span class="mono"><?= $e((string) $user['username']) ?></span> (<?= $user['role'] === 'admin' ? 'Administrator' : 'Redaktion' ?>). Bilder, Alben und Veröffentlichen kommen in den nächsten Schritten.</p>
  <?php if ($remaining <= 3): ?><div class="notice" role="note">Nur noch <?= (int) $remaining ?> Wiederherstellungscode(s) übrig. Neue erzeugst du unter „Mein Konto“.</div><?php endif; ?>
  <?php if ($chain !== null): ?>
  <p class="muted mono">Protokoll: <?= $chain['ok'] ? 'unversehrt' : 'Lücke ab Eintrag ' . (int) $chain['broken_at'] ?> · <?= (int) $chain['count'] ?> Einträge<?= $chain['last_at'] ? ', letzter ' . $e(gmdate('Y-m-d H:i', (int) $chain['last_at'])) . ' UTC' : '' ?></p>
  <?php endif; ?>
</section>
