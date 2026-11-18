#!/bin/zsh
# Lädt den CODE von Lichtkammer per FTPS zu all-inkl hoch (public/ in den Webroot, app/ nach /app).
#   ./deploy.sh          Probelauf: zeigt nur, was übertragen würde
#   ./deploy.sh --jetzt  wirklich hochladen und danach live prüfen
# Überträgt nie data/, originals/, media/ oder .env. Löscht auf dem Server nichts.
# Zugang: deploy/.env (Vorlage deploy/env.beispiel), Passwort im macOS-Schlüsselbund.
# --jetzt nur nach Probelauf und Freigabe durch Lutz.
set -euo pipefail
cd "$(dirname "$0")"

# Einmalige Einrichtung des Servers (app/.env): siehe deploy/einrichten.sh
#   ./deploy.sh --einrichten --probe   Probelauf   |   ./deploy.sh --einrichten   echt   |   ./deploy.sh --token-entfernen
#   ./deploy.sh --env-setzen NAME=WERT [--probe]   einen erlaubten Wert in der Server-.env ändern (z. B. LK_BASE_URL)
case "${1:-}" in
  --einrichten|--token-entfernen|--env-setzen) exec ./deploy/einrichten.sh "$@" ;;
esac

fehler() { echo "Abbruch: $*" >&2; exit 1; }
JETZT=0
case "${1:-}" in
  --jetzt) JETZT=1 ;;
  "") ;;
  *) echo "Aufruf: ./deploy.sh (Probelauf) oder ./deploy.sh --jetzt (hochladen)"; exit 2 ;;
esac

DOMAIN="" FTP_HOST="" FTP_USER=""
[[ -f deploy/.env ]] && source ./deploy/.env
[[ -n "$DOMAIN" && -n "$FTP_HOST" && -n "$FTP_USER" ]] || fehler "In deploy/.env fehlen DOMAIN, FTP_HOST oder FTP_USER. Vorlage: deploy/env.beispiel"
command -v lftp >/dev/null || fehler "lftp fehlt (brew install lftp)."

if (( JETZT )) && [[ -n "$(git status --porcelain -- app public 2>/dev/null)" ]]; then
  fehler "Änderungen in app/ oder public/ sind nicht committet. Erst committen, dann hochladen."
fi

VERSION=$(grep -oE "VERSION = '[^']+'" app/src/Config.php | cut -d"'" -f2)
[[ -n "$VERSION" ]] || fehler "Keine Version in app/src/Config.php gefunden."
echo "== Version $VERSION, Ziel $DOMAIN"

DIENST="web-ftp-$DOMAIN"
LFTP_PASSWORD=$(security find-generic-password -a "$FTP_USER" -s "$DIENST" -w 2>/dev/null) || \
  fehler "Kein Passwort im Schlüsselbund (Dienst $DIENST, Konto $FTP_USER). Lutz legt es an mit:
  security add-generic-password -U -a $FTP_USER -s $DIENST -w"
export LFTP_PASSWORD

MOPTS="--reverse --overwrite --verbose --exclude-glob .DS_Store --exclude-glob .env --exclude ^media/"
(( JETZT )) || MOPTS+=" --dry-run"
# media/ (Fotos) wird nie übertragen, nur die PHP-Sperre media/.htaccess (ein Glob wie media/* würde sie mit ausschließen).
MEDIA_HTACCESS="mkdir -p -f media; put -O media public/media/.htaccess"
(( JETZT )) || MEDIA_HTACCESS="echo 'Probelauf: media/.htaccess würde übertragen'"
# Der FTP-Benutzer der Domain sieht nur ihren Ordner: public/ liegt im Webroot, app/ darin als /app
lftp -c "set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; set net:max-retries 2; set cmd:fail-exit true; open --user $FTP_USER --env-password ftp://$FTP_HOST; mirror $MOPTS public/ /; mirror $MOPTS app/ /app; $MEDIA_HTACCESS"
unset LFTP_PASSWORD

if (( ! JETZT )); then
  echo "== Probelauf, nichts übertragen. Hochladen (nach Freigabe durch Lutz): ./deploy.sh --jetzt"
  exit 0
fi

echo "== Live prüfen: https://$DOMAIN/health"
ONLINE=$(curl -fsS -H 'Cache-Control: no-cache' "https://$DOMAIN/health?v=$VERSION" | awk '{print $2}' || true)
[[ "$ONLINE" == "$VERSION" ]] || fehler "Online steht Version \"${ONLINE:-keine}\", erwartet $VERSION. Bitte nachsehen."
echo "== Fertig: https://$DOMAIN/ hat Version $VERSION."
