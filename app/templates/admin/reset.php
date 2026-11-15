<?php /** @var callable $e @var ?string $error @var ?string $link @var string $preToken */ ?>
<section class="narrow">
  <p class="eyebrow">Notfall</p>
  <h1 class="h-page">Konto <em>zurücksetzen</em></h1>
  <p class="intro">Nur mit dem Notfall-Token (<span class="mono">LK_RESET_TOKEN</span> in <span class="mono">app/.env</span>). Es entsteht ein Link, mit dem Passwort und zweiter Faktor neu eingerichtet werden.</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <?php if (!empty($link)): ?>
  <div class="notice" role="status">Falls das Konto existiert, ist dieser Link 24 Stunden und einmal gültig:<br><span class="mono wrap"><?= $e($link) ?></span><br>Danach bitte <span class="mono">LK_RESET_TOKEN</span> aus <span class="mono">app/.env</span> löschen.</div>
  <?php else: ?>
  <form class="card form" method="post" action="/admin/reset">
    <input type="hidden" name="_csrf" value="<?= $e($preToken) ?>">
    <label class="field"><span>Notfall-Token</span><input name="token" type="password" class="mono" autocomplete="off" required></label>
    <label class="field"><span>Benutzername</span><input name="username" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>
    <button class="btn" type="submit">Link erzeugen</button>
  </form>
  <?php endif; ?>
</section>
