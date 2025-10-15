FROM php:7.4-apache

# Install required system packages and PHP extensions
RUN apt-get update && apt-get install -y \
    libxml2-dev \
    && docker-php-ext-install soap mysqli pdo_mysql

# Enable Apache rewrite module (optional, for .htaccess or routing)
RUN a2enmod rewrite

# Copy your project files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html/

# Expose the default Apache port
EXPOSE 80
