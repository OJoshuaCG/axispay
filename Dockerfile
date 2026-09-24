# syntax=docker/dockerfile:1
#
# Production image (ADR-0035). One image, several roles, chosen by the command:
#
#   web        Nginx + PHP-FPM on port 8080 (default)
#   worker     php artisan queue:work (queues from QUEUE_NAMES)
#   scheduler  php artisan schedule:work (runs schedule:run every minute)
#   release    one-shot: migrate with the migrator user, seed the catalog, exit
#
# No .env is baked in: every setting comes from the container environment.
# Runtime caches (config, routes, views, events) are built at container start
# because they depend on that environment. See docs/deployment/dokploy.md.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22
ARG DEBIAN_RELEASE=trixie

# ---------------------------------------------------------------------------
# base: PHP-FPM with the extensions the application needs at runtime.
# `composer check-platform-reqs --no-dev` runs in the vendor stage, so a
# missing extension fails the build instead of a request in production.
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-${DEBIAN_RELEASE} AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# pdo_mysql: MariaDB. intl: Filament, number/date formatting. bcmath + gmp:
# fast paths for brick/math (money). zip: Filament exports. pcntl: graceful
# SIGTERM handling and job timeouts in queue:work. OPcache ships enabled.
RUN install-php-extensions pdo_mysql intl bcmath gmp zip pcntl \
    && apt-get update \
    && apt-get install --yes --no-install-recommends nginx tini procps \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default /usr/local/etc/php-fpm.d/*.conf \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/app/php.ini "$PHP_INI_DIR/conf.d/zz-paylink.ini"
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-paylink.conf
COPY docker/app/nginx.conf /etc/nginx/nginx.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# vendor: production Composer dependencies (no dev packages) and the
# Filament assets that `filament:upgrade` publishes into public/.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_NO_INTERACTION=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress \
    && composer check-platform-reqs --no-dev

COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
# package:discover writes bootstrap/cache/packages.php without dev providers;
# filament:assets publishes public/{css,js,fonts}/filament (gitignored).
RUN composer dump-autoload --no-dev --optimize \
    && php artisan package:discover --ansi \
    && php artisan filament:assets --ansi \
    && rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php

# ---------------------------------------------------------------------------
# assets: Vite build (Tailwind, self-hosted Jost via laravel-vite-plugin
# fonts + fontaine). pnpm is the project's package manager (pnpm-lock.yaml).
# The Filament theme imports vendor/filament CSS, so vendor is copied in.
# ---------------------------------------------------------------------------
FROM node:${NODE_VERSION}-${DEBIAN_RELEASE}-slim AS assets

WORKDIR /app
RUN corepack enable

COPY package.json pnpm-lock.yaml .npmrc ./
RUN pnpm install --frozen-lockfile

COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY --from=vendor /var/www/html/vendor ./vendor
RUN pnpm run build

# ---------------------------------------------------------------------------
# runtime
# ---------------------------------------------------------------------------
FROM base AS runtime

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="paylink" \
      org.opencontainers.image.version="${APP_VERSION}"

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER="Monolog\\Formatter\\JsonFormatter" \
    LOG_LEVEL=info

# Code is owned by root and read-only for the runtime user.
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY --chmod=0755 docker/app/entrypoint.sh /usr/local/bin/paylink-entrypoint
COPY --chmod=0755 docker/app/healthcheck.sh /usr/local/bin/paylink-healthcheck

# Only storage/ and bootstrap/cache are writable at runtime; the code is not.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
                storage/framework/views storage/logs storage/app/private storage/app/public \
                bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && test ! -e .env

USER www-data

EXPOSE 8080
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=3 \
    CMD ["paylink-healthcheck"]

ENTRYPOINT ["tini", "--", "paylink-entrypoint"]
# No default arguments: the role comes from CONTAINER_ROLE (default web).
CMD []
