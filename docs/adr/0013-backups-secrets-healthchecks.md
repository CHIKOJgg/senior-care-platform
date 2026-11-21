# ADR-0013. Бэкапы MySQL, обязательные секреты и healthcheck

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Эксплуатация
- **Связанные ADR:** [ADR-0007](0007-aes256gcm-and-data-masking.md), [ADR-0010](0010-observability-stack.md), [ADR-0011](0011-docker-caddy-nginx-phpfpm.md)

## Контекст

Production хранит заявки, SOS, согласия и профили. Потеря БД или ключа шифрования недопустима. Нужны регулярные бэкапы, fail-fast при отсутствии секретов и проверка не только процесса, но и БД, криптографии и auth.

## Решение

### Секреты

`docker-compose.prod.yml` требует:

- `APP_SECRET` — подпись HMAC-токенов;
- `APP_ENCRYPTION_KEY` — AES-256-GCM;
- `ADMIN_PHONE`, `ADMIN_PASSWORD`;
- `METRICS_TOKEN`;
- `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `MYSQL_EXPORTER_PASSWORD`;
- `GRAFANA_ADMIN_PASSWORD`.

Обязательные переменные объявлены через `${VAR:?message}`. Без них стек не запускается. `APP_ENCRYPTION_KEY` нельзя менять после начала работы: старые медицинские заметки станут нечитаемыми.

### Бэкапы

Контейнер `backup` использует `mysqldump`:

- запуск при старте и каждые 24 часа;
- `--single-transaction --routines --triggers`;
- gzip-сжатие;
- том `senior_care_backups`;
- retention 7 дней по умолчанию (`BACKUP_RETENTION_DAYS`).

Восстановление:

```bash
docker cp senior_care_backup:/backups/<file>.sql.gz ./backup.sql.gz
gzip -d backup.sql.gz
docker exec -i senior_care_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" senior_care < backup.sql
```

### Healthcheck

`/api/health` проверяет:

- `SELECT 1` и задержку БД;
- доступность каталога `database/`;
- AES encrypt/decrypt roundtrip;
- создание и проверку HMAC-токена.

Ответ `200` — `ok`, `503` — `degraded`.

### MySQL exporter

`monitoring/mysql/init/01-exporter.sh` создаёт пользователя `exporter` с правами `PROCESS`, `REPLICATION CLIENT`, `SELECT`.

## Обоснование

1. Fail-fast защищает от запуска production с dev-секретами.
2. `APP_SECRET` и `APP_ENCRYPTION_KEY` разделены.
3. Ключ не попадает в дамп БД.
4. Ежедневный дамп даёт RPO не более 24 часов.
5. `--single-transaction` не блокирует InnoDB.
6. Глубокий healthcheck отражает реальную работоспособность.

## Последствия

### Положительные

- Автоматические бэкапы без cron на хосте.
- Production не стартует без секретов.
- Healthcheck ловит деградацию БД и криптографии.
- Exporter имеет минимальные права.

### Отрицательные / риски

- Бэкапы не шифруются и лежат на том же хосте.
- Нет автоматической проверки восстановления.
- Retention 7 дней может быть недостаточен.
- `mysqldump` выполняется под root.
- Секреты видны в `docker inspect`.
- Нет алерта на неудачный бэкап.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| Ручной `mysqldump` по cron | Зависит от хоста и легко забыть |
| MySQL Enterprise Backup | Платно и избыточно |
| Managed backup | Передача данных за периметр |
| Репликация + failover | Нужен второй узел |
| Vault/SOPS | Усиление на будущее, сейчас env + `:?` |

## Ссылки

- `.env.prod.example`
- `docker-compose.prod.yml`
- `monitoring/mysql/backup.sh`
- `monitoring/mysql/init/01-exporter.sh`
- `app/Controllers/HealthController.php`
