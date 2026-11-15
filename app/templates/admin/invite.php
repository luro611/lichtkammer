<?php /** @var callable $e @var ?string $error @var string $preToken @var array<string,mixed> $inv @var string $username */
$reset = $inv['purpose'] === 'reenroll'; ?>
<section class="narrow">
  <p class="eyebrow"><?= $reset ? 'Konto zurücksetzen' : 'Einladung' ?></p>
  <h1 class="h-page"><?= $reset ? 'Neues <em>Passwort</em>' : 'Konto <em>einrichten</em>' ?></h1>
  <p class="intro"><?= $reset ? 'Setze ein neues Passwort. Danach richtest du den zweiten Faktor neu ein.' : 'Wähle Benutzername und Passwort. Danach richtest du den zweiten Faktor ein.' ?></p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form class="card form" method="post" action="">
    <input type="hidden" name="_csrf" value="<?= $e($preToken) ?>">
    <?php if ($reset): ?>
    <p class="field"><span>Benutzername</span><b class="mono"><?= $e((string) $inv['target_name']) ?></b></p>
    <?php else: ?>
    <label class="field"><span>Benutzername (Kleinbuchstaben, Ziffern, . _ -)</span>
      <input name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
    <?php endif; ?>
    <label class="field"><span>Passwort (mindestens 12 Zeichen)</span>
      <input name="password" type="password" autocomplete="new-password" minlength="12" required></label>
    <button class="btn" type="submit">Weiter</button>
  </form>
</section>
