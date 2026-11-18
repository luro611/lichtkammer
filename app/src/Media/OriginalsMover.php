<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

use Lichtkammer\Config;

/**
 * Zieht Originale aus dem alten Standardordner <Wurzel>/originals (auf all-inkl im Webroot) in den
 * Ordner LK_ORIGINALS_DIR außerhalb des Webroots (Entwurf 20, H1/E19).
 *
 * Regeln: nie überschreiben, nie löschen, bevor die Kopie geprüft ist (SHA-256), Symlinks werden nicht angefasst,
 * ein Abbruch mitten drin ist harmlos (der nächste Aufruf macht weiter). In der Datenbank steht nur der relative
 * Pfad (original_path), er ändert sich nicht.
 */
final class OriginalsMover
{
    /** Höchstdauer eines Durchgangs in Sekunden (all-inkl: max_execution_time 60). */
    private const BUDGET = 25;

    /** Alter Ordner, falls er sich vom eingestellten unterscheidet; sonst null. */
    public static function legacyDir(Config $config): ?string
    {
        $legacy = dirname($config->appDir) . '/originals';
        $new = $config->originalsDir();
        if (!is_dir($legacy) || is_link($legacy)) {
            return null;
        }
        $a = realpath($legacy);
        $b = is_dir($new) ? realpath($new) : false;
        if ($a === false || ($b !== false && $a === $b) || rtrim($legacy, '/') === rtrim($new, '/')) {
            return null;
        }
        return $legacy;
    }

    /** Anzahl Dateien, die im alten Ordner noch auf den Umzug warten (ohne Sperrdateien). */
    public static function pending(Config $config): int
    {
        $legacy = self::legacyDir($config);
        return $legacy === null ? 0 : count(self::files($legacy));
    }

    /**
     * Ein Durchgang. @return array{moved:int,remaining:int,conflicts:int}
     */
    public static function run(Config $config, ?int $budget = null): array
    {
        $result = ['moved' => 0, 'remaining' => 0, 'conflicts' => 0];
        $legacy = self::legacyDir($config);
        if ($legacy === null) {
            return $result;
        }
        $dst = rtrim($config->originalsDir(), '/');
        $deadline = microtime(true) + ($budget ?? self::BUDGET);

        // Ein Durchgang zur Zeit (mehrere gleichzeitige Anfragen).
        $lockFile = $config->dataDir() . '/originals-move.lock';
        $lock = @fopen($lockFile, 'cb');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $result['remaining'] = self::pending($config);
            return $result;
        }
        try {
            // Schnellweg: Zielordner gibt es noch nicht -> der ganze Ordner wird in einem Schritt umbenannt.
            if (!file_exists($dst)) {
                $parent = dirname($dst);
                if (!is_dir($parent)) {
                    $old = umask(0077);
                    @mkdir($parent, 0700, true);
                    umask($old);
                }
                $n = count(self::files($legacy));
                if (is_dir($parent) && @rename($legacy, $dst)) {
                    @chmod($dst, 0700);
                    $result['moved'] = $n;
                    return $result;
                }
            }
            $old = umask(0077);
            if (!is_dir($dst)) {
                @mkdir($dst, 0700, true);
            }
            umask($old);
            if (!is_dir($dst)) {
                $result['remaining'] = count(self::files($legacy));
                return $result;
            }
            foreach (self::files($legacy) as $rel) {
                if (microtime(true) > $deadline) {
                    break;
                }
                $r = self::moveOne($legacy . '/' . $rel, $dst . '/' . $rel);
                if ($r === 'moved') {
                    $result['moved']++;
                } elseif ($r === 'conflict') {
                    $result['conflicts']++;
                }
            }
            $left = self::files($legacy);
            $result['remaining'] = count($left);
            if ($left === []) { // nur Sperrdateien und leere Ordner übrig
                self::cleanup($legacy);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $result;
    }

    /** @return 'moved'|'conflict'|'skipped' */
    private static function moveOne(string $from, string $to): string
    {
        if (is_link($from) || !is_file($from)) {
            return 'skipped';
        }
        $dir = dirname($to);
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
        if (file_exists($to)) { // nie überschreiben: gleicher Inhalt -> Quelle ist überflüssig, sonst Konflikt
            if (is_file($to) && hash_file('sha256', $from) === hash_file('sha256', $to)) {
                return @unlink($from) ? 'moved' : 'skipped';
            }
            return 'conflict';
        }
        if (@rename($from, $to)) {
            return 'moved';
        }
        // anderes Dateisystem: kopieren, prüfen, erst dann die Quelle entfernen
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'xb');
        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }
            return 'skipped';
        }
        $ok = stream_copy_to_stream($in, $out) === filesize($from);
        fclose($in);
        fclose($out);
        if (!$ok || hash_file('sha256', $from) !== hash_file('sha256', $to)) {
            @unlink($to);
            return 'skipped';
        }
        @chmod($to, 0600);
        return @unlink($from) ? 'moved' : 'skipped';
    }

    /** @return list<string> relative Pfade aller Dateien außer .htaccess/index.html im Wurzelordner */
    private static function files(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        $base = strlen(rtrim($dir, '/')) + 1;
        foreach ($it as $f) {
            if (!$f->isFile() && !$f->isLink()) {
                continue;
            }
            $rel = substr($f->getPathname(), $base);
            if ($rel === '.htaccess' || $rel === 'index.html') {
                continue;
            }
            $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    private static function cleanup(string $dir): void
    {
        @unlink($dir . '/.htaccess');
        @unlink($dir . '/index.html');
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            @rmdir($sub);
        }
        @rmdir($dir);
    }
}
