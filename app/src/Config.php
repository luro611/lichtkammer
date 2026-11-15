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
    public const VERSION = '0.1.0-dev';

    /** @param array<string,string> $values */
    private function __construct(
        private readonly array $values,
        public readonly string $appDir,
        public readonly string $publicDir,
    ) {
    }

    /**
     * @param array<string,string> $env  Umgebungsvariablen (normalerweise getenv())
     */
    public static function load(string $appDir, string $publicDir, array $env): self
    {
        $values = [];
        foreach ([$appDir . '/../.env', $appDir . '/.env'] as $file) { // app/.env gewinnt
            if (is_file($file) && is_readable($file)) {
                $values = array_replace($values, self::parseEnvFile((string) file_get_contents($file)));
            }
        }
        foreach ($env as $key => $value) {
            if (str_starts_with((string) $key, 'LK_') && is_string($value)) {
                $values[$key] = $value;
            }
        }
        return new self($values, rtrim($appDir, '/'), rtrim($publicDir, '/'));
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

    private function path(string $key, string $default): string
    {
        $p = $this->get($key, $default);
        return rtrim($p === '' ? $default : $p, '/');
    }
}
