# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: dependencias PHP (composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-req=ext-mongodb
COPY . .
RUN composer dump-autoload --optimize

# ---------------------------------------------------------------------------
# Stage 2: assets del frontend servido por Blade (@vite en routes/web.php)
# ---------------------------------------------------------------------------
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: imagen final
# ---------------------------------------------------------------------------
FROM php:8.4-apache

# php:8.4-apache ya trae compiladas la mayoria de extensiones que composer.lock
# exige (json, tokenizer, ctype, filter, hash, mbstring, openssl, session,
# fileinfo, pcre, iconv). Solo faltan: pdo_mysql, bcmath, pcntl, opcache
# (ext nativas) y mongodb (requiere PECL + libssl-dev para compilar).
RUN apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && docker-php-ext-install pdo_mysql bcmath pcntl opcache \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && a2enmod rewrite \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && apt-get purge -y --auto-remove libssl-dev pkg-config \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=frontend /app/public/build /var/www/html/public/build

RUN chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
