#!/bin/zsh
# Baut das Testpaket dist/lichtkammer-testpaket-<Version>.zip für externe Tester:
#   Software-Zip (+ .sha256), Anleitung (PDF), INSTALL.txt, LIESMICH-Tester.txt, LICENSE, NOTICE.
#   tools/testpaket.sh            baut aus dem letzten Commit (HEAD) und der vorhandenen Anleitung (docs/anleitung/Lichtkammer-Anleitung.pdf)
# Prüft den Inhalt automatisch auf Verbotenes und private Spuren. Reproduzierbar (feste Zeitstempel, sortierte Liste).
set -euo pipefail
cd "${0:A:h}/.."

VERSION=$(grep -oE "VERSION = '[^']+'" app/src/Config.php | cut -d"'" -f2)
[[ -n "$VERSION" ]] || { echo "Abbruch: Keine Version in app/src/Config.php." >&2; exit 1; }
PDF=docs/anleitung/Lichtkammer-Anleitung.pdf
[[ -f "$PDF" ]] || { echo "Abbruch: $PDF fehlt (docs/anleitung/bauen.sh)." >&2; exit 1; }
git diff --quiet HEAD -- app public .env.example tools/INSTALL.txt 2>/dev/null || { echo "Abbruch: Uncommittete Änderungen in app/, public/ oder .env.example: das Software-Zip käme aus HEAD." >&2; exit 1; }
grep -q "Version $VERSION" <(strings "$PDF" 2>/dev/null) 2>/dev/null || echo "Hinweis: Die PDF trägt vielleicht eine andere Version; docs/anleitung/bauen.sh neu laufen lassen." >&2

echo "== Software-Zip bauen und prüfen"
tools/release-zip.sh --pruefen >/dev/null
SW="dist/lichtkammer-$VERSION.zip"
[[ -f "$SW" && -f "$SW.sha256" ]] || { echo "Abbruch: Software-Zip fehlt." >&2; exit 1; }

NAME="lichtkammer-testpaket-$VERSION"
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
D="$TMP/$NAME"; mkdir -p "$D"
cp "$SW" "$SW.sha256" "$D/"
cp "$PDF" "$D/Lichtkammer-Anleitung.pdf"
cp tools/testpaket/INSTALL.txt "$D/INSTALL.txt"
sed "s/@VERSION@/$VERSION/g" tools/testpaket/LIESMICH-Tester.txt > "$D/LIESMICH-Tester.txt"
git show HEAD:LICENSE > "$D/LICENSE"; git show HEAD:NOTICE > "$D/NOTICE"

echo "== Inhalt prüfen"
erlaubt=("lichtkammer-$VERSION.zip" "lichtkammer-$VERSION.zip.sha256" Lichtkammer-Anleitung.pdf INSTALL.txt LIESMICH-Tester.txt LICENSE NOTICE)
for f in $(cd "$D" && ls -A); do
  (( ${erlaubt[(Ie)$f]} )) || { echo "Abbruch: Unerwartete Datei im Testpaket: $f" >&2; exit 1; }
done
for f in $erlaubt; do [[ -f "$D/$f" ]] || { echo "Abbruch: $f fehlt." >&2; exit 1; }; done
# Verbotene Muster in den Textdateien und in der PDF (entpackt gelesen) sowie im Software-Zip (entpackt)
MUSTER='v119013|/Users/|pve10|pve01|luroPVE|@g(oogle)?mail\.|\.local>|BEGIN [A-Z ]*PRIVATE KEY|/htdocs/v[0-9]{3,}|otpauth://totp/[^ ]*secret=[A-Z2-7]{16}|printhero|lutz\.rothe'
# Nur für Texte und PDF zusätzlich: interne Dokumente dürfen nicht genannt werden
MUSTER_INTERN='docs/planung|CLAUDE\.md|Prüfbericht|Entwurf [0-9]{2}'
mkdir -p "$TMP/x"; (cd "$TMP/x" && unzip -q "$D/lichtkammer-$VERSION.zip")
bad=$(cd "$TMP/x" && find . \( -name '.env' -o -name '*.sqlite*' -o -name '*.db' -o -name '*.pem' -o -name '*.key' -o -name '.DS_Store' -o -path '*/tests/*' -o -path '*/deploy/*' -o -path '*/docs/*' \) -print)
[[ -z "$bad" ]] || { echo "Abbruch: Verbotene Dateien im Software-Zip:" >&2; echo "$bad" >&2; exit 1; }
hits=$(cd "$TMP/x" && grep -rIlE "$MUSTER" . || true)
[[ -z "$hits" ]] || { echo "Abbruch: Private Spuren im Software-Zip:" >&2; echo "$hits" >&2; exit 1; }
hits=$(cd "$D" && grep -IlE "$MUSTER|$MUSTER_INTERN" INSTALL.txt LIESMICH-Tester.txt LICENSE NOTICE || true)
[[ -z "$hits" ]] || { echo "Abbruch: Private Spuren in den Textdateien: $hits" >&2; exit 1; }
# PDF: Text und Metadaten (Titel, Autor, Pfade) prüfen
if command -v pdftotext >/dev/null; then pdftotext "$PDF" - > "$TMP/pdf.txt"; else strings "$PDF" > "$TMP/pdf.txt"; fi
hits=$(grep -IE "$MUSTER|$MUSTER_INTERN" "$TMP/pdf.txt" | head -3 || true)
[[ -z "$hits" ]] || { echo "Abbruch: Private Spuren in der PDF: $hits" >&2; exit 1; }
strings "$PDF" | grep -aiE '/(Author|Creator|Producer)' | head -3 || true

echo "== Zip schreiben (reproduzierbar)"
find "$D" -type f -exec touch -t 202601010000 {} +
mkdir -p dist; rm -f "dist/$NAME.zip"
(cd "$TMP" && find "$NAME" -type f | LC_ALL=C sort | zip -X -q "$OLDPWD/dist/$NAME.zip" -@)
(cd dist && (shasum -a 256 "$NAME.zip" 2>/dev/null || sha256sum "$NAME.zip") > "$NAME.zip.sha256")
echo "== Fertig: dist/$NAME.zip ($(du -h "dist/$NAME.zip" | cut -f1))"
unzip -l "dist/$NAME.zip" | tail -n +4 | sed '$d' | sed '$d'
cat "dist/$NAME.zip.sha256"
