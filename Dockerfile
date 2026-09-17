FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_mysql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

RUN printf '%s\n' 'upload_max_filesize=50M' 'post_max_size=55M' 'max_file_uploads=20' > /usr/local/etc/php/conf.d/bigevent-uploads.ini

WORKDIR /var/www/html
