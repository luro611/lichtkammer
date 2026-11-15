<?php /** @var callable $e @var ?string $error @var string $qr @var string $secret @var string $serverTime @var string $csrf @var string $username */ ?>
<section class="narrow">
  <p class="eyebrow">Zweiter Faktor</p>
  <h1 class="h-page">App <em>koppeln</em></h1>
  <p class="intro">Scanne den Code mit einer Authenticator-App (zum Beispiel Apple Passwörter, 1Password, Aegis) oder tippe den Schlüssel ein. Bestätige dann mit dem angezeigten Code.</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <div class="card form">
    <div class="qr"><?= $qr ?></div>
    <p class="field"><span>Schlüssel zum Abtippen</span><b class="mono secret"><?= $e($secret) ?></b></p>
    <form method="post" action="/admin/enroll">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <label class="field"><span>Code aus der App</span>
        <input name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" required autofocus></label>
      <button class="btn" type="submit">Bestätigen</button>
    </form>
    <p class="muted mono">Serverzeit: <?= $e($serverTime) ?></p>
  </div>
</section>
