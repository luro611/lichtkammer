<?php /** @var callable $e @var ?string $error @var string $preToken @var string $next @var string $username */ ?>
<section class="narrow">
  <div class="seal" aria-hidden="false"><?= file_get_contents(__DIR__ . '/../partials/siegel.svg') ?></div>
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">An<em>melden</em></h1>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form class="card form" method="post" action="/admin/login">
    <input type="hidden" name="_csrf" value="<?= $e($preToken) ?>">
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <label class="field"><span>Benutzername</span>
      <input name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
    <label class="field"><span>Passwort</span>
      <input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn" type="submit">Weiter</button>
  </form>
</section>
