#!/bin/zsh
# Tests für deploy/einrichten.sh mit Attrappen für security, lftp und curl (kein echter Server).
#   zsh tests/einrichten.sh
cd "$(dirname "$0")/.."
ROOT=$PWD
FEHLER=0; ANZAHL=0
pruefe() { ANZAHL=$((ANZAHL+1)); if ! eval "$2"; then FEHLER=$((FEHLER+1)); echo "FEHLER: $1" >&2; fi; }

neu() { # frische Umgebung
  S=$(mktemp -d); export STUB_DIR=$S TMPDIR=$S/tmp; mkdir -p $TMPDIR
  print "DOMAIN=test.example\nFTP_HOST=ftp.example\nFTP_USER=u1\nLK_DATA_DIR=/data/x" > $S/deploy.env
  export DEPLOY_ENV_DATEI=$S/deploy.env
  print -rn "ftp-geheim" > $S/keychain-web-ftp-test.example
  : > $S/aufrufe.log; : > $S/listing
  unset STUB_CLS_FEHLT STUB_PUT_FEHLT STUB_HTTP STUB_KEYCHAIN_SCHREIBEN_FEHLT
  export PATH="$ROOT/tests/stubs:$ORIG_PATH"
}
ORIG_PATH=$PATH
lauf() { zsh ./deploy.sh "$@" > $S/out.txt 2> $S/err.txt; RC=$?; ALL="$(cat $S/out.txt $S/err.txt)"; }
ohne_reste() { [[ -z "$(ls -A $TMPDIR)" ]]; }

# Argumente
neu; lauf --einrichten --foo
pruefe "unbekanntes Argument -> Abbruch" '(( RC != 0 ))'
lauf --token-entfernen --probe
pruefe "--token-entfernen --probe -> Abbruch" '(( RC != 0 ))'

# Probelauf: nichts erzeugt, nichts übertragen, kein Schlüsselbund-Schreiben
neu; lauf --einrichten --probe
pruefe "Probelauf Exit 0" '(( RC == 0 ))'
pruefe "Probelauf: kein put" '! grep -q "put -O" $S/aufrufe.log'
pruefe "Probelauf: kein Schlüssel im Schlüsselbund" '[[ ! -f $S/keychain-lichtkammer-app-key-test.example ]]'
pruefe "Probelauf: zeigt Werte" '[[ "$ALL" == *"LK_BASE_URL=https://test.example"* && "$ALL" == *"LK_DATA_DIR=/data/x"* ]]'
pruefe "Probelauf: Aufräumen" ohne_reste

# vorhandene .env -> Abbruch, nichts überschrieben
neu; print ".env\nindex.php" > $S/listing; lauf --einrichten
pruefe "vorhandene .env -> Abbruch" '(( RC != 0 )) && [[ "$ALL" == *"schon app/.env"* ]]'
pruefe "vorhandene .env: kein put, kein Schlüssel" '! grep -q "put -O" $S/aufrufe.log && [[ ! -f $S/keychain-lichtkammer-app-key-test.example ]]'
# Auflistung scheitert -> Abbruch
neu; export STUB_CLS_FEHLT=1; lauf --einrichten
pruefe "Server-Abfrage scheitert -> Abbruch" '(( RC != 0 )) && ! grep -q "put -O" $S/aufrufe.log'

# echter Lauf (Attrappen)
neu; print "index.php" > $S/listing; lauf --einrichten
KEY=$(cat $S/keychain-lichtkammer-app-key-test.example 2>/dev/null)
HOCH=$(cat $S/hochgeladen.env 2>/dev/null)
TOKEN=$(print -r -- "$HOCH" | sed -n 's/^LK_SETUP_TOKEN=//p')
pruefe "Lauf Exit 0" '(( RC == 0 ))'
pruefe "Schlüssel im Schlüsselbund, 44 Zeichen Base64" '[[ "$KEY" =~ ^[A-Za-z0-9+/]{43}=$ ]]'
pruefe ".env enthält alle Werte" '[[ "$HOCH" == *"LK_ENV=production"* && "$HOCH" == *"LK_BASE_URL=https://test.example"* && "$HOCH" == *"LK_NOINDEX=1"* && "$HOCH" == *"LK_SITE_NAME=Lichtkammer"* && "$HOCH" == *"LK_DATA_DIR=/data/x"* && "$HOCH" == *"LK_APP_KEY=$KEY"* ]]'
pruefe "Token 48 Hex-Zeichen" '[[ "$TOKEN" =~ ^[0-9a-f]{48}$ ]]'
pruefe "Rechte 600 beim Hochladen" 'grep -q "Rechte der lokalen Datei: 600" $S/aufrufe.log'
pruefe "Schlüssel nie in der Ausgabe" '[[ "$ALL" != *"$KEY"* ]]'
pruefe "Schlüssel nie in Programm-Argumenten" '! grep -qF -- "$KEY" $S/aufrufe.log'
pruefe "FTP-Passwort nicht in Argumenten/Ausgabe" '! grep -q "ftp-geheim" $S/aufrufe.log && [[ "$ALL" != *ftp-geheim* ]]'
pruefe "Token genau einmal angezeigt" '[[ $(print -r -- "$ALL" | grep -c "$TOKEN") == 1 ]]'
pruefe "temporärer Ordner gelöscht" ohne_reste

# Wiederholung nach Fehlschlag: vorhandener Schlüssel wird wiederverwendet
neu; print "index.php" > $S/listing; export STUB_PUT_FEHLT=1; lauf --einrichten
pruefe "Übertragung scheitert -> Abbruch" '(( RC != 0 ))'
pruefe "Abbruch: temporärer Ordner trotzdem gelöscht (trap)" ohne_reste
pruefe "Abbruch: Token nicht angezeigt" '[[ "$ALL" != *LK_SETUP_TOKEN* ]]'
K1=$(cat $S/keychain-lichtkammer-app-key-test.example); unset STUB_PUT_FEHLT
lauf --einrichten; HOCH=$(cat $S/hochgeladen.env)
pruefe "zweiter Versuch nutzt vorhandenen Schlüssel" '(( RC == 0 )) && [[ "$HOCH" == *"LK_APP_KEY=$K1"* ]]'

# Schlüsselbund schreibt nicht -> nichts wird übertragen
neu; print "index.php" > $S/listing; export STUB_KEYCHAIN_SCHREIBEN_FEHLT=1; lauf --einrichten
pruefe "Schlüsselbund-Fehler -> Abbruch ohne Übertragung" '(( RC != 0 )) && ! grep -q "put -O" $S/aufrufe.log'
pruefe "Schlüsselbund-Fehler: Aufräumen" ohne_reste

# Prüfung per curl schlägt an (200)
neu; print "index.php" > $S/listing; export STUB_HTTP=200; lauf --einrichten
TOKEN=$(sed -n 's/^LK_SETUP_TOKEN=//p' $S/hochgeladen.env)
pruefe "HTTP 200 -> Abbruch, Token nicht angezeigt" '(( RC != 0 )) && [[ "$ALL" != *"$TOKEN"* ]]'
pruefe "HTTP 200: Aufräumen" ohne_reste

# Token entfernen
neu; print ".env" > $S/listing
print "LK_ENV=production\nLK_APP_KEY=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=\nLK_SETUP_TOKEN=abc123\nLK_DATA_DIR=/d" > $S/server.env
lauf --token-entfernen; HOCH=$(cat $S/hochgeladen.env 2>/dev/null)
pruefe "Token entfernt, Rest bleibt" '(( RC == 0 )) && [[ "$HOCH" != *LK_SETUP_TOKEN* && "$HOCH" == *LK_APP_KEY=* && "$HOCH" == *LK_DATA_DIR=/d* ]]'
pruefe "Token-Entfernen: Schlüssel nicht in der Ausgabe" '[[ "$ALL" != *AAAAAAAAAAAA* ]]'
pruefe "Token-Entfernen: Aufräumen" ohne_reste
neu; print "index.php" > $S/listing; lauf --token-entfernen
pruefe "ohne Server-.env -> Abbruch" '(( RC != 0 ))'
neu; print ".env" > $S/listing; print "LK_ENV=production\nLK_SETUP_TOKEN=abc" > $S/server.env; lauf --token-entfernen
pruefe "Server-.env ohne Schlüssel -> nicht verändert" '(( RC != 0 )) && [[ ! -f $S/hochgeladen.env ]]'

echo "$ANZAHL Prüfungen, $FEHLER Fehler"
(( FEHLER == 0 ))
