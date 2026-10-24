FROM php:8.1-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev libcurl4-openssl-dev && docker-php-ext-install curl mbstring dom xml xmlwriter && pecl install pcov && docker-php-ext-enable pcov && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist
COPY . .
CMD ["composer", "test"]
