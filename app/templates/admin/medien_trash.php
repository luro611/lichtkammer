<?php /** @var callable $e @var string $csrf @var list<array<string,mixed>> $roots @var list<array<string,mixed>> $files @var int $days @var int $photoTrash */ ?>
<section class="hero compact">
  <p class="eyebrow">Medien</p>
  <h1>Medien-<em>Papierkorb</em></h1>
  <p class="intro">Gelöschte Ordner (mit allem darin) und Dateien bleiben <?= (int) $days ?> Tage hier. Ein Ordner kommt immer ganz zurück, mit seinen Unterordnern, Fotos und Dateien. Gibt es den Namen inzwischen wieder, wird der Inhalt zusammengeführt.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
  <p class="small"><a href="/admin/medien">← zurück zu Medien</a><?= $photoTrash > 0 ? ' · <a href="/admin/papierkorb">einzeln gelöschte Fotos (' . (int) $photoTrash . ')</a>' : '' ?></p>
<?php if ($roots === [] && $files === []): ?>
  <p class="muted">Der Papierkorb ist leer.</p>
<?php else: ?>
  <form method="post" action="/admin/medien/papierkorb" class="form" id="mtrash">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="bulkbar">
      <button class="btn small" type="submit" name="aktion" value="wiederherstellen">Wiederherstellen</button>
      <button class="btn small ghost" type="submit" name="aktion" value="loeschen" data-confirm="Die Auswahl endgültig löschen? Fotos werden mit ihrem Original gelöscht, das lässt sich nicht rückgängig machen.">Endgültig löschen</button>
    </div>
    <div class="tablewrap"><table>
      <thead><tr><th></th><th>Name</th><th>Inhalt</th><th>Gelöscht</th><th>Noch</th></tr></thead>
      <tbody>
<?php foreach ($roots as $r): ?>
        <tr><td><input type="checkbox" name="ordner_id[]" value="<?= (int) $r['id'] ?>" aria-label="Ordner <?= $e((string) $r['name']) ?> auswählen"></td>
          <td class="wrap"><?= $e((string) $r['name']) ?> <span class="small muted">(Ordner)</span></td>
          <td class="small"><?= (int) $r['photos'] ?> Fotos, <?= (int) $r['files'] ?> Dateien, <?= (int) $r['folders'] ?> Unterordner</td>
          <td class="mono small"><?= $e(gmdate('Y-m-d', (int) $r['deleted_at'])) ?></td><td class="mono small"><?= (int) $r['days_left'] ?> Tage</td></tr>
<?php endforeach; ?>
<?php foreach ($files as $r): ?>
        <tr><td><input type="checkbox" name="datei[]" value="<?= (int) $r['id'] ?>" aria-label="<?= $e((string) $r['name']) ?> auswählen"></td>
          <td class="wrap"><?= $e((string) $r['name']) ?> <span class="small muted">(Datei)</span></td>
          <td class="small"><?= $e(strtoupper((string) $r['kind'])) ?></td>
          <td class="mono small"><?= $e(gmdate('Y-m-d', (int) $r['deleted_at'])) ?></td><td class="mono small"><?= (int) $r['days_left'] ?> Tage</td></tr>
<?php endforeach; ?>
      </tbody></table></div>
  </form>
<?php endif; ?>
</section>
