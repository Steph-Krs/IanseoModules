# PHP environment of the test bench. ianseo itself is not in the image: bin/core.php
# downloads the official package and updates it, so the image never needs a rebuild
# when ianseo publishes a release. Base images pinned by digest: they change only
# through a visible change of this file. Build context: tests/.
#
# PHP_IMAGE chooses the PHP version (tests/run.sh --php): 8.3 by default, 8.0 the oldest
# version the ianseo installer accepts (Install/install-1.php). PHP 8.0 images exist
# on Debian 11 (bullseye) only.
ARG PHP_IMAGE=php:8.3-apache-bookworm@sha256:a14446c32a3c8aeb9b4dac7fb73b03dfd8006e670d9b43ea7947e4030d3cbae6

# jsdom for the browser-side checks, installed from the lockfile (exact versions,
# integrity checked) in a stage of its own: npm never reaches the final image.
FROM node:22-bookworm-slim@sha256:e9f565d32859f0cbfb52125eafe8be39390f24dac302dc3cdf566e8ba3ab21ee AS jsdom
WORKDIR /opt/bench-node
COPY dom/package.json dom/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund

# PHPUnit from its lockfile, resolved for PHP 8.0 (config.platform) so that the same
# tests run on every PHP of the bench. Composer never reaches the final image.
FROM composer:2.8.12@sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c AS phpunit
WORKDIR /opt/bench-php
COPY phpunit/composer.json phpunit/composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --no-plugins --ignore-platform-reqs

FROM ${PHP_IMAGE}

# Debian 11 (PHP 8.0) is out of support: its security repository moved to archive.debian.org
# (deb.debian.org still lists the packages, then answers 404). The main repository is still
# on deb.debian.org; when it moves too, redirect it the same way.
RUN if grep -q 'VERSION_CODENAME=bullseye' /etc/os-release; then \
        sed -i 's#http://deb.debian.org/debian-security#http://archive.debian.org/debian-security#' /etc/apt/sources.list; \
    fi

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd zip intl \
 && rm -rf /var/lib/apt/lists/*

RUN { echo 'memory_limit = 1024M'; echo 'date.timezone = UTC'; echo 'allow_url_fopen = On'; } \
    > /usr/local/etc/php/conf.d/bench.ini

# Node from the jsdom stage rather than the distribution's: the same version whatever the
# Debian release under PHP (bullseye's is too old for jsdom).
COPY --from=jsdom /usr/local/bin/node /usr/local/bin/node
COPY --from=jsdom /opt/bench-node/node_modules /opt/bench-node/node_modules
ENV NODE_PATH=/opt/bench-node/node_modules
COPY --from=phpunit /opt/bench-php/vendor /opt/bench-php/vendor

WORKDIR /src/tests
ENTRYPOINT ["php"]
