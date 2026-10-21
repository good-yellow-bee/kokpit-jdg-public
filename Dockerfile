FROM php:8.4.19-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev libxml2-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libcurl4-openssl-dev libonig-dev postgresql-client && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) pdo_pgsql intl bcmath soap zip gd curl mbstring opcache pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/kokpit.ini /usr/local/etc/php/conf.d/kokpit.ini
COPY docker/php/zz-kokpit-pool.conf /usr/local/etc/php-fpm.d/zz-kokpit-pool.conf
RUN git config --system --add safe.directory /app \
    && git config --system --add safe.directory /app/.git
WORKDIR /app

FROM base AS development
# Sterownik pokrycia dla Infection (D-104); obraz `production` go nie ma. Źródło: tag z GitHub z przypiętą sumą, bez pecl.php.net.
RUN curl -fsSL -o /tmp/pcov.tar.gz https://github.com/krakjoe/pcov/archive/refs/tags/v1.0.12.tar.gz \
    && echo "fdd07cad8e2ff42f0c9f095d84aeef11dab0fde7a008805f61883cbcb1b3f12b  /tmp/pcov.tar.gz" | sha256sum -c - \
    && mkdir /tmp/pcov && tar -xzf /tmp/pcov.tar.gz -C /tmp/pcov --strip-components=1 \
    && cd /tmp/pcov && phpize && ./configure && make -j$(nproc) && make install \
    && docker-php-ext-enable pcov && rm -rf /tmp/pcov /tmp/pcov.tar.gz
COPY . /app
RUN composer install --no-interaction --prefer-dist --no-scripts
CMD ["php-fpm"]

FROM base AS production
COPY . /app
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --classmap-authoritative \
    && APP_ENV=prod APP_DEBUG=0 VAULT_DIRECTORY=/run/kokpit/vault DB_HOST=localhost DB_NAME=kokpit DB_ROLE=kokpit_app DB_KOKPIT_APP_PASSWORD='' APP_SECRET='' bin/console asset-map:compile \
    && rm -rf var \
    && install -d -o 33 -g 33 -m 0750 var
CMD ["php-fpm"]

FROM nginx:1.28.2-alpine AS proxy
RUN apk add --no-cache iproute2 \
    && sed -i 's|^error_log .*;|error_log /dev/null;|' /etc/nginx/nginx.conf
COPY --from=production /app/public /app/public
COPY docker/proxy/start-proxy.sh /usr/local/bin/start-proxy
COPY docker/proxy/local.conf docker/proxy/prod.conf /kokpit/
RUN chmod 0755 /usr/local/bin/start-proxy
ENTRYPOINT ["/usr/local/bin/start-proxy"]
