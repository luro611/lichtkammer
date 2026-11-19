#!/bin/bash
# Baut das Zip für Webspace-Installationen (nur FTP): lichtkammer-<Version>.zip samt Prüfsumme.
#   tools/release-zip.sh              baut aus dem letzten Commit (HEAD) nach dist/
#   tools/release-zip.sh --pruefen    baut und prüft zusätzlich den Inhalt (nichts Verbotenes im Paket)
# Es kommt nur hinein, was im Repository versioniert ist (git archive): kein data/, keine .env, keine Fotos.
set -euo pipefail
cd "$(dirname "$0")/.."

PRUEFEN=0
[[ "${1:-}" == "--pruefen" ]] && PRUEFEN=1
command -v git >/dev/null && command -v zip >/dev/null || { echo "Abbruch: git und zip werden gebraucht." >&2; exit 1; }

VERSION=$(grep -oE "VERSION = '[^']+'" app/src/Config.php | cut -d"'" -f2)
[[ -n "$VERSION" ]] || { echo "Abbruch: Keine Version in app/src/Config.php." >&2; exit 1; }
# Bei einem Tag muss die Version im Code zum Tag passen.
if [[ -n "${GITHUB_REF_NAME:-}" && "${GITHUB_REF_NAME}" == v* && "${GITHUB_REF_NAME#v}" != "$VERSION" ]]; then
  echo "Abbruch: Tag ${GITHUB_REF_NAME} passt nicht zur Version $VERSION in app/src/Config.php." >&2; exit 1
fi

NAME="lichtkammer-$VERSION"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/$NAME" dist
git archive --format=tar HEAD app public docker Dockerfile compose.yaml .dockerignore .env.example LICENSE NOTICE README.md tools/INSTALL.txt | tar -x -C "$TMP/$NAME"
mv "$TMP/$NAME/tools/INSTALL.txt" "$TMP/$NAME/INSTALL.txt"
rmdir "$TMP/$NAME/tools"
echo "$VERSION" > "$TMP/$NAME/VERSION"

if (( PRUEFEN )); then
  bad=$(cd "$TMP/$NAME" && find . \( -name '.env' -o -name '*.sqlite' -o -name '*.sqlite3' -o -name '*.db' -o -name '*.pem' -o -name '*.key' -o -name '.DS_Store' \) -print; \
        find . -path './data/*' -o -path './originals/*' -o -path './tests/*' -o -path './docs/*' -o -path './deploy/*' -o -path './public/media/*' ! -name '.htaccess' 2>/dev/null | grep -v '^./public/media/.htaccess$' || true)
  [[ -z "$bad" ]] || { echo "Abbruch: Verbotene Dateien im Paket:" >&2; echo "$bad" >&2; exit 1; }
  # Private Spuren im Inhalt (Prüfung 39): lokale Mac-Pfade, Gmail, Rechnername als Adresse, private Schlüssel,
  # echte all-inkl-Kundennummer im Pfad (Platzhalter vXXXXXX geht durch), TOTP-Adresse mit festem Schlüssel.
  PRIVAT_MUSTER='/Users/|@g(oogle)?mail\.|\.local>|BEGIN [A-Z ]*PRIVATE KEY|/htdocs/v[0-9]{3,}|otpauth://totp/[^ ]*secret=[A-Z2-7]{16}'
  spuren=$(cd "$TMP/$NAME" && grep -rIlE "$PRIVAT_MUSTER" . || true)
  [[ -z "$spuren" ]] || { echo "Abbruch: Private Spuren im Paket (Muster PRIVAT_MUSTER):" >&2; echo "$spuren" >&2; exit 1; }
  for must in app/src/App.php public/index.php public/media/.htaccess public/.user.ini public/fehler.html Dockerfile compose.yaml .dockerignore docker/entrypoint.sh .env.example INSTALL.txt LICENSE NOTICE VERSION; do
    [[ -e "$TMP/$NAME/$must" ]] || { echo "Abbruch: $must fehlt im Paket." >&2; exit 1; }
  done
fi

rm -f "dist/$NAME.zip" "dist/$NAME.zip.sha256"
# Reproduzierbar: feste Zeitstempel, sortierte Dateiliste, keine Zusatzfelder (zip -X)
find "$TMP/$NAME" -exec touch -t 202601010000 {} +
(cd "$TMP" && find "$NAME" | LC_ALL=C sort | zip -X -q "$OLDPWD/dist/$NAME.zip" -@)
(cd dist && (shasum -a 256 "$NAME.zip" 2>/dev/null || sha256sum "$NAME.zip") > "$NAME.zip.sha256")
echo "== Fertig: dist/$NAME.zip ($(du -h "dist/$NAME.zip" | cut -f1))"
cat "dist/$NAME.zip.sha256"
