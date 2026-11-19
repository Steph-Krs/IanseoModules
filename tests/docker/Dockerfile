# PHP environment of the test bench. ianseo itself is not in the image: bin/core.php
# downloads the official package and updates it, so the image never needs a rebuild
# when ianseo publishes a release. Base images pinned by digest: they change only
# through a visible change of this file. Build context: tests/.

# jsdom for the browser-side checks, installed from the lockfile (exact versions,
# integrity checked) in a stage of its own: npm never reaches the final image.
FROM node:22-bookworm-slim@sha256:e9f565d32859f0cbfb52125eafe8be39390f24dac302dc3cdf566e8ba3ab21ee AS jsdom
WORKDIR /opt/bench-node
COPY dom/package.json dom/package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund

FROM php:8.3-apache-bookworm@sha256:a14446c32a3c8aeb9b4dac7fb73b03dfd8006e670d9b43ea7947e4030d3cbae6

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev nodejs \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" mysqli pdo_mysql gd zip intl \
 && rm -rf /var/lib/apt/lists/*

RUN { echo 'memory_limit = 1024M'; echo 'date.timezone = UTC'; echo 'allow_url_fopen = On'; } \
    > /usr/local/etc/php/conf.d/bench.ini

COPY --from=jsdom /opt/bench-node/node_modules /opt/bench-node/node_modules
ENV NODE_PATH=/opt/bench-node/node_modules

WORKDIR /src/tests
ENTRYPOINT ["php"]
