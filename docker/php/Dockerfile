FROM php:8.4-cli-alpine

RUN apk add --no-cache curl git libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql pgsql \
    && apk del .build-deps \
    && php -r "exit(extension_loaded('xmlreader') ? 0 : 1);" \
    && printf '%s\n' 'zend.assertions=-1' > /usr/local/etc/php/conf.d/zz-production.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

EXPOSE 8080

CMD ["php", "bin/server.php"]
