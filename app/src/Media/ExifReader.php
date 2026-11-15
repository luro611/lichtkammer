<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/**
 * Liest EXIF aus dem Original. Gespeichert wird nur eine feste, harmlose Auswahl (Kamera, Objektiv,
 * Blende, Zeit, ISO, Brennweite, Datum). GPS wird nie gespeichert, nur als Hinweis „hat GPS“ gemerkt.
 */
final class ExifReader
{
    /** @return array{fields:array<string,string>,taken_at:?string,has_gps:bool} */
    public static function read(string $path, string $mime): array
    {
        $out = ['fields' => [], 'taken_at' => null, 'has_gps' => false];
        if (!function_exists('exif_read_data') || !in_array($mime, ['image/jpeg', 'image/webp', 'image/heic', 'image/heif'], true)) {
            return $out;
        }
        $raw = @exif_read_data($path, 'ANY_TAG', false, false);
        if (!is_array($raw)) {
            return $out;
        }
        $out['has_gps'] = isset($raw['GPSLatitude']) || isset($raw['GPSLongitude']) || isset($raw['GPSVersion']);
        $make = self::text($raw['Make'] ?? '');
        $model = self::text($raw['Model'] ?? '');
        if ($model !== '') {
            $out['fields']['camera'] = ($make !== '' && !str_starts_with(strtolower($model), strtolower($make)) ? $make . ' ' : '') . $model;
        } elseif ($make !== '') {
            $out['fields']['camera'] = $make;
        }
        $lens = self::text($raw['UndefinedTag:0xA434'] ?? ($raw['LensModel'] ?? ''));
        if ($lens !== '') {
            $out['fields']['lens'] = $lens;
        }
        if (($f = self::rational($raw['FNumber'] ?? null)) !== null) {
            $out['fields']['aperture'] = 'f/' . rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
        }
        if (($t = self::rational($raw['ExposureTime'] ?? null)) !== null && $t > 0) {
            $out['fields']['exposure'] = $t >= 1 ? rtrim(rtrim(number_format($t, 1, '.', ''), '0'), '.') . ' s' : '1/' . (int) round(1 / $t) . ' s';
        }
        $iso = $raw['ISOSpeedRatings'] ?? null;
        $iso = is_array($iso) ? ($iso[0] ?? null) : $iso;
        if (is_numeric($iso) && (int) $iso > 0 && (int) $iso < 10000000) {
            $out['fields']['iso'] = (string) (int) $iso;
        }
        if (($fl = self::rational($raw['FocalLength'] ?? null)) !== null && $fl > 0) {
            $out['fields']['focal_length'] = rtrim(rtrim(number_format($fl, 1, '.', ''), '0'), '.') . ' mm';
        }
        $dt = self::text($raw['DateTimeOriginal'] ?? ($raw['DateTimeDigitized'] ?? ''));
        if (preg_match('/^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', $dt, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $out['taken_at'] = "$m[1]-$m[2]-$m[3] $m[4]:$m[5]:$m[6]";
        }
        return $out;
    }

    private static function rational(mixed $v): ?float
    {
        if (is_numeric($v)) {
            return (float) $v;
        }
        if (is_string($v) && preg_match('~^(\d+)/(\d+)$~', $v, $m) === 1 && (int) $m[2] !== 0) {
            return (int) $m[1] / (int) $m[2];
        }
        return null;
    }

    /** Nur druckbarer Text, gültiges UTF-8, höchstens 100 Zeichen. Ausgabe wird später trotzdem escaped. */
    private static function text(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = trim(str_replace("\0", '', $v));
        if (!mb_check_encoding($v, 'UTF-8')) {
            $v = (string) @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
        }
        $v = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? '';
        return mb_substr(trim($v), 0, 100);
    }
}
