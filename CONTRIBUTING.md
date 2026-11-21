# Contributing

## Требования

- PHP 8.2+ для ручного запуска и тестов.
- Docker Desktop для one-click MySQL-стека.
- Node.js нужен только для проверок ADR/emoji и regex.

## Локальный запуск

```bash
start.bat
```

или:

```bash
./start.sh
```

Скрипт поднимает MySQL и приложение через Docker Compose, выполняет миграции и seed, затем открывает `http://localhost:8000`.

Для ручного SQLite-режима:

```bash
php database/migrate.php
php database/seed.php
php -S 127.0.0.1:8000 router.php
```

## Тесты

```bash
docker compose up -d --build
docker exec senior_care_platform php tests/run_tests.php
docker exec senior_care_platform php tests/test_frontend_wcag.php
docker exec senior_care_platform php tests/test_api_requests.php
docker exec senior_care_platform php tests/test_e2e_comprehensive.php
node tests/test_adr_and_emoji.js
node tests/test_v8_regex.js
```

Тесты меняют БД и rate-limit. Для повторного чистого прогона используйте `docker compose down -v`.

## Правила изменений

- Одна ветка — одна логическая доработка.
- Коммиты в формате Conventional Commits: `feat:`, `fix:`, `docs:`, `test:`, `chore:`.
- Не добавляйте Composer/npm-зависимости без отдельного ADR.
- Не коммитьте `.env`, `storage/`, дампы и реальные персональные данные.
- При изменении поведения обновляйте README и ADR.
- Для новых архитектурных решений добавляйте ADR в `docs/adr/`.
- Для UI-изменений проверяйте мобильную ширину, клавиатурную навигацию и senior-mode.

## Pull request

В PR укажите:

- что изменено и зачем;
- как проверить;
- затронутые роли и маршруты;
- результаты тестов;
- риски или миграционные шаги.

Перед пушем выполните:

```bash
php -l <изменённые php-файлы>
node --check <изменённые js-файлы>
git diff --check
```
