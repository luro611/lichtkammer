<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/** Bildbibliothek hinter einer Schnittstelle: Imagick (Normalfall) oder GD (Ersatz, ohne HEIC). */
interface ImageEngine
{
    public function name(): string;

    /** @return list<string> Ausgabeformate, die wirklich funktionieren: avif, webp, jpeg */
    public function formats(): array;

    /** Kann diese Bibliothek den MIME-Typ lesen? */
    public function canRead(string $mime): bool;

    /**
     * Öffnet ein Bild, wendet die Ausrichtung (EXIF) an und entfernt alle Metadaten (auch GPS) für die Ausgabe.
     *
     * @throws MediaException
     */
    public function open(string $path, int $extraRotation = 0): ImageSource;
}
