<?php /** @var callable $e @var array $templates @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Seiten</p>
  <h1>Neue <em>Seite</em></h1>
  <p class="intro">Eine Seite mit eigenem Titel anlegen oder eine Vorlage nehmen. Danach trägst du den Text ein. Sichtbar wird sie erst mit dem <a href="/admin/veroeffentlichen">Veröffentlichen</a>.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($templates !== []): ?>
  <form class="inline" method="post" action="/admin/seiten">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Mit Vorlage anlegen</span><select name="vorlage"><?php foreach ($templates as $k => $t): ?><option value="<?= $e((string) $k) ?>"><?= $e($t[0]) ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Anlegen</button>
  </form>
<?php endif; ?>
  <form class="inline" method="post" action="/admin/seiten">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label class="field"><span>Neue leere Seite</span><input name="title" maxlength="120" required placeholder="Titel"></label>
    <button class="btn ghost" type="submit">Anlegen</button>
  </form>
</section>
