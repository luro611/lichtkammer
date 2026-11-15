<?php /** @var callable $e @var list<array<string,mixed>> $rows @var int $days @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Papierkorb</p>
  <h1>Gelöschte <em>Fotos</em></h1>
  <p class="intro">Hier liegen Fotos <?= (int) $days ?> Tage, danach werden sie samt Original endgültig gelöscht. Beim Wiederherstellen kommen sie in ihre früheren Galerien zurück.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($rows === []): ?>
  <p class="muted">Der Papierkorb ist leer.</p>
<?php else: ?>
  <form method="post" action="/admin/papierkorb" id="trash" class="form">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="bulkbar">
      <label class="small"><input type="checkbox" data-select-all="trash"> alle</label>
      <button class="btn small" type="submit" name="aktion" value="wiederherstellen">Wiederherstellen</button>
      <button class="btn small ghost" type="submit" name="aktion" value="loeschen" data-confirm="Die ausgewählten Fotos endgültig löschen? Das Original wird mit gelöscht, das lässt sich nicht rückgängig machen.">Endgültig löschen</button>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th></th><th>Foto</th><th>Gelöscht</th><th>Noch</th></tr></thead>
      <tbody>
<?php foreach ($rows as $r): ?>
        <tr><td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="<?= $e((string) $r['original_name']) ?> auswählen"></td>
          <td class="wrap"><?= $e((string) ($r['title'] !== '' ? $r['title'] : $r['original_name'])) ?> <span class="mono small muted"><?= $e((string) $r['original_name']) ?></span></td>
          <td class="mono small"><?= $e(gmdate('Y-m-d', (int) $r['deleted_at'])) ?></td>
          <td class="mono small"><?= (int) $r['days_left'] ?> Tage</td></tr>
<?php endforeach; ?>
      </tbody></table></div>
  </form>
<?php endif; ?>
</section>
