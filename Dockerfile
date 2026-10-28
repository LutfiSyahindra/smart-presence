# syntax=docker/dockerfile:1

FROM php:8.3-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install --no-install-recommends -y \
        gosu libfreetype6-dev libicu-dev libjpeg62-turbo-dev libonig-dev \
        libpng-dev libwebp-dev libxml2-dev libzip-dev unzip aapt apksigner default-jre-headless \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl mbstring opcache pcntl pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

FROM php-base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress \
        --no-scripts --no-autoloader

COPY . .
RUN mkdir -p bootstrap/cache storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        storage/app/public storage/app/private \
    && composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && composer check-platform-reqs --no-dev \
    && php artisan package:discover --ansi

FROM node:22-bookworm-slim AS frontend

WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# app.ts imports Ziggy from Composer's vendor directory.
COPY --from=vendor /var/www/html/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
ARG VITE_APP_NAME="RSARSY Presence"
ENV VITE_APP_NAME=${VITE_APP_NAME}
RUN npm run build

FROM php-base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    XDG_CONFIG_HOME=/var/www/html/storage/app/private/.config \
    XDG_DATA_HOME=/var/www/html/storage/app/private/.local/share

COPY --from=vendor /var/www/html /var/www/html
COPY --from=frontend /build/public/build ./public/build
COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/laravel-entrypoint

RUN ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data
ENTRYPOINT ["laravel-entrypoint"]
CMD ["php-fpm", "-F"]

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
    CMD php -r '$socket = @fsockopen("127.0.0.1", 9000, $errno, $error, 3); exit($socket === false ? 1 : 0);'

FROM nginx:stable-alpine AS web

WORKDIR /var/www/html
COPY --from=app /var/www/html/public ./public
COPY docker/nginx/default.conf.template /etc/nginx/templates/default.conf.template
RUN rm /etc/nginx/conf.d/default.conf

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/up || exit 1
