<?php /** @var callable $e @var list<string> $codes @var bool $setup */ ?>
<section class="narrow">
  <p class="eyebrow">Wiederherstellungscodes</p>
  <h1 class="h-page">Gut <em>aufbewahren</em></h1>
  <p class="intro">Mit diesen zehn Codes kommst du hinein, wenn du dein Gerät verlierst. Jeder gilt einmal. Sie werden nur jetzt angezeigt: bitte ausdrucken oder im Passwort-Manager speichern.</p>
  <ol class="codes mono"><?php foreach ($codes as $c): ?><li><?= $e($c) ?></li><?php endforeach; ?></ol>
  <?php if (!empty($setup)): ?>
  <div class="notice" role="note">Letzter Schritt der Einrichtung: Lösche die Zeile <span class="mono">LK_SETUP_TOKEN</span> aus <span class="mono">app/.env</span>. Bis dahin zeigt der Admin eine Warnung.</div>
  <?php endif; ?>
  <div class="actions"><a class="btn" href="/admin">Weiter zur Verwaltung</a></div>
</section>
