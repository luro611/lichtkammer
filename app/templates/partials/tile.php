<?php /** @var callable $e @var array<string,mixed> $ph @var string $formId */
$label = $ph['title'] !== '' ? $ph['title'] : $ph['original_name']; ?>
    <li class="thumb" id="foto-<?= (int) $ph['id'] ?>" data-id="<?= (int) $ph['id'] ?>" data-status="<?= $e((string) $ph['status']) ?>">
      <label class="pick"><input type="checkbox" name="ids[]" value="<?= (int) $ph['id'] ?>" form="<?= $e($formId) ?>" aria-label="<?= $e((string) $label) ?> auswählen"></label>
      <a class="tilelink" href="/admin/fotos/<?= (int) $ph['id'] ?>">
<?php if ($ph['thumb']): ?>
        <img src="/media/<?= $e((string) $ph['thumb']) ?>" alt="<?= $e((string) $ph['alt_text']) ?>" loading="lazy" width="240">
<?php else: ?>
        <div class="ph"><?= $ph['status'] === 'failed' ? 'Fehler' : 'wird verarbeitet' ?></div>
<?php endif; ?>
      </a>
      <span class="mono small wrap"><?= $e((string) $label) ?></span>
<?php if ($ph['status'] === 'failed'): ?><span class="small error-text"><?= $e((string) $ph['error']) ?></span><?php endif; ?>
<?php if ($ph['alt_text'] === ''): ?><span class="small badge">Alt-Text fehlt</span><?php endif; ?>
