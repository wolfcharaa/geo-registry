FROM php:8.5.11-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pgsql \
    && apk del .build-deps \
    && apk add --no-cache libpq

WORKDIR /app
