# Änderungsverlauf

Format: pro Version die Abschnitte Neu, Geändert, Behoben und Hinweise zum Update. Datenbank-Migrationen werden immer genannt.
Versionen: 0.x bis zum öffentlichen Release, danach 1.0.0.

## [Unveröffentlicht]

### Neu
- Anmeldung: Auf der Seite „Zweiter Faktor“ kann man „Auf diesem Gerät 24 Stunden lang nicht erneut nach dem Code fragen“ ankreuzen (Vorgabe: aus). Danach genügt auf diesem Gerät 24 Stunden lang das Passwort. Das Gerät wird über ein zufälliges Token (Cookie `lk_dev`, HttpOnly, Strict, Secure) erkannt; in der Datenbank steht nur ein Hash. Es gilt nur für dasselbe Konto und denselben Browser (User-Agent), läuft fest nach 24 Stunden ab (wird nicht verlängert) und wird nur nach einem TOTP-Code gesetzt, nicht nach einem Wiederherstellungscode. Höchstens 10 Geräte je Konto. Abmelden, Passwortänderung, „Sitzungen beenden“, Konto sperren oder zurücksetzen und neue Einrichtung des Authenticators widerrufen das Vertrauen. Protokoll: `device_trusted`, `device_trust_used`.
- Karussell und Startseiten-Fotos: Wechselzeit frei einstellbar (2 bis 30 Sekunden, Standard 6); die Startseiten-Fotos hatten bisher kein Feld dafür.

### Behoben
- Karussell: Höhe stimmte nicht, wenn Fotos unterschiedliche Formate hatten (Hochformat streckte alle Folien, Bild sprang beim Wechseln). Folien haben jetzt feste Höhe nach dem gewählten Format, das Bild füllt sie. Auf Handybreite werden sehr flache Formate (2:1, 21:9, 3:1) etwas höher dargestellt, die Bildlaufleiste unter dem Karussell entfällt, wenn die Knöpfe da sind.

### Hinweise zum Update
- Datenbank-Migration 9 (Tabelle `trusted_devices`, nur additiv) läuft beim ersten Aufruf selbst.

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
