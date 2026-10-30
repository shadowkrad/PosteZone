# Dockerfile for PosteZone Container
FROM alpine:3.20

# Install Nginx and PHP 8.3 with SQLite support
RUN apk add --no-cache \
    nginx \
    curl \
    openssl \
    sqlite \
    php83 \
    php83-fpm \
    php83-pdo \
    php83-pdo_sqlite \
    php83-sqlite3 \
    php83-curl \
    php83-gd \
    php83-json \
    php83-session \
    php83-openssl \
    php83-mbstring \
    php83-opcache \
    php83-ctype

# Link php83 binary to php
RUN ln -sf /usr/bin/php83 /usr/bin/php

# Configure PHP upload limits and memory
RUN echo "upload_max_filesize = 50M" > /etc/php83/conf.d/99_custom.ini && \
    echo "post_max_size = 50M" >> /etc/php83/conf.d/99_custom.ini && \
    echo "memory_limit = 256M" >> /etc/php83/conf.d/99_custom.ini && \
    echo "max_execution_time = 180" >> /etc/php83/conf.d/99_custom.ini

# Configure PHP-FPM to listen on 127.0.0.1:9000
RUN sed -i 's/listen = 127.0.0.1:9000/listen = 127.0.0.1:9000/' /etc/php83/php-fpm.d/www.conf && \
    sed -i 's/;listen.owner = nobody/listen.owner = www-data/' /etc/php83/php-fpm.d/www.conf && \
    sed -i 's/;listen.group = nobody/listen.group = www-data/' /etc/php83/php-fpm.d/www.conf && \
    sed -i 's/user = nobody/user = www-data/' /etc/php83/php-fpm.d/www.conf && \
    sed -i 's/group = nobody/group = www-data/' /etc/php83/php-fpm.d/www.conf

# Add www-data user if not exists
RUN id -u www-data >/dev/null 2>&1 || adduser -u 82 -D -S -G www-data www-data

# Copy Nginx configuration
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/php-fpm.conf /etc/nginx/php-fpm.conf

# Setup working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Copy and setup entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/backup.sh /usr/local/bin/backup.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/backup.sh

# Environment defaults
ENV DB_PATH=/data/postezone.db

# Expose HTTP port
EXPOSE 80

# Volume for persistent SQLite database
VOLUME ["/data"]

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
