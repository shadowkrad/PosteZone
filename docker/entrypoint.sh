#!/bin/sh
set -e

echo "[PosteZone] Initializing container environment..."

# Ensure data directory exists
mkdir -p /data /data/backups
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

# Credenziali area riservata (admin.html e API di scrittura)
ADMIN_USER="${ADMIN_USER:-admin}"
if [ -z "$ADMIN_PASSWORD" ]; then
    ADMIN_PASSWORD="$(head -c 12 /dev/urandom | base64 | tr -d '/+=' | head -c 14)"
    echo "[PosteZone] ADMIN_PASSWORD non impostata: generata una password temporanea."
    echo "[PosteZone] Utente: $ADMIN_USER  Password: $ADMIN_PASSWORD"
    echo "[PosteZone] Imposta ADMIN_PASSWORD (file .env) per renderla stabile."
fi
printf '%s:%s\n' "$ADMIN_USER" "$(openssl passwd -apr1 "$ADMIN_PASSWORD")" > /etc/nginx/.htpasswd
chmod 640 /etc/nginx/.htpasswd
chown root:nginx /etc/nginx/.htpasswd 2>/dev/null || true

# Backup giornaliero del database (03:30), conservati in /data/backups
BACKUP_KEEP="${BACKUP_KEEP:-14}"
echo "30 3 * * * DB_PATH=/data/postezone.db BACKUP_KEEP=$BACKUP_KEEP /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1" > /etc/crontabs/root
crond -b -l 8

echo "[PosteZone] Starting PHP-FPM and Nginx..."
php-fpm83 -D
exec nginx -g "daemon off;"
