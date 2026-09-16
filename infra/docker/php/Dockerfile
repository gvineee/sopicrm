# Local-dev image for the Laravel app/worker/scheduler containers.
# NOT a production image (no opcache tuning, no multi-stage slim build) —
# that is a later Integration/DevOps concern once a hosting provider is chosen
# (docs/decisions.md § Open business-policy questions #6).
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libcurl4-openssl-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo \
        pdo_pgsql \
        pgsql \
        bcmath \
        zip \
        intl \
        gd \
        mbstring \
        curl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 8000

# Overridden per-service by infra/docker-compose.yml (app runs `artisan serve`,
# worker runs `queue:work`, scheduler loops `schedule:run`).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
