<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/**
 * Verschiebt eine einzelne Datei, ohne je zu überschreiben und ohne halbe Dateien am Ziel (Prüfung 22, B1).
 *
 * Gleiches Dateisystem: rename() (atomar). Anderes Dateisystem (z. B. zwei Docker-Volumes): nicht rename(), denn PHP
 * kopiert dann intern ohne Prüfung und hinterlässt bei einem Abbruch eine abgeschnittene Datei unter dem endgültigen
 * Namen. Stattdessen in eine Teildatei neben dem Ziel kopieren, SHA-256 prüfen, die Teildatei umbenennen und erst dann
 * die Quelle löschen. Ein Abbruch hinterlässt höchstens eine Teildatei, die der nächste Durchgang ersetzt.
 */
final class SafeMove
{
    public const PART_SUFFIX = '.lk-teil';

    /**
     * @param string|null $sha erwartete SHA-256 der Quelle (null: nur Quelle und Kopie vergleichen)
     * @param bool $copy immer den Kopierweg nehmen (für Tests, die nur ein Dateisystem haben)
     * @return 'moved'|'exists'|'failed'
     */
    public static function file(string $from, string $to, ?string $sha = null, bool $copy = false): string
    {
        if (file_exists($to) || is_link($to)) {
            return 'exists';
        }
        $dir = dirname($to);
        if (!is_dir($dir)) {
            $old = umask(0077);
            @mkdir($dir, 0700, true);
            umask($old);
        }
        $a = @stat($from);
        $b = @stat($dir);
        if (!$copy && $a !== false && $b !== false && $a['dev'] === $b['dev']) {
            return @rename($from, $to) ? 'moved' : 'failed';
        }
        $sha ??= hash_file('sha256', $from);
        if (!is_string($sha)) {
            return 'failed';
        }
        $part = $to . self::PART_SUFFIX;
        if (is_link($part)) {
            return 'failed';
        }
        @unlink($part); // Rest eines abgebrochenen Durchgangs
        $old = umask(0077);
        $in = @fopen($from, 'rb');
        $out = @fopen($part, 'xb');
        umask($old);
        $ok = $in !== false && $out !== false && stream_copy_to_stream($in, $out) !== false && fflush($out);
        if (is_resource($in)) {
            fclose($in);
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (!$ok || hash_file('sha256', $part) !== $sha) {
            @unlink($part);
            return 'failed';
        }
        @chmod($part, 0600);
        if (file_exists($to) || !@rename($part, $to)) { // Ziel inzwischen belegt: nie überschreiben
            @unlink($part);
            return file_exists($to) ? 'exists' : 'failed';
        }
        @unlink($from);
        return 'moved';
    }
}
