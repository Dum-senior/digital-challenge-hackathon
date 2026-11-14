FROM python:3.12-slim-bookworm AS python
FROM php:8.3-apache-bookworm AS web
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

FROM web AS inference
COPY --from=python /usr/local/bin/python* /usr/local/bin/
COPY --from=python /usr/local/lib/libpython* /usr/local/lib/
COPY --from=python /usr/local/lib/python3.12 /usr/local/lib/python3.12
RUN ldconfig && apt-get update && apt-get install -y --no-install-recommends tesseract-ocr tesseract-ocr-rus \
    && rm -rf /var/lib/apt/lists/* && useradd -r -m inference
WORKDIR /inference
COPY services/dispatcher-api/requirements.txt .
RUN python3 -m pip install --no-cache-dir -r requirements.txt
COPY --chown=inference:inference services/dispatcher-api/app.py .
COPY --chown=inference:inference services/dispatcher-api/kb ./kb
RUN chown inference:inference /inference
USER inference
CMD ["sh","-ec","python3 app.py && exec uvicorn app:app --host 0.0.0.0 --port 8092 --limit-concurrency 24"]
