FROM serversideup/php:8.4.5-fpm-nginx
ENV PHP_OPCACHE_ENABLE=1

# Switch to root to install dependencies
USER root

# Install GD dependencies
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Install Imagick — required by simplesoftwareio/simple-qrcode's PNG
# backend (bacon/bacon-qr-code's ImagickImageBackEnd). Without this,
# QRService::generateBase64() throws "You need to install the imagick
# extension to use this back end" on every /api/receive/qr call —
# hit this in production before it was added here.
RUN apt-get update && apt-get install -y libmagickwand-dev --no-install-recommends \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && rm -rf /var/lib/apt/lists/*


# Copy application files
COPY --chown=www-data:www-data . /var/www/html

# Switch back to www-data user
USER www-data

EXPOSE 8080

# Laravel needs these to exist before artisan runs (package:discover compiles
# views). Git doesn't track empty folders, so create them explicitly rather
# than relying on files that happen to be committed.
RUN mkdir -p storage/framework/views storage/framework/cache/data storage/framework/sessions storage/logs bootstrap/cache

# Install dependencies and optimize
RUN composer install --no-interaction --optimize-autoloader --no-dev

RUN php artisan storage:link
RUN php artisan optimize:clear
