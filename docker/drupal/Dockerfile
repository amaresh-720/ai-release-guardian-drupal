# Drupal 11 + PHP 8.3 + Apache + Drush.
# Friend-friendly: one image, no DDEV required, just `docker compose up -d`.

FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libonig-dev libxml2-dev libicu-dev libpq-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mysqli gd zip opcache intl bcmath mbstring xml \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Apache needs mod_rewrite for Drupal clean URLs.
RUN a2enmod rewrite headers expires

# Composer-managed Drupal puts the front controller in /web, so retarget Apache.
RUN sed -ri -e 's!/var/www/html!/var/www/html/web!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf

RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'max_execution_time=120'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=2'; \
    } > /usr/local/etc/php/conf.d/zz-drupal.ini

WORKDIR /var/www/html
EXPOSE 80
