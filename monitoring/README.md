# Мониторинг «ЗаботаРядом»

Стек поднимается вместе с `docker-compose.prod.yml`.

## Состав

| Компонент | Роль |
|---|---|
| Prometheus | Сбор метрик `/api/metrics`, MySQL-экспортёра и самого себя |
| Grafana | Дашборд «ЗаботаРядом — Production» и просмотр логов |
| Loki | Хранилище логов (7 дней) |
| Promtail | Сбор файлов приложения/Nginx и stdout контейнеров |
| mysqld-exporter | Метрики MySQL |

## Метрики приложения

`/api/metrics` отдаёт:

- `app_http_requests_total{route,method,status}` — счётчик запросов;
- `app_http_request_duration_seconds_sum/_count` — длительность запросов;
- `app_db_up`, `app_db_latency_seconds`;
- `app_help_requests{status}`, `app_sos_alerts{status}`;
- `app_volunteers{state}`, `app_users_total{role}`;
- `app_overdue_tasks`, `app_memory_usage_bytes`, `app_info`.

Эндпоинт защищён `METRICS_TOKEN` (Bearer) и доступен только с внутренних адресов.

## Логи

- `/var/www/html/storage/logs/app.log` — действия и события безопасности;
- `/var/www/html/storage/logs/php-error.log` — ошибки PHP;
- `/var/log/nginx/access.log`, `error.log` — HTTP-доступ;
- stdout контейнеров проекта — через Docker SD в Promtail.

В Grafana используйте запросы:

```logql
{job="care-app"}
{job="care-nginx"}
{job="care-app", level="ERROR"}
{container="senior_care_prod"}
```

## Проверка

```bash
curl -H "Authorization: Bearer $METRICS_TOKEN" http://localhost/api/metrics
curl http://localhost:9090/-/ready
curl http://localhost:3100/ready
curl http://localhost:3000/api/health
```

## Алерты

Правила лежат в `prometheus/alerts.yml`. Они видны в Prometheus → Alerts и на дашборде. Для отправки уведомлений подключите Alertmanager (не входит в базовый стек).
