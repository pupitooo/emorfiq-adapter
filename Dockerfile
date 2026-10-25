FROM php:8.5-cli-alpine AS base

RUN apk add --no-cache supercronic libpq \
    && apk add --no-cache --virtual .php-build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .php-build-deps
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

FROM base AS runtime
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY app /app/app
COPY bin /app/bin
COPY config /app/config
COPY tools/log-viewer /app/tools/log-viewer
COPY docker/crontab /app/docker/crontab
RUN mkdir -p storage/logs && chown -R www-data:www-data storage
USER www-data
CMD ["php", "-S", "0.0.0.0:8080", "-t", "tools/log-viewer/public", "tools/log-viewer/public/router.php"]

FROM base AS tools
RUN composer install --no-scripts --prefer-dist --no-interaction
COPY . .
CMD ["composer", "check"]
