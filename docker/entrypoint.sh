#!/bin/sh
set -e

echo "[STARTUP] Initializing Senior Care Platform..."

# Ensure storage directories exist and are writable by PHP-FPM (www-data)
mkdir -p /var/www/html/database /var/www/html/storage/data /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/metrics /run/nginx /var/log/nginx
chown -R www-data:www-data /var/www/html/database /var/www/html/storage

# Ensure PHP-FPM listens on TCP 9000 for Nginx
if [ -f /usr/local/etc/php-fpm.d/www.conf ]; then
    sed -i 's|^[;[:space:]]*listen[[:space:]]*=.*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf
    # Allow PHP-FPM workers to inherit container environment variables (DB, APP_*, etc.)
    if grep -qE '^[;[:space:]]*clear_env' /usr/local/etc/php-fpm.d/www.conf; then
        sed -i 's|^[;[:space:]]*clear_env[[:space:]]*=.*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf
    else
        echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf
    fi
fi

# Run database migrations and seeders
php /var/www/html/database/migrate.php
php /var/www/html/database/seed.php

# Migrations run as root and may create the SQLite file; hand it back to PHP-FPM
chown -R www-data:www-data /var/www/html/database /var/www/html/storage
find /var/www/html/database /var/www/html/storage -type d -exec chmod 775 {} \;
find /var/www/html/database /var/www/html/storage -type f -exec chmod 664 {} \;

echo "[STARTUP] Testing Nginx config..."
nginx -t

echo "[STARTUP] Starting PHP-FPM..."
php-fpm -D
sleep 1
echo "[STARTUP] Processes before nginx:"
ps aux | grep -E 'nginx|php-fpm' | grep -v grep || true

echo "[STARTUP] Starting Nginx..."
nginx -g 'daemon off;'
echo "[STARTUP] Nginx exited with code $?"
