<?php /** @var callable $e @var ?string $error @var string $preToken @var array<string,mixed> $info @var string $dir */ ?>
<section class="narrow">
  <p class="eyebrow">Ersteinrichtung</p>
  <h1 class="h-page">Aus <em>Sicherung</em> einspielen</h1>
  <p class="intro">Für einen neuen Server oder einen Umzug: Datenbank und Dateien einer früheren Installation werden übernommen. Die Konten (mit Passwort und zweitem Faktor) kommen mit. Voraussetzung ist <strong>derselbe <span class="mono">LK_APP_KEY</span></strong> wie bei der Sicherung. Sitzungen, vertraute Geräte und offene Einladungen werden verworfen, alle melden sich neu an.</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
<?php if ($info['state'] === 'none'): ?>
  <div class="card form">
    <h2 class="h2s">Noch keine Sicherung bereitgestellt</h2>
    <p>Den Ordner der Sicherung (<span class="mono">MANIFEST.json</span>, <span class="mono">lichtkammer.sqlite</span>, optional <span class="mono">files/</span>, <span class="mono">originals.sha256</span> und <span class="mono">originals/</span>) per FTPS in diesen Ordner auf dem Server legen:</p>
    <p class="mono wrap"><?= $e($dir) ?></p>
    <p class="small muted">Der Ordner ist für Besucher gesperrt. Liegt er im Webroot, genügt <span class="mono">LK_RESTORE_DIR</span> in <span class="mono">app/.env</span>. Danach diese Seite neu laden.</p>
  </div>
<?php elseif ($info['state'] === 'invalid'): ?>
  <div class="notice error" role="alert"><strong>Die Sicherung ist nicht nutzbar:</strong> <?= $e((string) $info['error']) ?></div>
<?php else: $m = $info['manifest']; ?>
  <form class="card form" method="post" action="/admin/setup/wiederherstellen">
    <input type="hidden" name="_csrf" value="<?= $e($preToken) ?>">
    <p class="mono small muted">Sicherung vom <?= $e(gmdate('Y-m-d H:i', (int) ($m['created_at'] ?? 0))) ?> UTC · Version <?= $e((string) ($m['app_version'] ?? '?')) ?> · Schema <?= (int) $m['schema_version'] ?> · <?= (int) $info['originals'] ?> Originale in der Liste</p>
    <label class="field"><span>Einrichtungs-Token</span>
      <input name="token" type="password" class="mono" autocomplete="off" required></label>
    <button class="btn" type="submit">Sicherung einspielen</button>
  </form>
<?php endif; ?>
  <p class="small"><a href="/admin/setup">Zurück zur normalen Einrichtung</a></p>
</section>
