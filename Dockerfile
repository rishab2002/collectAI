FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        oniguruma-dev \
        # The Fandom wiki dump the catalog importer reads is .7z, and there is
        # no usable pure-PHP 7-Zip reader. Streaming the archive through this
        # binary is what keeps the import one 16 MB download instead of 11,041
        # requests against someone else's site.
        p7zip \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-interaction --no-progress

COPY . .

# .dockerignore keeps the host's storage artifacts out of the image, so the
# runtime directories Laravel expects (compiled views, cache, sessions, logs)
# have to be recreated here. Paths are listed individually because RUN uses
# BusyBox ash, which has no brace expansion.
RUN mkdir -p \
        storage/framework/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        bootstrap/cache \
    && chmod -R ug+w storage bootstrap/cache

RUN composer dump-autoload --optimize

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
