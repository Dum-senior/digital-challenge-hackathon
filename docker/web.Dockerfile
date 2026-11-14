FROM php:8.3-apache-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev unzip ffmpeg \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY . .
COPY docker/dispatcher.conf /etc/apache2/sites-enabled/000-default.conf
RUN mkdir -p storage/attachments storage/logs public/uploads \
    && chown -R www-data:www-data storage public/uploads \
    && printf 'upload_max_filesize=20M\npost_max_size=21M\n' > /usr/local/etc/php/conf.d/voice.ini
