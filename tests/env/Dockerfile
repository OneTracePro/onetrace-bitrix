# PHP с Apache для тестового Битрикса: расширения, которые проверяет мастер установки.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/download/2.12.0/install-php-extensions /usr/local/bin/
RUN install-php-extensions gd mysqli opcache zip intl bcmath exif soap \
    && a2enmod rewrite \
    # .htaccess of Bitrix: urlrewrite.php handles missing files (/cdp-sw.js, SEF URLs).
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
COPY php.ini /usr/local/etc/php/conf.d/zz-bitrix.ini
