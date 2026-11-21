#!/bin/sh
set -e

mkdir -p /var/www/html/storage/logs \
         /var/www/html/storage/cache \
         /var/www/html/storage/metrics \
         /var/www/html/storage/data

if [ "${DB_CONNECTION:-mysql}" = "mysql" ]; then
    echo "[STARTUP] Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
    until mysqladmin ping \
        --ssl=0 \
        -h "${DB_HOST:-mysql}" \
        -P "${DB_PORT:-3306}" \
        -u "${DB_USERNAME:-senior}" \
        -p"${DB_PASSWORD:-}" \
        --silent 2>/dev/null; do
        sleep 2
    done
fi

php /var/www/html/database/migrate.php
php /var/www/html/database/seed.php

exec "$@"
