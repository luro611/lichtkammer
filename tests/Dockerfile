# Testumgebung: PHP 8.5 CLI mit intl (wie Docker-Image und all-inkl), sodium und pdo_sqlite sind schon dabei.
#   docker build -t lichtkammer-test -f tests/Dockerfile tests
#   docker run --rm -v "$PWD":/app -w /app lichtkammer-test php tests/run.php
FROM php:8.5-cli
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev \
    && docker-php-ext-install -j"$(nproc)" intl \
    && rm -rf /var/lib/apt/lists/*
