# Testumgebung: PHP 8.5 CLI mit intl, GD, exif und Imagick (wie Docker-Image und all-inkl).
# sodium und pdo_sqlite sind schon dabei.
#   docker build -t lichtkammer-test -f tests/Dockerfile tests
#   docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php
FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libmagickwand-dev libheif-dev libavif-dev libwebp-dev libjpeg-dev libpng-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" intl gd exif \
    && (pecl install imagick || pecl install imagick-3.8.0) && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
