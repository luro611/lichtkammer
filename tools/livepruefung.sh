#!/bin/zsh
# Lichtkammer: Prüfung einer laufenden Installation von außen (nur lesende Abrufe, ändert nichts).
# Entwurf 20, Abschnitt 1.2/3.5/3.6. Läuft nach jedem Hochladen und nach dem Umschalten der Domain.
#
#   tools/livepruefung.sh https://lichtkammer.br-foto.com [--index|--noindex] [--urlliste datei] [--neben-host host]...
#
#   --index / --noindex   erwartet robots.txt „Allow“ (live) bzw. „Disallow: /“ (Testserver); ohne Angabe wird nur angezeigt
#   --urlliste datei      je Zeile ein Pfad der alten Seite (z. B. /featured-content/island-2022/); jeder muss 200 liefern,
#                         auch nach Weiterleitungen (nie 404); Lücken kommen in die Weiterleitungstabelle
#   --neben-host host     erwartet 301 von dieser Adresse auf die Hauptadresse (mehrfach möglich)
# Ausgabe: je Prüfung eine Zeile OK oder FEHLER; Exit-Code 1, wenn etwas fehlschlägt.
set -uo pipefail

BASIS="" ERWARTUNG="" URLLISTE="" NEBEN=()
while (( $# )); do
  case "$1" in
    --index) ERWARTUNG=index ;;
    --noindex) ERWARTUNG=noindex ;;
    --urlliste) shift; URLLISTE="${1:-}" ;;
    --neben-host) shift; NEBEN+=("${1:-}") ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    https://*) BASIS="${1%/}" ;;
    *) echo "Unbekanntes Argument \"$1\". Aufruf: tools/livepruefung.sh https://adresse [--index|--noindex] [--urlliste datei] [--neben-host host]" >&2; exit 2 ;;
  esac
  shift
done
[[ -n "$BASIS" ]] || { echo "Aufruf: tools/livepruefung.sh https://adresse [--index|--noindex] [--urlliste datei] [--neben-host host]" >&2; exit 2; }
[[ -z "$URLLISTE" || -f "$URLLISTE" ]] || { echo "Die URL-Liste \"$URLLISTE\" gibt es nicht." >&2; exit 2; }
HOST="${BASIS#https://}"
FEHLER=0
CURL=(curl -s --max-time 25)

ok()  { printf "  OK      %s\n" "$1"; }
bad() { printf "  FEHLER  %s\n" "$1"; FEHLER=$((FEHLER+1)); }
code() { "${CURL[@]}" -o /dev/null -w "%{http_code}" "$@" 2>/dev/null || echo 000; }
kopf() { # kopf <url> <Kopfname> -> Wert (leer, wenn nicht gesetzt)
  "${CURL[@]}" -I "$1" 2>/dev/null | tr -d '\r' | grep -i "^$2:" | head -n 1 | cut -d: -f2- | sed 's/^ *//'
}

echo "== Prüfung von $BASIS"

echo "-- Sperren (erwartet 403 oder 404)"
for p in /app/.env /app/bootstrap.php /app/src/Config.php /data/ /data/lichtkammer.sqlite /originals/ /import/manifest.json /restore/MANIFEST.json /.git/HEAD /.env /media/x.php /x.bak /x.sql /x.zip /.user.ini; do
  c=$(code "$BASIS$p")
  [[ "$c" == 403 || "$c" == 404 ]] && ok "$p → $c" || bad "$p → $c (erwartet 403 oder 404)"
done

echo "-- HTTPS"
c=$(code "http://$HOST/")
loc=$("${CURL[@]}" -o /dev/null -w "%{redirect_url}" "http://$HOST/" 2>/dev/null || true)
[[ "$c" == 301 && "$loc" == https://* ]] && ok "http → https ($c)" || bad "http://$HOST/ → $c $loc (erwartet 301 auf https)"
[[ "$(code "$BASIS/health")" == 200 ]] && ok "/health → 200" || bad "/health liefert nicht 200"
body=$("${CURL[@]}" "$BASIS/health" 2>/dev/null)
[[ "$body" == ok* ]] && ok "/health meldet ok" || bad "/health antwortet nicht mit ok"

echo "-- Köpfe"
for p in / /admin/login; do
  for k in Content-Security-Policy Strict-Transport-Security X-Content-Type-Options X-Frame-Options Referrer-Policy Cross-Origin-Opener-Policy Cross-Origin-Resource-Policy; do
    v=$(kopf "$BASIS$p" "$k")
    [[ -n "$v" ]] && ok "$p: $k gesetzt" || bad "$p: $k fehlt"
  done
done
v=$(kopf "$BASIS/admin/login" Cache-Control)
[[ "$v" == *no-store* ]] && ok "/admin/login: Cache-Control no-store" || bad "/admin/login: Cache-Control no-store fehlt (\"$v\")"
v=$(kopf "$BASIS/" Strict-Transport-Security)
[[ "$v" == *includeSubDomains* || "$v" == *preload* ]] && bad "HSTS mit includeSubDomains/preload: $v" || ok "HSTS ohne includeSubDomains und preload"
v=$(kopf "$BASIS/" X-Powered-By)
[[ -z "$v" ]] && ok "kein X-Powered-By" || bad "X-Powered-By gesetzt: $v"
css=$("${CURL[@]}" "$BASIS/" 2>/dev/null | grep -o '/assets/css/[^"?]*' | head -n 1)
if [[ -n "$css" ]]; then
  v=$(kopf "$BASIS$css" X-Content-Type-Options)
  [[ "$v" == nosniff ]] && ok "$css: nosniff (Datei von Apache)" || bad "$css: nosniff fehlt"
else
  echo "  --      keine Stylesheet-Adresse auf der Startseite gefunden (noch nicht veröffentlicht?)"
fi

echo "-- Sicherung und Fehlerseite (ohne Anmeldung nie erreichbar)"
for p in /admin/sicherung /admin/sicherung/originale /admin/sicherung/original/1 /admin/system; do
  c=$(code "$BASIS$p")
  [[ "$c" == 303 || "$c" == 302 || "$c" == 401 || "$c" == 403 || "$c" == 404 ]] && ok "$p ohne Anmeldung → $c" || bad "$p ohne Anmeldung → $c (erwartet Umleitung zur Anmeldung oder 403/404)"
done
c=$(code -X POST "$BASIS/admin/sicherung/paket")
[[ "$c" == 303 || "$c" == 302 || "$c" == 401 || "$c" == 403 || "$c" == 404 ]] && ok "POST /admin/sicherung/paket ohne Anmeldung → $c" || bad "POST /admin/sicherung/paket ohne Anmeldung → $c"
[[ "$(code "$BASIS/fehler.html")" == 200 ]] && ok "/fehler.html → 200 (eigene Fehlerseite)" || bad "/fehler.html fehlt"
body=$("${CURL[@]}" "$BASIS/app/.env" 2>/dev/null)
[[ "$body" != *Apache* && "$body" != *"Forbidden"* ]] && ok "403-Seite ohne Apache-Standardtext" || bad "403-Seite zeigt den Apache-Standardtext (ErrorDocument greift nicht)"

echo "-- Suchmaschinen"
robots=$("${CURL[@]}" "$BASIS/robots.txt" 2>/dev/null)
xrobots=$(kopf "$BASIS/" X-Robots-Tag)
case "$ERWARTUNG" in
  noindex) [[ "$robots" == *"Disallow: /"* ]] && ok "robots.txt sperrt alles" || bad "robots.txt sperrt nicht (Testserver erwartet)"
           [[ "$xrobots" == *noindex* ]] && ok "X-Robots-Tag noindex" || bad "X-Robots-Tag noindex fehlt" ;;
  index)   [[ "$robots" != *"Disallow: /"$'\n'* && "$robots" != *"Disallow: /" ]] && ok "robots.txt erlaubt" || bad "robots.txt sperrt alles (Livegang erwartet Allow)"
           [[ "$robots" == *Sitemap:* ]] && ok "robots.txt nennt die Sitemap" || bad "robots.txt ohne Sitemap"
           [[ -z "$xrobots" ]] && ok "kein X-Robots-Tag" || bad "X-Robots-Tag gesetzt: $xrobots"
           [[ "$(code "$BASIS/sitemap.xml")" == 200 ]] && ok "sitemap.xml → 200" || bad "sitemap.xml liefert nicht 200" ;;
  *) echo "  --      robots.txt: $(print -r -- "$robots" | head -n 2 | tr '\n' ' ')(ohne --index/--noindex nur angezeigt)" ;;
esac

if (( ${#NEBEN} )); then
  echo "-- Nebenadressen (erwartet 301 in einem Schritt auf $BASIS)"
  for h in "${NEBEN[@]}"; do
    out=$("${CURL[@]}" -o /dev/null -w "%{http_code} %{redirect_url}" -H "Host: $h" --resolve "$h:443:$("${CURL[@]}" -o /dev/null -w '%{remote_ip}' "$BASIS/" 2>/dev/null)" "https://$h/probe?x=1" 2>/dev/null || true)
    [[ "$out" == "301 $BASIS/probe?x=1" ]] && ok "$h → $out" || bad "$h → \"$out\" (erwartet 301 $BASIS/probe?x=1)"
  done
fi

if [[ -n "$URLLISTE" ]]; then
  echo "-- Alte Adressen (jede muss am Ende 200 liefern, nie 404)"
  n=0 f=0
  while IFS= read -r p || [[ -n "$p" ]]; do
    [[ -z "$p" || "$p" == \#* ]] && continue
    [[ "$p" == /* ]] || p="/$p"
    n=$((n+1))
    res=$("${CURL[@]}" -L --max-redirs 5 -o /dev/null -w "%{http_code} %{url_effective}" "$BASIS$p" 2>/dev/null || echo "000 -")
    if [[ "${res%% *}" == 200 ]]; then :; else f=$((f+1)); bad "$p → $res"; fi
  done < "$URLLISTE"
  (( f == 0 )) && ok "alle $n alten Adressen führen zu einer Seite" || echo "  $f von $n alten Adressen ohne Ziel: Lücken in die Weiterleitungstabelle (Admin → Weiterleitungen), dann neu veröffentlichen."
fi

echo
if (( FEHLER == 0 )); then echo "== Alles in Ordnung."; else echo "== $FEHLER Prüfung(en) fehlgeschlagen."; fi
exit $(( FEHLER > 0 ))
