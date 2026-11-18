# Entwurf: Admin-Navigation, Seiten, Menü und vier Designs

Stand 18.11.2026, Wünsche von Lutz. (Die Nummer 22 war schon für die Prüfung von Schritt 8a vergeben, deshalb 23.) Risiko insgesamt: **niedrig**. Es ändert sich nur Aufbau und Aussehen des Admins, keine Daten, keine Rechte, keine Datenbank.

## 1. Seitenleiste

| Nr. | Entscheidung | Empfehlung |
|---|---|---|
| N1 | Unterpunkte werden eingerückt, Abschnittsüberschriften fett und unterstrichen (Haarlinie). | So umsetzen. |
| N2 | **Inhalte**: Medien, Fotos, Galerien, Fotografen. **Seiten**: Startseite, Alle Seiten, Neue Seite, Menü & Fußzeile. **Verwaltung**: Logos, Einstellungen, Papierkorb, (Admin:) Konten, Import, dazu Protokoll, Mein Konto. | So umsetzen. Der Abschnitt „Seite“ heißt „Seiten“. |
| N3 | „Alle Seiten“ ist eine flache Übersicht **aller** Seiten (Titel, Adresse, Status, wo verlinkt), alphabetisch nach Titel. Es fehlt keine Seite: Quelle ist `Pages::all()`, kein Filter. Ein Test legt Impressum, Datenschutz und importierte Seiten an und prüft, dass alle in der Liste stehen. | So umsetzen. |
| N4 | „Neue Seite“ hat einen eigenen Aufruf `/admin/seiten/neu` (Titel, Vorlage). Das Formular in der Liste entfällt. Nach dem Anlegen geht es wie bisher in den Editor. | So umsetzen. |
| N5 | Menü und Fußzeile: eine Seite `/admin/menue` mit den drei Gruppen (Menü oben, Fußzeile, nicht verlinkt), Umschalten der Verlinkung und Pfeile für die Reihenfolge. Das gibt es heute in der Seitenliste (Entwurf 14, S5/S6); es zieht um, es wird **nichts neu gebaut**: dieselben Routen `verlinken` und `bewegen`, nur mit Rückkehr auf die Menü-Seite. | So umsetzen. |
| N6 | Kurzer Name für die Reihenfolge: **„Reihenfolge“** als Abschnitt auf der Seite „Menü & Fußzeile“ (statt „Seitenreihenfolge“). Eine eigene dritte Seite wäre doppelt. | So umsetzen. |

Die alten Adressen bleiben gültig (`/admin/seiten` zeigt „Alle Seiten“).

## 2. Designs

Vier Designs, je hell und dunkel. Die Namen beschreiben die Anmutung, nicht Personen oder Marken:

| Alter Wert | Neuer Name (`data-design`) | Anmutung |
|---|---|---|
| `lurohome` / „luro“ | **Warm** (`warm`) | Papierfarben, Terrakotta, Haarlinien, 2 px, Serifenüberschriften |
| `rothe` / „rothe.it“ | **Klar** (`klar`) | neutral, rund, Terrakotta nur als Akzent |
| neu | **Studio** (`studio`) | Anmutung wie apple.com: helles Grau und Weiß, tiefes Schwarz im Dunkel, blaue Links und Knöpfe, sehr runde Formen, Überschriften in Inter. Nur Farbe und Gestaltung, keine Apple-Schrift, kein Logo, keine Assets. |
| neu | **Blau** (`blau`) | Marine- und Himmelblau, kleine Radien, Serifenüberschriften mit blauem Akzentwort |

- **Alte gespeicherte Werte:** `lurohome` wird beim Laden zu `warm`, `rothe` zu `klar`, alles Unbekannte zu `warm`; der neue Wert wird gleich zurückgespeichert.
- **Kontrast:** Alle Text-auf-Fläche-Paare (Text/Hintergrund, Gedämpft/Hintergrund, Akzent/Hintergrund, Knopf) mindestens 4,5:1 (WCAG AA). Wird beim Bauen mit einem kleinen Skript gerechnet; das Ergebnis steht im CHANGELOG-Eintrag.
- Das öffentliche Theme von br-foto bleibt unberührt (eigenes `site.css`).
- Knopffarbe läuft über zwei neue Variablen (`--btn-bg`, `--btn-fg`), damit Studio blaue Knöpfe haben kann, ohne die anderen Designs zu ändern.

## 3. Offene Punkte

Keine. Eine Auslegung: „Darunter müssen wirklich alle Seiten stehen“ lese ich als vollständige Übersichtsliste „Alle Seiten“ im Abschnitt „Seiten“. Falls Lutz die einzelnen Seiten als Unterpunkte in der Seitenleiste meint, sagt er es; mit 20 und mehr Seiten (WordPress-Import) würde die Leiste dann aber sehr lang.
