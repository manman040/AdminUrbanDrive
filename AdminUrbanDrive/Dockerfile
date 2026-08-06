FROM php:8.2-apache

# Cloud Run injects PORT (defaults to 8080); point Apache at it.
ENV PORT=8080
RUN sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf \
    && sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite

COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 8080
