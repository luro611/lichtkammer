# Lichtkammer: PHP 8.5 mit Apache, Imagick (AVIF/HEIC über libheif) und SQLite.
FROM php:8.5-apache

ARG VERSION=dev
LABEL org.opencontainers.image.title="Lichtkammer" \
      org.opencontainers.image.description="Schlankes Foto-CMS für eigene Bilder (PHP und SQLite)" \
      org.opencontainers.image.source="https://github.com/luro611/lichtkammer" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later" \
      org.opencontainers.image.version="${VERSION}"

RUN apt-get update && apt-get install -y --no-install-recommends \
        libmagickwand-dev libheif-dev libavif-dev libwebp-dev libjpeg-dev libpng-dev libzip-dev libicu-dev libgmp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd exif intl zip bcmath gmp \
    && (pecl install imagick || pecl install imagick-3.8.0) && docker-php-ext-enable imagick \
    && a2enmod rewrite headers remoteip \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php.ini /usr/local/etc/php/conf.d/lichtkammer.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/lk-entrypoint
RUN chmod +x /usr/local/bin/lk-entrypoint

WORKDIR /var/www/html
COPY app ./app
COPY public ./public
COPY LICENSE NOTICE /usr/share/doc/lichtkammer/

# Daten, Originale und erzeugte Bilder liegen in Volumes außerhalb des Webroots.
ENV LK_DATA_DIR=/var/lib/lichtkammer/data \
    LK_ORIGINALS_DIR=/var/lib/lichtkammer/originals \
    LK_MEDIA_DIR=/var/www/html/public/media
RUN mkdir -p /var/lib/lichtkammer/data /var/lib/lichtkammer/originals /var/www/html/public/media \
    && chown -R www-data:www-data /var/lib/lichtkammer /var/www/html/public/media
VOLUME ["/var/lib/lichtkammer/data", "/var/lib/lichtkammer/originals", "/var/www/html/public/media"]

ENTRYPOINT ["lk-entrypoint"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r 'exit(str_starts_with((string)@file_get_contents("http://127.0.0.1/health"),"ok ")?0:1);'
