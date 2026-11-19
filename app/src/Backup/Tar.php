<?php
declare(strict_types=1);

namespace Lichtkammer\Backup;

/**
 * Minimaler TAR-Schreiber (ustar) zum Streamen: Kopf, Inhalt in Blöcken, Auffüllung. Ohne Zip-Erweiterung, ohne den
 * ganzen Inhalt im Speicher. Macs, Windows (ab 10) und Linux entpacken das Format.
 */
final class Tar
{
    private const BLOCK = 512;
    public const MAX_SIZE = 8589934591; // 11 Oktalziffern

    /** Gesamtgröße eines Eintrags im Archiv (Kopf, Inhalt, Auffüllung). */
    public static function entrySize(int $size): int
    {
        return self::BLOCK + (int) (ceil($size / self::BLOCK) * self::BLOCK);
    }

    /** Größe des Archivendes. */
    public static function endSize(): int
    {
        return 2 * self::BLOCK;
    }

    public static function end(): string
    {
        return str_repeat("\0", 2 * self::BLOCK);
    }

    public static function padding(int $size): string
    {
        $rest = $size % self::BLOCK;
        return $rest === 0 ? '' : str_repeat("\0", self::BLOCK - $rest);
    }

    /** @throws \InvalidArgumentException bei unmöglichen Namen oder zu großen Dateien */
    public static function header(string $name, int $size, int $mtime): string
    {
        if ($size < 0 || $size > self::MAX_SIZE) {
            throw new \InvalidArgumentException('Datei zu groß für das TAR-Format.');
        }
        if ($name === '' || $name[0] === '/' || str_contains($name, "\0") || str_contains($name, '..')) {
            throw new \InvalidArgumentException('Ungültiger Name im Archiv.');
        }
        $prefix = '';
        if (strlen($name) > 100) {
            $cut = strrpos(substr($name, 0, 156), '/');
            if ($cut === false || strlen($name) - $cut - 1 > 100 || $cut > 155) {
                throw new \InvalidArgumentException('Name zu lang für das TAR-Format.');
            }
            $prefix = substr($name, 0, $cut);
            $name = substr($name, $cut + 1);
        }
        $h = str_pad($name, 100, "\0")
            . sprintf('%07o', 0644) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%07o', 0) . "\0"
            . sprintf('%011o', $size) . "\0"
            . sprintf('%011o', max(0, $mtime)) . "\0"
            . '        ' // Prüfsumme (erst Leerzeichen)
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_pad('', 32, "\0") . str_pad('', 32, "\0")
            . sprintf('%07o', 0) . "\0" . sprintf('%07o', 0) . "\0"
            . str_pad($prefix, 155, "\0");
        $h = str_pad($h, self::BLOCK, "\0");
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($h[$i]);
        }
        return substr($h, 0, 148) . sprintf('%06o', $sum) . "\0 " . substr($h, 156);
    }
}
