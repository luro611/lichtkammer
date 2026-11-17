# Änderungsverlauf

Format: pro Version die Abschnitte Neu, Geändert, Behoben und Hinweise zum Update. Datenbank-Migrationen werden immer genannt.
Versionen: 0.x bis zum öffentlichen Release, danach 1.0.0.

## [Unveröffentlicht]

### Neu
- Anmeldung: Auf der Seite „Zweiter Faktor“ kann man „Auf diesem Gerät 24 Stunden lang nicht erneut nach dem Code fragen“ ankreuzen (Vorgabe: aus). Danach genügt auf diesem Gerät 24 Stunden lang das Passwort. Das Gerät wird über ein zufälliges Token (Cookie `lk_dev`, HttpOnly, Strict, Secure) erkannt; in der Datenbank steht nur ein Hash. Es gilt nur für dasselbe Konto und denselben Browser (User-Agent), läuft fest nach 24 Stunden ab (wird nicht verlängert) und wird nur nach einem TOTP-Code gesetzt, nicht nach einem Wiederherstellungscode. Höchstens 10 Geräte je Konto. Abmelden (nur dieses Gerät), Passwortänderung, „Sitzungen beenden“, Konto sperren oder zurücksetzen und neue Einrichtung des Authenticators widerrufen das Vertrauen. Ein mitgeschicktes, aber nicht angenommenes Token wird aus dem Browser gelöscht; gehört es einem anderen Konto oder kommt es aus einem anderen Browser, steht das im Protokoll (`device_trust_rejected`, `reason=user|ua`), weil das bei richtigem Passwort auf ein kopiertes Cookie hindeutet. Protokoll außerdem: `device_trusted`, `device_trust_used`.
- Startseiten-Fotos: Feld für die Wechselzeit (gab es bisher nur beim Karussell).

### Geändert
- Mein Konto: Neue Wiederherstellungscodes verlangen jetzt Passwort und Code aus der App (bisher nur das Passwort). Grund: Eine Sitzung von einem vertrauten Gerät entsteht ohne zweiten Faktor; mit dem Passwort allein sollen daraus keine dauerhaften Ersatzcodes werden.
- Karussell: Wechselzeit als Zahlenfeld von 2 bis 30 Sekunden (Standard 6) statt Auswahl 4/6/8/12 (serverseitig bisher 3 bis 15). Beim schnellen Wischen rastet jede Folie ein (`scroll-snap-stop`), es wird keine mehr übersprungen.

### Behoben
- Karussell: Höhe stimmte nicht, wenn Fotos unterschiedliche Formate hatten (Hochformat streckte alle Folien, Bild sprang beim Wechseln). Folien haben jetzt feste Höhe nach dem gewählten Format, das Bild füllt sie; ältere Browser ohne `aspect-ratio` fallen auf die Bildhöhe zurück. Auf Handybreite werden sehr flache Formate höher dargestellt (2:1 wie 16:9; 21:9 und 3:1 als 2:1), die Reihenfolge der Formate bleibt dabei erhalten. Die Bildlaufleiste unter dem Karussell entfällt, wenn die Knöpfe da sind.
- Anmeldung: Der Haken „nicht erneut nach dem Code fragen“ blieb nach einem falsch eingetippten Code nicht gesetzt. Nach einer Passwortänderung wird das eigene (dann ungültige) Gerätecookie gelöscht, und der Hinweis nennt die Geräte.

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
