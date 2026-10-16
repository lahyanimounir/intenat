# syntax=docker/dockerfile:1
# Image PHP-FPM de l'API INTERNAT : sert aussi le worker de files et le planificateur.

FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip libpng libjpeg-turbo freetype fcgi \
    && apk add --no-cache --virtual .construction $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd opcache bcmath pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .construction

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-internat.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dépendances d'abord : la couche est réutilisée tant que composer.lock ne change pas.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && mkdir -p storage/app/prive storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
