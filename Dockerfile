FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql pdo_mysql gd zip \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

RUN mkdir -p /tmp/php_sessions \
    && chown -R www-data:www-data /tmp/php_sessions \
    && chmod 1733 /tmp/php_sessions

RUN { \
        echo "ServerName localhost"; \
        echo "<Directory /var/www/html>"; \
        echo "    AllowOverride All"; \
        echo "    Require all granted"; \
        echo "</Directory>"; \
    } > /etc/apache2/conf-available/app-security.conf \
    && a2enconf app-security

RUN { \
        echo "display_errors = Off"; \
        echo "log_errors = On"; \
        echo "error_log = /dev/stderr"; \
        echo "session.save_path = /tmp/php_sessions"; \
        echo "upload_max_filesize = 16M"; \
        echo "post_max_size = 16M"; \
        echo "date.timezone = Asia/Jakarta"; \
    } > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html
COPY . /var/www/html

EXPOSE 80

CMD ["sh", "-c", "PORT=${PORT:-80}; sed -ri \"s/^Listen [0-9]+/Listen ${PORT}/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]

