FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev unzip git ffmpeg \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN printf 'upload_max_filesize=20M\npost_max_size=21M\n' > /usr/local/etc/php/conf.d/voice.ini
WORKDIR /app
