<?php /** @var callable $e @var list<array{level:string,title:string,text:string}> $items */
$label = ['ok' => 'In Ordnung', 'warn' => 'Bitte ansehen', 'bad' => 'Muss behoben werden', 'info' => 'Zur Kenntnis'];
$bad = count(array_filter($items, static fn (array $i): bool => $i['level'] === 'bad'));
$warn = count(array_filter($items, static fn (array $i): bool => $i['level'] === 'warn'));
?>
<section class="wide">
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">System<em>prüfung</em></h1>
  <p class="intro">Hier steht, ob der Server richtig eingerichtet ist. Es werden nur Zustände gezeigt, nie Schlüssel, Passwörter oder Token.</p>
<?php if ($bad > 0): ?>
  <div class="notice error" role="alert"><?= $bad ?> Punkt<?= $bad === 1 ? '' : 'e' ?> müssen behoben werden.</div>
<?php elseif ($warn > 0): ?>
  <div class="notice" role="status"><?= $warn ?> Punkt<?= $warn === 1 ? '' : 'e' ?> sollten angesehen werden.</div>
<?php else: ?>
  <div class="notice ok" role="status">Alles in Ordnung.</div>
<?php endif; ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Prüfung</th><th>Stand</th><th>Ergebnis</th></tr></thead>
    <tbody>
<?php foreach ($items as $i): ?>
      <tr>
        <td><?= $e($i['title']) ?></td>
        <td><span class="badge<?= $i['level'] === 'ok' ? '' : ($i['level'] === 'info' ? '' : ' cover') ?>"><?= $e($label[$i['level']] ?? $i['level']) ?></span></td>
        <td><?= $e($i['text']) ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
</section>
