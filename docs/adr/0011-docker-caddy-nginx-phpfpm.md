# ADR-0011. One-click MySQL-стек и production-контейнеры Caddy/Nginx/PHP-FPM

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Инфраструктура
- **Связанные ADR:** [ADR-0003](0003-database-sqlite-mysql.md), [ADR-0010](0010-observability-stack.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Нужен предсказуемый запуск на Windows, macOS и Linux, отдельный production-стек с HTTPS и MySQL, а также запуск в один клик без установки PHP на хост. Демонстрационная среда должна максимально совпадать с production по СУБД.

## Решение

### One-click development

`start.bat`, `start.command` и `start.sh` вызывают `docker compose up --build`. В `docker-compose.yml` два сервиса:

- `app` — PHP 8.2 CLI с PDO MySQL/SQLite, встроенный сервер, порт `${APP_PORT:-8000}`;
- `mysql` — MySQL 8.0 с healthcheck и томом `senior_care_mysql_dev`.

`docker/dev-entrypoint.sh` ждёт MySQL, выполняет `migrate.php` и `seed.php`, затем запускает приложение. Браузер открывается после ответа `/api/health`.

### Production

`docker-compose.prod.yml` + `Dockerfile.prod`:

```text
Caddy 2.8 (edge, 80/443)
└── app: Nginx + PHP-FPM 8.2
    ├── MySQL 8.0
    ├── backup
    ├── mysqld-exporter
    ├── Prometheus
    ├── Loki + Promtail
    └── Grafana
```

Особенности:

- Caddy — единственный контейнер, опубликованный наружу.
- Nginx отдаёт статику, проксирует PHP на `127.0.0.1:9000` и добавляет security-заголовки.
- PHP-FPM работает с `display_errors=Off`, `log_errors=On`.
- Healthchecks контролируют приложение, MySQL, Prometheus, Loki и Grafana.
- Ресурсы ограничены через `deploy.resources`.
- Логи Docker ограничены 10 МБ × 3 файла.

## Обоснование

1. Docker убирает различия окружений.
2. MySQL в one-click исключает расхождение с production.
3. Caddy автоматически получает TLS-сертификаты.
4. Nginx + PHP-FPM — проверенная связка.
5. Внутренние сервисы не опубликованы наружу.
6. Браузер открывается только после готовности БД и приложения.

## Последствия

### Положительные

- Одинаковый запуск локально и на сервере.
- MySQL и приложение стартуют одной командой.
- HTTPS и security-заголовки из коробки.
- Бэкапы и мониторинг входят в production-стек.

### Отрицательные / риски

- Для one-click нужен Docker.
- Production-образ одностадийный и содержит лишние файлы.
- Caddy остаётся единой точкой отказа.
- Секреты передаются через переменные окружения.
- Нет Kubernetes и автоскейлинга.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| PHP на хосте | Разные окружения и ручная установка MySQL |
| Kubernetes | Избыточен для одного сервиса |
| Apache + mod_php | Менее удобен в контейнерах |
| Nginx как edge | Ручной Certbot вместо автоматического TLS Caddy |
| Traefik | Caddy проще для текущего масштаба |

## Ссылки

- `start.bat`, `start.command`, `start.sh`
- `docker-compose.yml`, `docker-compose.prod.yml`
- `Dockerfile`, `Dockerfile.prod`
- `docker/dev-entrypoint.sh`, `docker/entrypoint.sh`
- `docker/nginx.conf`, `docker/php.ini`
