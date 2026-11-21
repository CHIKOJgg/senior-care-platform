#!/bin/sh
set -e

echo "[STARTUP] Initializing Senior Care Platform..."

mkdir -p /var/www/html/database /var/www/html/storage/data /var/www/html/storage/logs /var/www/html/storage/cache /var/www/html/storage/metrics /run/nginx /var/log/nginx
chown -R www-data:www-data /var/www/html/database /var/www/html/storage

if [ -f /usr/local/etc/php-fpm.d/www.conf ]; then
    sed -i 's|^[;[:space:]]*listen[[:space:]]*=.*|listen = 127.0.0.1:9000|' /usr/local/etc/php-fpm.d/www.conf
    if grep -qE '^[;[:space:]]*clear_env' /usr/local/etc/php-fpm.d/www.conf; then
        sed -i 's|^[;[:space:]]*clear_env[[:space:]]*=.*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf
    else
        echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf
    fi
fi

php /var/www/html/database/migrate.php
php /var/www/html/database/seed.php

chown -R www-data:www-data /var/www/html/database /var/www/html/storage
find /var/www/html/database /var/www/html/storage -type d -exec chmod 775 {} \;
find /var/www/html/database /var/www/html/storage -type f -exec chmod 664 {} \;

if [ "${USE_NGINX:-1}" = "1" ]; then
    echo "[STARTUP] Testing Nginx config..."
    nginx -t
    echo "[STARTUP] Starting PHP-FPM and Nginx..."
    php-fpm -D
    exec nginx -g "daemon off;"
fi

exec "$@"
