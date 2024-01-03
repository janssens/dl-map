FROM php:8.2-cli

RUN apt-get update && apt-get install -y libzip-dev zip curl libpng-dev libonig-dev libjpeg-dev libfreetype6-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) zip gd mbstring exif iconv

ENV PHP_MEMORY_LIMIT=-1