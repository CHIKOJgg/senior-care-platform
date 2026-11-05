#!/bin/sh
set -e

echo "[STARTUP] Initializing Senior Care Platform..."

# Ensure storage directories exist and are writable by PHP-FPM (www-data)
mkdir -p /var/www/html/database /var/www/html/storage/data /var/www/html/storage/logs /var/www/html/storage/cache
chown -R www-data:www-data /var/www/html/database /var/www/html/storage

# Run database migrations and seeders
php /var/www/html/database/migrate.php
php /var/www/html/database/seed.php

# Migrations run as root and may create the SQLite file; hand it back to PHP-FPM
chown -R www-data:www-data /var/www/html/database /var/www/html/storage
find /var/www/html/database /var/www/html/storage -type d -exec chmod 775 {} \;
find /var/www/html/database /var/www/html/storage -type f -exec chmod 664 {} \;

echo "[STARTUP] Starting PHP-FPM and Nginx..."
php-fpm -D
exec nginx -g "daemon off;"
