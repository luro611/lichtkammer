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
    private const OVERLAP = 4096;
    /** Lange Namen: in Binärdaten (Bilder, Schriften) praktisch nie zufällig. */
    private const MARKERS = '/\/(?:JavaScript|OpenAction|Launch|EmbeddedFile|RichMedia|XFA|SubmitForm|GoToE|GoToR|ImportData)(?![A-Za-z0-9])/';
    /** Leerraum und Kommentare zwischen PDF-Wörtern (besitzergreifend: kein Zurückverfolgen). */
    private const WS = '(?:[\x00\t\n\f\r ]|%[^\r\n]*+)++';
    /** Verweis „n g R“; am Ende des Puffers abgeschnitten zählt er auch (der Rest liegt im nächsten Block). */
    private const REF = '\d++(?:' . self::WS . '(?:\d++(?:' . self::WS . '(?:R|\z)|\z)|\z)|\z)';
    /** Wert von /JS: Text „(“ oder Hex-Text „<“ (danach Hex-Ziffer, Leerraum oder „>“). */
    private const JS_VALUE = '(?:\(|<(?:[0-9A-Fa-f\x00\t\n\f\r >]|\z))';
    /**
     * Kurze Namen (/JS, /AA) stehen in komprimierten Bild- und Schriftdaten zufällig etwa einmal je 10 MB. Sie zählen deshalb nur
     * mit einem passenden Wert dahinter: /JS mit Text, Hex-Text oder Verweis, /AA mit Wörterbuch << oder Verweis. Steht der Name
     * am Ende des Puffers (Wert im nächsten Block, auch nach langem Leerraum), zählt er ebenfalls.
     */
    private const SHORT = '/\/(?:JS(?:' . self::JS_VALUE . '|\z|' . self::WS . '(?:' . self::JS_VALUE . '|' . self::REF . '|\z))|AA(?:<(?:<|\z)|\z|' . self::WS . '(?:<(?:<|\z)|' . self::REF . '|\z)))/';

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
                $plain = preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $buf);
                $long = $plain === null ? false : preg_match(self::MARKERS, $plain);
                $short = $plain === null ? false : preg_match(self::SHORT, $plain);
                if ($long === false || $short === false) { // Prüfung nicht möglich: lieber ablehnen als durchlassen
                    throw new MediaException('Das PDF konnte nicht geprüft werden und wird nicht angenommen.');
                }
                if ($long === 1 || $short === 1) {
                    throw new MediaException('Dieses PDF enthält aktive Inhalte (Skripte, Startaktionen oder eingebettete Dateien) und wird nicht angenommen. Bitte ein „flaches“ PDF exportieren.');
                }
                $tail = substr($buf, -self::OVERLAP);
            }
        } finally {
            fclose($fh);
        }
    }
}
