<?php /** @var callable $e @var ?string $error @var string $preToken @var string $username */ ?>
<section class="narrow">
  <p class="eyebrow">Ersteinrichtung</p>
  <h1 class="h-page">Erstes <em>Konto</em></h1>
  <p class="intro">Hier entsteht das Administrator-Konto. Das Einrichtungs-Token steht in <span class="mono">app/.env</span> (<span class="mono">LK_SETUP_TOKEN</span>).</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form class="card form" method="post" action="/admin/setup">
    <input type="hidden" name="_csrf" value="<?= $e($preToken) ?>">
    <label class="field"><span>Einrichtungs-Token</span>
      <input name="token" type="password" class="mono" autocomplete="off" required></label>
    <label class="field"><span>Benutzername (Kleinbuchstaben, Ziffern, . _ -)</span>
      <input name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
    <label class="field"><span>Passwort (mindestens 12 Zeichen)</span>
      <input name="password" type="password" autocomplete="new-password" minlength="12" required></label>
    <button class="btn" type="submit">Weiter</button>
  </form>
  <p class="small"><a href="/admin/setup/wiederherstellen">Stattdessen eine Sicherung einspielen</a> (neuer Server, Umzug)</p>
</section>
