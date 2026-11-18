#!/bin/zsh
# Einmalige Einrichtung des Testservers: erzeugt app/.env mit Schlüssel und Einrichtungs-Token.
# Wird von ./deploy.sh aufgerufen:
#   ./deploy.sh --einrichten --probe    Probelauf: zeigt nur, was passieren würde (erzeugt und überträgt nichts)
#   ./deploy.sh --einrichten            wirklich einrichten (nur nach Freigabe durch Lutz)
#   ./deploy.sh --token-entfernen       LK_SETUP_TOKEN aus der Server-.env löschen (nach der Einrichtung)
#   ./deploy.sh --env-setzen NAME=WERT [--probe]
#                                       einen erlaubten Wert in der Server-.env setzen oder ändern (Entwurf 20, M5)
# Nicht Teil davon: Schlüssel-Rotation und Änderungen an einer vorhandenen .env.
# Der Schlüssel LK_APP_KEY wird nie ausgegeben, nie als Programm-Argument übergeben und nie ins Repository geschrieben.
set -euo pipefail
umask 077

fehler() { echo "Abbruch: $*" >&2; exit 1; }

AUFRUF="./deploy.sh --einrichten [--probe] | --token-entfernen | --env-setzen NAME=WERT [--probe]"
MODUS="" PROBE=0 ENV_PAAR=""
while (( $# )); do
  case "$1" in
    --einrichten) MODUS=einrichten ;;
    --token-entfernen) MODUS=token ;;
    --env-setzen) MODUS=envsetzen; shift; (( $# )) || fehler "Nach --env-setzen fehlt NAME=WERT. Aufruf: $AUFRUF"; ENV_PAAR="$1" ;;
    --probe) PROBE=1 ;;
    *) fehler "Unbekanntes Argument \"$1\". Aufruf: $AUFRUF" ;;
  esac
  shift
done
[[ -n "$MODUS" ]] || fehler "Aufruf: $AUFRUF"
(( PROBE )) && [[ "$MODUS" == token ]] && fehler "--probe gibt es nur zusammen mit --einrichten oder --env-setzen."

cd "$(dirname "$0")/.."
DOMAIN="" FTP_HOST="" FTP_USER="" LK_SITE_NAME="" LK_DATA_DIR="" LK_ORIGINALS_DIR=""
ENVDATEI="${DEPLOY_ENV_DATEI:-deploy/.env}"
[[ -f "$ENVDATEI" ]] && source "${ENVDATEI:A}"
[[ -n "$DOMAIN" && -n "$FTP_HOST" && -n "$FTP_USER" ]] || fehler "In deploy/.env fehlen DOMAIN, FTP_HOST oder FTP_USER. Vorlage: deploy/env.beispiel"
command -v lftp >/dev/null || fehler "lftp fehlt (brew install lftp)."

BASE_URL="https://$DOMAIN"
SITE_NAME="${LK_SITE_NAME:-Lichtkammer}"
# Keine Vorgabe mehr im Code (Prüfung 21, N3): beide Ordner kommen aus deploy/.env, beide liegen über dem Webroot.
[[ "$MODUS" != einrichten || ( -n "$LK_DATA_DIR" && -n "$LK_ORIGINALS_DIR" ) ]] || fehler "In deploy/.env fehlen LK_DATA_DIR und LK_ORIGINALS_DIR (beide oberhalb des Webroots, z. B. /www/htdocs/vXXXXXX/lichtkammer-data und .../lichtkammer-originals). Vorlage: deploy/env.beispiel"
DATA_DIR="$LK_DATA_DIR"
ORIGINALS_DIR="$LK_ORIGINALS_DIR"
for w in "$SITE_NAME" "$DATA_DIR" "$ORIGINALS_DIR" "$DOMAIN"; do
  [[ "$w" != *[$'\n\r"\'\\']* ]] || fehler "Werte in deploy/.env dürfen keine Zeilenumbrüche, Anführungszeichen oder Backslashes enthalten."
done

DIENST="web-ftp-$DOMAIN"
KEY_DIENST="lichtkammer-app-key-$DOMAIN"
LFTP_PASSWORD=$(security find-generic-password -a "$FTP_USER" -s "$DIENST" -w 2>/dev/null) || \
  fehler "Kein Passwort im Schlüsselbund (Dienst $DIENST, Konto $FTP_USER). Lutz legt es an mit:
  security add-generic-password -U -a $FTP_USER -s $DIENST -w"
export LFTP_PASSWORD

TMP=""
aufraeumen() {
  if [[ -n "$TMP" && -d "$TMP" ]]; then
    for f in "$TMP"/*(DN); do
      rm -P -f "$f" 2>/dev/null || rm -f "$f"
    done
    rmdir "$TMP" 2>/dev/null || true
  fi
  unset LFTP_PASSWORD
}
trap aufraeumen EXIT
trap 'exit 130' INT TERM HUP

ftp() { # ftp "<lftp-Befehle>" – feste Sicherheitseinstellungen, Passwort über LFTP_PASSWORD
  lftp -c "set ftp:ssl-force true; set ftp:ssl-protect-data true; set ssl:verify-certificate true; set net:max-retries 2; set cmd:fail-exit true; open --user $FTP_USER --env-password ftp://$FTP_HOST; $1"
}

# Liegt app/.env auf dem Server? Rückgabe 0 = ja, 1 = nein; Abbruch, wenn die Abfrage selbst scheitert.
server_env_vorhanden() {
  local liste
  liste=$(ftp "cls -1 -a /app") || fehler "Ordner app/ auf dem Server nicht lesbar. Zuerst ./deploy.sh --jetzt, damit der Code (und app/) auf dem Server liegt."
  print -r -- "$liste" | grep -Eq '(^|/)\.env$'
}

http_code() { curl -s -o /dev/null -w "%{http_code}" --max-time 20 "$BASE_URL/app/.env" || true; }

if [[ "$MODUS" == einrichten ]]; then
  echo "== Einrichten für $DOMAIN (Server $FTP_HOST, Benutzer $FTP_USER)"
  if server_env_vorhanden; then
    fehler "Auf dem Server liegt schon app/.env. Es wird nichts überschrieben (Rotation und Änderungen gehören nicht zu --einrichten)."
  fi
  echo "== Auf dem Server liegt noch keine app/.env."
  if security find-generic-password -a LK_APP_KEY -s "$KEY_DIENST" >/dev/null 2>&1; then
    SCHLUESSEL_BEKANNT=1
  else
    SCHLUESSEL_BEKANNT=0
  fi

  if (( PROBE )); then
    echo "== PROBELAUF – es wird nichts erzeugt, gespeichert oder übertragen. Geplant:"
    if (( SCHLUESSEL_BEKANNT )); then
      echo "  1. Schlüssel: im Schlüsselbund liegt schon einer (Dienst $KEY_DIENST); er wird wiederverwendet, weil auf dem Server noch keine .env liegt."
    else
      echo "  1. LK_APP_KEY erzeugen (openssl rand -base64 32), im Schlüsselbund ablegen (Dienst $KEY_DIENST, Konto LK_APP_KEY), Lesbarkeit prüfen."
    fi
    echo "  2. LK_SETUP_TOKEN erzeugen (openssl rand -hex 24); wird Ihnen einmal angezeigt."
    echo "  3. .env in einem frischen Ordner (Rechte 600) bauen mit:"
    cat <<TXT
       LK_ENV=production
       LK_BASE_URL=$BASE_URL
       LK_SITE_NAME=$SITE_NAME
       LK_NOINDEX=1
       LK_APP_KEY=<wird erzeugt, wird nie angezeigt>
       LK_SETUP_TOKEN=<wird erzeugt, wird einmal angezeigt>
       LK_DATA_DIR=$DATA_DIR
       LK_ORIGINALS_DIR=$ORIGINALS_DIR
TXT
    echo "  4. Per FTPS nach $FTP_HOST:/app/.env übertragen (Rechte 600), lokale Datei sofort löschen."
    echo "  5. Prüfen, dass $BASE_URL/app/.env 403 oder 404 liefert."
    echo "== Echter Lauf (nach Freigabe durch Lutz): ./deploy.sh --einrichten"
    exit 0
  fi

  # Schlüssel zuerst in den Schlüsselbund, prüfen, erst dann weitermachen
  if (( SCHLUESSEL_BEKANNT )); then
    echo "== Schlüssel liegt schon im Schlüsselbund (Dienst $KEY_DIENST), wird wiederverwendet."
    KEY=$(security find-generic-password -a LK_APP_KEY -s "$KEY_DIENST" -w 2>/dev/null) || fehler "Schlüssel im Schlüsselbund nicht lesbar."
  else
    KEY=$(openssl rand -base64 32)
    # Über Standardeingabe (security -i), damit der Schlüssel in keiner Prozessliste auftaucht
    security -i <<<"add-generic-password -a LK_APP_KEY -s $KEY_DIENST -w $KEY" >/dev/null 2>&1 || fehler "Schlüssel konnte nicht im Schlüsselbund abgelegt werden. Es wurde nichts übertragen."
    GELESEN=$(security find-generic-password -a LK_APP_KEY -s "$KEY_DIENST" -w 2>/dev/null) || GELESEN=""
    [[ -n "$KEY" && "$GELESEN" == "$KEY" ]] || fehler "Schlüssel im Schlüsselbund nicht lesbar oder abweichend. Es wurde nichts übertragen."
    echo "== Schlüssel im Schlüsselbund abgelegt und gelesen (Dienst $KEY_DIENST, Konto LK_APP_KEY)."
  fi
  [[ "$KEY" =~ '^[A-Za-z0-9+/]{43}=$' ]] || fehler "Schlüssel im Schlüsselbund hat nicht das erwartete Format (32 Byte, Base64)."
  TOKEN=$(openssl rand -hex 24)

  TMP=$(mktemp -d "${TMPDIR:-/tmp}/lk-einrichten.XXXXXX")
  chmod 700 "$TMP"
  {
    print -r -- "LK_ENV=production"
    print -r -- "LK_BASE_URL=$BASE_URL"
    print -r -- "LK_SITE_NAME=$SITE_NAME"
    print -r -- "LK_NOINDEX=1"
    print -r -- "LK_APP_KEY=$KEY"
    print -r -- "LK_SETUP_TOKEN=$TOKEN"
    print -r -- "LK_DATA_DIR=$DATA_DIR"
    print -r -- "LK_ORIGINALS_DIR=$ORIGINALS_DIR"
  } > "$TMP/.env"
  chmod 600 "$TMP/.env"
  unset KEY

  echo "== Übertrage app/.env per FTPS"
  ( cd "$TMP" && ftp "put -O /app .env; chmod 600 /app/.env" ) >/dev/null || fehler "Übertragung fehlgeschlagen. Auf dem Server nachsehen, ob app/.env liegt; der Schlüssel bleibt im Schlüsselbund."
  rm -P -f "$TMP/.env" 2>/dev/null || rm -f "$TMP/.env"
  echo "== Lokale temporäre Datei gelöscht."

  CODE=$(http_code)
  if [[ "$CODE" == 403 || "$CODE" == 404 ]]; then
    echo "== $BASE_URL/app/.env liefert $CODE (nicht abrufbar). Gut."
  else
    echo "!! $BASE_URL/app/.env liefert \"$CODE\", erwartet 403 oder 404." >&2
    echo "!! Die Datei ist womöglich abrufbar. Sofort app/.env auf dem Server löschen und Claude Bescheid geben." >&2
    echo "!! Das Einrichtungs-Token wird nicht angezeigt." >&2
    exit 1
  fi

  cat <<TXT

==========================================================
 Einrichtungs-Token (wird nur jetzt einmal angezeigt):

   $TOKEN

 1. $BASE_URL/admin/setup öffnen und das Token dort eingeben.
 2. Danach das Token vom Server entfernen:
      ./deploy.sh --token-entfernen
 Der Schlüssel liegt im Schlüsselbund (Dienst $KEY_DIENST, Konto LK_APP_KEY)
 und sollte zusätzlich in den Passwort-Manager.
==========================================================
TXT
  exit 0
fi

# --- --env-setzen NAME=WERT ---
if [[ "$MODUS" == envsetzen ]]; then
  # Nur Werte, die kein Geheimnis sind und die sich im Betrieb ändern. LK_APP_KEY, die Token und Passwörter gehören nicht dazu.
  ERLAUBT=(LK_BASE_URL LK_NOINDEX LK_ORIGINALS_DIR LK_RESTORE_DIR LK_REDIRECT_HOSTS LK_REDIRECTS_PERMANENT LK_SITE_NAME LK_ENV)
  [[ "$ENV_PAAR" == *=* ]] || fehler "Erwartet wird NAME=WERT (zum Beispiel LK_NOINDEX=0)."
  NAME="${ENV_PAAR%%=*}" WERT="${ENV_PAAR#*=}"
  (( ${ERLAUBT[(Ie)$NAME]} )) || fehler "$NAME darf so nicht gesetzt werden. Erlaubt: ${ERLAUBT[*]}. Schlüssel und Token bleiben davon ausgenommen."
  [[ "$WERT" != *[$'\n\r"\'\\ #']* && -n "$WERT" ]] || fehler "Der Wert darf nicht leer sein und keine Leerzeichen, Zeilenumbrüche, Anführungszeichen, Backslashes oder # enthalten."
  case "$NAME" in
    LK_BASE_URL) [[ "$WERT" =~ '^https://[A-Za-z0-9.-]+(:[0-9]+)?$' ]] || fehler "LK_BASE_URL muss die Form https://www.beispiel.de haben (ohne Pfad, ohne Schrägstrich am Ende)." ;;
    LK_NOINDEX|LK_REDIRECTS_PERMANENT) [[ "$WERT" == 0 || "$WERT" == 1 ]] || fehler "$NAME erwartet 0 oder 1." ;;
    LK_ENV) [[ "$WERT" == production || "$WERT" == development ]] || fehler "LK_ENV erwartet production oder development." ;;
    LK_ORIGINALS_DIR|LK_RESTORE_DIR) [[ "$WERT" == /* ]] || fehler "$NAME erwartet einen absoluten Pfad." ;;
    LK_REDIRECT_HOSTS) [[ "$WERT" =~ '^[A-Za-z0-9.,-]+$' ]] || fehler "LK_REDIRECT_HOSTS erwartet Hostnamen, durch Komma getrennt, ohne Leerzeichen." ;;
  esac
  echo "== Wert setzen für $DOMAIN: $NAME=$WERT"
  server_env_vorhanden || fehler "Auf dem Server liegt keine app/.env."
  TMP=$(mktemp -d "${TMPDIR:-/tmp}/lk-envsetzen.XXXXXX")
  chmod 700 "$TMP"
  ( cd "$TMP" && ftp "get /app/.env -o .env" ) >/dev/null || fehler "app/.env konnte nicht geladen werden."
  chmod 600 "$TMP/.env"
  grep -q '^LK_APP_KEY=.\{20,\}' "$TMP/.env" || fehler "Die Server-.env enthält keinen Schlüssel; sie wird nicht verändert."
  ALT=$(grep "^$NAME=" "$TMP/.env" | tail -n 1 | cut -d= -f2- || true)
  if [[ -n "$ALT" ]]; then echo "   bisher: $NAME=$ALT"; else echo "   bisher: nicht gesetzt"; fi
  if [[ "$ALT" == "$WERT" ]]; then
    echo "== Der Wert steht schon so in der Server-.env. Nichts zu tun."
    exit 0
  fi
  if (( PROBE )); then
    echo "== PROBELAUF – nichts wurde verändert. Ohne --probe würde $NAME=$WERT in app/.env gesetzt (Rechte 600), der Schlüssel bliebe unangetastet und wird nie angezeigt."
    exit 0
  fi
  { grep -v "^$NAME=" "$TMP/.env" || true; print -r -- "$NAME=$WERT"; } > "$TMP/neu"
  chmod 600 "$TMP/neu"
  grep -q '^LK_APP_KEY=' "$TMP/neu" || fehler "Interner Fehler: Schlüsselzeile fehlt nach der Änderung; nichts hochgeladen."
  mv "$TMP/neu" "$TMP/.env"
  ( cd "$TMP" && ftp "put -O /app .env; chmod 600 /app/.env" ) >/dev/null || fehler "Hochladen fehlgeschlagen. Die Server-.env ist unverändert."
  echo "== $NAME in app/.env auf dem Server gesetzt (Rechte 600). Der Schlüssel wurde nicht angezeigt."
  CODE=$(http_code)
  [[ "$CODE" == 403 || "$CODE" == 404 ]] && echo "== $BASE_URL/app/.env liefert $CODE. Gut." || { echo "!! $BASE_URL/app/.env liefert \"$CODE\", erwartet 403 oder 404." >&2; exit 1; }
  exit 0
fi

# --- --token-entfernen ---
echo "== Token entfernen für $DOMAIN"
server_env_vorhanden || fehler "Auf dem Server liegt keine app/.env."
TMP=$(mktemp -d "${TMPDIR:-/tmp}/lk-token.XXXXXX")
chmod 700 "$TMP"
( cd "$TMP" && ftp "get /app/.env -o .env" ) >/dev/null || fehler "app/.env konnte nicht geladen werden."
chmod 600 "$TMP/.env"
grep -q '^LK_APP_KEY=.\{20,\}' "$TMP/.env" || fehler "Die Server-.env enthält keinen Schlüssel; sie wird nicht verändert."
if ! grep -q '^LK_SETUP_TOKEN=' "$TMP/.env"; then
  echo "== In der Server-.env steht kein LK_SETUP_TOKEN. Nichts zu tun."
  exit 0
fi
grep -v '^LK_SETUP_TOKEN=' "$TMP/.env" > "$TMP/neu"
chmod 600 "$TMP/neu"
grep -q '^LK_APP_KEY=' "$TMP/neu" || fehler "Interner Fehler: Schlüsselzeile fehlt nach dem Entfernen; nichts hochgeladen."
mv "$TMP/neu" "$TMP/.env"
( cd "$TMP" && ftp "put -O /app .env; chmod 600 /app/.env" ) >/dev/null || fehler "Hochladen fehlgeschlagen. Das Token steht noch auf dem Server."
echo "== LK_SETUP_TOKEN aus app/.env auf dem Server entfernt (Rechte 600). Der Schlüssel wurde nicht angezeigt."
CODE=$(http_code)
[[ "$CODE" == 403 || "$CODE" == 404 ]] && echo "== $BASE_URL/app/.env liefert $CODE. Gut." || echo "!! $BASE_URL/app/.env liefert \"$CODE\", erwartet 403 oder 404." >&2
