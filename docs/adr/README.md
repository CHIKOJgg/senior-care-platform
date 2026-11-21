# Architecture Decision Records

Архитектурные решения платформы **«ЗаботаРядом»**. Каждый документ фиксирует контекст, выбранное решение, причины выбора, последствия и рассмотренные альтернативы.

## Реестр решений

| № | Решение | Категория | Статус |
|---|---|---|---|
| [ADR-0001](0001-php82-custom-mvc.md) | PHP 8.2 и собственное MVC-ядро без фреймворка | Backend | Принято |
| [ADR-0002](0002-vanilla-js-frontend-wcag.md) | Vanilla JS и CSS без сборки, приоритет WCAG 2.1 AAA | Frontend | Принято |
| [ADR-0003](0003-database-sqlite-mysql.md) | SQLite для unit/CI, MySQL для one-click dev и production | Данные | Принято |
| [ADR-0004](0004-pdo-idempotent-migrations.md) | PDO и идемпотентные миграции на чистом PHP | Данные | Принято |
| [ADR-0005](0005-hmac-auth-and-rate-limiting.md) | Stateless HMAC-токены, HttpOnly-cookie, `token_version` и rate limiting | Безопасность | Принято |
| [ADR-0006](0006-zero-composer-custom-libraries.md) | Нулевые Composer-зависимости и собственные компоненты | Зависимости | Принято |
| [ADR-0007](0007-aes256gcm-and-data-masking.md) | AES-256-GCM, маскирование ПДн, согласия и право на забвение | Приватность | Принято |
| [ADR-0008](0008-identity-provider-adapters.md) | Адаптеры верификации МСИ РБ и ЕСИА | Интеграции | Принято |
| [ADR-0009](0009-emergency-112-adapter.md) | Адаптер МЧС 112 и устойчивая очередь SOS | Интеграции | Принято |
| [ADR-0010](0010-observability-stack.md) | Prometheus, Grafana, Loki, Promtail и healthcheck | Observability | Принято |
| [ADR-0011](0011-docker-caddy-nginx-phpfpm.md) | One-click MySQL-стек и production-контейнеры Caddy/Nginx/PHP-FPM | Инфраструктура | Принято |
| [ADR-0012](0012-custom-test-harness.md) | Собственный тест-харнесс, CI и MySQL-smoke | Тестирование | Принято |
| [ADR-0013](0013-backups-secrets-healthchecks.md) | Бэкапы MySQL, обязательные секреты и healthcheck | Эксплуатация | Принято |
| [ADR-0014](0014-web-speech-and-audio-api.md) | Web Speech API и Web Audio API для доступности | Frontend | Принято |
| [ADR-0015](0015-anti-fraud-keyword-ai-fallback.md) | Keyword-антифрод с необязательным OpenRouter AI и fallback | Безопасность | Принято |

## Стек в одном месте

| Слой | Решение |
|---|---|
| Backend | PHP 8.2+, `declare(strict_types=1)`, MVC и сервисный слой |
| Frontend | HTML5, CSS3 custom properties, vanilla JavaScript, Web Speech API |
| Хранилище | SQLite 3 для unit/CI, MySQL 8 для one-click dev и production |
| Доступ к данным | PDO, prepared statements, миграции `database/migrate.php` |
| Аутентификация | HMAC-SHA256-токены, HttpOnly-cookie, `token_version` |
| Шифрование | AES-256-GCM через OpenSSL |
| Верификация | Адаптеры МСИ РБ и ЕСИА |
| Экстренные вызовы | Адаптер МЧС 112 с offline queue |
| Наблюдаемость | `/api/health`, `/api/metrics`, Prometheus, Grafana, Loki, Promtail |
| Запуск | `start.bat`, `start.command`, `start.sh` → Docker Compose с MySQL |
| Тестирование | PHP-харнесс, E2E, adversarial/challenger, Node-проверки, CI |

## Режимы запуска

| Режим | База | Как запускается |
|---|---|---|
| One-click | MySQL 8 | `start.bat`, `start.command`, `start.sh` или `docker compose up --build` |
| Локальная разработка | SQLite 3 | `php database/migrate.php`, `php database/seed.php`, `php -S 127.0.0.1:8000 router.php` |
| Production | MySQL 8 | `docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build` |
| CI | SQLite + MySQL-smoke | `.github/workflows/ci.yml` |

## Формат нового ADR

```markdown
# ADR-XXXX. Краткое название

- **Статус:** Предложено
- **Дата:** YYYY-MM-DD
- **Категория:** Backend / Frontend / Данные / Безопасность / Инфраструктура
- **Связанные ADR:** —

## Контекст
## Решение
## Обоснование
## Последствия
## Альтернативы
## Ссылки
```
