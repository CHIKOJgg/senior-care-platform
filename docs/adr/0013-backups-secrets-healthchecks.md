# ADR-0013. Бэкапы MySQL, секреты окружения и healthcheck

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Категория:** Эксплуатация
- **Связанные ADR:** [ADR-0007](0007-aes256gcm-and-data-masking.md), [ADR-0010](0010-observability-stack.md), [ADR-0011](0011-docker-caddy-nginx-phpfpm.md)

## Контекст

Production хранит заявки, SOS-историю, согласия и профили. Потеря БД или ключа шифрования недопустима. Нужно:

- регулярно делать резервные копии и уметь восстановиться;
- не коммитить секреты в репозиторий;
- падать при старте, если критичный секрет не задан;
- проверять живость не только процесса, но и БД, шифрования и auth;
- иметь предсказуемую процедуру восстановления.

## Решение

### Секреты

- `.env.example` — локальные значения (SQLite, dev-секреты).
- `.env.prod.example` — шаблон для production; значения-заглушки, которые нужно заменить.
- `docker-compose.prod.yml` использует обязательные переменные с `:?`:
  - `APP_SECRET` — HMAC-подпись токенов;
  - `APP_ENCRYPTION_KEY` — AES-256-GCM;
  - `ADMIN_PHONE`, `ADMIN_PASSWORD`;
  - `METRICS_TOKEN`;
  - `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `MYSQL_EXPORTER_PASSWORD`;
  - `GRAFANA_ADMIN_PASSWORD`.
- Если переменная не задана, compose падает до запуска — fail-fast.
- `APP_ENCRYPTION_KEY` нельзя менять после начала работы: старые заметки станут нечитаемыми.
- В production `AuthService` и `CryptoService` выбрасывают исключение при отсутствии секретов.

### Бэкапы

Отдельный контейнер `backup` (`mysql:8.0`) c `monitoring/mysql/backup.sh`:

```sh
mysqldump -h mysql -u root --single-transaction --routines --triggers "$MYSQL_DATABASE" > "$file"
gzip -f "$file"
find /backups -type f -name '*.sql.gz' -mtime +"${BACKUP_RETENTION_DAYS:-7}" -delete
sleep 86400
```

- запуск при старте и каждые 24 часа;
- дамп в том `senior_care_backups`;
- retention 7 дней по умолчанию (`BACKUP_RETENTION_DAYS`);
- `--single-transaction` — консистентный снимок без блокировки InnoDB;
- `--routines --triggers` — сохраняются процедуры/триггеры.

Восстановление (из README):

```bash
docker cp senior_care_backup:/backups/<file>.sql.gz ./backup.sql.gz
gzip -d backup.sql.gz
docker exec -i senior_care_mysql mysql -uroot -p"$MYSQL_ROOT_PASSWORD" senior_care < backup.sql
```

### Healthcheck

`GET /api/health` (`HealthController`):

- БД: `SELECT 1`, задержка в мс;
- storage: `is_writable(database/)`;
- crypto: encrypt→decrypt roundtrip;
- auth: create→verify probe-токен;
- статус `ok` (200) или `degraded` (503);
- Docker healthcheck вызывает его каждые 30 с (timeout 5 с, retries 3, start_period 15 с).

### MySQL exporter

`monitoring/mysql/init/01-exporter.sh` создаёт пользователя `exporter` с правами `PROCESS, REPLICATION CLIENT, SELECT` — минимально необходимые для `mysqld-exporter`.

## Обоснование

1. **Fail-fast.** Обязательные переменные не дают случайно запустить production с dev-секретом.
2. **Разделение ключей.** `APP_SECRET` (подпись) и `APP_ENCRYPTION_KEY` (шифрование) независимы; компрометация одного не раскрывает другое.
3. **Ключ вне дампа.** Бэкап БД не содержит ключ шифрования, поэтому утечка дампа не раскрывает медицинские заметки.
4. **Ежедневный дамп + retention.** Простое и предсказуемое RPO ≤ 24 ч; старые копии автоматически удаляются.
5. **`--single-transaction`.** Не блокирует работу приложения и даёт консистентный снимок.
6. **Глубокий healthcheck.** Проверяет не только «процесс жив», но и БД, крипто и auth — то есть реальную работоспособность.
7. **Минимальные права exporter.** Принцип наименьших привилегий.

## Последствия

### Положительные

- Автоматические бэкапы без ручных cron-задач на хосте.
- Восстановление описано и воспроизводимо.
- Production не стартует без секретов.
- Healthcheck ловит деградацию крипто/БД.
- Exporter не имеет лишних прав.

### Отрицательные / риски

- **Бэкапы не шифруются.** `.sql.gz` лежит в Docker volume; при доступе к хосту данные (кроме `mobility_notes`) читаемы. Нужно шифрование бэкапов/внешнее хранилище.
- **Бэкапы на том же хосте.** Потеря хоста = потеря и БД, и бэкапов; нужна off-site копия.
- **Нет проверки восстановления.** Бэкап не тестируется автоматически; можно обнаружить битость только при инциденте.
- **Retention 7 дней.** Для долгосрочного аудита/разумительного RPO может не хватить.
- **`mysqldump` под root.** Лучше отдельный backup-пользователь с минимальными правами.
- **Нет шифрования секретов в env.** `docker inspect` покажет значения; нужен Docker secrets/Vault/SOPS.
- **Healthcheck дергает БД и крипто.** При высокой частоте это добавляет нагрузку; сейчас 30 с — приемлемо.
- **Нет автоматического алерта на failed backup.** Prometheus не отслеживает возраст последнего дампа.
- **`APP_ENCRYPTION_KEY` не ротируется.** Смена ключа — ручная операция с перешифровкой.

### Нейтральные

- `backup` использует тот же образ `mysql:8.0`, что и БД.
- Восстановление выполняется вручную по документированной процедуре.

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **Ручной `mysqldump` по cron на хосте** | Зависит от хоста, легко забыть, нет изоляции. |
| **MySQL Enterprise Backup** | Платно, избыточно для объёма. |
| **Облачный managed backup** | Передача данных за периметр, стоимость, вопросы ПДн. |
| **Репликация + failover** | Сложнее, требует второго узла; для текущего масштаба избыточно. |
| **Vault/SOPS для секретов** | Рекомендуемое усиление, но добавляет компонент/процесс; сейчас env + compose `:?`. |
| **Без healthcheck** | Docker не сможет отличить «жив» от «работает корректно». |

## Ссылки

- `.env.example`, `.env.prod.example`
- `docker-compose.prod.yml` — `:?`-переменные, healthcheck, backup.
- `monitoring/mysql/backup.sh`
- `monitoring/mysql/init/01-exporter.sh`
- `app/Controllers/HealthController.php`
- `app/Core/Database.php`
- `app/Services/CryptoService.php`, `app/Services/AuthService.php`
- `README.md` — «Production Docker», «Восстановление из бэкапа».
