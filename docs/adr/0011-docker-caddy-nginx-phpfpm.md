# ADR-0011. Docker, Caddy, Nginx + PHP-FPM

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Категория:** Инфраструктура
- **Связанные ADR:** [ADR-0003](0003-database-sqlite-mysql.md), [ADR-0010](0010-observability-stack.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Нужно обеспечить:

- одинаковый запуск на Windows/macOS/Linux и в CI;
- отдельные dev- и prod-конфигурации;
- HTTPS и единую точку входа в production;
- изоляцию БД и мониторинга от публичной сети;
- воспроизводимую сборку без установки PHP/Nginx на хост;
- ограничение ресурсов и ротацию логов.

При этом команда небольшая, а бюджет на инфраструктуру минимален.

## Решение

### Development

`docker-compose.yml` + `Dockerfile`:

- образ `php:8.2-cli-alpine`;
- `pdo`, `pdo_sqlite`;
- SQLite в `database/database.sqlite`;
- встроенный сервер `php -S 0.0.0.0:8000 router.php`;
- порт `8000`, volume `.:/var/www/html`;
- `APP_ENV=local`, `APP_DEBUG=true`.

### Production

`docker-compose.prod.yml` + `Dockerfile.prod`:

```text
Caddy 2.8 (edge, 80/443)
   └── app: Nginx + PHP-FPM 8.2 (php:8.2-fpm-alpine)
          ├── MySQL 8.0 (InnoDB, utf8mb4)
          ├── backup (mysqldump, 24h, retention 7d)
          ├── mysqld-exporter
          ├── Prometheus
          ├── Loki + Promtail
          └── Grafana
```

Особенности:

- **Caddy** — единственный контейнер, опубликованный наружу; автоматический HTTPS (Let's Encrypt) при `CADDY_DOMAIN=домен`; HSTS; reverse proxy на `app:80`.
- **Nginx** — `root /var/www/html/public`, `try_files` на `index.php`, FastCGI на `127.0.0.1:9000`, security headers (CSP, X-Frame-Options, Referrer-Policy, Permissions-Policy), gzip, кэш статики 1h, ACL для `/api/metrics`, запрет доступа к `database/`, `storage/`, `app/`.
- **PHP-FPM** — `display_errors=Off`, `log_errors=On`, `error_log` в `storage/logs/php-error.log`, `expose_php=Off`.
- **Entrypoint** — создаёт каталоги, запускает `migrate.php` и `seed.php`, выставляет права `www-data`, стартует PHP-FPM и Nginx.
- **Healthchecks** — `/api/health` для app, `mysqladmin ping` для MySQL, `/api/health` для Grafana, `/ready` для Prometheus/Loki.
- **Resource limits** — CPU/RAM для каждого сервиса (app 1 CPU/512M, MySQL 1 CPU/1G и т.д.).
- **Logging** — json-file, `max-size: 10m`, `max-file: 3`.
- **Volumes** — MySQL, логи, метрики, Prometheus, Loki, Grafana, Caddy, бэкапы.

## Обоснование

1. **Воспроизводимость.** Docker убирает «у меня работает» и фиксирует версии PHP/MySQL/Nginx.
2. **Parity dev/prod.** Один код, разные compose-файлы; production максимально близок к реальному.
3. **Caddy вместо ручного TLS.** Автоматические сертификаты, HTTP→HTTPS, HSTS, минимум конфигурации.
4. **Nginx + PHP-FPM — проверенная связка.** Высокая производительность, статические файлы отдаёт Nginx, PHP — FPM.
5. **Изоляция.** MySQL, Prometheus, Loki, Grafana не опубликованы наружу; metrics доступен только из приватных сетей.
6. **Единая точка входа.** Caddy упрощает TLS, логирование реального IP и будущее подключение WAF.
7. **Ограничение ресурсов.** Защищает хост от «съедания» памяти при пиках.
8. **Ротация логов.** Предотвращает переполнение диска.

## Последствия

### Положительные

- Одинаковый запуск локально, в CI и на сервере.
- HTTPS «из коробки» с Caddy.
- Безопасные заголовки и закрытые внутренние сервисы.
- Простое масштабирование ресурсов через `deploy.resources`.
- Бэкапы и мониторинг поднимаются вместе с приложением.
- Healthchecks дают автоматический перезапуск при сбое.

### Отрицательные / риски

- **`Dockerfile.prod` — одностадийный.** В образ копируется весь контекст (`COPY . /var/www/html`), включая тесты, docs, `.git` (если не исключён `.dockerignore`). Образ больше, чем мог бы быть при multi-stage.
- **`Dockerfile` dev запускает миграции на этапе сборки** (`RUN php database/migrate.php && php database/seed.php`), а не только при старте; это может создать БД внутри образа.
- **Нет оркестратора.** Один хост, нет автоскейлинга и self-healing уровня Kubernetes.
- **Caddy — single point of failure.** При падении edge недоступен весь сервис.
- **Секреты в env.** Переменные окружения видны в `docker inspect`; нужен secret manager для усиления.
- **Prometheus/Loki/Grafana на localhost.** Удалённый доступ требует туннеля или отдельной авторизации.
- **Nginx `set_real_ip_from` доверяет приватным сетям.** При неправильной сети можно подделать IP; Caddy должен быть единственным edge.
- **CSP допускает `unsafe-inline`.** Ослабляет защиту от XSS; требуется вынос inline-скриптов/стилей.
- **Нет WAF/rate limiting на edge.** Rate limiting реализован в приложении, а не на Caddy/Nginx.

### Нейтральные

- Порт приложения в dev — `8000`, в prod — только `80` внутри, наружу — Caddy.
- MySQL и monitoring используют bridge-сеть `backend`.

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **Bare-metal PHP/Nginx** | Сложнее воспроизводить, обновлять и изолировать; нет parity с CI. |
| **Kubernetes** | Избыточен для одного сервиса и небольшой команды; высокая операционная сложность. |
| **Docker Swarm** | Проще K8s, но всё ещё оркестратор; текущего compose достаточно. |
| **Apache + mod_php** | Менее производителен и менее распространён в контейнерах; Nginx+FPM стандартнее. |
| **Traefik вместо Caddy** | Хорош, но Caddy проще для автоматического TLS и минимального конфига. |
| **Nginx как edge** | Пришлось бы вручную настраивать Certbot/ACME; Caddy делает это автоматически. |
| **Multi-stage build** | Рекомендуется как улучшение; текущий образ проще, но больше. |

## Ссылки

- `docker-compose.yml`, `docker-compose.prod.yml`
- `Dockerfile`, `Dockerfile.prod`
- `docker/nginx.conf`, `docker/php.ini`, `docker/entrypoint.sh`
- `monitoring/caddy/Caddyfile`
- `.dockerignore`
- `README.md` — разделы запуска.
- `PROJECT.md` — «Environment».
