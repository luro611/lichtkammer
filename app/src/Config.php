<?php
declare(strict_types=1);

namespace Lichtkammer;

/**
 * Konfiguration aus Umgebungsvariablen und optionaler .env-Datei (gleiches Schema für
 * all-inkl und Docker). Echte Umgebungsvariablen haben Vorrang vor der .env-Datei.
 * Alle Pfade stehen nur hier, nirgends sonst im Code.
 */
final class Config
{
    public const VERSION = '0.1.0-rc1';

    /** @param array<string,string> $values */
    private function __construct(
        private readonly array $values,
        public readonly string $appDir,
        public readonly string $publicDir,
        public readonly bool $webrootEnvFound = false,
    ) {
    }

    /**
     * @param array<string,string> $env  Umgebungsvariablen (normalerweise getenv())
     */
    public static function load(string $appDir, string $publicDir, array $env): self
    {
        $values = [];
        // Liegt app/ im Webroot (all-inkl), wird nur app/.env gelesen: eine .env im Webroot wäre
        // (bei fehlender Sperre) abrufbar und wird deshalb nicht benutzt, der Admin meldet sie.
        $appReal = realpath($appDir);
        $publicReal = realpath($publicDir);
        $parentIsWebroot = $appReal !== false && $publicReal !== false && dirname($appReal) === $publicReal;
        $webrootEnvFound = false;
        foreach ([$appDir . '/../.env', $appDir . '/.env'] as $file) { // app/.env gewinnt
            if ($parentIsWebroot && $file === $appDir . '/../.env') {
                $webrootEnvFound = is_file($file);
                continue;
            }
            if (is_file($file) && is_readable($file)) {
                $values = array_replace($values, self::parseEnvFile((string) file_get_contents($file)));
            }
        }
        foreach ($env as $key => $value) {
            if (str_starts_with((string) $key, 'LK_') && is_string($value)) {
                $values[$key] = $value;
            }
        }
        return new self($values, rtrim($appDir, '/'), rtrim($publicDir, '/'), $webrootEnvFound);
    }

    /** @return array<string,string> */
    public static function parseEnvFile(string $content): array
    {
        $out = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            } elseif (preg_match('/\s#/', $value) === 1) {
                $value = trim((string) preg_split('/\s#/', $value, 2)[0]); // Kommentar hinter dem Wert
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = strtolower($this->get($key, $default ? '1' : '0'));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Projektwurzel: Elternordner von app/ (Docker, Entwicklung) bzw. der Webroot (all-inkl). */
    private function root(): string
    {
        return dirname($this->appDir);
    }

    /** Daten (SQLite, Sitzungen, Protokolle). Standard: <Wurzel>/data. */
    public function dataDir(): string
    {
        return $this->path('LK_DATA_DIR', $this->root() . '/data');
    }

    /** Originalbilder, werden nie überschrieben. Standard: <Wurzel>/originals. */
    public function originalsDir(): string
    {
        return $this->path('LK_ORIGINALS_DIR', $this->root() . '/originals');
    }

    /**
     * Ordner mit dem Importpaket (Umzug). Auf all-inkl legt man das Paket per FTPS in einen Ordner im Webroot
     * (LK_IMPORT_DIR); die App sperrt ihn dann per .htaccess. Standard: <Datenordner>/import.
     */
    public function importDir(): string
    {
        return $this->path('LK_IMPORT_DIR', $this->dataDir() . '/import');
    }

    /**
     * Bereitstellungsordner für eine Sicherung, die eingespielt werden soll (Entwurf 20, H2). Auf all-inkl legt man die Sicherung
     * per FTPS in einen Ordner im Webroot (LK_RESTORE_DIR, z. B. <Webroot>/restore); die App sperrt ihn per .htaccess.
     * Standard: <Datenordner>/restore.
     */
    public function restoreDir(): string
    {
        return $this->path('LK_RESTORE_DIR', $this->dataDir() . '/restore');
    }

    /** Erzeugte öffentliche Bildvarianten. Standard: <public>/media. */
    public function mediaDir(): string
    {
        return $this->path('LK_MEDIA_DIR', $this->publicDir . '/media');
    }

    public function baseUrl(): string
    {
        return rtrim($this->get('LK_BASE_URL', ''), '/');
    }

    public function siteName(): string
    {
        return $this->get('LK_SITE_NAME', 'Lichtkammer');
    }

    public function isDebug(): bool
    {
        return $this->get('LK_ENV', 'production') === 'development';
    }

    /** Hinter einem Reverse-Proxy (Docker/LXC): X-Forwarded-Proto vertrauen. */
    public function trustProxy(): bool
    {
        return $this->bool('LK_TRUST_PROXY');
    }

    /** Testinstallationen: Suchmaschinen aussperren. */
    public function noindex(): bool
    {
        return $this->bool('LK_NOINDEX');
    }

    /**
     * Adressen, die auf die Hauptadresse (LK_BASE_URL) weitergeleitet werden, z. B. br-foto.com und lichtkammer.br-foto.com,
     * wenn www.br-foto.com die Hauptadresse ist. Nur eingetragene Hosts werden umgeleitet (Entwurf 20, E4/M1).
     *
     * @return list<string>
     */
    public function redirectHosts(): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', strtolower($this->get('LK_REDIRECT_HOSTS'))) ?: [] as $h) {
            if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $h) === 1) {
                $out[] = $h;
            }
        }
        return $out;
    }

    /** Host der Hauptadresse (ohne Port), leer ohne LK_BASE_URL. */
    public function baseHost(): string
    {
        return strtolower((string) (parse_url($this->baseUrl(), PHP_URL_HOST) ?? ''));
    }

    /**
     * Umzugs-Weiterleitungen (alte Adresse -> neue Adresse) als dauerhaftes 301 für Browser. Standard: nein, sie gelten nur
     * eine Stunde im Browser-Zwischenspeicher, damit ein Rückfall auf die alte Seite nicht an gemerkten Weiterleitungen hängt
     * (Entwurf 20, M2). Nach der Übergangszeit (14 Tage) auf 1 stellen.
     */
    public function redirectsPermanent(): bool
    {
        return $this->bool('LK_REDIRECTS_PERMANENT');
    }

    /**
     * Darf die Fehlerseite bei unvollständiger Konfiguration den Grund nennen? Ja bei Entwicklung, solange ein
     * Einrichtungs-Token gesetzt ist (Einrichtung, ohne SSH braucht man die Meldung) und solange noch gar kein Schlüssel
     * konfiguriert ist (frische Installation). Danach sieht ein Unangemeldeter nur „nicht verfügbar“; der Grund steht im
     * Fehlerprotokoll des Servers (Prüfung 08, C6).
     */
    public function showConfigDetails(): bool
    {
        return $this->isDebug() || $this->setupToken() !== '' || $this->appKey() === '';
    }

    public function int(string $key, int $default): int
    {
        $v = $this->get($key, '');
        return $v !== '' && ctype_digit($v) ? (int) $v : $default;
    }

    /** Geheimschlüssel (Base64, 32 Byte). Wird nie ausgegeben. */
    public function appKey(): string
    {
        return $this->get('LK_APP_KEY');
    }

    /** Alter Schlüssel, nur zum Entschlüsseln nach einer Rotation. */
    public function appKeyPrevious(): string
    {
        return $this->get('LK_APP_KEY_PREVIOUS');
    }

    public function setupToken(): string
    {
        return $this->get('LK_SETUP_TOKEN');
    }

    public function resetToken(): string
    {
        return $this->get('LK_RESET_TOKEN');
    }

    /** @return list<string> Adressen/Netze der Reverse-Proxys (nur Hinweis für Apache, siehe docker/). */
    public function trustedProxies(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $this->get('LK_TRUSTED_PROXIES')) ?: [])));
    }

    /** Ausnahme: Datenordner darf im Webroot liegen (Warnung im Admin). */
    public function allowDataInWebroot(): bool
    {
        return $this->bool('LK_ALLOW_DATA_IN_WEBROOT');
    }

    public function sessionIdleSeconds(): int
    {
        return max(60, $this->int('LK_SESSION_IDLE', 1800));
    }

    public function sessionAbsoluteSeconds(): int
    {
        return max(300, $this->int('LK_SESSION_ABSOLUTE', 43200));
    }

    /** Globale Bremse: so viele Hash-Prüfungen im Fenster, danach „bitte später“. */
    public function globalBrakeLimit(): int
    {
        return max(1, $this->int('LK_GLOBAL_BRAKE_LIMIT', 20));
    }

    public function globalBrakeWindow(): int
    {
        return max(1, $this->int('LK_GLOBAL_BRAKE_WINDOW', 10));
    }

    /** WAL nur auf ausdrücklichen Wunsch (auf dem Ziel prüfen). */
    public function sqliteWal(): bool
    {
        return $this->bool('LK_SQLITE_WAL');
    }

    private function path(string $key, string $default): string
    {
        $p = $this->get($key, $default);
        return rtrim($p === '' ? $default : $p, '/');
    }
}
