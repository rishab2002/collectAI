# Two runtimes are built from this file and they share nothing on purpose.
#
#   `dev`  — php artisan serve, dev dependencies, source bind-mounted over the
#            image by docker-compose.yml. Unchanged from what it always was.
#   `prod` — nginx + php-fpm under supervisord, no dev dependencies, assets
#            compiled in, source baked into the image.
#
# `prod` is deliberately the LAST stage, so a bare `docker build .` produces the
# production image. docker-compose.yml therefore has to name `target: dev`
# explicitly — without it the development stack would silently start building
# production.

# ---------------------------------------------------------------------------
# dev
# ---------------------------------------------------------------------------

FROM php:8.4-cli-alpine AS dev

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

# ---------------------------------------------------------------------------
# prod-base — the PHP runtime both the vendor stage and the final image share
# ---------------------------------------------------------------------------

FROM php:8.4-fpm-alpine AS prod-base

RUN apk add --no-cache \
        postgresql-dev \
        postgresql-client \
        libzip-dev \
        oniguruma-dev \
        # Same reason as the dev stage: DumpPageSource shells out to `7z`.
        # Not a stray dependency — do not drop it when slimming this image.
        p7zip \
        nginx \
        supervisor \
        # The entrypoint generates a self-signed certificate when no origin
        # certificate is mounted, so the container boots for local testing.
        openssl \
        $PHPIZE_DEPS \
    # opcache and pcntl are the two the dev stage has no use for. opcache is
    # the single biggest production win available here; pcntl is what lets
    # queue:work enforce a per-job timeout and shut down gracefully on deploy
    # rather than being killed mid-vision-call.
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath zip opcache pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor — production dependencies only
# ---------------------------------------------------------------------------
#
# Built on prod-base rather than the stock composer image so the PHP version and
# the installed extensions resolving platform requirements are the ones that
# will actually run the code. `--no-scripts` because package:discover needs the
# application source, which arrives in the prod stage.

FROM prod-base AS vendor

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --no-progress \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# assets — compiled CSS/JS
# ---------------------------------------------------------------------------
#
# Not optional: the layout calls @vite(['resources/css/app.css',
# 'resources/js/app.js']), so without public/build every page 500s on a missing
# manifest.
#
# vendor/ is copied in because resources/css/app.css declares
#   @source '../../vendor/laravel/framework/.../Pagination/resources/views/*.blade.php'
# and Tailwind cannot emit the paginator's classes from a path that isn't there.
# /admin/proposals is paginated, so this is a real screen, not a hypothetical.
#
# This stage needs network beyond npm: vite.config.js uses the plugin's
# bunny('Instrument Sans') helper, which fetches the font files at build time.

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor

RUN npm run build

# ---------------------------------------------------------------------------
# prod
# ---------------------------------------------------------------------------

FROM prod-base AS prod

COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/app.conf /etc/nginx/app.conf
COPY docker/nginx/cloudflare-ips.conf /etc/nginx/cloudflare-ips.conf
COPY docker/nginx/cloudflare-allow.conf /etc/nginx/cloudflare-allow.conf
COPY docker/nginx/origin-mode.conf /etc/nginx/origin-mode.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN mkdir -p \
        storage/framework/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/logs \
        bootstrap/cache \
        /run/nginx \
    && chown -R www-data:www-data storage bootstrap/cache

# Runs post-autoload-dump, i.e. `artisan package:discover`, which needs the
# source copied above. No .env exists at build time and none is needed —
# package discovery does not read configuration.
RUN composer dump-autoload --optimize --no-dev

# Parse the nginx configuration at build time, so a typo fails the build instead
# of crash-looping a container. Without this a bad directive costs a full deploy
# to discover: nginx exits, supervisord retries ten times, gives up, and the only
# symptom compose reports is "container is unhealthy" after ~213 seconds — with
# php-fpm running happily the whole time, which points the investigation at the
# wrong process.
#
# A throwaway certificate is needed because nginx.conf includes ssl.conf, which
# the entrypoint only writes at boot once it knows whether a real origin
# certificate was mounted. All three files are removed again so nothing here can
# ever be mistaken for a usable key.
RUN openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
        -keyout /tmp/build-check.key \
        -out /tmp/build-check.pem \
        -subj "/CN=build-check" >/dev/null 2>&1 \
    && printf 'ssl_certificate /tmp/build-check.pem;\nssl_certificate_key /tmp/build-check.key;\n' \
        > /etc/nginx/ssl.conf \
    && nginx -t \
    && rm -f /tmp/build-check.key /tmp/build-check.pem /etc/nginx/ssl.conf

EXPOSE 80 443

ENTRYPOINT ["entrypoint"]
CMD ["web"]
