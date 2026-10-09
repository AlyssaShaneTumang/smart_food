
FROM php:8.3-apache

# Install required system libraries
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

# Enable Apache rewrite
RUN a2enmod rewrite

# Copy website files
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

# Apache port
EXPOSE 80
