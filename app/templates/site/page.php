<?php /** @var \Lichtkammer\Site\Renderer $h @var array<string,mixed> $pg @var string $html */
$e = static fn (?string $s): string => \Lichtkammer\Site\Renderer::h($s);
?>
<article class="wrap block<?= empty($wide) ? ' narrow' : ' pageblocks' ?>">
  <h1 class="h-page"><?= $e((string) $pg['title']) ?></h1>
  <div class="<?= empty($wide) ? 'prose' : 'blocks' ?>"><?= $html ?></div>
</article>
