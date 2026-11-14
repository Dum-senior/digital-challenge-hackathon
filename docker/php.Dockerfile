FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev unzip git \
    && docker-php-ext-install pdo_mysql mbstring zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
