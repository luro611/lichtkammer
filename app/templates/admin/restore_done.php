<?php /** @var callable $e @var array<string,int> $res @var int $pending */ ?>
<section class="narrow">
  <p class="eyebrow">Ersteinrichtung</p>
  <h1 class="h-page">Sicherung <em>eingespielt</em></h1>
  <div class="notice ok" role="status"><?= (int) $res['users'] ?> Konten, <?= (int) $res['photos'] ?> Fotos (Schema <?= (int) $res['schema'] ?>), <?= (int) $res['files'] ?> Dateien übernommen.</div>
  <ol>
    <li>Mit dem bisherigen Konto <a href="/admin/login">anmelden</a> (Passwort und zweiter Faktor wie bisher).</li>
<?php if ($pending > 0): ?>
    <li><?= (int) $pending ?> Originale warten noch im Bereitstellungsordner: unter <a href="/admin/wiederherstellung">Verwaltung → Sicherung</a> zurückholen.</li>
<?php endif; ?>
    <li>Danach „Medienvarianten neu erzeugen“ (Fotos) und <strong>Veröffentlichen</strong>.</li>
    <li>Das Einrichtungs-Token aus <span class="mono">app/.env</span> entfernen (<span class="mono">./deploy.sh --token-entfernen</span>).</li>
  </ol>
</section>
