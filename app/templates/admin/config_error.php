<?php /** @var callable $e @var ?string $problem null = Grund nicht anzeigen (Produktion ohne Einrichtungs-Token) */ ?>
<section class="narrow">
  <p class="eyebrow">Verwaltung</p>
  <h1 class="h-page">Admin <em>nicht verfügbar</em></h1>
  <p class="intro"><?= $problem === null ? 'Der Admin startet erst, wenn die Konfiguration in Ordnung ist.' : 'Die Konfiguration ist nicht vollständig. Der Admin startet erst, wenn das behoben ist.' ?> Die öffentliche Seite läuft weiter.</p>
<?php if ($problem === null): ?>
  <div class="notice error" role="alert">Der Admin ist gerade nicht verfügbar. Der Grund steht im Fehlerprotokoll des Servers.</div>
<?php else: ?>
  <div class="notice error" role="alert"><?= $e($problem) ?></div>
<?php endif; ?>
<?php if ($problem !== null && str_contains($problem, 'LK_APP_KEY') && !str_contains($problem, 'LK_APP_KEY_PREVIOUS')): // Hilfe für die Ersteinrichtung ohne Konsole: nur anzeigen, nichts speichern ?>
  <h2 class="h2s">Zwei Zeilen für die Einrichtung</h2>
  <p>Kopiere diese zwei Zeilen in die Datei <code>app/.env</code> auf deinem Server (Vorlage: <code>.env.example</code>). Die Werte werden hier nur angezeigt und nirgends gespeichert; bei jedem Aufruf der Seite sind es neue. Den Schlüssel bitte sicher aufbewahren (Passwort-Manager).</p>
  <pre class="mono small"><?= $e("LK_APP_KEY=" . base64_encode(random_bytes(32)) . "\nLK_SETUP_TOKEN=" . bin2hex(random_bytes(24))) ?></pre>
  <p class="small muted">Nach der Einrichtung die Zeile LK_SETUP_TOKEN wieder löschen.</p>
<?php endif; ?>
</section>
