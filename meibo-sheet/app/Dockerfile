FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && printf 'upload_max_filesize=64M\npost_max_size=72M\nmax_execution_time=300\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/meibo.ini
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-pypdf python3-reportlab fonts-ipafont-gothic \
 && rm -rf /var/lib/apt/lists/*
COPY forms/ /opt/meibo/forms/
COPY web/ /var/www/html/
