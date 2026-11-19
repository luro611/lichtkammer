<?php /** @var callable $e @var ?array{n:int,labels:list<string>,own:bool} $also */
// Ehrlich statt geschätzt: Der Bau nimmt immer alles Gespeicherte mit. Die Liste kommt aus dem Probelauf und zählt Teile der Seite
// (eine Menü- oder Designänderung betrifft viele), daher keine Zahl „Änderungen“.
if (!empty($also['n'])): $rest = (int) $also['n'] - count($also['labels']); ?>
    <p class="small muted also">Dabei geht alles Gespeicherte online. Noch nicht online <?= !empty($also['own']) ? 'sind außerdem' : 'sind' ?>: <?= $e(implode(', ', $also['labels'])) ?><?= $rest > 0 ? ' und ' . $rest . ' weitere' . ($rest === 1 ? 'r Teil' : ' Teile') . ' der Seite' : '' ?>.</p>
<?php endif; ?>
