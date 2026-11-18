<?php /** @var callable $e @var string $csrf @var ?string $error @var array<string,mixed> $info @var string $dir @var int $pending @var ?array<string,int> $res @var int $absent */ ?>
<section class="hero">
  <p class="eyebrow">Sicherung</p>
  <h1>Originale <em>zurückholen</em></h1>
  <p class="intro">Nach dem Einspielen einer Sicherung liegen die Originale noch im Bereitstellungsordner. Hier werden sie Stück für Stück an ihren Platz außerhalb des Webroots gebracht. Jede Datei wird vorher gegen die Prüfsummenliste geprüft; vorhandene Originale werden nie überschrieben. Der Vorgang lässt sich beliebig oft fortsetzen.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <div class="card form">
    <p class="mono wrap small muted"><?= $e($dir) ?></p>
<?php if ($res !== null): ?>
    <p class="notice ok" role="status">Dieser Durchgang: <?= (int) $res['moved'] ?> verschoben<?= $res['remaining'] > 0 ? ', ' . (int) $res['remaining'] . ' folgen im nächsten Durchgang' : '' ?><?= $res['bad'] > 0 ? ', <strong>' . (int) $res['bad'] . ' mit falscher Prüfsumme oder Konflikt</strong> (nicht verschoben)' : '' ?><?= $res['missing'] > 0 ? ', ' . (int) $res['missing'] . ' fehlen im Ordner' : '' ?>.</p>
<?php endif; ?>
    <p><?= (int) $pending ?> Originale warten. <?= $absent > 0 ? '<strong>' . (int) $absent . ' Fotos in der Datenbank haben noch kein Original am Ziel.</strong>' : 'Zu allen Fotos in der Datenbank gibt es ein Original.' ?></p>
<?php if ($pending > 0): ?>
    <form method="post" action="/admin/wiederherstellung/weiter"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit">Weiter</button></form>
<?php endif; ?>
  </div>
</section>
