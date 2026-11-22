# ADR-0003. MySQL 8 для dev, test и production, без ORM

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Обновлено:** 2026-11-21 — �������� ���� полностью удалён
- **Категория:** База данных
- **Связанные ADR:** [ADR-0004](0004-pdo-idempotent-migrations.md), [ADR-0010](0010-observability-stack.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Платформа хранит пользователей, профили подопечных и волонтёров, заявки, чат,
памятки, антифрод-оповещения, достижения, согласия, SOS-вызовы и коды сброса
пароля.

Требования:

- одинаковая СУБД во всех окружениях, чтобы dev/test не расходились с production;
- транзакции, внешние ключи, индексы и воспроизводимая схема;
- поддержка параллельных записей координаторов, волонтёров и фоновых проверок;
- бэкапы, мониторинг и восстановление стандартными инструментами MySQL;
- без ORM и миграционного фреймворка (см. ADR-0001, ADR-0006).

## Решение

**Единственная поддерживаемая СУБД — MySQL 8.0+ (InnoDB, utf8mb4).**
�������� ����-ветка удалена из `app/Core/Database.php`, Docker-образов, скриптов,
тестов и документации.

| Окружение | СУБД | Как включается |
|---|---|---|
| Локальная разработка | MySQL 8.0 (Docker-сервис `mysql`) | `DB_CONNECTION=mysql` + `DB_HOST/PORT/DATABASE/USERNAME/PASSWORD` |
| Тесты / CI | MySQL 8.0 (service-контейнер) | те же переменные, БД пересоздаётся `database/reset.php` |
| Production | MySQL 8.0 | те же переменные + том `senior_care_mysql` |

`app/Core/Database.php` — singleton-фабрика PDO:

- DSN `mysql:host=...;port=...;dbname=...;charset=utf8mb4`;
- `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`;
- любой другой `DB_CONNECTION` отклоняется с явной ошибкой;
- кэш соединения в статическом `$instance`, сброс через `resetConnection()`.

Схема одна: `database/schema.mysql.sql` (InnoDB, `utf8mb4_unicode_ci`,
именованные FK и индексы). ORM отсутствует: модели наследуют
`app/Core/Model.php` и работают через PDO prepared statements.

## Обоснование

1. **Одинаковая СУБД везде.** Тесты проверяют тот же диалект, те же FK и тот же
   движок, что и production.
2. **Production-надёжность InnoDB.** Транзакции, row-level locking, устойчивость
   к параллельным записям, привычные `mysqldump`, `mysqld-exporter` и restore.
3. **Явные внешние ключи и каскады.** `ON DELETE CASCADE` для профилей, согласий
   и SOS; `ON DELETE SET NULL` для `help_requests.volunteer_id`.
4. **Один PDO-слой.** Код моделей и сервисов не знает о драйвере, но больше не
   маскирует различия диалектов.
5. **Контроль над SQL.** Без ORM запросы прозрачны и легко индексируются.
6. **Воспроизводимые миграции.** `migrate.php` использует
   `INFORMATION_SCHEMA` и MySQL advisory lock, а не файловые схемы.

## Последствия

### Положительные

- Dev, test и production используют MySQL 8.
- CI прогоняет полную матрицу на MySQL service-контейнере.
- Production получает транзакции, бэкапы и метрики MySQL.
- Нет дрейфа между �������� ����- и MySQL-схемами.
- Нет N+1 и скрытых запросов ORM.

### Отрицательные / риски

- **Нужен MySQL-сервер для локального запуска.** Решается `docker compose up`.
- **MySQL сериализует конкурентные записи.** Возможны deadlock/lock-wait;
  критичные операции используют атомарные `UPDATE ... WHERE` и короткие
  транзакции.
- **Нет ORM-абстракции.** Смена СУБД потребует правок SQL.
- **PostgreSQL не поддерживается.** Это осознанное ограничение.

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **�������� ���� для dev/test** | Другой диалект, другой движок и другая конкурентная модель; тесты не проверяли production-путь. |
| **PostgreSQL** | Хороший кандидат, но требует нового DSN, schema-файла и переписывания миграций. |
| **ORM (Doctrine/Eloquent)** | Скрывает SQL, добавляет зависимости и магию; для аудита и небольших таблиц неоправдан. |
| **NoSQL (MongoDB)** | Плохо подходит для строгих связей (users → profiles → requests → consents) и отчётности. |
| **Файлы/JSON** | Не дают транзакций, индексов и целостности; неприемлемо для ПДн и SOS. |

## Ссылки

- `app/Core/Database.php` — MySQL-only PDO.
- `database/schema.mysql.sql` — схема.
- `database/migrate.php`, `database/reset.php`, `database/seed.php`.
- `docker-compose.yml` (MySQL dev), `docker-compose.prod.yml` (MySQL prod).
- `README.md` — раздел «СУБД».
- `plan/06_DATABASE_MIGRATIONS.md`.
