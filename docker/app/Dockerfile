FROM php:8.3-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpng-dev libonig-dev libxml2-dev libzip-dev zip \
    build-essential autoconf \
    python3 python3-venv \
    nodejs npm \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip \
    && rm -rf /var/lib/apt/lists/*

RUN python3 -m venv /opt/yt \
    && /opt/yt/bin/pip install --no-cache-dir -U pip yt-dlp

ENV PATH="/opt/yt/bin:${PATH}"

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader || true
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

RUN chown -R www-data:www-data /var/www/html \
    && find storage -type d -exec chmod 775 {} \; \
    && find bootstrap/cache -type d -exec chmod 775 {} \;

EXPOSE 9000
CMD ["php-fpm"]
