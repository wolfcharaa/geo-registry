FROM php:8.4-cli-alpine

ARG PECL_UV_VERSION=0.3.1

RUN apk add --no-cache curl git libpq libuv \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libuv-dev linux-headers postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql pgsql sockets \
    && pecl install "uv-${PECL_UV_VERSION}" \
    && docker-php-ext-enable --ini-name 40-uv.ini uv \
    && apk del .build-deps \
    && php -r "foreach (['pcntl', 'pdo_pgsql', 'pgsql', 'posix', 'sockets', 'uv', 'xmlreader'] as \$extension) { if (!extension_loaded(\$extension)) { fwrite(STDERR, \"Missing extension: {\$extension}\\n\"); exit(1); } }" \
    && printf '%s\n' 'zend.assertions=-1' > /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080

CMD ["bin/app"]
