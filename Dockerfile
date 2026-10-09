# syntax=docker/dockerfile:1
# Multi-stage build: frontend (Vite) -> PHP dependencies (Composer) -> runtime images.
#   docker compose up -d --build        (see docker-compose.yml)
# Targets: "app" (PHP 8.5-FPM, default) and "web" (nginx serving public/).

FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN mkdir -p ../public && npm run build

FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --ignore-platform-reqs

FROM php:8.5-fpm-alpine AS app
RUN apk add --no-cache gmp \
    && apk add --no-cache --virtual .build-deps gmp-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gmp bcmath \
    && (php -m | grep -qi '^Zend OPcache$' || docker-php-ext-install opcache) \
    && apk del .build-deps
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-bookspot.ini
WORKDIR /var/www/html
COPY --chown=www-data:www-data backend/ backend/
COPY --chown=www-data:www-data public/api/ public/api/
COPY --chown=www-data:www-data public/.htaccess public/.user.ini public/
COPY --from=vendor --chown=www-data:www-data /build/vendor/ vendor/
COPY --from=frontend --chown=www-data:www-data /build/public/ public/
RUN mkdir -p var && chown -R www-data:www-data var
VOLUME ["/var/www/html/var"]
EXPOSE 9000
CMD ["php-fpm"]

FROM nginx:1.29-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public/ /var/www/html/public/
