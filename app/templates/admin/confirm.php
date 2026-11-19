<?php /** @var callable $e @var string $csrf @var string $action @var string $what @var array<string,string> $fields @var ?string $error */ ?>
<section class="narrow">
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">Bitte <em>bestätigen</em></h1>
  <p class="intro"><?= $e($what) ?>: Dafür fragt Lichtkammer noch einmal nach deinem Passwort und dem aktuellen Code aus der App. So kann niemand, der nur an eine offene Anmeldung gekommen ist, ein Administrator-Konto anlegen oder übernehmen.</p>
  <?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>
  <form class="card form" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <?php foreach ($fields as $k => $v): ?><input type="hidden" name="<?= $e($k) ?>" value="<?= $e($v) ?>"><?php endforeach; ?>
    <label class="field"><span>Aktuelles Passwort</span><input name="current_password" type="password" autocomplete="current-password" required></label>
    <label class="field"><span>Aktueller Code aus der App</span><input name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" required></label>
    <p class="small muted">Jeder Code gilt nur einmal. Hast du dich gerade erst angemeldet, bitte auf den nächsten Code warten (30 Sekunden).</p>
    <div class="actions"><button class="btn" type="submit">Bestätigen</button> <a class="btn ghost" href="/admin/users">Abbrechen</a></div>
  </form>
</section>
