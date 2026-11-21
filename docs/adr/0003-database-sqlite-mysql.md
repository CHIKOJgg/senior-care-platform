# ADR-0003. SQLite для unit/CI, MySQL для one-click dev и production

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Данные
- **Связанные ADR:** [ADR-0004](0004-pdo-idempotent-migrations.md), [ADR-0011](0011-docker-caddy-nginx-phpfpm.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Нужно одновременно обеспечить:

- быстрый локальный запуск без внешней СУБД для unit-тестов и CI;
- единый MySQL-стек для запуска в один клик и production;
- транзакции, внешние ключи, индексы и предсказуемые бэкапы;
- отсутствие ORM и миграционного фреймворка.

## Решение

Поддерживаются две СУБД через единый PDO-слой:

| Режим | СУБД | Подключение |
|---|---|---|
| Unit-тесты и CI | SQLite 3 | `DB_CONNECTION=sqlite`, файл `database/database.sqlite` |
| One-click dev | MySQL 8 | `docker compose`, `DB_HOST=mysql` |
| Production | MySQL 8 | `DB_CONNECTION=mysql`, параметры `DB_*` |

Особенности:

- SQLite: `foreign_keys=ON`, WAL, `busy_timeout=5000`, `synchronous=NORMAL`.
- MySQL: InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`.
- Схемы: `database/schema.sql` и `database/schema.mysql.sql`.
- Доступ к данным — PDO prepared statements, без ORM.
- One-click-скрипты и `docker-compose.yml` всегда поднимают MySQL-контейнер.

## Обоснование

1. SQLite не требует service-контейнера в unit-тестах и CI.
2. MySQL даёт конкурентную запись, привычные бэкапы и мониторинг.
3. PDO-абстракция позволяет переключать СУБД переменными окружения.
4. Явный SQL проще аудировать и оптимизировать.
5. One-click MySQL исключает расхождение между демонстрацией и production.

## Последствия

### Положительные

- Быстрый CI и локальные тесты.
- Реалистичный MySQL-стек для демонстрации и production.
- Прозрачные SQL-запросы и индексы.

### Отрицательные / риски

- Две схемы нужно синхронизировать вручную.
- SQLite сериализует записи и не подходит для production-нагрузки.
- PostgreSQL не поддерживается.
- Часть legacy-тестов, специфичных для SQLite, на MySQL пропускает WAL-проверки.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| Только SQLite | Слабая конкурентная запись и нереалистичный production |
| Только MySQL | Усложняет unit-тесты и CI |
| PostgreSQL | Потребует отдельной схемы и DSN; сейчас не поддерживается |
| ORM | Скрывает SQL и добавляет зависимости |

## Ссылки

- `app/Core/Database.php`
- `database/schema.sql`, `database/schema.mysql.sql`
- `docker-compose.yml`
- `docker/dev-entrypoint.sh`
