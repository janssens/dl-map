FROM php:8.2-apache

RUN apt-get update && apt-get install -y libzip-dev zip curl libpng-dev libonig-dev libjpeg-dev libfreetype6-dev libsqlite3-dev sqlite3

RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j$(nproc) zip gd mbstring exif iconv pdo_sqlite sqlite3

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
