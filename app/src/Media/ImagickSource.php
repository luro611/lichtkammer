<?php
declare(strict_types=1);

namespace Lichtkammer\Media;

final class ImagickSource implements ImageSource
{
    public function __construct(private readonly \Imagick $im)
    {
    }

    public function width(): int
    {
        return $this->im->getImageWidth();
    }

    public function height(): int
    {
        return $this->im->getImageHeight();
    }

    public function encode(int $width, string $format): string
    {
        try {
            $c = clone $this->im;
            if ($width < $c->getImageWidth()) {
                $c->resizeImage($width, 0, \Imagick::FILTER_LANCZOS, 1.0);
            }
            $c->stripImage(); // EXIF, GPS, Profile: nichts davon in die öffentliche Ausgabe
            switch ($format) {
                case 'jpeg':
                    $c->setImageFormat('jpeg');
                    $c->setImageCompression(\Imagick::COMPRESSION_JPEG);
                    $c->setImageCompressionQuality(82);
                    $c->setInterlaceScheme(\Imagick::INTERLACE_PLANE);
                    $c->setSamplingFactors(['2x2', '1x1', '1x1']);
                    break;
                case 'webp':
                    $c->setImageFormat('webp');
                    $c->setImageCompressionQuality(80);
                    break;
                case 'avif':
                    $c->setImageFormat('avif');
                    $c->setImageCompressionQuality(55);
                    break;
                default:
                    throw new MediaException('Unbekanntes Ausgabeformat.');
            }
            $blob = $c->getImageBlob();
            $c->clear();
            return $blob;
        } catch (MediaException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new MediaException('Eine Bildvariante konnte nicht erzeugt werden.');
        }
    }

    public function placeholder(): string
    {
        $c = clone $this->im;
        $c->resizeImage(20, 0, \Imagick::FILTER_TRIANGLE, 1.0);
        $c->blurImage(1, 1);
        $c->stripImage();
        $c->setImageFormat('jpeg');
        $c->setImageCompressionQuality(40);
        $blob = $c->getImageBlob();
        $c->clear();
        return $blob;
    }

    public function close(): void
    {
        $this->im->clear();
    }
}
