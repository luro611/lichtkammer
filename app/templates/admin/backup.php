<?php /** @var callable $e @var string $csrf @var ?string $error @var array<string,mixed> $sum @var bool $confirmed @var int $left @var int $now @var int $partMb */
use Lichtkammer\Admin\SystemCheck;
$h = static fn (int $b): string => SystemCheck::human($b);
$parts = $sum['parts'];
?>
<section class="hero">
  <p class="eyebrow">Verwaltung</p>
  <h1>Sicherung <em>herunterladen</em></h1>
  <p class="intro">Eine Sicherung besteht aus zwei Teilen: dem <strong>Paket</strong> (Datenbank mit allen Konten, Fotos, Galerien und Seiten, dazu private Dateien und eine Prüfsummenliste) und den <strong>Originalen</strong> der Fotos. Beides zusammen ergibt einen Ordner, aus dem sich Lichtkammer auf einem neuen Server wieder einspielen lässt (Anleitung: <span class="mono">docs/betrieb/Wiederherstellung.md</span>). Die Sicherung gehört auf einen verschlüsselten Datenträger. Der Schlüssel <span class="mono">LK_APP_KEY</span> gehört <strong>nicht</strong> dazu, er liegt getrennt im Passwort-Manager.</p>
<?php include __DIR__ . '/../partials/notices.php'; ?>
<?php if ($sum['lastAt'] === null): ?>
  <div class="notice" role="note">Es wurde noch keine Sicherung heruntergeladen.</div>
<?php else: $days = intdiv($now - (int) $sum['lastAt'], 86400); ?>
  <div class="notice<?= $days > 3 ? ' error' : ' ok' ?>" role="status">Zuletzt heruntergeladen: <?= $e(gmdate('d.m.Y H:i', (int) $sum['lastAt'])) ?> UTC<?= $days > 0 ? ' (vor ' . $days . ' Tag' . ($days === 1 ? '' : 'en') . ')' : '' ?>.</div>
<?php endif; ?>

  <h2 class="h-sec">1. Bestätigen</h2>
<?php if ($confirmed): ?>
  <div class="notice ok" role="status">Bestätigt. Die Downloads sind noch etwa <?= max(1, (int) ceil($left / 60)) ?> Minute(n) freigeschaltet.</div>
<?php else: ?>
  <form class="card form" method="post" action="/admin/sicherung/bestaetigen">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <p class="muted">Eine Sicherung enthält alle Daten. Deshalb fragt Lichtkammer hier noch einmal nach deinem Passwort und dem aktuellen Code aus der App. Danach sind die Downloads 10 Minuten freigeschaltet.</p>
    <label class="field"><span>Aktuelles Passwort</span><input name="current_password" type="password" autocomplete="current-password" required></label>
    <label class="field"><span>Aktueller Code aus der App</span><input name="code" class="mono" inputmode="numeric" autocomplete="one-time-code" required></label>
    <button class="btn" type="submit">Bestätigen</button>
  </form>
<?php endif; ?>

  <h2 class="h-sec">2. Paket</h2>
  <div class="card form">
    <p>Datenbank (<?= $h((int) $sum['dbBytes']) ?>), <?= (int) $sum['files'] ?> private Datei(en) (<?= $h((int) $sum['fileBytes']) ?>), Prüfsummenliste von <?= (int) $sum['originals'] ?> Original(en). Ohne die Originale selbst.</p>
<?php if ($confirmed): ?>
    <form method="post" action="/admin/sicherung/paket"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="btn" type="submit">Paket herunterladen (.tar)</button></form>
<?php else: ?>
    <p class="small muted">Erst bestätigen (Schritt 1).</p>
<?php endif; ?>
    <p class="small muted">Die Datei lässt sich auf Mac, Windows und Linux mit einem Doppelklick entpacken. Es entsteht ein Ordner mit <span class="mono">MANIFEST.json</span>, <span class="mono">lichtkammer.sqlite</span>, <span class="mono">originals.sha256</span> und <span class="mono">files/</span>.</p>
  </div>

  <h2 class="h-sec">3. Originale</h2>
  <div class="card form">
    <p><?= (int) $sum['originals'] ?> Original(e), zusammen <?= $h((int) $sum['originalBytes']) ?>. Die Originale enthalten alle Aufnahmedaten, auch den Aufnahmeort. Sie werden nie öffentlich ausgeliefert, nur hier an Administratoren.</p>
<?php if ((int) $sum['absent'] > 0): ?>
    <div class="notice error" role="alert"><?= (int) $sum['absent'] ?> Foto(s) haben in der Datenbank ein Original, das als Datei fehlt. Diese Fotos sind in der Sicherung nicht enthalten.</div>
<?php endif; ?>
<?php if ((int) $sum['odd'] > 0): ?>
    <div class="notice error" role="alert"><?= (int) $sum['odd'] ?> Foto(s) haben einen ungewöhnlichen Pfad oder eine ungültige Prüfsumme und werden nicht gesichert.</div>
<?php endif; ?>
<?php if ($parts === []): ?>
    <p class="muted">Noch keine Originale vorhanden.</p>
<?php else: ?>
    <p class="small muted">Weil Webserver Downloads zeitlich begrenzen, gibt es die Originale in <?= count($parts) ?> Teil(en) von je etwa <?= (int) $partMb ?> MB. Jeder Teil ist ein eigenes Archiv; alle Teile in denselben Ordner entpacken. Bricht ein Download ab, genügt es, nur diesen Teil noch einmal zu laden.</p>
    <ol class="rows">
<?php foreach ($parts as $i => $pt): ?>
      <li class="row"><span>Teil <?= $i + 1 ?> von <?= count($parts) ?>: <?= (int) $pt['count'] ?> Foto(s), <?= $h((int) $pt['bytes']) ?></span>
<?php if ($confirmed): ?>
        <a class="btn ghost small" href="/admin/sicherung/originale?nach=<?= (int) $pt['after'] ?>">Herunterladen</a>
<?php endif; ?>
      </li>
<?php endforeach; ?>
    </ol>
    <p class="small muted">Einzelne Originale (mit Fortsetzen nach Abbruch) gibt es unter <span class="mono">/admin/sicherung/original/&lt;Foto-Nummer&gt;</span>.</p>
<?php endif; ?>
  </div>

  <h2 class="h-sec">Wieder einspielen</h2>
  <p>Auf einem neuen Server beginnt das Einspielen bei der Ersteinrichtung (Link „Stattdessen eine Sicherung einspielen“). Die Originale holt <a href="/admin/wiederherstellung">diese Seite</a> danach zurück. Prüfe die Sicherung einmal auf einem Testserver, bevor du dich darauf verlässt.</p>
</section>
