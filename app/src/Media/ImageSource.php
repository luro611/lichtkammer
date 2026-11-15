<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/** Ein geöffnetes, bereits ausgerichtetes Bild. Ausgaben enthalten nie Metadaten. */
interface ImageSource
{
    public function width(): int;

    public function height(): int;

    /** Skaliert auf die Breite (nie vergrößern) und kodiert. @return string Bildbytes @throws MediaException */
    public function encode(int $width, string $format): string;

    /** Winziges JPEG für den Unschärfe-Platzhalter. */
    public function placeholder(): string;

    public function close(): void;
}
