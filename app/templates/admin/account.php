<?php /** @var callable $e @var ?string $error @var ?string $ok @var ?string $notice @var int $remaining */ ?>
<section class="narrow wide">
  <p class="eyebrow">Mein Konto</p>
  <h1 class="h-page">Sicher<em>heit</em></h1>
  <?php if (!empty($notice)): ?><div class="notice" role="note"><?= $e($notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <?php if (!empty($ok)): ?><div class="notice" role="status"><?= $e($ok) ?></div><?php endif; ?>

  <h2 class="h-sec">Passwort ändern</h2>
  <form class="card form" method="post" action="/admin/account/password">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Aktuelles Passwort</span><input name="current_password" type="password" autocomplete="current-password" required></label>
    <label class="field"><span>Aktueller Code aus der App</span><input name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" required></label>
    <label class="field"><span>Neues Passwort (mindestens 12 Zeichen)</span><input name="new_password" type="password" autocomplete="new-password" minlength="12" required></label>
    <label class="field"><span>Neues Passwort wiederholen</span><input name="new_password2" type="password" autocomplete="new-password" minlength="12" required></label>
    <button class="btn" type="submit">Passwort ändern</button>
  </form>

  <h2 class="h-sec">Wiederherstellungscodes</h2>
  <form class="card form" method="post" action="/admin/account/codes">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <p class="muted"><?= (int) $remaining ?> von 10 Codes sind noch unbenutzt. Neue Codes machen alle alten ungültig.</p>
    <label class="field"><span>Aktuelles Passwort</span><input name="current_password" type="password" autocomplete="current-password" required></label>
    <button class="btn" type="submit">Neue Codes erzeugen</button>
  </form>
  <p class="muted">Neues Gerät oder verlorenes Passwort? Das setzt ein Administrator über einen Rücksetz-Link zurück.</p>
</section>
