FROM php:8.4-apache

RUN a2enmod rewrite && \
    sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf \
    && echo "<Directory /var/www/html/public>\n  AllowOverride All\n</Directory>" >> /etc/apache2/apache2.conf

RUN apt-get update && apt-get install -y \
    git curl zip unzip libfreetype6-dev libicu-dev \
    libjpeg62-turbo-dev libonig-dev libpng-dev libpq-dev libzip-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install exif gd intl mbstring pcntl pdo pdo_pgsql zip

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache
