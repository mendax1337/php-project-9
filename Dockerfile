FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y libzip-dev libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install zip pdo pdo_pgsql \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

WORKDIR /app

COPY . .

RUN composer install

CMD ["bash", "-c", "make start"]
