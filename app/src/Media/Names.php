<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/**
 * Namen von Ordnern und Dateien sind Daten, nie Pfade (Entwurf 16, 5.1). Eine Stelle für alle: Ordner, Dateien, Fotos.
 * Es wird so wenig wie möglich verändert: NFC, dann nur Unsichtbares und Täuschendes entfernt. Alles andere bleibt,
 * auch Zeichen, die Windows nicht mag, und reservierte Windows-Namen (Ausgabe ist immer maskiert, der Name wird nie Dateiname).
 */
final class Names
{
    public const MAX_LEN = 255;
    public const MAX_SEGMENTS = 8;
    public const MAX_PATH = 800;
    public const FOLDER_FALLBACK = 'Unbenannter Ordner';
    public const FILE_FALLBACK = 'Datei';

    /** Steuerzeichen (auch C1), Bidi-Steuerzeichen (auch U+061C), Zeichen ohne Breite, unsichtbare Füllzeichen (Hangul, Mongolisch), Zeilen-/Absatztrenner, Tag-Zeichen, weicher Trennstrich. */
    private const INVISIBLE = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{3164}\x{FEFF}\x{FFA0}\x{FFF9}-\x{FFFB}\x{E0000}-\x{E007F}]/u';

    /**
     * @return array{name:string,key:string,changed:bool} changed = Unsichtbares entfernt, gekürzt oder ersetzt (Umbau zu NFC zählt nicht)
     */
    public static function clean(string $raw, string $fallback = self::FOLDER_FALLBACK): array
    {
        $s = $raw;
        if (!mb_check_encoding($raw, 'UTF-8')) { // ungültige Bytes werden zu U+FFFD
            $old = mb_substitute_character();
            mb_substitute_character(0xFFFD);
            $s = mb_scrub($raw, 'UTF-8');
            mb_substitute_character($old);
        }
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        $nfc = $n === false ? $s : $n;
        $t = preg_replace(self::INVISIBLE, '', $nfc) ?? '';
        $t = preg_replace('/^[\s.]+|[\s.]+$/u', '', $t) ?? '';
        if (mb_strlen($t) > self::MAX_LEN) {
            $t = preg_replace('/^[\s.]+|[\s.]+$/u', '', mb_substr($t, 0, self::MAX_LEN)) ?? '';
        }
        if ($t === '') {
            $t = $fallback;
        }
        return ['name' => $t, 'key' => self::key($t), 'changed' => $t !== $nfc && $nfc !== ''];
    }

    /** Vergleichsschlüssel: NFC und kleingeschrieben (bewusste Näherung, ohne ß/İ-Sonderfälle von Finder und Windows). */
    public static function key(string $name): string
    {
        $n = \Normalizer::normalize($name, \Normalizer::FORM_C);
        return mb_strtolower($n === false ? $name : $n);
    }

    /**
     * Pfad aus dem Browser (Text mit „/“ oder Liste) in geprüfte Segmente. Nie ein Dateipfad: nur Anzeigenamen.
     *
     * @param string|list<mixed> $path
     * @return array{segments:list<string>,changed:bool}
     * @throws MediaException bei zu vielen Segmenten oder zu langem Pfad
     */
    public static function segments(string|array $path): array
    {
        $parts = [];
        foreach (is_array($path) ? $path : [$path] as $p) {
            if (!is_string($p)) {
                throw new MediaException('Ungültiger Ordnerpfad.');
            }
            foreach (explode('/', $p) as $piece) {
                $parts[] = $piece;
            }
        }
        $out = [];
        $changed = false;
        $total = 0;
        foreach ($parts as $piece) {
            if ($piece === '' ) { // „//“ und führender oder abschließender Schrägstrich (absoluter Pfad) ergeben kein Segment
                continue;
            }
            $c = self::clean($piece);
            if (in_array(trim($piece), ['.', '..'], true)) { // „..“ ist nie ein Ordner und führt nirgends hin
                continue;
            }
            $changed = $changed || $c['changed'];
            $out[] = $c['name'];
            $total += mb_strlen($c['name']) + 1;
        }
        if (count($out) > self::MAX_SEGMENTS) {
            throw new MediaException('Die Ordner sind zu tief verschachtelt (höchstens ' . self::MAX_SEGMENTS . ' Ebenen).');
        }
        if ($total > self::MAX_PATH) {
            throw new MediaException('Der Ordnerpfad ist zu lang.');
        }
        return ['segments' => $out, 'changed' => $changed];
    }

    /** Dateiname aus einem Browser-Namen: nur das letzte Segment, geprüft. */
    public static function fileName(string $raw): string
    {
        $last = basename(str_replace('\\', '/', $raw));
        return self::clean($last, self::FILE_FALLBACK)['name'];
    }

    /** Dateien und Ordner, die beim Ordner-Upload still übersprungen werden (Systemmüll). */
    public static function isJunk(string $name): bool
    {
        $l = mb_strtolower($name);
        return $name === '' || $name[0] === '.' || $l === 'thumbs.db' || $l === 'desktop.ini' || $l === '__macosx' || str_starts_with($name, '._');
    }

    /** Natürliche Sortierung ohne Groß-/Kleinschreibung („Bild 2“ vor „Bild 10“). */
    public static function natural(string $a, string $b): int
    {
        return strnatcasecmp($a, $b) ?: strcmp($a, $b);
    }

    /** Name für einen Kopfwert (Download): ohne Anführungszeichen, Schrägstriche, Steuer- und Bidi-Zeichen, höchstens 100 Zeichen. */
    public static function headerName(string $name, string $fallback = 'datei'): array
    {
        $n = preg_replace(self::INVISIBLE, '', $name) ?? '';
        $n = str_replace(['"', '\\', '/', ';', '%', ':'], '', $n);
        $n = mb_substr(trim($n), 0, 100);
        if ($n === '') {
            $n = $fallback;
        }
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', transliterator_transliterate('Any-Latin; Latin-ASCII', $n) ?: $n) ?? '';
        $ascii = trim($ascii) === '' ? $fallback : trim($ascii);
        return ['utf8' => $n, 'ascii' => $ascii];
    }
}
