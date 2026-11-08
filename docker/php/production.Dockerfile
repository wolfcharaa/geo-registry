# syntax=docker/dockerfile:1.7

ARG PHP_RUNTIME_IMAGE=php:8.4-cli-alpine@sha256:b58f9a66fe9734250fc42818edc77d679a071a83cefdcda87b21b668a91094b1
ARG COMPOSER_IMAGE=composer:2@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0

FROM ${COMPOSER_IMAGE} AS composer

FROM ${PHP_RUNTIME_IMAGE} AS php-runtime

ARG PECL_UV_VERSION=0.3.1

RUN apk add --no-cache curl libpq libuv unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libuv-dev linux-headers postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" opcache pcntl pdo_pgsql pgsql sockets \
    && pecl install "uv-${PECL_UV_VERSION}" \
    && docker-php-ext-enable --ini-name 40-uv.ini uv \
    && apk del .build-deps \
    && php -r "foreach (['pcntl', 'pdo_pgsql', 'pgsql', 'posix', 'sockets', 'uv', 'xmlreader'] as \$extension) { if (!extension_loaded(\$extension)) { fwrite(STDERR, \"Missing extension: {\$extension}\\n\"); exit(1); } }" \
    && printf '%s\n' \
        'expose_php=0' \
        'memory_limit=512M' \
        'opcache.enable=1' \
        'opcache.enable_cli=1' \
        'opcache.validate_timestamps=0' \
        'zend.assertions=-1' \
        > /usr/local/etc/php/conf.d/zz-production.ini

FROM php-runtime AS vendor

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache unzip

WORKDIR /app

COPY app/composer.json app/composer.lock ./
COPY app/src ./src

RUN composer install \
    --classmap-authoritative \
    --no-ansi \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist

FROM php-runtime AS production

ARG RELEASE_VERSION=dev
ARG VCS_REF=unknown
ARG MESSAGE_BUS_CORE_REF=unknown
ARG MESSAGE_BUS_WORKER_REF=unknown

LABEL org.opencontainers.image.title="Geo Registry" \
    org.opencontainers.image.version="${RELEASE_VERSION}" \
    org.opencontainers.image.revision="${VCS_REF}" \
    org.geo-registry.message-bus-core.revision="${MESSAGE_BUS_CORE_REF}" \
    org.geo-registry.message-bus-worker.revision="${MESSAGE_BUS_WORKER_REF}"

RUN addgroup -g 10001 app \
    && adduser -D -H -u 10001 -G app app \
    && mkdir -p /app/var \
    && chown app:app /app/var

WORKDIR /app

COPY --chown=app:app --from=vendor /app/vendor ./vendor
COPY --chown=app:app app/bin ./bin
COPY --chown=app:app app/config ./config
COPY --chown=app:app app/src ./src
COPY --chown=app:app app/composer.json app/composer.lock ./

USER app

EXPOSE 8080

CMD ["bin/app"]
