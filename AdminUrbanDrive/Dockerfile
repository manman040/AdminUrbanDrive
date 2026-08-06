FROM php:8.2-apache

# Cloud Run injects PORT (defaults to 8080); point Apache at it.
ENV PORT=8080
RUN sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf \
    && sed -ri "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite

# Never let a stray PHP warning/notice get echoed into the response body --
# that breaks any later header()/redirect call ("headers already sent") and
# leaks internal paths. Still logged server-side via Apache's error log.
RUN { \
    echo "display_errors=Off"; \
    echo "log_errors=On"; \
    } > /usr/local/etc/php/conf.d/production-errors.ini

COPY . /var/www/html/

WORKDIR /var/www/html

EXPOSE 8080
