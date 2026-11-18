<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/**
 * Plausibilitäts- und Marker-Prüfung für PDF (Entwurf 16, 5.3). Ehrlich: ein Filter gegen die gängigen aktiven Inhalte,
 * kein Beweis (in komprimierten Objektströmen sieht man nicht alles). Die eigentliche Absicherung: nur Angemeldete laden hoch,
 * PDFs werden nie auf dem Server gerendert oder umgewandelt, Auslieferung mit festem Typ und nosniff, im Admin als Download.
 * Die Datei wird in Blöcken gelesen (nie ganz im Speicher); die Blöcke überlappen, damit ein Marker auf der Grenze nicht entgeht.
 */
final class PdfCheck
{
    private const BLOCK = 65536;
    private const OVERLAP = 256;
    private const MARKERS = '/\/(?:JavaScript|JS|OpenAction|AA|Launch|EmbeddedFile|RichMedia|XFA|SubmitForm|GoToE|GoToR|ImportData)(?![A-Za-z0-9])/';

    /** @throws MediaException */
    public static function check(string $file): void
    {
        $size = (int) filesize($file);
        $fh = @fopen($file, 'rb');
        if ($fh === false || $size < 20) {
            throw new MediaException('Die Datei ist kein gültiges PDF.');
        }
        try {
            if (fread($fh, 5) !== '%PDF-') {
                throw new MediaException('Die Datei ist kein gültiges PDF.');
            }
            fseek($fh, max(0, $size - 1024));
            if (!str_contains((string) fread($fh, 1024), '%%EOF')) {
                throw new MediaException('Das PDF ist unvollständig oder beschädigt (Dateiende fehlt).');
            }
            rewind($fh);
            $tail = '';
            while (!feof($fh)) {
                $block = (string) fread($fh, self::BLOCK);
                if ($block === '') {
                    break;
                }
                $buf = $tail . $block;
                // Namens-Tarnung auflösen: /Java#53cript → /JavaScript
                $plain = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $buf) ?? $buf;
                if (preg_match(self::MARKERS, $plain) === 1) {
                    throw new MediaException('Dieses PDF enthält aktive Inhalte (Skripte, Startaktionen oder eingebettete Dateien) und wird nicht angenommen. Bitte ein „flaches“ PDF exportieren.');
                }
                $tail = substr($buf, -self::OVERLAP);
            }
        } finally {
            fclose($fh);
        }
    }
}
