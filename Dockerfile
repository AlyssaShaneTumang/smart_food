
FROM php:8.3-apache

# Install required libraries
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libonig-dev \
        libcurl4-openssl-dev \
    && docker-php-ext-install \
        pdo_mysql \
        mysqli \
        mbstring \
        curl \
    && rm -rf /var/lib/apt/lists/*

# Ensure only prefork MPM is enabled
RUN a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork rewrite

# Copy PHP application
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
