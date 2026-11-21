FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
 && printf 'upload_max_filesize=64M\npost_max_size=72M\nmax_execution_time=300\nmemory_limit=512M\n' > /usr/local/etc/php/conf.d/meibo.ini
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-openpyxl libreoffice-calc fonts-ipafont-gothic fonts-ipafont-mincho \
 && rm -rf /var/lib/apt/lists/* \
 && printf '%s\n' '<?xml version="1.0"?>' '<!DOCTYPE fontconfig SYSTEM "fonts.dtd">' '<fontconfig>' '<alias><family>游ゴシック</family><prefer><family>IPAGothic</family></prefer></alias>' '<alias><family>游明朝</family><prefer><family>IPAMincho</family></prefer></alias>' '</fontconfig>' > /etc/fonts/local.conf
COPY forms/ /opt/meibo/forms/
COPY web/ /var/www/html/
