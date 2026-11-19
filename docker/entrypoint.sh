#!/bin/sh
# Schaltet die Übernahme der Client-Adresse aus X-Forwarded-For nur ein, wenn LK_TRUSTED_PROXIES gesetzt ist.
# Ohne diese Variable bleibt mod_remoteip wirkungslos: X-Forwarded-For wird ignoriert und kann keine Adresse fälschen.
set -eu
conf=/etc/apache2/conf-available/lk-remoteip.conf
if [ -n "${LK_TRUSTED_PROXIES:-}" ]; then
    case "$LK_TRUSTED_PROXIES" in
        *[!0-9a-fA-F:./,\ ]*) echo "LK_TRUSTED_PROXIES enthält ungültige Zeichen (erlaubt: Adressen/Netze, getrennt durch Leerzeichen oder Komma)." >&2; exit 1 ;;
    esac
    proxies=$(printf '%s' "$LK_TRUSTED_PROXIES" | tr ',' ' ')
    printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy %s\n' "$proxies" > "$conf"
    a2enconf lk-remoteip >/dev/null
fi
# Nur für lokale Tests: Beginnt LK_BASE_URL mit http:// (nicht https://), leitet die .htaccess nicht auf HTTPS um.
case "${LK_BASE_URL:-}" in
    http://*) printf 'SetEnvIf Request_URI .* LK_HTTP_OK=1\n' > /etc/apache2/conf-available/lk-http.conf; a2enconf lk-http >/dev/null ;;
esac
exec docker-php-entrypoint "$@"
