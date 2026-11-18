<?php /** Umschalter Design (vier Designs) und Farbschema; design.js setzt aria-pressed. */ ?>
<div class="tools">
  <div class="pill designs" role="group" aria-label="Design"><?php foreach (['warm' => 'Warm', 'klar' => 'Klar', 'studio' => 'Studio', 'blau' => 'Blau'] as $k => $t): ?><button type="button" data-design-set="<?= $k ?>" aria-pressed="<?= $k === 'warm' ? 'true' : 'false' ?>"><?= $t ?></button><?php endforeach; ?></div>
  <div class="pill" role="group" aria-label="Farbschema"><button type="button" data-theme-set="light" aria-label="Hell" aria-pressed="true">☀</button><button type="button" data-theme-set="dark" aria-label="Dunkel" aria-pressed="false">☾</button></div>
</div>
