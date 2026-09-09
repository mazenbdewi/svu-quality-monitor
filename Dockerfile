# syntax=docker/dockerfile:1.7

# --------------------------------------------------
# PHP runtime and Composer
# --------------------------------------------------
FROM mysql:8.4@sha256:da906917ca4ace3ba55538b7c2ee97a9bc865ef14a4b6920b021f0249d603f3d AS mysql-tools

FROM php:8.4-fpm-bookworm AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        bcmath \
        exif \
        pcntl \
        intl \
        zip \
        gd \
        opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer


# --------------------------------------------------
# Composer dependencies
# --------------------------------------------------
FROM php-base AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

COPY . .

RUN composer dump-autoload \
    --no-dev \
    --classmap-authoritative \
    --no-interaction \
    --no-scripts

RUN composer check-platform-reqs


# --------------------------------------------------
# Frontend assets: Vite / Filament / Tailwind
# --------------------------------------------------
FROM node:22-bookworm-slim AS frontend

WORKDIR /app

COPY package*.json ./

RUN if [ -f package-lock.json ]; then \
        npm ci; \
    else \
        npm install; \
    fi

COPY . .

RUN npm run build


# --------------------------------------------------
# Laravel PHP application
# --------------------------------------------------
FROM php-base AS app

RUN apt-get update && apt-get install -y --no-install-recommends libncurses6 && rm -rf /var/lib/apt/lists/*
COPY --from=mysql-tools /usr/bin/mysql /usr/local/bin/mysql
COPY --from=mysql-tools /usr/bin/mysqldump /usr/local/bin/mysqldump

WORKDIR /var/www/html

COPY --from=vendor --chown=www-data:www-data /app /var/www/html

COPY --from=frontend \
    --chown=www-data:www-data \
    /app/public/build \
    /var/www/html/public/build

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-application.ini
COPY docker/php/entrypoint.sh /usr/local/bin/application-entrypoint

RUN chmod +x /usr/local/bin/application-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Match published Filament assets to the locked PHP packages, not stale source assets.
RUN APP_ENV=production APP_DEBUG=false CACHE_STORE=array SESSION_DRIVER=array php artisan filament:assets

ENTRYPOINT ["application-entrypoint"]

CMD ["php-fpm"]


# --------------------------------------------------
# Nginx web server
# --------------------------------------------------
FROM nginx:1.28-alpine AS web

WORKDIR /var/www/html

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

COPY --from=app /var/www/html/public /var/www/html/public
