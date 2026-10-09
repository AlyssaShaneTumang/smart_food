
FROM php:8.3-apache

# Install required PHP dependencies
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring curl \
    && rm -rf /var/lib/apt/lists/*

# Ensure Apache loads only one MPM
RUN find /etc/apache2/mods-enabled/ \
        -maxdepth 1 -name 'mpm_*.load' -delete \
    && a2enmod mpm_prefork rewrite \
    && apache2ctl -t \
    && apache2ctl -M | grep mpm

# Copy application files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

# Enforce a single MPM again at container start (the runtime environment
# can end up with extra MPMs enabled even though the build check passed)
RUN printf '%s\n' \
        '#!/bin/sh' \
        'set -e' \
        'rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf' \
        'ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load' \
        'ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf' \
        'if [ -n "$PORT" ]; then' \
        '  sed -i "s/^Listen 80$/Listen $PORT/" /etc/apache2/ports.conf' \
        '  sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$PORT>/" /etc/apache2/sites-enabled/000-default.conf' \
        'fi' \
        'grep -rl "LoadModule mpm_" /etc/apache2 || true' \
        'apache2ctl -t' \
        'exec apache2-foreground' \
        > /usr/local/bin/start-app \
    && chmod +x /usr/local/bin/start-app

EXPOSE 80
RUN sed -i 's/^Listen 3306$/Listen 80/' /etc/apache2/ports.conf