# Operations Runbook

## One-click development

```bash
start.bat
```

или:

```bash
./start.sh
```

Что происходит:

1. Docker Compose запускает MySQL 8 и PHP-приложение.
2. `docker/dev-entrypoint.sh` ждёт MySQL.
3. Выполняются миграции и seed.
4. Приложение открывается на `http://localhost:8000`.

Остановка — `Ctrl+C`. Сброс dev-базы:

```bash
docker compose down -v
```

## Проверка состояния

```bash
curl http://localhost:8000/api/health
docker ps --filter name=senior_care
docker logs senior_care_platform --tail 100
docker logs senior_care_mysql_dev --tail 100
```

`/api/health` возвращает `200` при доступной БД и успешных проверках crypto/auth. `503` означает деградацию.

## MySQL development

```bash
docker compose exec mysql mysql -usenior -psenior_dev_password senior_care
```

Список таблиц:

```sql
SHOW TABLES;
```

Проверка заявок:

```sql
SELECT id, status, region_code, created_at FROM help_requests ORDER BY id DESC LIMIT 20;
```

## Production deployment

```bash
cp .env.prod.example .env.prod
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
```

Обязательные секреты:

- `APP_SECRET`
- `APP_ENCRYPTION_KEY`
- `ADMIN_PHONE`
- `ADMIN_PASSWORD`
- `METRICS_TOKEN`
- пароли MySQL
- пароль Grafana

`APP_ENCRYPTION_KEY` нельзя менять после начала работы: старые медицинские заметки станут нечитаемыми.

## Логи и мониторинг

- Приложение: `storage/logs/app.log`
- PHP: `storage/logs/php-error.log`
- Nginx: `docker exec senior_care_prod tail -f /var/log/nginx/access.log`
- Prometheus: `http://127.0.0.1:9090`
- Grafana: `http://127.0.0.1:3000`
- Loki: `http://127.0.0.1:3100`

## Бэкапы

```bash
docker exec senior_care_backup ls -la /backups
docker cp senior_care_backup:/backups/<file>.sql.gz ./backup.sql.gz
gzip -d backup.sql.gz
docker exec -i senior_care_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" senior_care < backup.sql
```

По умолчанию бэкапы создаются при старте и каждые 24 часа, хранятся 7 дней.

## Типовые проблемы

### Docker не запущен

Запустите Docker Desktop и повторите one-click.

### Порт 8000 занят

```bash
start.bat 8001
```

или:

```bash
./start.sh 8001
```

### MySQL не проходит healthcheck

```bash
docker compose down -v
docker compose up --build
```

Если проблема повторяется, проверьте свободное место и логи `senior_care_mysql_dev`.

### Приложение отвечает 503

Проверьте `/api/health`, логи приложения и доступность MySQL. Убедитесь, что заданы `APP_SECRET` и `APP_ENCRYPTION_KEY` для production.

### Тесты падают после предыдущего прогона

Тесты меняют БД и rate-limit. Перезапустите на чистом томе:

```bash
docker compose down -v
docker compose up -d --build
```
