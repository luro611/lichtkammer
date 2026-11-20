<?php /** @var callable $e @var string $csrf @var string $action @var string $what @var list<array{name:string,uses:list<string>,live:bool}> $rows @var int $more @var array<string,string|list<string>> $fields @var string $cancel */ ?>
<section class="narrow">
  <p class="eyebrow">Medien</p>
  <h1 class="h-page">Das wird noch <em>verwendet</em></h1>
  <p class="intro"><?= $e($what) ?> Folgende Dateien stehen in Seiten. Machst du es trotzdem, fehlen sie dort, und das Veröffentlichen stoppt, bis du die Seiten angepasst hast.</p>
  <ul class="card plain" data-used-list>
    <?php foreach ($rows as $r): ?>
      <li><strong><?= $e($r['name']) ?></strong><?= $r['live'] ? ' <span class="muted">(auf der Live-Seite)</span>' : '' ?>
        <ul><?php foreach ($r['uses'] as $u): ?><li><?= $e($u) ?></li><?php endforeach; ?></ul></li>
    <?php endforeach; ?>
    <?php if ($more > 0): ?><li class="muted">und <?= (int) $more ?> weitere</li><?php endif; ?>
  </ul>
  <form class="card form" method="post" action="<?= $e($action) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="bestaetigt" value="1">
    <?php /* Listenfelder brauchen "[]", sonst behält PHP nur den letzten Wert als Text und inputList() liefert nichts (Prüfung 46, B1) */ foreach ($fields as $k => $v): foreach ((array) $v as $one): ?><input type="hidden" name="<?= $e(is_array($v) ? $k . '[]' : $k) ?>" value="<?= $e((string) $one) ?>"><?php endforeach; endforeach; ?>
    <div class="actions"><button class="btn" type="submit">Trotzdem ausführen</button> <a class="btn ghost" href="<?= $e($cancel) ?>">Abbrechen</a></div>
  </form>
</section>
