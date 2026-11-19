<?php /** @var callable $e @var ?string $flash @var ?string $error @var bool $flashError @var string $csrf */ ?>
<?php if (!empty($flash) && !empty($flashError)): ?><div class="notice error" role="alert"><?= $e($flash) ?>
  <form class="inline" method="post" action="/admin/veroeffentlichen"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn small" type="submit" data-busy="Wird veröffentlicht …">Erneut versuchen</button></form></div>
<?php elseif (!empty($flash)): ?><div class="notice ok" role="status"><?= $e($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>