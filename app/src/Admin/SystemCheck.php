<?php
declare(strict_types=1);

namespace Lichtkammer\Admin;

use Lichtkammer\Auth\Clock;
use Lichtkammer\Auth\Database;
use Lichtkammer\Auth\Preflight;
use Lichtkammer\Auth\Schema;
use Lichtkammer\Config;

/**
 * Systemprüfung (Entwurf 20, 1.6): dieselben Grundlagen wie die Startprüfung, als Liste für Administratoren. Zeigt nur
 * Zustände, nie Schlüssel, Token oder Passwörter. Pfade nur als „im Webroot / außerhalb“.
 *
 * Stufen: ok, warn (bitte ansehen), bad (muss behoben werden), info (nur zur Kenntnis).
 */
final class SystemCheck
{
    /** Zeichenfolge, die `public/.user.ini` für highlight.string setzt: so lässt sich messen, ob der Server die Datei liest. */
    public const USER_INI_MARK = '#DD0001';
    private const DISK_CACHE = 86400;

    /** @return list<array{level:string,title:string,text:string}> */
    public static function run(Config $config, Database $db, Clock $clock, ?string $dataDirReal): array
    {
        $o = [];
        $add = static function (string $level, string $title, string $text) use (&$o): void {
            $o[] = ['level' => $level, 'title' => $title, 'text' => $text];
        };

        // PHP und Erweiterungen
        $add(version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'bad', 'PHP-Version', PHP_VERSION . (version_compare(PHP_VERSION, '8.3.0', '>=') ? '' : ' (nötig: 8.3 oder neuer)'));
        $missing = array_values(array_filter(['sodium', 'intl', 'pdo_sqlite'], static fn (string $x): bool => !extension_loaded($x)));
        $add($missing === [] ? 'ok' : 'bad', 'Pflicht-Erweiterungen (sodium, intl, pdo_sqlite)', $missing === [] ? 'alle vorhanden' : 'fehlt: ' . implode(', ', $missing));
        $img = array_values(array_filter(['imagick', 'gd'], 'extension_loaded'));
        $add($img !== [] ? 'ok' : 'bad', 'Bildverarbeitung', $img !== [] ? implode(' und ', $img) . (in_array('imagick', $img, true) ? ' (HEIC/AVIF je nach Server möglich)' : ' (ohne Imagick: kein HEIC/AVIF)') : 'weder Imagick noch GD gefunden');
        $add(extension_loaded('exif') ? 'ok' : 'warn', 'EXIF lesen', extension_loaded('exif') ? 'vorhanden' : 'Erweiterung exif fehlt: Aufnahmedaten werden nicht gelesen');

        // PHP-Einstellungen
        $display = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || strtolower((string) ini_get('display_errors')) === 'stderr';
        $add(!$display || $config->isDebug() ? 'ok' : 'warn', 'Fehlermeldungen im Browser', $display ? 'an (nur für die Entwicklung gedacht)' : 'aus (richtig)');
        $add(filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN) ? 'ok' : 'warn', 'Fehlerprotokoll des Servers', filter_var(ini_get('log_errors'), FILTER_VALIDATE_BOOLEAN) ? 'an' : 'aus: Fehler würden nirgends stehen');
        $userIni = ini_get('highlight.string') === self::USER_INI_MARK;
        $add('info', '.user.ini', $userIni ? 'wird vom Server gelesen (Einstellungen aus public/.user.ini gelten)' : 'wird nicht gelesen. Bei Docker (Apache-Modul) ist das normal, dort gilt docker/php.ini. Auf all-inkl dann die Einstellungen im KAS prüfen.');
        $mem = self::bytes((string) ini_get('memory_limit'));
        $add($mem === -1 || $mem >= 256 * 1048576 ? 'ok' : 'warn', 'Arbeitsspeicher je Anfrage', (string) ini_get('memory_limit') . ($mem !== -1 && $mem < 256 * 1048576 ? ' (große Fotos brauchen mindestens 256M)' : ''));
        $add('info', 'Laufzeit, Upload-Grenzen', 'max_execution_time ' . (string) ini_get('max_execution_time') . ' s, upload_max_filesize ' . (string) ini_get('upload_max_filesize') . ', post_max_size ' . (string) ini_get('post_max_size'));

        // Adresse und Verbindung
        $https = str_starts_with($config->baseUrl(), 'https://');
        $add($https ? 'ok' : 'warn', 'Adresse der Seite', $https ? 'https (verschlüsselt)' : 'LK_BASE_URL beginnt nicht mit https:// (nur für lokale Entwicklung in Ordnung)');
        if ($config->noindex()) {
            $add('info', 'Suchmaschinen', 'ausgesperrt (LK_NOINDEX=1). Für die Hauptseite nach dem Livegang auf 0 stellen.');
        }

        // Ordner und Dateien
        if ($dataDirReal !== null) {
            $inWeb = Preflight::inside($dataDirReal, $config->publicDir);
            $add($inWeb ? 'bad' : 'ok', 'Datenordner', ($inWeb ? 'liegt im öffentlichen Webroot (nur per .htaccess geschützt)' : 'liegt außerhalb des Webroots') . (is_writable($dataDirReal) ? ', beschreibbar' : ', NICHT beschreibbar'));
            $mode = @fileperms($dataDirReal);
            if ($mode !== false && ($mode & 0077) !== 0) {
                $add('info', 'Rechte des Datenordners', sprintf('%o: für andere Benutzer lesbar. Empfohlen: 700.', $mode & 0777));
            }
        }
        $origInWeb = Preflight::inside(self::resolve($config->originalsDir()), $config->publicDir);
        $add($origInWeb ? 'bad' : 'ok', 'Originale', $origInWeb ? 'liegen im öffentlichen Webroot. LK_ORIGINALS_DIR außerhalb setzen.' : 'liegen außerhalb des Webroots');
        $env = $config->appDir . '/.env';
        if (is_file($env)) {
            $m = @fileperms($env);
            $add($m !== false && ($m & 0077) !== 0 ? 'warn' : 'ok', 'Rechte der Datei app/.env', $m === false ? 'unbekannt' : sprintf('%o', $m & 0777) . ($m !== false && ($m & 0077) !== 0 ? ': für andere Benutzer lesbar. Empfohlen: 600.' : ''));
        }
        $add($config->allowDataInWebroot() ? 'warn' : 'ok', 'Ausnahme „Daten im Webroot“', $config->allowDataInWebroot() ? 'LK_ALLOW_DATA_IN_WEBROOT ist gesetzt. In der Produktion nicht verwenden.' : 'nicht gesetzt');

        // Token
        $add($config->setupToken() !== '' ? 'warn' : 'ok', 'Einrichtungs-Token', $config->setupToken() !== '' ? 'LK_SETUP_TOKEN ist gesetzt. Nach der Einrichtung entfernen (./deploy.sh --token-entfernen).' : 'nicht gesetzt');
        $add($config->resetToken() !== '' ? 'info' : 'ok', 'Notfall-Token', $config->resetToken() !== '' ? 'LK_RESET_TOKEN ist gesetzt. Nur für den Notfall gedacht; sonst leer lassen.' : 'nicht gesetzt (richtig)');
        $add($config->isDebug() ? 'warn' : 'ok', 'Betriebsart', $config->isDebug() ? 'Entwicklung (LK_ENV=development): Fehlermeldungen können Einzelheiten zeigen' : 'Produktion');

        // Datenbank
        $ic = (string) $db->pdo->query('PRAGMA quick_check')->fetchColumn();
        $schema = (int) ($db->value("SELECT v FROM meta WHERE k = 'schema_version'") ?? 0);
        $add($ic === 'ok' ? 'ok' : 'bad', 'Datenbank', 'Prüfung ' . ($ic === 'ok' ? 'bestanden' : 'meldet Fehler (Sicherung einspielen?)') . ', Schema ' . $schema . ($schema === count(Schema::migrations()) ? '' : ' (erwartet ' . count(Schema::migrations()) . ')'));

        // Uhr
        $lastAudit = (int) ($db->value('SELECT MAX(at) FROM audit_log') ?? 0);
        $now = $clock->now();
        $add($lastAudit > $now + 300 ? 'bad' : 'ok', 'Uhr des Servers', gmdate('d.m.Y H:i', $now) . ' UTC' . ($lastAudit > $now + 300 ? ' (das Protokoll hat Einträge aus der Zukunft: die Uhr geht falsch)' : ''));

        // Platz
        $used = self::usedBytes($config, $db, $clock, true);
        $free = @disk_free_space($config->dataDir() !== '' && is_dir($config->dataDir()) ? $config->dataDir() : '/');
        $total = @disk_total_space($config->dataDir() !== '' && is_dir($config->dataDir()) ? $config->dataDir() : '/');
        $diskWarn = self::diskWarning($config, $db, $clock);
        $add($diskWarn === null ? 'ok' : 'warn', 'Speicherplatz', 'Daten, Originale und Bilder belegen ' . self::human($used)
            . ($free !== false && $total !== false && $total > 0 ? '; auf dem Laufwerk sind ' . self::human((int) $free) . ' von ' . self::human((int) $total) . ' frei (auf geteilten Servern zeigt das oft das ganze Laufwerk, nicht Ihren Anteil)' : '')
            . ($config->int('LK_DISK_WARN_GB', 0) > 0 ? '; Warnung ab ' . $config->int('LK_DISK_WARN_GB', 0) . ' GB' : ''));

        // Sicherung
        $last = $db->value("SELECT v FROM meta WHERE k = 'last_backup_at'");
        $days = $last === null ? null : intdiv($now - (int) $last, 86400);
        $add($last === null ? 'warn' : ($days > 3 ? 'warn' : 'ok'), 'Letzte Sicherung', $last === null ? 'Es wurde noch keine Sicherung heruntergeladen (Verwaltung → Sicherung).' : gmdate('d.m.Y H:i', (int) $last) . ' UTC, vor ' . $days . ' Tag(en)');

        $add('info', 'Version', Config::VERSION);
        return $o;
    }

    /** Hinweis fürs Warnband oder null. Nutzt den Zwischenspeicher (höchstens einmal je Tag wird gezählt). */
    public static function diskWarning(Config $config, Database $db, Clock $clock): ?string
    {
        $dir = is_dir($config->dataDir()) ? $config->dataDir() : '/';
        $free = @disk_free_space($dir);
        $minMb = $config->int('LK_DISK_MIN_FREE_MB', 1024);
        if ($free !== false && $minMb > 0 && $free < $minMb * 1048576) {
            return 'Auf dem Laufwerk sind nur noch ' . self::human((int) $free) . ' frei. Neue Fotos könnten nicht mehr gespeichert werden.';
        }
        $warnGb = $config->int('LK_DISK_WARN_GB', 0);
        if ($warnGb > 0) {
            $used = self::usedBytes($config, $db, $clock);
            if ($used > $warnGb * 1073741824) {
                return 'Daten, Originale und Bilder belegen ' . self::human($used) . ' (Warnschwelle ' . $warnGb . ' GB, LK_DISK_WARN_GB). Bitte Platz schaffen oder den Tarif prüfen.';
            }
        }
        return null;
    }

    /** Größe von Daten, Originalen und erzeugten Bildern; höchstens einmal je Tag neu gezählt (außer $force). */
    public static function usedBytes(Config $config, Database $db, Clock $clock, bool $force = false): int
    {
        $at = (int) ($db->value("SELECT v FROM meta WHERE k = 'disk_checked_at'") ?? 0);
        $cached = $db->value("SELECT v FROM meta WHERE k = 'disk_used_bytes'");
        if (!$force && $cached !== null && $clock->now() - $at < self::DISK_CACHE) {
            return (int) $cached;
        }
        $sum = 0;
        $seen = [];
        foreach ([$config->dataDir(), $config->originalsDir(), $config->mediaDir()] as $dir) {
            $real = realpath($dir);
            if ($real === false || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $sum += (int) $f->getSize();
                }
            }
        }
        foreach (['disk_used_bytes' => (string) $sum, 'disk_checked_at' => (string) $clock->now()] as $k => $v) {
            $db->run('INSERT INTO meta(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $v]);
        }
        return $sum;
    }

    public static function human(int $bytes): string
    {
        $v = (float) $bytes;
        foreach (['Byte', 'KB', 'MB', 'GB', 'TB'] as $i => $u) {
            if ($v < 1024 || $u === 'TB') {
                return ($i === 0 ? (string) $bytes : number_format($v, $v < 10 ? 1 : 0, ',', '.')) . ' ' . $u;
            }
            $v /= 1024;
        }
        return (string) $bytes;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '-1') {
            return -1;
        }
        $n = (int) $v;
        return match (strtoupper(substr($v, -1))) {
            'G' => $n * 1073741824,
            'M' => $n * 1048576,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /** Pfad auflösen, auch wenn der Ordner noch nicht existiert (wie Preflight). */
    private static function resolve(string $dir): string
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
}
