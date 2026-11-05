#!/bin/sh
set -e

mkdir -p /backups

while true; do
    ts=$(date +%Y%m%d-%H%M%S)
    file="/backups/${MYSQL_DATABASE}-${ts}.sql"
    echo "[BACKUP] Starting ${file}"

    if MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump \
        -h mysql -u root \
        --single-transaction --routines --triggers \
        "$MYSQL_DATABASE" > "$file"; then
        gzip -f "$file"
        echo "[BACKUP] Done: ${file}.gz"
    else
        echo "[BACKUP] Failed"
        rm -f "$file"
    fi

    find /backups -type f -name '*.sql.gz' -mtime +"${BACKUP_RETENTION_DAYS:-7}" -delete
    sleep 86400
done
