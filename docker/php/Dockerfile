FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache $PHPIZE_DEPS icu-dev linux-headers sqlite-dev libzip-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite bcmath opcache pcntl zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

FROM base AS dev
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS

ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} -S app 2>/dev/null || true \
    && adduser -u ${UID} -G app -S -h /home/app app 2>/dev/null || true \
    && mkdir -p /home/app && chown -R ${UID}:${GID} /home/app
USER app
ENV HOME=/home/app

FROM base AS prod
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader
COPY . .
RUN php artisan config:cache && php artisan route:cache
