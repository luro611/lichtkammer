#!/usr/bin/env zsh
# Wiederherstellungsprobe A (Docker) und C (falscher Schlüssel), docs/betrieb/Wiederherstellung.md, Abschnitt „Proben“.
#
#   tools/restore-probe.sh [--probe a|c|ac] [--behalten] [--ohne-bauen] [--schluesseldatei DATEI] [--zugang-datei DATEI] SICHERUNGSORDNER
#
# SICHERUNGSORDNER: der Ordner mit lichtkammer-paket.tar und den Originalteilen (*.tar), oder ein schon entpackter Ordner
#   (MANIFEST.json, lichtkammer.sqlite, …). Er wird nur gelesen.
# Schlüssel (nie als Argument, nie ausgegeben): Umgebungsvariable LK_APP_KEY, sonst --schluesseldatei (eine Zeile), sonst
#   verdeckte Eingabe im Terminal. Probe C braucht ihn nicht (sie nimmt absichtlich einen falschen).
# Anmeldeprüfung (optional): LK_PROBE_USER und LK_PROBE_PASSWORD, dazu LK_PROBE_TOTP_SECRET (Base32) oder LK_PROBE_TOTP_CODE
#   (frischer Code aus der App) oder Eingabe im Terminal. Alternativ --zugang-datei mit Zeilen LK_PROBE_USER=…, LK_PROBE_PASSWORD=…,
#   LK_PROBE_TOTP_SECRET=… (wird nur gelesen, nicht ausgeführt). Ohne Zugangsdaten werden die Schritte mit Anmeldung übersprungen und
#   genannt; die Originale holt dann ein Ersatzweg direkt im Container zurück (nicht der echte Weg über die Verwaltung).
# Die Bildvarianten erzeugt die Probe über /admin/varianten/schritt (Runde für Runde wie das Skript der Seite, Verwaltung → Bildvarianten),
#   mit Prüfung von Warnband, Zahl „N von N“ und Dauer der längsten Runde (Grenze von all-inkl: 60 s).
# Es entsteht nichts im Repository und nichts auf einem Server: ein Container mit eigenen, leeren Volumes auf 127.0.0.1, am Ende
# entfernt (außer mit --behalten). Docker-Host: der lokale, oder der aus DOCKER_HOST/DOCKER_CONTEXT, dann läuft das Skript auf diesem Host
# (siehe Wiederherstellung.md, Variante pve10).
emulate -L zsh
setopt pipefail
zmodload zsh/datetime

repo=${0:A:h:h}
# Wie hinter dem Reverse-Proxy der echten Installation: TLS endet davor, die App bekommt X-Forwarded-Proto.
curl() { command curl -H 'X-Forwarded-Proto: https' "$@"; }
probes=ac keep=0 build=1 keyfile= accfile= src= novar=0
while (( $# )); do
  case $1 in
    --probe) probes=${2:-}; shift 2 ;;
    --behalten) keep=1; shift ;;
    --ohne-bauen) build=0; shift ;;
    --ohne-varianten) novar=1; shift ;; # Diagnose: Bildvarianten nach dem Einspielen NICHT neu erzeugen
    --schluesseldatei) keyfile=${2:-}; shift 2 ;;
    --zugang-datei) accfile=${2:-}; shift 2 ;;
    -h|--help) sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    -*) print -u2 "Unbekannte Option: $1"; exit 2 ;;
    *) src=$1; shift ;;
  esac
done
[[ $probes == (a|c|ac) ]] || { print -u2 "--probe: a, c oder ac"; exit 2; }
[[ -n $src && -d $src ]] || { print -u2 "Aufruf: tools/restore-probe.sh [Optionen] SICHERUNGSORDNER"; exit 2; }
command -v docker >/dev/null || { print -u2 "docker fehlt."; exit 2; }
command -v curl >/dev/null || { print -u2 "curl fehlt."; exit 2; }
src=${src:A}

work=$(mktemp -d "${TMPDIR:-/tmp}/lk-probe.XXXXXX") || exit 1
chmod 700 $work
pass=0 failn=0 skipn=0
containers=() volumes=()

ok()   { print "  ✓ $1"; pass=$(( pass + 1 )); }
bad()  { print "  ✗ $1"; failn=$(( failn + 1 )); }
skip() { print "  – übersprungen: $1"; skipn=$(( skipn + 1 )); }
head1() { print; print "== $1"; }

cleanup() {
  if (( keep )); then
    if (( ${#containers} )); then
      print; print "Behalten: Container ${containers[*]}; Volumes ${volumes[*]}"
      print "Aufräumen später: docker rm -f ${containers[*]}; docker volume rm ${volumes[*]}"
    fi
  else
    (( ${#containers} )) && docker rm -f ${containers[@]} >/dev/null 2>&1
    (( ${#volumes} )) && docker volume rm ${volumes[@]} >/dev/null 2>&1
  fi
  rm -rf $work
}
trap cleanup EXIT
trap 'print; print "Abgebrochen."; exit 130' INT TERM

# ------------------------------------------------------------------ Eingaben
if [[ -n $accfile ]]; then
  [[ -f $accfile ]] || { print -u2 "Zugangsdatei fehlt: $accfile"; exit 2; }
  while IFS='=' read -r k v; do
    case $k in
      LK_PROBE_USER) LK_PROBE_USER=$v ;;
      LK_PROBE_PASSWORD) LK_PROBE_PASSWORD=$v ;;
      LK_PROBE_TOTP_SECRET) LK_PROBE_TOTP_SECRET=$v ;;
    esac
  done < $accfile
fi
if [[ $probes == *a* && -z ${LK_APP_KEY:-} ]]; then
  if [[ -n $keyfile ]]; then
    [[ -f $keyfile ]] || { print -u2 "Schlüsseldatei fehlt: $keyfile"; exit 2; }
    read -r LK_APP_KEY < $keyfile
  elif [[ -t 0 ]]; then
    read -rs "LK_APP_KEY?LK_APP_KEY (verdeckt): "; print
  fi
  [[ -n ${LK_APP_KEY:-} ]] || { print -u2 "Kein LK_APP_KEY (Umgebungsvariable, --schluesseldatei oder Eingabe im Terminal)."; exit 2; }
  LK_APP_KEY=${LK_APP_KEY//[[:space:]]/}
  export LK_APP_KEY
fi

# ------------------------------------------------------------------ Sicherung bereitstellen
head1 "Sicherung lesen"
if [[ -f $src/MANIFEST.json && -f $src/lichtkammer.sqlite ]]; then
  bk=$src
else
  bk=$work/sicherung; mkdir -p $bk
  tars=($src/*.tar(N))
  (( ${#tars} )) || { print -u2 "Im Ordner liegt weder eine entpackte Sicherung noch ein .tar."; exit 2; }
  for t in $tars; do tar -xf $t -C $bk || { print -u2 "Entpacken fehlgeschlagen: $t"; exit 1; }; done
fi
[[ -f $bk/MANIFEST.json && -f $bk/lichtkammer.sqlite ]] || { print -u2 "MANIFEST.json oder lichtkammer.sqlite fehlt (ist lichtkammer-paket.tar im Ordner?)."; exit 1; }
nlist=0; [[ -f $bk/originals.sha256 ]] && nlist=$(grep -c . $bk/originals.sha256)
norig=0; [[ -d $bk/originals ]] && norig=$(find $bk/originals -type f | wc -l | tr -d ' ')
print "Manifest: $(tr -d '\n' < $bk/MANIFEST.json)"
print "Prüfsummenliste: $nlist Originale, im Ordner: $norig Dateien"
(( nlist == norig )) && ok "Zahl der Originale im Ordner passt zur Liste" || bad "Zahl der Originale ($norig) passt nicht zur Liste ($nlist): fehlen Teile?"

# ------------------------------------------------------------------ Image
if (( build )); then
  head1 "Image bauen (aus dem Dockerfile im Repository)"
  t0=$EPOCHREALTIME
  docker build -q -t lichtkammer:probe --build-arg VERSION=probe $repo > $work/build.log 2>&1 \
    && ok "Image lichtkammer:probe gebaut ($(printf '%.0f' $(( EPOCHREALTIME - t0 ))) s)" \
    || { cat $work/build.log; print -u2 "Bauen fehlgeschlagen."; exit 1; }
else
  docker image inspect lichtkammer:probe >/dev/null 2>&1 || { print -u2 "--ohne-bauen: Image lichtkammer:probe fehlt."; exit 1; }
fi

phpq() { # PHP-Code (ohne <?php) im Container ausführen, Ausgabe auf stdout
  docker exec -u www-data $cid php -d error_reporting=0 -r "$1"
}
countsql="SELECT (SELECT COUNT(*) FROM users)||'|'||(SELECT COUNT(*) FROM photos)||'|'||(SELECT COUNT(*) FROM galleries)||'|'||(SELECT COUNT(*) FROM pages)||'|'||(SELECT COUNT(*) FROM gallery_photos)"
counts_code="\$p=new PDO('sqlite:' . \$argv[1]); echo \$p->query(\"$countsql\")->fetchColumn();"
expected=$(docker run --rm --entrypoint php -v "$bk/lichtkammer.sqlite":/s/lichtkammer.sqlite:ro lichtkammer:probe -d error_reporting=0 -r "$counts_code" -- /s/lichtkammer.sqlite 2>/dev/null)
[[ $expected == [0-9]*'|'*'|'*'|'*'|'* ]] || { print -u2 "Konten und Zahlen der Sicherung nicht lesbar."; exit 1; }
print "Inhalt der Sicherung (Konten|Fotos|Galerien|Seiten|Zuordnungen): $expected"

# ------------------------------------------------------------------ Container
tag=$$-$RANDOM
start_stack() { # $1 = Name, Schlüssel kommt aus $probe_key
  cid=lkprobe-$tag-$1
  local v port i
  for v in data orig media; do docker volume create lkprobe-$tag-$1-$v >/dev/null; volumes+=(lkprobe-$tag-$1-$v); done
  setup_token=$(openssl rand -hex 24)
  for i in 1 2 3 4 5; do
    port=$(( 20000 + RANDOM % 30000 ))
    LK_APP_KEY=$probe_key LK_SETUP_TOKEN=$setup_token docker run -d --name $cid \
      --security-opt no-new-privileges:true --cap-drop ALL --cap-add CHOWN --cap-add SETUID --cap-add SETGID --cap-add NET_BIND_SERVICE \
      --memory 1g --log-opt max-size=10m --log-opt max-file=5 \
      -p 127.0.0.1:$port:80 \
      -e LK_APP_KEY -e LK_SETUP_TOKEN -e LK_BASE_URL=https://127.0.0.1:$port -e LK_TRUST_PROXY=1 -e LK_TRUSTED_PROXIES='10.0.0.0/8 172.16.0.0/12 192.168.0.0/16' -e LK_NOINDEX=1 -e LK_SITE_NAME=Probe \
      -v lkprobe-$tag-$1-data:/var/lib/lichtkammer/data -v lkprobe-$tag-$1-orig:/var/lib/lichtkammer/originals \
      -v lkprobe-$tag-$1-media:/var/www/html/public/media \
      lichtkammer:probe >/dev/null 2>$work/run.err && break
    docker rm -f $cid >/dev/null 2>&1
    port=
  done
  [[ -n $port ]] || { cat $work/run.err; return 1; }
  containers+=($cid)
  base=http://127.0.0.1:$port # erreichbar über http; X-Forwarded-Proto gibt den Reverse-Proxy davor wieder (siehe curl unten)
  for i in {1..60}; do
    [[ $(curl -s --max-time 3 $base/health) == ok\ * ]] && return 0
    sleep 1
  done
  docker logs --tail 20 $cid 2>&1 | sed 's/^/    /'
  return 1
}

stage_backup() { # Sicherung in den Bereitstellungsordner im Datenvolume legen
  docker exec -u www-data $cid mkdir -p /var/lib/lichtkammer/data/restore || return 1
  docker cp "$bk/." $cid:/var/lib/lichtkammer/data/restore/ || return 1
  docker exec -u root $cid chown -R www-data:www-data /var/lib/lichtkammer/data/restore
}

csrf_of() { # $1 = URL, Cookie-Topf $jar
  curl -s --max-time 30 -b $jar -c $jar $1 | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1
}
post_form() { # $1 = URL, Rest: --data-urlencode-Argumente; Antwort in $work/resp.html, Status in $hc
  local url=$1; shift
  hc=$(curl -s --max-time 120 -b $jar -c $jar -o $work/resp.html -w '%{http_code}' "$@" $url)
}
plain() { sed -e 's/<[^>]*>/ /g' -e 's/&amp;/\&/g;s/&quot;/"/g;s/&#039;/'"'"'/g' $work/resp.html | tr -s ' \n' ' ' ; }

restore_via_setup() { # POST mit Einrichtungs-Token; setzt $hc, Antwort in resp.html
  jar=$work/jar-setup; : > $jar
  local csrf; csrf=$(csrf_of $base/admin/setup/wiederherstellen)
  [[ -n $csrf ]] || { hc=000; : > $work/resp.html; return 1; }
  print -rn -- $setup_token > $work/tok
  post_form $base/admin/setup/wiederherstellen --data-urlencode "_csrf=$csrf" --data-urlencode "token@$work/tok"
  rm -f $work/tok
}

totp() { TOTP_SECRET=$1 python3 -I -c '
import base64, hmac, hashlib, os, struct, time
s = os.environ["TOTP_SECRET"].replace(" ", "").upper()
s += "=" * (-len(s) % 8)
h = hmac.new(base64.b32decode(s), struct.pack(">Q", int(time.time()) // 30), hashlib.sha1).digest()
o = h[-1] & 15
print("%06d" % ((struct.unpack(">I", h[o:o+4])[0] & 0x7fffffff) % 1000000))'; }

# ------------------------------------------------------------------ Probe A
probe_a() {
  head1 "Probe A: frischer Container, leere Volumes, derselbe Schlüssel"
  local t_all=$EPOCHREALTIME t1 t2 t3 csrf code res
  probe_key=$LK_APP_KEY
  start_stack a || { bad "Container startet nicht"; return; }
  ok "Container läuft (Härtung wie compose.yaml), $base"
  stage_backup && ok "Sicherung im Bereitstellungsordner (Paket, Prüfsummenliste, Originale)" || { bad "Bereitstellen fehlgeschlagen"; return; }

  t1=$EPOCHREALTIME
  restore_via_setup
  if [[ $hc == 200 && $(plain) == *eingespielt* ]]; then
    ok "Datenbank eingespielt: $(plain | grep -o '[0-9]* Konten, [0-9]* Fotos[^.]*') ($(printf '%.1f' $(( EPOCHREALTIME - t1 ))) s)"
  else
    bad "Einspielen schlug fehl (Status $hc): $(plain | cut -c1-300)"; return
  fi

  res=$(phpq "\$p=new PDO('sqlite:/var/lib/lichtkammer/data/lichtkammer.sqlite'); echo \$p->query(\"$countsql\")->fetchColumn();")
  [[ $res == $expected ]] \
    && ok "Konten, Fotos, Galerien, Seiten und Zuordnungen stimmen ($res)" \
    || bad "Zahlen weichen ab: Sicherung $expected, eingespielt $res"
  [[ $(docker exec $cid sh -c 'ls /var/lib/lichtkammer/data/restore/lichtkammer.sqlite 2>/dev/null | wc -l' | tr -d ' ') == 0 ]] \
    && ok "Datenbank liegt nicht mehr im Bereitstellungsordner" || bad "Datenbank liegt noch im Bereitstellungsordner"

  # Anmeldung (Passwort und TOTP), nur mit Zugangsdaten
  local logged=0
  if [[ -n ${LK_PROBE_USER:-} ]]; then
    if [[ -z ${LK_PROBE_PASSWORD:-} && -t 0 ]]; then read -rs "LK_PROBE_PASSWORD?Passwort von $LK_PROBE_USER (verdeckt): "; print; fi
    if [[ -z ${LK_PROBE_PASSWORD:-} ]]; then
      skip "Anmeldung (LK_PROBE_PASSWORD fehlt)"
    else
      jar=$work/jar-admin; : > $jar
      csrf=$(csrf_of $base/admin/login)
      print -rn -- "$LK_PROBE_PASSWORD" > $work/pw
      post_form $base/admin/login --data-urlencode "_csrf=$csrf" --data-urlencode "username=$LK_PROBE_USER" --data-urlencode "password@$work/pw"
      rm -f $work/pw
      if [[ $hc == 303 || $hc == 302 ]]; then
        ok "Anmeldung, Stufe 1: Passwort angenommen"
        csrf=$(csrf_of $base/admin/login/totp)
        code=
        if [[ -n ${LK_PROBE_TOTP_SECRET:-} ]] && command -v python3 >/dev/null; then code=$(totp $LK_PROBE_TOTP_SECRET)
        elif [[ -n ${LK_PROBE_TOTP_CODE:-} ]]; then code=$LK_PROBE_TOTP_CODE
        elif [[ -t 0 ]]; then read -r "code?Frischer Code aus der App: "; fi
        if [[ -z $code ]]; then
          skip "Anmeldung, Stufe 2 (kein TOTP-Geheimnis, kein Code)"
        else
          post_form $base/admin/login/totp --data-urlencode "_csrf=$csrf" --data-urlencode "code=$code"
          hc=$(curl -s --max-time 30 -b $jar -c $jar -o /dev/null -w '%{http_code}' $base/admin)
          if [[ $hc == 200 ]]; then ok "Anmeldung, Stufe 2: TOTP angenommen, Verwaltung offen"; logged=1
          else bad "Anmeldung mit TOTP gescheitert (Status $hc beim Aufruf von /admin)"; fi
        fi
      else
        bad "Anmeldung mit Passwort gescheitert (Status $hc)"
      fi
    fi
  else
    skip "Anmeldung mit Passwort und TOTP (keine LK_PROBE_USER-Zugangsdaten gegeben)"
  fi

  # Originale zurückholen
  t2=$EPOCHREALTIME
  local pend left rounds=0
  if (( logged )); then
    while (( rounds < 200 )); do
      curl -s --max-time 60 -b $jar -c $jar $base/admin/wiederherstellung -o $work/resp.html
      pend=$(plain | grep -o '[0-9]* Originale warten' | grep -o '^[0-9]*')
      [[ ${pend:-0} == 0 ]] && break
      csrf=$(sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' $work/resp.html | head -1)
      post_form $base/admin/wiederherstellung/weiter --data-urlencode "_csrf=$csrf"
      (( rounds++ ))
    done
    curl -s --max-time 60 -b $jar -c $jar $base/admin/wiederherstellung -o $work/resp.html
    if [[ $(plain) == *' 0 Originale warten'* && $(plain) == *'Zu allen Fotos in der Datenbank gibt es ein Original'* ]]; then
      ok "Originale über /admin/wiederherstellung zurückgeholt ($rounds Durchgänge, $(printf '%.1f' $(( EPOCHREALTIME - t2 ))) s)"
    else
      bad "Originale nicht vollständig zurückgeholt: $(plain | grep -o '[0-9]* Originale warten[^.]*\.[^.]*\.' | head -1)"
    fi
  else
    docker exec -u www-data $cid php -r '
      require "/var/www/html/app/bootstrap.php";
      $c = Lichtkammer\Config::load("/var/www/html/app", "/var/www/html/public", getenv());
      do { $r = (new Lichtkammer\Backup\Restore($c))->moveOriginals(); } while ($r["remaining"] > 0);
      echo "verschoben ", $r["moved"], ", falsch ", $r["bad"], ", fehlend ", $r["missing"];' > $work/mv.txt 2>&1 \
      && ok "Originale zurückgeholt über Ersatzweg im Container, nicht über die Verwaltung (${$(<$work/mv.txt)})" \
      || bad "Ersatzweg zum Zurückholen der Originale scheiterte: $(<$work/mv.txt)"
  fi

  # SHA-256 aller Originale gegen die Liste der Sicherung
  if (( nlist > 0 )); then
    docker exec -i -u www-data $cid sh -c 'cd /var/lib/lichtkammer/originals && sha256sum -c' < $bk/originals.sha256 > $work/sha.txt 2>&1
    local good; good=$(grep -c ': OK$' $work/sha.txt)
    (( good == nlist )) \
      && ok "SHA-256: alle $good Originale stimmen mit der Prüfsummenliste überein" \
      || bad "SHA-256: nur $good von $nlist stimmen ($(grep -v ': OK$' $work/sha.txt | head -3 | tr '\n' ' '))"
  else
    skip "SHA-256 der Originale (Sicherung ohne Prüfsummenliste)"
  fi

  # Varianten neu erzeugen (der Weg, den Lutz auf all-inkl geht: Verwaltung → Bildvarianten), veröffentlichen, ausliefern
  if (( logged )); then
    local vr=0 vdone=0 jr tokv vt0 vdt vmax=0 vn vm
    tokv=$(csrf_of $base/admin/varianten)
    if (( novar )); then
      print "  (Diagnose --ohne-varianten: keine Varianten erzeugt)"
    else
      curl -s --max-time 60 -b $jar -c $jar $base/admin -o $work/resp.html
      [[ $(plain) == *'haben keine Bildvarianten'* ]] \
        && ok "Warnband nach dem Einspielen: „… haben keine Bildvarianten“ erscheint von selbst" \
        || bad "Warnband „haben keine Bildvarianten“ fehlt nach dem Einspielen"
      vt0=$EPOCHREALTIME
      while (( vr < 1000 )); do # wie das Skript der Seite: Runde für Runde, die erste mit neu=1
        local r0=$EPOCHREALTIME q=
        (( vr == 0 )) && q='?neu=1'
        jr=$(curl -s --max-time 90 -b $jar -c $jar -X POST -H "X-CSRF-Token: $tokv" "$base/admin/varianten/schritt$q")
        vdt=$(( EPOCHREALTIME - r0 )); (( vdt > vmax )) && vmax=$vdt
        (( vr++ ))
        [[ $jr == *'"done":true'* ]] && { vdone=1; break; }
        [[ -z $jr || $jr == *'"error"'* ]] && break
      done
      curl -s --max-time 60 -b $jar -c $jar $base/admin/varianten -o $work/resp.html
      vn=$(plain | grep -o '[0-9]* von [0-9]* Fotos haben Bildvarianten' | head -1)
      vm=(${=vn})
      if (( vdone )) && [[ -n $vn && ${vm[1]} == ${vm[3]} && ${vm[1]} -gt 0 ]]; then
        ok "Bildvarianten über /admin/varianten erzeugt: ${vm[1]} von ${vm[3]} Fotos haben Bildvarianten ($vr Runden, $(printf '%.1f' $(( EPOCHREALTIME - vt0 ))) s, längste Runde $(printf '%.1f' $vmax) s)"
      else
        bad "Bildvarianten nicht vollständig erzeugt (Runden $vr, fertig $vdone, Seite: ${vn:-ohne Angabe}, letzte Antwort: ${jr:0:120})"
      fi
      (( vmax < 60 )) && ok "Jede Runde blieb unter der 60-Sekunden-Grenze von all-inkl (längste $(printf '%.1f' $vmax) s)" || bad "Eine Runde dauerte $(printf '%.0f' $vmax) s, über der Grenze von 60 s"
      curl -s --max-time 60 -b $jar -c $jar $base/admin -o $work/resp.html
      [[ $(plain) != *'haben keine Bildvarianten'* ]] && ok "Warnband „keine Bildvarianten“ verschwunden" || bad "Warnband steht trotz fertiger Varianten noch"
    fi

    csrf=$(csrf_of $base/admin/veroeffentlichen)
    t3=$EPOCHREALTIME
    post_form $base/admin/veroeffentlichen --data-urlencode "_csrf=$csrf"
    if [[ $hc == 200 && $(plain) == *'Veröffentlicht. Die neue Fassung'* ]]; then
      ok "Veröffentlichen läuft ($(printf '%.1f' $(( EPOCHREALTIME - t3 ))) s)"
    else
      bad "Veröffentlichen schlug fehl (Status $hc): $(plain | grep -o 'notice error[^.]*' | cut -c1-200)"
    fi
    local home gal slug imgs url okn=0 nn=0 ct
    home=$(curl -s --max-time 30 -o $work/home.html -w '%{http_code}' $base/)
    [[ $home == 200 ]] && ok "Startseite wird ausgeliefert" || bad "Startseite: Status $home"
    curl -s --max-time 30 $base/galerien/ -o $work/gal.html
    slug=$(sed -n 's/.*href="\/galerien\/\([a-z0-9-]*\)\/".*/\1/p' $work/gal.html | head -1)
    if [[ -n $slug ]]; then
      ok "Galerienübersicht nennt Galerien (erste: $slug)"
      curl -s --max-time 30 $base/galerien/$slug/ -o $work/g1.html
      imgs=($(grep -o '/media/[A-Za-z0-9_./-]*\.\(jpg\|jpeg\|webp\|avif\)' $work/g1.html $work/gal.html $work/home.html | sed 's/^[^:]*://' | sort -u | head -8))
      for url in $imgs; do
        (( nn++ ))
        ct=$(curl -s --max-time 60 -o /dev/null -w '%{http_code} %{content_type}' $base$url)
        [[ $ct == '200 image/'* ]] && (( okn++ ))
      done
      (( nn > 0 && okn == nn )) && ok "Bilder werden ausgeliefert ($okn von $nn geprüft, Status 200, Typ image/…)" || bad "Bilder: $okn von $nn lieferbar"
    else
      bad "Galerienübersicht nennt keine Galerie (nach dem Veröffentlichen)"
    fi
  else
    skip "Bildvarianten neu erzeugen, Veröffentlichen, Bilder ausliefern (brauchen die Anmeldung)"
  fi
  print "  Zeit Probe A insgesamt (ohne Bauen des Images): $(printf '%.1f' $(( EPOCHREALTIME - t_all ))) s"
}

# ------------------------------------------------------------------ Probe C
probe_c() {
  head1 "Probe C: falscher Schlüssel, das Einspielen muss scheitern und nichts verändern"
  probe_key=$(openssl rand -base64 32)
  start_stack c || { bad "Container startet nicht"; return; }
  stage_backup || { bad "Bereitstellen fehlgeschlagen"; return; }
  restore_via_setup
  local msg; msg=$(plain)
  if [[ $hc == 400 && $msg == *'LK_APP_KEY passt nicht'* ]]; then
    ok "Abgelehnt (Status 400) mit der Meldung „LK_APP_KEY passt nicht …“"
  else
    bad "Erwartet Status 400 und „LK_APP_KEY passt nicht“, bekommen: Status $hc, $(print -r -- $msg | cut -c1-250)"
  fi
  local u; u=$(phpq "\$p=new PDO('sqlite:/var/lib/lichtkammer/data/lichtkammer.sqlite'); echo \$p->query('SELECT COUNT(*) FROM users')->fetchColumn(), '|', \$p->query('SELECT COUNT(*) FROM photos')->fetchColumn();")
  [[ $u == '0|0' ]] && ok "Datenbank unverändert (keine Konten, keine Fotos)" || bad "Datenbank hat sich verändert: Konten|Fotos = $u"
  local aside; aside=$(docker exec $cid sh -c 'ls /var/lib/lichtkammer/data | grep -c "vor-wiederherstellung\|wiederherstellung-"')
  [[ $aside == 0 ]] && ok "Keine Reste im Datenordner (keine beiseitegelegte oder halbe Datenbank)" || bad "Reste im Datenordner: $aside"
  docker exec $cid test -f /var/lib/lichtkammer/data/restore/lichtkammer.sqlite && ok "Sicherung liegt unverändert im Bereitstellungsordner" || bad "Sicherung im Bereitstellungsordner fehlt"
  local no; no=$(docker exec $cid sh -c 'find /var/lib/lichtkammer/originals -type f | wc -l' | tr -d ' ')
  [[ $no == 0 ]] && ok "Originalordner leer geblieben" || bad "Originalordner hat $no Dateien"
  curl -s --max-time 30 $base/admin/setup -o /dev/null -w '%{http_code}' | grep -q 200 && ok "Einrichtung steht weiter offen (Neuer Versuch mit richtigem Schlüssel möglich)" || bad "Einrichtung nicht mehr offen"
}

started=$EPOCHREALTIME
[[ $probes == *a* ]] && probe_a
[[ $probes == *c* ]] && probe_c

print
print "== Ergebnis: $pass bestanden, $failn fehlgeschlagen, $skipn übersprungen, Gesamtzeit $(printf '%.0f' $(( EPOCHREALTIME - started ))) s"
(( failn == 0 ))
