<?php
declare(strict_types=1);

namespace Lichtkammer\Auth;

use Lichtkammer\Config;
use Lichtkammer\Media\OriginalsMover;

/**
 * Startprüfung für den Admin. Fehler führen zu einer Fehlerseite statt zu Standardwerten.
 * Die Meldungen enthalten nie Schlüssel oder Token.
 */
final class Preflight
{
    /** @return list<string> Hinweise, die dauerhaft im Admin angezeigt werden (Warnband) */
    public static function warnings(Config $config, ?string $dataDirReal): array
    {
        $w = [];
        if ($dataDirReal !== null && self::inside($dataDirReal, $config->publicDir)) {
            $w[] = 'Der Datenordner liegt im öffentlichen Webroot (LK_ALLOW_DATA_IN_WEBROOT=1). Die Datenbank ist nur durch die .htaccess geschützt. Besser: LK_DATA_DIR außerhalb des Webroots setzen.';
        }
        $orig = $config->originalsDir();
        if (self::inside(self::plannedPath($orig), $config->publicDir)) {
            $w[] = 'Der Ordner der Originale liegt im öffentlichen Webroot (LK_ALLOW_DATA_IN_WEBROOT=1). Originale mit allen Metadaten (auch GPS) sind nur durch die .htaccess geschützt. Besser: LK_ORIGINALS_DIR außerhalb des Webroots setzen.';
        }
        $pending = OriginalsMover::pending($config);
        if ($pending > 0) {
            $w[] = $pending . ' Original(e) liegen noch im alten Ordner und werden beim nächsten Aufruf nach LK_ORIGINALS_DIR verschoben (siehe docs/betrieb/Originale-verschieben.md).';
        }
        return $w;
    }

    /** Wie prepareDataDir: Pfad auflösen, auch wenn der Ordner noch nicht existiert. */
    private static function plannedPath(string $dir): string
    {
        $existing = $dir;
        $rest = [];
        while ($existing !== '' && !is_dir($existing) && dirname($existing) !== $existing) {
            array_unshift($rest, basename($existing));
            $existing = dirname($existing);
        }
        $real = realpath($existing);
        return ($real === false ? $existing : rtrim($real, '/')) . ($rest !== [] ? '/' . implode('/', $rest) : '');
    }

    /** @throws ConfigError */
    public static function check(Config $config): string
    {
        foreach (['sodium', 'intl', 'pdo_sqlite'] as $ext) {
            if (!extension_loaded($ext)) {
                throw new ConfigError('Die PHP-Erweiterung „' . $ext . '“ fehlt. Der Admin startet nicht ohne sie.');
            }
        }
        if ($config->webrootEnvFound) {
            throw new ConfigError('Im Webroot liegt eine .env-Datei. Sie wird aus Sicherheitsgründen nicht gelesen. Bitte die Datei nach app/.env verschieben und im Webroot löschen.');
        }
        $base = $config->baseUrl();
        if (preg_match('#^https?://[^/]+$#', $base) !== 1) {
            throw new ConfigError('LK_BASE_URL fehlt oder ist ungültig (Beispiel: https://lichtkammer.example.org, ohne Pfad).');
        }
        new Keys($config->appKey(), $config->appKeyPrevious()); // wirft ConfigError mit Hinweis
        if ($config->trustProxy() && $config->trustedProxies() === []) {
            throw new ConfigError('LK_TRUST_PROXY=1 braucht LK_TRUSTED_PROXIES (Adresse des Reverse-Proxys). Ohne diese Angabe ließen sich Adressen fälschen.');
        }
        foreach (['LK_SETUP_TOKEN' => $config->setupToken(), 'LK_RESET_TOKEN' => $config->resetToken()] as $name => $tok) {
            if ($tok !== '' && strlen($tok) < 32) {
                throw new ConfigError($name . ' ist zu kurz (mindestens 32 Zeichen). Erzeugen mit: openssl rand -hex 24');
            }
        }
        $dataDir = self::prepareDataDir($config);
        if (self::inside(self::plannedPath($config->originalsDir()), $config->publicDir) && !$config->allowDataInWebroot()) {
            throw new ConfigError('Der Ordner der Originale liegt im öffentlichen Webroot. Originale enthalten alle Metadaten (auch GPS). Bitte LK_ORIGINALS_DIR außerhalb des Webroots setzen (auf all-inkl z. B. neben dem Datenordner). Die vorhandenen Originale werden dann automatisch dorthin verschoben. Nur wenn es nicht anders geht: LK_ALLOW_DATA_IN_WEBROOT=1.');
        }
        return $dataDir;
    }

    /** Legt den Datenordner an (0700, mit Sperrdateien) und liefert den realen Pfad. @throws ConfigError */
    public static function prepareDataDir(Config $config): string
    {
        $dir = $config->dataDir();
        $existing = $dir;
        $rest = [];
        while ($existing !== '' && !is_dir($existing) && dirname($existing) !== $existing) {
            array_unshift($rest, basename($existing));
            $existing = dirname($existing);
        }
        $realExisting = realpath($existing);
        if ($realExisting === false) {
            throw new ConfigError('Der Datenordner ist nicht erreichbar.');
        }
        $planned = rtrim($realExisting, '/') . ($rest !== [] ? '/' . implode('/', $rest) : '');
        if (self::inside($planned, $config->publicDir) && !$config->allowDataInWebroot()) {
            throw new ConfigError('Der Datenordner liegt im öffentlichen Webroot. Bitte LK_DATA_DIR außerhalb des Webroots setzen (auf all-inkl z. B. neben dem Ordner der Domain). Nur wenn es nicht anders geht: LK_ALLOW_DATA_IN_WEBROOT=1.');
        }
        if (!is_dir($planned)) {
            $old = umask(0077);
            $ok = @mkdir($planned, 0700, true);
            umask($old);
            if (!$ok && !is_dir($planned)) {
                throw new ConfigError('Der Datenordner konnte nicht angelegt werden. Bitte Schreibrechte prüfen.');
            }
        }
        $real = realpath($planned);
        if ($real === false || !is_writable($real)) {
            throw new ConfigError('Der Datenordner ist nicht beschreibbar.');
        }
        if (!is_file($real . '/.htaccess')) {
            @file_put_contents($real . '/.htaccess', "Require all denied\n");
        }
        if (!is_file($real . '/index.html')) {
            @file_put_contents($real . '/index.html', '');
        }
        return $real;
    }

    public static function inside(string $path, string $root): bool
    {
        $r = realpath($root);
        $root = ($r === false ? $root : $r);
        $path = rtrim($path, '/') . '/';
        $root = rtrim($root, '/') . '/';
        return str_starts_with($path, $root);
    }
}
