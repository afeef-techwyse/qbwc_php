FROM php:7.4-apache

# Install SOAP + MySQL extensions
RUN docker-php-ext-install soap mysqli

# Copy project files
COPY . /var/www/html/

# Expose default Apache port
EXPOSE 80