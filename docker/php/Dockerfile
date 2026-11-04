FROM php:8.4-fpm-bookworm

ARG LOCAL_UID=1000
ARG LOCAL_GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libonig-dev libxml2-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pcntl mbstring dom xml xmlwriter \
    && groupmod -o -g "$LOCAL_GID" www-data \
    && usermod -o -u "$LOCAL_UID" -g www-data www-data \
    && mkdir -p /var/www/html/storage/framework/cache/data /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views /var/www/html/storage/logs /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV XDG_CONFIG_HOME=/tmp/.config \
    COMPOSER_HOME=/tmp/composer
WORKDIR /var/www/html
USER www-data

CMD ["php-fpm"]
