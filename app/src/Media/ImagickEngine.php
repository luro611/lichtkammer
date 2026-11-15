<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

final class ImagickEngine implements ImageEngine
{
    /** @var list<string>|null */
    private ?array $formats = null;

    public static function available(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    public function name(): string
    {
        return 'Imagick';
    }

    public function formats(): array
    {
        if ($this->formats !== null) {
            return $this->formats;
        }
        $f = [];
        $supported = array_map('strtoupper', \Imagick::queryFormats());
        foreach (['jpeg' => 'JPEG', 'webp' => 'WEBP', 'avif' => 'AVIF'] as $name => $code) {
            if (!in_array($code, $supported, true)) {
                continue;
            }
            if ($name !== 'jpeg' && !$this->probe($code)) { // wirklich kodieren, nicht nur gemeldet
                continue;
            }
            $f[] = $name;
        }
        return $this->formats = $f;
    }

    private function probe(string $code): bool
    {
        try {
            $im = new \Imagick();
            $im->newImage(16, 16, new \ImagickPixel('#888888'));
            $im->setImageFormat($code);
            $ok = strlen($im->getImageBlob()) > 0;
            $im->clear();
            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    public function canRead(string $mime): bool
    {
        $map = ['image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/webp' => 'WEBP', 'image/heic' => 'HEIC', 'image/heif' => 'HEIC'];
        return isset($map[$mime]) && in_array($map[$mime], array_map('strtoupper', \Imagick::queryFormats()), true);
    }

    public function open(string $path, int $extraRotation = 0): ImageSource
    {
        try {
            $im = new \Imagick();
            $im->setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
            $im->readImage($path . '[0]'); // nur das erste Bild (kein Mehrbild-Missbrauch)
            self::orient($im);
            if ($extraRotation % 360 !== 0) {
                $im->rotateImage(new \ImagickPixel('none'), (float) $extraRotation);
            }
            if ($im->getImageColorspace() !== \Imagick::COLORSPACE_SRGB && $im->getImageColorspace() !== \Imagick::COLORSPACE_GRAY) {
                $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
            $im->setImagePage(0, 0, 0, 0);
            if ($im->getImageAlphaChannel()) { // PNG mit Transparenz: auf Weiß
                $im->setImageBackgroundColor(new \ImagickPixel('white'));
                $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            }
        } catch (\Throwable) {
            throw new MediaException('Das Bild konnte nicht gelesen werden. Die Datei ist beschädigt oder das Format wird nicht unterstützt.');
        }
        return new ImagickSource($im);
    }

    private static function orient(\Imagick $im): void
    {
        $o = $im->getImageOrientation();
        $rot = match ($o) {
            \Imagick::ORIENTATION_BOTTOMRIGHT, \Imagick::ORIENTATION_BOTTOMLEFT => 180.0,
            \Imagick::ORIENTATION_RIGHTTOP, \Imagick::ORIENTATION_RIGHTBOTTOM => 90.0,
            \Imagick::ORIENTATION_LEFTBOTTOM, \Imagick::ORIENTATION_LEFTTOP => 270.0,
            default => 0.0,
        };
        if (in_array($o, [\Imagick::ORIENTATION_TOPRIGHT, \Imagick::ORIENTATION_BOTTOMLEFT, \Imagick::ORIENTATION_LEFTTOP, \Imagick::ORIENTATION_RIGHTBOTTOM], true)) {
            $im->flopImage(); // gespiegelte Varianten
        }
        if ($rot !== 0.0) {
            $im->rotateImage(new \ImagickPixel('none'), $rot);
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
