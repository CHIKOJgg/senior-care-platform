# ADR-0012. Собственный тест-харнесс, CI и MySQL-smoke

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Тестирование
- **Связанные ADR:** [ADR-0001](0001-php82-custom-mvc.md), [ADR-0003](0003-database-sqlite-mysql.md), [ADR-0006](0006-zero-composer-custom-libraries.md)

## Контекст

Критические сценарии платформы требуют проверки бизнес-логики, безопасности, криптографии, геозоны, доступности и интеграций. Composer/PHPUnit не используются. Нужны быстрые локальные прогоны, E2E через HTTP и проверка MySQL в CI.

## Решение

Используется собственный PHP-харнесс и несколько уровней тестирования:

```text
tests/
├── run_tests.php                  32 unit/security проверки
├── test_frontend_wcag.php         57 WCAG/ADR проверок
├── test_api_requests.php          26 REST API проверок
├── test_e2e_comprehensive.php     257 E2E проверок
├── e2e_suite/
│   ├── TestHarness.php
│   ├── run_e2e.php
│   └── Tier1..Tier4
├── test_badge.php
├── test_privacy.php
├── test_sos_geofence.php
├── stress_m1_crypto.php
├── test_adversarial_*.php
├── test_challenger_*.php
└── test_adr_and_emoji.js
```

### Уровни E2E

| Tier | Проверки |
|---|---|
| T1 Feature Coverage | 24 функции × 5 проверок |
| T2 Boundary & Corner | геозона, коды, токены, маскирование, SOS, лимиты |
| T3 Cross-Feature | заявка → маскирование → принятие → GPS → бейдж → приватность |
| T4 Real-World | 10 пользовательских сценариев |
| T5 Adversarial | white-box атаки, крипто-стресс, подделка бейджей |

`run_e2e.php` поддерживает выбор tier, strict-режим, JSON-отчёт и verbose-вывод.

### Запуск

```bash
docker compose up -d --build
docker exec senior_care_platform php tests/run_tests.php
docker exec senior_care_platform php tests/test_frontend_wcag.php
docker exec senior_care_platform php tests/test_api_requests.php
docker exec senior_care_platform php tests/test_e2e_comprehensive.php
```

Для локального SQLite-прогона используются те же PHP-команды. Тесты меняют БД и rate-limit, поэтому повторный чистый прогон выполняется после `docker compose down -v`.

### CI

`.github/workflows/ci.yml` выполняет:

- `php -l` для всех PHP-файлов;
- миграции и seed на SQLite;
- unit/security, WCAG/ADR, REST API и E2E;
- Node-проверки ADR/emoji и regex;
- сборку production-образа и healthcheck;
- job `mysql-smoke`: MySQL 8, миграции, seed, health и логин.

## Обоснование

1. Нет зависимости от PHPUnit и Composer.
2. Прямой доступ к внутренним классам без моков.
3. Уровни покрывают контракты, границы, интеграции и атаки.
4. CI проверяет и SQLite-путь, и MySQL-путь.
5. Тесты документируют поведение системы.

## Последствия

### Положительные

- Быстрый запуск без установки зависимостей.
- Покрытие безопасности, доступности и бизнес-логики.
- Проверка production Docker-сборки.

### Отрицательные / риски

- Нет coverage, mocking и параллельного запуска.
- Часть тестов зависит от состояния БД.
- Adversarial/challenger-тесты запускаются вручную.
- E2E могут конфликтовать по портам при неправильном запуске.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| PHPUnit | Composer и лишняя церемония |
| Pest | Строится на PHPUnit |
| Codeception | Тяжёлый и избыточный |
| Playwright/Cypress | Нужен Node.js и браузеры |
| Только ручное тестирование | Не защищает от регрессий |

## Ссылки

- `tests/run_tests.php`
- `tests/e2e_suite/*`
- `.github/workflows/ci.yml`
- `README.md`
