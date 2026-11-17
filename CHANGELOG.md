# Änderungsverlauf

Format: pro Version die Abschnitte Neu, Geändert, Behoben und Hinweise zum Update. Datenbank-Migrationen werden immer genannt.
Versionen: 0.x bis zum öffentlichen Release, danach 1.0.0.

## [0.1.0-rc1] – Probelauf des Release-Ablaufs

### Neu
- Anmeldung mit Passwort, Authenticator-App (TOTP) und Wiederherstellungscodes, Einladungen, Protokoll.
- Bildverwaltung: Hochladen in Stücken, Verkleinern (AVIF, WebP, JPEG), Galerien, Fotografen, Papierkorb, Mehrfachbearbeitung, Zuordnung nach Galerie.
- Öffentliche Seite als fertiges HTML, Veröffentlichen mit Rückgängig und Vorschau.
- Seiten-Editor mit Blöcken (Text, Bild, Raster, Zitat, Knopf, Spalten, Bild und Text, Karussell, Titelbild, Galerien-Kacheln, Startseiten-Fotos), Blockstile, Bildausschnitt.
- Startseite aus Blöcken, Logos (hell, dunkel, Favicon), Seitenreihenfolge für Menü und Fußzeile, Seitentitel an/aus.
- Umzug von WordPress (Exportskript, Import im Admin, Weiterleitungen alter Adressen).

### Hinweise zum Update
- Datenbank-Migrationen 1 bis 8 laufen beim ersten Aufruf selbst.
