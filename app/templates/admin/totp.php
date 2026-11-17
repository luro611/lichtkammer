<?php /** @var callable $e @var ?string $error @var string $csrf @var string $next @var bool $trust */ ?>
<section class="narrow">
  <p class="eyebrow">Verwaltung · Schritt 2 von 2</p>
  <h1 class="h-page">Zweiter <em>Faktor</em></h1>
  <p class="intro">Gib den 6-stelligen Code aus deiner Authenticator-App ein. Hast du dein Gerät nicht dabei, geht auch ein Wiederherstellungscode.</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form class="card form" method="post" action="/admin/login/totp">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <label class="field"><span>Code</span>
      <input name="code" class="mono" inputmode="text" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required autofocus></label>
    <label class="check"><input type="checkbox" name="trust" value="1"<?= !empty($trust) ? ' checked' : '' ?>> Auf diesem Gerät <?= intdiv(\Lichtkammer\Auth\Auth::DEVICE_TTL, 3600) ?> Stunden lang nicht erneut nach dem Code fragen (nur auf deinem eigenen Gerät; Abmelden beendet das)</label>
    <button class="btn" type="submit">Anmelden</button>
  </form>
</section>
