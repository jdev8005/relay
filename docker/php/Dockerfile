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

# Composer dependencies first, as their own layer. This layer only
# rebuilds when composer.json or composer.lock change, not on every
# code edit — which is most of the build time.
COPY app/composer.json app/composer.lock ./
RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction

COPY app/ ./

RUN composer dump-autoload --optimize --classmap-authoritative

# Production opcache. validate_timestamps=0 means PHP never stats files
# to check for changes — correct for an immutable image, and wrong for
# anything with a bind mount.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.preload_user=www-data'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# Directories tmpfs will mount over at runtime. They must exist and be
# writable in the image, or Laravel fails before the mount is visible.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
