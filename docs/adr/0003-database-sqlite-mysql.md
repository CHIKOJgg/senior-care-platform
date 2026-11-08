# ADR-0003. SQLite 3 (dev/test) и MySQL 8 (production), без ORM

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Категория:** База данных
- **Связанные ADR:** [ADR-0004](0004-pdo-idempotent-migrations.md), [ADR-0010](0010-observability-stack.md), [ADR-0013](0013-backups-secrets-healthchecks.md)

## Контекст

Платформа хранит 12 сущностей: пользователей, профили подопечных и волонтёров, заявки, чат, памятки, антифрод-оповещения, достижения, согласия, SOS-вызовы и коды сброса пароля.

Требования:

- **Разработка и CI** должны запускаться без внешней СУБД: клонировал репозиторий — и всё работает.
- **Production** должен выдерживать параллельные запросы координаторов, волонтёров и фоновых проверок, а также поддерживать бэкапы и экспорт метрик.
- Нужны транзакции, внешние ключи, индексы и воспроизводимая схема.
- Нежелательно тащить ORM и миграционный фреймворк (см. ADR-0001, ADR-0006).

## Решение

Реализована **двойная поддержка СУБД через единый PDO-слой**:

| Окружение | СУБД | Как включается |
|---|---|---|
| Локальная разработка, тесты, CI | **SQLite 3** (файл `database/database.sqlite`) | `DB_CONNECTION=sqlite` (по умолчанию) |
| Production | **MySQL 8.0** (InnoDB, `utf8mb4`) | `DB_CONNECTION=mysql` + `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` |

`app/Core/Database.php` — singleton-фабрика PDO:

- SQLite: `PRAGMA foreign_keys = ON`, `journal_mode = WAL`, `busy_timeout = 5000`, `synchronous = NORMAL`;
- MySQL: DSN `mysql:host=...;port=...;dbname=...;charset=utf8mb4`, `ERRMODE_EXCEPTION`, `FETCH_ASSOC`;
- кэш соединения в статическом `$instance`, сброс через `resetConnection()`.

Схема продублирована в двух файлах:

- `database/schema.sql` — SQLite (`INTEGER PRIMARY KEY AUTOINCREMENT`, `REAL`, индексы `IF NOT EXISTS`);
- `database/schema.mysql.sql` — MySQL (`INT UNSIGNED AUTO_INCREMENT`, `DOUBLE`, `InnoDB`, `utf8mb4_unicode_ci`, именованные FK и индексы).

ORM отсутствует: модели наследуют `app/Core/Model.php` и работают через PDO prepared statements.

## Обоснование

1. **Нулевая настройка для разработки и CI.** SQLite — один файл, нет сервера, паролей и портов. Это резко упрощает `php -S`, Docker dev и GitHub Actions.
2. **Production-надёжность MySQL.** InnoDB даёт транзакции, row-level locking, устойчивость к параллельным записям и привычные инструменты бэкапа (`mysqldump`), мониторинга (`mysqld-exporter`) и восстановления.
3. **WAL для SQLite.** В dev/test WAL повышает параллелизм чтения и записи и снижает `database is locked`; `busy_timeout` дополнительно сглаживает конкуренцию.
4. **Явные внешние ключи и каскады.** `ON DELETE CASCADE` для профилей, согласий и SOS; `ON DELETE SET NULL` для `help_requests.volunteer_id` — заявка и история сохраняются при удалении волонтёра.
5. **Один PDO-слой.** Код моделей и сервисов не знает, какая СУБД используется; переключение — переменной окружения.
6. **Контроль над SQL.** Без ORM запросы прозрачны, их легко оптимизировать индексами и объяснить аудитору. Для 12 таблиц это практичнее, чем Eloquent/Doctrine.

## Последствия

### Положительные

- Быстрый onboarding: `php database/migrate.php && php database/seed.php && php -S ...`.
- CI прогоняет тесты на SQLite без service-контейнера; отдельный job проверяет MySQL.
- Production получает транзакции, бэкапы и метрики MySQL.
- Нет N+1 и скрытых запросов ORM.
- Легко читать и индексировать SQL.

### Отрицательные / риски

- **Диалекты расходятся.** Два schema-файла нужно синхронизировать вручную; `AUTOINCREMENT`, `REAL`/`DOUBLE`, `INTEGER`/`INT UNSIGNED`, `DATETIME` ведут себя по-разному.
- **SQLite сериализует записи.** При высокой конкуренции возможны блокировки; для production это не используется.
- **PostgreSQL не реализован.** `README.md` упоминает PostgreSQL, но `Database.php` поддерживает только `sqlite` и `mysql`. Это расхождение документации и кода; при необходимости PostgreSQL придётся добавлять отдельно.
- **Нет ORM-абстракции.** Смена СУБД потребует правок SQL, а не только конфигурации.
- **Нет автоматической проверки совпадения схем.** Дрейф между `schema.sql` и `schema.mysql.sql` ловится только тестами/ревью.

### Нейтральные

- SQLite-файл и MySQL-том живут в разных местах (`database/database.sqlite` и volume `senior_care_mysql`).
- Для production используется отдельный `docker-compose.prod.yml`, а dev — `docker-compose.yml`.

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **Только SQLite** | Просто, но слабая конкурентная запись, отсутствие сетевого доступа и привычных production-бэкапов/мониторинга. |
| **Только MySQL** | Усложняет локальный старт и CI; для тестов нужен service-контейнер. |
| **PostgreSQL** | Хороший кандидат, но в текущей реализации отсутствует; добавление потребует нового DSN и schema-файла. |
| **ORM (Doctrine/Eloquent)** | Скрывает SQL, добавляет зависимости и магию; для аудита и небольших таблиц неоправдан. |
| **NoSQL (MongoDB)** | Плохо подходит для строгих связей (users → profiles → requests → consents) и отчётности. |
| **Файлы/JSON** | Не дают транзакций, индексов и целостности; неприемлемо для ПДн и SOS. |

## Ссылки

- `app/Core/Database.php` — выбор драйвера и PRAGMA.
- `database/schema.sql` — SQLite-схема.
- `database/schema.mysql.sql` — MySQL-схема.
- `database/migrate.php`, `database/seed.php`.
- `docker-compose.yml` (SQLite dev), `docker-compose.prod.yml` (MySQL prod).
- `README.md` — раздел «СУБД».
- `plan/06_DATABASE_MIGRATIONS.md`.
