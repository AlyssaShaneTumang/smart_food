
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

# Check Apache configuration again when starting
RUN printf '#!/bin/sh\nset -e\napache2ctl -t\nexec apache2-foreground\n' \
    > /usr/local/bin/start-app \
    && chmod +x /usr/local/bin/start-app

EXPOSE 80

CMD ["start-app"]
