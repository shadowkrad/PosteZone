#!/bin/sh
set -e

echo "[PosteZone] Initializing container environment..."

# Ensure data directory exists
mkdir -p /data
mkdir -p /var/www/html/data

# If /data/postezone.db doesn't exist yet, copy initial seed database
if [ ! -f /data/postezone.db ]; then
    if [ -f /var/www/html/data/postezone.db ]; then
        echo "[PosteZone] Initializing /data/postezone.db from pre-packaged seed..."
        cp /var/www/html/data/postezone.db /data/postezone.db
    else
        echo "[PosteZone] Creating empty database..."
        touch /data/postezone.db
    fi
fi

# Ensure permissions
chown -R www-data:www-data /data /var/www/html
chmod -R 775 /data

echo "[PosteZone] Starting PHP-FPM and Nginx..."
php-fpm83 -D
exec nginx -g "daemon off;"
