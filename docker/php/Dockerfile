FROM php:8.4-cli-alpine

RUN apk add --no-cache curl git libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl pgsql \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080

CMD ["php", "bin/server.php"]
