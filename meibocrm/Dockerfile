FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && printf 'upload_max_filesize=8M\npost_max_size=10M\n' > /usr/local/etc/php/conf.d/crm.ini
COPY web/ /var/www/html/
