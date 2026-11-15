<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

final class GdSource implements ImageSource
{
    public function __construct(private \GdImage $img)
    {
    }

    public function width(): int
    {
        return imagesx($this->img);
    }

    public function height(): int
    {
        return imagesy($this->img);
    }

    private function scaled(int $width): \GdImage
    {
        $w = $this->width();
        // transparente Flächen auf Weiß
        $flat = imagecreatetruecolor($w, $this->height());
        imagefill($flat, 0, 0, (int) imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $this->img, 0, 0, 0, 0, $w, $this->height());
        if ($width >= $w) {
            return $flat;
        }
        $out = imagescale($flat, $width, -1, IMG_BICUBIC);
        if ($out === false) {
            throw new MediaException('Eine Bildvariante konnte nicht erzeugt werden.');
        }
        return $out;
    }

    public function encode(int $width, string $format): string
    {
        $im = $this->scaled($width);
        ob_start();
        $ok = match ($format) {
            'jpeg' => (function () use ($im): bool {
                imageinterlace($im, true);
                return imagejpeg($im, null, 82);
            })(),
            'webp' => imagewebp($im, null, 80),
            'avif' => function_exists('imageavif') && imageavif($im, null, 55),
            default => false,
        };
        $blob = (string) ob_get_clean();
        if (!$ok || $blob === '') {
            throw new MediaException('Eine Bildvariante konnte nicht erzeugt werden.');
        }
        return $blob;
    }

    public function placeholder(): string
    {
        $im = $this->scaled(20);
        imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
        ob_start();
        imagejpeg($im, null, 40);
        return (string) ob_get_clean();
    }

    public function close(): void
    {
        unset($this->img);
    }
}
