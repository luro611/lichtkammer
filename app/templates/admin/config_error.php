<?php /** @var callable $e @var string $problem */ ?>
<section class="narrow">
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">Admin <em>nicht verfügbar</em></h1>
  <p class="intro">Die Konfiguration ist nicht vollständig. Der Admin startet erst, wenn das behoben ist. Die öffentliche Seite läuft weiter.</p>
  <div class="notice error" role="alert"><?= $e($problem) ?></div>
</section>
