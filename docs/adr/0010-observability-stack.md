# ADR-0010. Prometheus, Grafana, Loki, Promtail и healthcheck

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Observability
- **Связанные ADR:** [ADR-0003](0003-database-sqlite-mysql.md), [ADR-0011](0011-docker-caddy-nginx-phpfpm.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Production-стек должен показывать доступность приложения и MySQL, HTTP-ошибки, задержки, активные SOS, просроченные заявки и состояние волонтёров. Нужны история логов, дашборды и алерты без SaaS и тяжёлого агента.

## Решение

В `docker-compose.prod.yml` включены:

| Компонент | Роль |
|---|---|
| Prometheus 2.53 | Сбор метрик и алертов, retention 15 дней |
| Grafana 10.4 | Дашборды и просмотр логов |
| Loki 2.9 | Хранилище логов, retention 7 дней |
| Promtail 2.9 | Сбор файлов и Docker stdout |
| mysqld-exporter | Метрики MySQL |

### Метрики приложения

`/api/metrics` отдаёт Prometheus-формат:

- `app_http_requests_total{route,method,status}`;
- `app_http_request_duration_seconds_sum/_count`;
- `app_db_up`, `app_db_latency_seconds`;
- `app_help_requests{status}`, `app_sos_alerts{status}`;
- `app_volunteers{state}`, `app_users_total{role}`;
- `app_overdue_tasks`, `app_memory_usage_bytes`, `app_info`.

HTTP-счётчики агрегируются в `storage/metrics/http_counters.json` под `flock`; бизнес-gauges читаются из БД.

### Healthcheck

`/api/health` проверяет:

- подключение к БД и задержку;
- доступность каталога `database/`;
- AES roundtrip;
- создание и проверку probe-токена.

Ответ `200` — `ok`, `503` — `degraded`.

### Защита

- `/api/metrics` требует `Authorization: Bearer $METRICS_TOKEN`.
- Nginx разрешает метрики только с localhost и приватных сетей.
- Prometheus, Loki и Grafana опубликованы только на `127.0.0.1`.

## Обоснование

1. Self-hosted контур не передаёт ПДн в облако.
2. Pull-модель Prometheus проста и стандартна.
3. Приложение само отдаёт метрики, отдельный агент не нужен.
4. Loki дешевле ELK при малом объёме логов.
5. Grafana provisioning версионирует дашборды в репозитории.
6. Алерты привязаны к бизнес-событиям, а не только к CPU/RAM.

## Последствия

### Положительные

- Видимость доступности, ошибок и бизнес-метрик.
- История логов и метрик.
- Автоматические алерты на SOS и падение БД.
- Воспроизводимый дашборд.

### Отрицательные / риски

- Один узел без HA.
- Файловые счётчики теряются при пересоздании тома.
- Нет Alertmanager и внешних уведомлений.
- `/api/metrics` делает запросы к БД.
- Логи могут содержать PII.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| ELK/OpenSearch | Тяжёлый и дорогой стек |
| Datadog/New Relic | SaaS, стоимость и передача данных |
| Только `docker logs` | Нет истории и алертов |
| Zabbix | Ориентирован на инфраструктуру, а не домен |

## Ссылки

- `app/Core/MetricsService.php`
- `app/Controllers/HealthController.php`, `app/Controllers/MetricsController.php`
- `monitoring/*`
- `docker/nginx.conf`
