<?php /** @var callable $e @var ?string $flash @var ?string $error */ ?>
<?php if (!empty($flash)): ?><div class="notice ok" role="status"><?= $e($flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice error" role="alert"><?= $e($error) ?></div><?php endif; ?>