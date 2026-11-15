<?php /** @var callable $e @var ?string $detail */ ?>
<section class="hero">
  <p class="eyebrow">Fehler</p>
  <h1>Das hat <em>nicht geklappt</em></h1>
  <p class="intro">Bitte später noch einmal versuchen.</p>
  <?php if (!empty($detail)): ?><pre class="mono"><?= $e($detail) ?></pre><?php endif; ?>
</section>
