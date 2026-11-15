<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

/** Ersatz ohne Imagick: JPEG, PNG, WebP lesen; JPEG und WebP schreiben (AVIF nur, wenn GD es kann). */
final class GdEngine implements ImageEngine
{
    public static function available(): bool
    {
        return extension_loaded('gd');
    }

    public function name(): string
    {
        return 'GD';
    }

    public function formats(): array
    {
        $f = [];
        $info = gd_info();
        if (function_exists('imagejpeg')) {
            $f[] = 'jpeg';
        }
        if (function_exists('imagewebp') && !empty($info['WebP Support'])) {
            $f[] = 'webp';
        }
        if (function_exists('imageavif') && !empty($info['AVIF Support'])) {
            $f[] = 'avif';
        }
        return $f;
    }

    public function canRead(string $mime): bool
    {
        $info = gd_info();
        return match ($mime) {
            'image/jpeg' => true,
            'image/png' => true,
            'image/webp' => !empty($info['WebP Support']),
            default => false,
        };
    }

    public function open(string $path, int $extraRotation = 0): ImageSource
    {
        $size = @getimagesize($path);
        if ($size === false) {
            throw new MediaException('Das Bild konnte nicht gelesen werden. Die Datei ist beschädigt oder das Format wird nicht unterstützt.');
        }
        // GD braucht etwa 5 Byte je Pixel; vorher prüfen, statt an der Speichergrenze abzustürzen.
        $limit = Capabilities::memoryLimitBytes();
        if ($limit > 0 && $size[0] * $size[1] * 5 > $limit * 0.8) {
            throw new MediaException('Das Bild ist für die Verarbeitung auf diesem Server zu groß (Speichergrenze). Bitte kleiner exportieren.');
        }
        $data = @file_get_contents($path);
        $img = $data === false ? false : @imagecreatefromstring($data);
        if ($img === false) {
            throw new MediaException('Das Bild konnte nicht gelesen werden. Die Datei ist beschädigt oder das Format wird nicht unterstützt.');
        }
        $deg = $extraRotation;
        if (($size['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $deg += match ((int) ($exif['Orientation'] ?? 1)) {
                3, 4 => 180,
                6, 5 => 270, // GD dreht gegen den Uhrzeigersinn
                8, 7 => 90,
                default => 0,
            };
            if (in_array((int) ($exif['Orientation'] ?? 1), [2, 4, 5, 7], true)) {
                imageflip($img, IMG_FLIP_HORIZONTAL);
            }
        }
        $deg = (($deg % 360) + 360) % 360;
        if ($deg !== 0) {
            $rot = imagerotate($img, (float) $deg, 0);
            if ($rot !== false) {
                $img = $rot;
            }
        }
        if (!imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        return new GdSource($img);
    }
}
