
FROM php:8.3-apache

# Install PHP extensions
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql mysqli mbstring curl \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite
RUN a2enmod rewrite

# Copy the entire PHP project
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
