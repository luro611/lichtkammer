<?php /** @var callable $e @var ?array{n:int,labels:list<string>} $also */
if (!empty($also['n'])): ?>
    <p class="small muted also">Dabei gehen auch <?= (int) $also['n'] ?> weitere Änderung<?= (int) $also['n'] === 1 ? '' : 'en' ?> online: <?= $e(implode(', ', $also['labels'])) ?><?= (int) $also['n'] > count($also['labels']) ? ' und weitere' : '' ?>.</p>
<?php endif; ?>
