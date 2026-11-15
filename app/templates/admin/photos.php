<?php /** @var callable $e @var list<array<string,mixed>> $photos @var int $total @var ?string $engine @var list<string> $formats @var int $maxMb @var string $csrf */ ?>
<section class="hero">
  <p class="eyebrow">Fotos</p>
  <h1>Fotos <em>hochladen</em></h1>
  <p class="intro">Bilder hierher ziehen oder auswählen, es dürfen viele auf einmal sein. Jedes Foto wird einzeln hochgeladen und danach verkleinert. Das Original bleibt unverändert gespeichert.</p>
<?php if ($engine === null): ?>
  <div class="notice error" role="alert">Auf diesem Server gibt es keine Bildbibliothek (Imagick oder GD). Hochladen ist deshalb nicht möglich.</div>
<?php else: ?>
  <div id="uploader" class="card dropzone" data-csrf="<?= $e($csrf) ?>" data-max-mb="<?= (int) $maxMb ?>">
    <p><strong>Fotos hier ablegen</strong> oder</p>
    <p><label class="btn" for="files">Dateien auswählen</label>
       <input id="files" class="visually-hidden" type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"></p>
    <p class="muted mono">JPEG, PNG, WebP, HEIC · höchstens <?= (int) $maxMb ?> MB je Datei · Verarbeitung mit <?= $e($engine) ?> (<?= $e(implode(', ', $formats)) ?>)</p>
  </div>
  <ul id="queue" class="queue" aria-live="polite"></ul>
<?php endif; ?>
</section>
<section>
  <h2>Zuletzt <em>hochgeladen</em></h2>
  <p class="muted mono"><?= (int) $total ?> Fotos insgesamt</p>
<?php if ($photos === []): ?>
  <p class="muted">Noch keine Fotos.</p>
<?php else: ?>
  <ul class="thumbs">
<?php foreach ($photos as $ph): ?>
    <li class="thumb" data-status="<?= $e((string) $ph['status']) ?>">
<?php if ($ph['thumb']): ?>
      <img src="/media/<?= $e((string) $ph['thumb']) ?>" alt="<?= $e((string) $ph['alt_text']) ?>" loading="lazy" width="240">
<?php else: ?>
      <div class="ph"><?= $ph['status'] === 'failed' ? 'Fehler' : 'wird verarbeitet' ?></div>
<?php endif; ?>
      <span class="mono small wrap"><?= $e((string) $ph['original_name']) ?></span>
<?php if ($ph['status'] === 'failed'): ?><span class="small error-text"><?= $e((string) $ph['error']) ?></span><?php endif; ?>
<?php if ($ph['alt_text'] === ''): ?><span class="small muted">Alt-Text fehlt</span><?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
<script src="<?= $e($asset('/assets/js/upload.js')) ?>" defer></script>
