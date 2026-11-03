FROM php:8.5-apache

ARG UID=1000
ARG GID=1000

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# WP-CLI nightly: the 2.12 stable release is not PHP 8.5 clean yet (deprecation notices)
RUN apt-get update \
 && apt-get install -y --no-install-recommends less mariadb-client msmtp-mta unzip \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions bcmath exif gd imagick intl mysqli pdo_mysql zip xdebug \
 && curl -fsSL -o /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli-nightly.phar \
 && chmod +x /usr/local/bin/wp \
 && a2enmod rewrite headers expires \
 && sed -i 's/^Listen 80$/Listen 8180/' /etc/apache2/ports.conf \
 && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8180>/' /etc/apache2/sites-available/000-default.conf \
 && groupmod -o -g ${GID} www-data \
 && usermod -o -u ${UID} -g ${GID} www-data \
 && mkdir -p /var/www/.wp-cli && chown www-data:www-data /var/www/.wp-cli

COPY php.ini /usr/local/etc/php/conf.d/zz-local.ini
COPY msmtprc /etc/msmtprc

WORKDIR /var/www/html
