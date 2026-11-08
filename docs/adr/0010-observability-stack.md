# ADR-0010. Наблюдаемость: Prometheus, Grafana, Loki и Promtail

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Категория:** Интеграция / observability
- **Связанные ADR:** [ADR-0003](0003-database-sqlite-mysql.md), [ADR-0006](0006-zero-composer-custom-libraries.md), [ADR-0011](0011-docker-caddy-nginx-phpfpm.md)

## Контекст

Production-стек должен отвечать на вопросы:

- доступно ли приложение и БД;
- сколько запросов, какие статусы, какая задержка;
- есть ли активные SOS, просроченные заявки, сколько волонтёров на модерации;
- что происходило в логах приложения, Nginx и контейнеров;
- когда закончилось место/наступил сбой.

Нельзя полагаться только на `docker logs` и `tail`: нужны история, дашборды и алерты. При этом проект сознательно избегает SaaS и тяжёлых агентов.

## Решение

Развёрнут self-hosted observability-стек в `docker-compose.prod.yml`:

| Компонент | Роль | Порт (только localhost) |
|---|---|---|
| **Prometheus 2.53** | Сбор метрик, rules, retention 15d | `127.0.0.1:9090` |
| **Grafana 10.4** | Дашборды, datasources, логи | `127.0.0.1:3000` |
| **Loki 2.9** | Хранилище логов, retention 168h (7d) | `127.0.0.1:3100` |
| **Promtail 2.9** | Сбор файлов и Docker stdout | внутри |
| **mysqld-exporter 0.15** | Метрики MySQL | внутри |
| **Caddy 2.8** | Edge-прокси, TLS, реальный IP | `80/443` |

### Метрики приложения

`app/Core/MetricsService.php` отдаёт `/api/metrics` в формате Prometheus:

- `app_info{version,php}`;
- `app_memory_usage_bytes`;
- `app_http_requests_total{route,method,status}`;
- `app_http_request_duration_seconds_sum/_count{route,method}`;
- `app_db_up`, `app_db_latency_seconds`;
- `app_help_requests{status}`;
- `app_sos_alerts{status}`;
- `app_volunteers{state}`;
- `app_users_total{role}`;
- `app_overdue_tasks`.

Счётчики HTTP агрегируются в `storage/metrics/http_counters.json` под `flock`; бизнес-gauges читаются из БД.

### Защита метрик

- `/api/metrics` требует `Authorization: Bearer $METRICS_TOKEN`;
- Nginx разрешает `/api/metrics` только с `127.0.0.1` и приватных сетей (`10/8`, `172.16/12`, `192.168/16`), остальным — `deny all`;
- Prometheus получает токен из `METRICS_TOKEN` через шаблон `prometheus.yml.template` (подстановка `sed` в entrypoint).

### Логи

- `storage/logs/app.log` — события и безопасность;
- `storage/logs/php-error.log` — ошибки PHP;
- `/var/log/nginx/access.log`, `error.log`;
- stdout контейнеров через Docker SD в Promtail;
- Loki хранит 7 дней (`limits_config.retention_period: 168h`).

### Дашборды и алерты

- Grafana provisioning: datasources `Prometheus` и `Loki`, дашборд `care-platform.json`.
- `monitoring/prometheus/alerts.yml`:
  - `CareAppDown` — `up{job="care-app"} == 0` 1 мин;
  - `CareDatabaseDown` — `app_db_up == 0` 1 мин;
  - `CareHigh5xx` — рост 5xx > 0.05/с за 5 мин;
  - `CareActiveSOS` — `app_sos_alerts{status="ACTIVE"} > 0` 1 мин;
  - `CareMysqlDown` — `mysql_up == 0` 1 мин.

## Обоснование

1. **Единый self-hosted контур.** Нет передачи метрик/логов в облако; данные остаются на сервере, что важно для ПДн.
2. **Prometheus — де-факто стандарт.** Pull-модель, простой текстовый формат, готовые экспортёры и Grafana.
3. **Метрики без отдельного агента.** Приложение само отдаёт `/api/metrics`; не нужен StatsD/OTel-коллектор.
4. **Loki дешевле ELK.** Индексируются только labels, а не полный текст; для небольшого объёма логов этого достаточно.
5. **Promtail уже умеет Docker SD.** Сбор stdout и файлов без сторонних агентов.
6. **Grafana provisioning.** Дашборды и datasources версионируются в репозитории, а не настраиваются руками.
7. **Алерты на бизнес-события.** `CareActiveSOS` и `app_overdue_tasks` важнее, чем просто CPU/RAM.

## Последствия

### Положительные

- Видимость доступности, ошибок, задержек и бизнес-метрик.
- История логов за 7 дней и метрик за 15 дней.
- Алерты на SOS и падение БД.
- Дашборд воспроизводится из кода.
- Метрики закрыты токеном и сетевыми ACL.

### Отрицательные / риски

- **Файловые счётчики.** `http_counters.json` может потеряться при пересоздании volume; метрики начнутся с нуля. Нет распределённого сбора.
- **Один узел.** Prometheus/Loki/Grafana не HA; падение узла = потеря наблюдаемости.
- **Нет Alertmanager.** Алерты видны в Prometheus/Grafana, но уведомления (email/Telegram) не настроены.
- **Нет tracing.** Нельзя проследить запрос через сервисы (впрочем, сервис один).
- **`/api/metrics` делает запросы к БД.** При частом scrape и большой БД это добавляет нагрузку; нужен кэш/лимит.
- **Логи могут содержать PII.** Нужна очистка/ротация и ограничение доступа к Grafana/Loki.
- **Grafana/Prometheus/Loki опубликованы на localhost.** Удалённый доступ требует SSH-туннеля или Caddy с авторизацией.
- **Retention фиксирован.** 7d/15d может не хватить для расследования инцидентов.

### Нейтральные

- Стек поднимается вместе с production-compose, а не отдельно.
- `mysqld-exporter` требует отдельного пользователя `exporter` (создаётся init-скриптом).

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **ELK/OpenSearch** | Тяжёлый, дорогой по RAM, избыточен для объёма логов проекта. |
| **Datadog/New Relic** | SaaS, стоимость, передача данных за периметр, вопросы ПДн. |
| **Только `docker logs`** | Нет истории, дашбордов и алертов. |
| **OpenTelemetry Collector** | Гибко, но добавляет компонент и сложность; текущих метрик достаточно. |
| **Zabbix** | Ориентирован на инфраструктуру, а не на бизнес-метрики приложения. |
| **Grafana Cloud** | Облако и передача данных; противоречит self-hosted подходу. |

## Ссылки

- `app/Core/MetricsService.php`
- `app/Controllers/MetricsController.php`
- `monitoring/prometheus/prometheus.yml.template`
- `monitoring/prometheus/alerts.yml`
- `monitoring/loki/loki-config.yml`
- `monitoring/promtail/promtail-config.yml`
- `monitoring/grafana/provisioning/*`
- `monitoring/grafana/dashboards/care-platform.json`
- `docker/nginx.conf` — ACL для `/api/metrics`.
- `monitoring/README.md`
