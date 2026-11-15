<?php /** @var callable $e @var string $csrf @var list<array<string,mixed>> $accounts @var ?string $link @var ?string $message */ ?>
<section class="wide">
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">Kon<em>ten</em></h1>
  <?php if (!empty($message)): ?><div class="notice" role="status"><?= $e($message) ?></div><?php endif; ?>
  <?php if (!empty($link)): ?><div class="notice" role="note">Link zum Weitergeben (wird nur jetzt angezeigt):<br><span class="mono wrap"><?= $e($link) ?></span></div><?php endif; ?>
  <div class="tablewrap"><table>
    <thead><tr><th>Benutzer</th><th>Rolle</th><th>Zweiter Faktor</th><th>Status</th><th>Aktionen</th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $a): $id = (int) $a['id']; ?>
      <tr>
        <td class="mono"><?= $e((string) $a['username']) ?></td>
        <td><?= $a['role'] === 'admin' ? 'Administrator' : 'Redaktion' ?></td>
        <td><?= $a['totp_confirmed_at'] ? 'eingerichtet' : 'fehlt' ?></td>
        <td><?= $a['disabled_at'] ? 'gesperrt' : 'aktiv' ?></td>
        <td class="row-actions">
          <?php foreach ([($a['disabled_at'] ? 'enable' : 'disable') => ($a['disabled_at'] ? 'Entsperren' : 'Sperren'), 'reset' => 'Zurücksetzen', 'sessions' => 'Sitzungen beenden'] as $act => $label): ?>
          <form method="post" action="/admin/users/<?= $id ?>/<?= $e($act) ?>"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn ghost small" type="submit"><?= $e($label) ?></button></form>
          <?php endforeach; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <h2 class="h-sec">Person einladen</h2>
  <form class="card form" method="post" action="/admin/users/invite">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Rolle</span>
      <select name="role"><option value="editor">Redaktion (Inhalte)</option><option value="admin">Administrator</option></select></label>
    <button class="btn" type="submit">Einladungslink erzeugen</button>
  </form>
</section>
