FROM php:8.4-cli-alpine

ARG PECL_EV_VERSION=1.2.3
ARG PECL_PQ_VERSION=2.2.3
ARG PECL_RAPHF_VERSION=2.0.2

RUN apk add --no-cache curl git libev libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libev-dev linux-headers postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql pgsql sockets \
    && pecl install "raphf-${PECL_RAPHF_VERSION}" \
    && docker-php-ext-enable --ini-name 20-raphf.ini raphf \
    && pecl install "pq-${PECL_PQ_VERSION}" \
    && docker-php-ext-enable --ini-name 30-pq.ini pq \
    && printf '\n' | pecl install "ev-${PECL_EV_VERSION}" \
    && docker-php-ext-enable --ini-name 40-ev.ini ev \
    && apk del .build-deps \
    && php -r "foreach (['ev', 'pcntl', 'pdo_pgsql', 'pgsql', 'posix', 'pq', 'raphf', 'sockets', 'xmlreader'] as \$extension) { if (!extension_loaded(\$extension)) { fwrite(STDERR, \"Missing extension: {\$extension}\\n\"); exit(1); } }" \
    && printf '%s\n' 'zend.assertions=-1' > /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080

CMD ["bin/app"]
