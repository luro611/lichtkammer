<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/** Wählt die Bildbibliothek: Imagick, sonst GD. Nichts davon ist fest verdrahtet. */
final class Capabilities
{
    public static function engine(): ?ImageEngine
    {
        if (ImagickEngine::available()) {
            return new ImagickEngine();
        }
        if (GdEngine::available()) {
            return new GdEngine();
        }
        return null;
    }

    public static function memoryLimitBytes(): int
    {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
