
FROM php:8.3-apache

# Install PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite
RUN a2enmod rewrite

# Copy PHP application
COPY . /var/www/html/

# Set file permissions
RUN chown -R www-data:www-data /var/www/html/

# Create startup script
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    'rm -f /etc/apache2/mods-enabled/mpm_event.load' \
    'rm -f /etc/apache2/mods-enabled/mpm_worker.load' \
    'rm -f /etc/apache2/mods-enabled/mpm_event.conf' \
    'rm -f /etc/apache2/mods-enabled/mpm_worker.conf' \
    'a2enmod mpm_prefork' \
    'sed -i -E "s/^[[:space:]]*Listen[[:space:]]+[0-9]+/Listen 80/" /etc/apache2/ports.conf' \
    'sed -i -E "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:80>/" /etc/apache2/sites-enabled/000-default.conf' \
    'apache2ctl -t' \
    'exec apache2-foreground' \
    > /usr/local/bin/start-app \
    && chmod +x /usr/local/bin/start-app

EXPOSE 80

CMD ["/usr/local/bin/start-app"]
