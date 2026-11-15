<?php /** @var callable $e @var list<array<string,mixed>> $rows @var ?array<string,mixed> $chain */ ?>
<section class="wide">
  <p class="eyebrow">Sicherheit</p>
  <h1 class="h-page">Proto<em>koll</em></h1>
  <?php if ($chain !== null): ?>
  <p class="notice <?= $chain['ok'] ? '' : 'error' ?>" role="status">Protokoll <?= $chain['ok'] ? 'unversehrt' : 'mit Lücke ab Eintrag ' . (int) $chain['broken_at'] ?> · <?= (int) $chain['count'] ?> Einträge<?= $chain['last_at'] ? ', letzter Eintrag ' . $e(gmdate('Y-m-d H:i:s', (int) $chain['last_at'])) . ' UTC' : '' ?>. Das Abschneiden der letzten Einträge erkennt die Kette nicht; deshalb stehen Anzahl und Zeit hier.</p>
  <?php endif; ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Zeit (UTC)</th><th>Ereignis</th><th>Konto</th><th>Details</th><th>Netz</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td class="mono"><?= $e(gmdate('Y-m-d H:i:s', (int) $r['at'])) ?></td><td><?= $e((string) $r['event']) ?></td><td><?= $e((string) ($r['username'] ?? '')) ?></td><td class="mono"><?= $e((string) $r['detail']) ?></td><td class="mono"><?= $e((string) $r['ip_prefix']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
