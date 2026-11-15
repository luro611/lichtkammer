<?php /** @var callable $e @var ?string $engine @var list<string> $formats @var int $maxMb @var string $csrf @var ?int $galleryId */ ?>
<?php if ($engine === null): ?>
  <div class="notice error" role="alert">Auf diesem Server gibt es keine Bildbibliothek (Imagick oder GD). Hochladen ist deshalb nicht möglich.</div>
<?php else: ?>
  <div id="uploader" class="card dropzone" data-csrf="<?= $e($csrf) ?>" data-max-mb="<?= (int) $maxMb ?>"<?= !empty($galleryId) ? ' data-gallery="' . (int) $galleryId . '"' : '' ?>>
    <p><strong>Fotos hier ablegen</strong> oder</p>
    <p><label class="btn" for="files">Dateien auswählen</label>
       <input id="files" class="visually-hidden" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"></p>
    <p class="muted mono">JPEG, PNG, WebP, HEIC · höchstens <?= (int) $maxMb ?> MB je Datei · Verarbeitung mit <?= $e($engine) ?> (<?= $e(implode(', ', $formats)) ?>)</p>
  </div>
  <ul id="queue" class="queue" aria-live="polite"></ul>
  <script src="<?= $e($asset('/assets/js/upload.js')) ?>" defer></script>
<?php endif; ?>
