# PHP environment of the test bench. ianseo itself is not in the image: bin/core.php
# downloads the official package and updates it, so the image never needs a rebuild
# when ianseo publishes a release. Base image pinned by digest: it changes only through
# a visible change of this file.
FROM php:8.3-apache-bookworm@sha256:a14446c32a3c8aeb9b4dac7fb73b03dfd8006e670d9b43ea7947e4030d3cbae6

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd zip intl \
 && rm -rf /var/lib/apt/lists/*

RUN { echo 'memory_limit = 1024M'; echo 'date.timezone = UTC'; echo 'allow_url_fopen = On'; } \
    > /usr/local/etc/php/conf.d/bench.ini

WORKDIR /src/tests
ENTRYPOINT ["php"]
