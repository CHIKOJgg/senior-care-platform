# ADR-0012. Собственный тест-харнесс и 5 уровней тестирования

- **Статус:** Принято
- **Дата:** 2026-11-08
- **Категория:** Тестирование
- **Связанные ADR:** [ADR-0001](0001-php82-custom-mvc.md), [ADR-0006](0006-zero-composer-custom-libraries.md)

## Контекст

Платформа критична: ошибка в геозоне, секретном слове, шифровании или SOS может привести к реальному вреду. Нужно проверять:

- бизнес-логику (заявки, принятие, завершение, watchdog);
- безопасность (RBAC, токены, брутфорс, маскирование, обезличивание);
- криптографию (AES-GCM, HMAC-бейджи, QR);
- границы (координаты, 150 м, 5 попыток, TTL);
- доступность (WCAG, ARIA, размеры, контраст);
- интеграции (МСИ, ЕСИА, МЧС).

При этом в проекте нет Composer/PHPUnit (ADR-0006), а тесты должны запускаться одной командой на чистом PHP.

## Решение

Используется **собственный тест-харнесс** и пятиуровневая пирамида:

```text
tests/
├── run_tests.php                  # unit + integration + security (32+)
├── test_api_requests.php          # HTTP/REST API
├── test_frontend_wcag.php         # доступность (55+)
├── test_e2e_comprehensive.php     # сквозные сценарии (257)
├── test_badge.php, test_privacy.php, test_sos_geofence.php, ...
├── e2e_suite/
│   ├── TestHarness.php
│   ├── run_e2e.php
│   ├── Tier1_FeatureCoverageTest.php
│   ├── Tier2_BoundaryCornerTest.php
│   ├── Tier3_CrossFeatureTest.php
│   └── Tier4_RealWorldScenariosTest.php
├── stress_m1_crypto.php
├── test_adversarial_*.php         # Tier 5
├── test_challenger_*.php
└── adversarial_privacy_test.php
```

### Уровни

| Tier | Что проверяет | Примеры |
|---|---|---|
| **T1 Feature Coverage** | 24 фичи × 5 тестов: API-контракты, схема, крипто, маскирование, Haversine, шаблоны | `Tier1_FeatureCoverageTest.php` |
| **T2 Boundary & Corner** | 0/149.9/150/150.1 м, антиподы, ±90/±180, кавычки/«ё», 5-я попытка кода, rate limit | `Tier2_BoundaryCornerTest.php` |
| **T3 Cross-Feature** | заявка → маскирование → принятие → демаскирование → GPS-закрытие → карма → QR; отзыв бейджа; право на забвение | `Tier3_CrossFeatureTest.php` |
| **T4 Real-World** | 10 пользовательских историй (доставка, лекарства + watchdog, SOS, регистрация волонтёра, забвение, антифрод, race condition, брутфорс, геозона, чат) | `Tier4_RealWorldScenariosTest.php` |
| **T5 Adversarial** | white-box атаки, крипто-стресс, подделка бейджей, геозона, WCAG-инварианты | `test_adversarial_*.php`, `stress_m1_crypto.php` |

### Запуск

```bash
php database/migrate.php
php database/seed.php
php tests/run_tests.php
php tests/test_api_requests.php
php tests/test_frontend_wcag.php
php tests/test_e2e_comprehensive.php
```

В CI (`.github/workflows/ci.yml`) дополнительно:

- `php -l` для всех PHP-файлов;
- MySQL service-контейнер и smoke-тест миграций/логина;
- сборка `Dockerfile.prod` и проверка `/api/health`.

## Обоснование

1. **Нет зависимости от PHPUnit.** Тесты запускаются на чистом PHP, как и приложение; не нужен `composer install` (ADR-0006).
2. **Прямой доступ к внутренним классам.** Можно проверять `CryptoService`, `GeoMatchingService`, `BadgeService`, `PrivacyService` без моков и фреймворка.
3. **Пирамида покрывает риски.** T1 — контракты, T2 — границы, T3 — интеграции, T4 — реальные сценарии, T5 — атаки.
4. **Проверка доступности в коде.** `test_frontend_wcag.php` ищет ARIA, размеры, отсутствие эмодзи, контрастные переменные — это дешевле, чем ручной аудит.
5. **CI проверяет production-путь.** Отдельный job собирает Docker-образ и дергает healthcheck, а не только unit-тесты.
6. **Детерминированность.** Тестовые личности, координаты и время передаются явно; это снижает flakiness.

## Последствия

### Положительные

- Полный контроль над ассертами и выводом.
- Быстрый запуск без установки зависимостей.
- Покрытие безопасности, доступности и бизнес-логики.
- CI ловит регрессии в HTTP, MySQL и Docker.
- Тесты служат документацией поведения.

### Отрицательные / риски

- **Собственный harness нужно поддерживать.** Нет готовых assertions, data providers, mocking, parallel run, coverage.
- **Много интеграционных тестов.** Они медленнее unit и могут зависеть от состояния БД/файлов.
- **Нет измерения coverage.** Неизвестно, какие ветки не покрыты.
- **Тесты связаны с реализацией.** При рефакторинге придётся править и тесты.
- **E2E-тесты запускают встроенный сервер.** Возможны гонки портов/процессов в CI.
- **T5-тесты частично дублируют T2/T3.** Это увеличивает время прогона.
- **Нет fuzzing/мутационного тестирования.** Крипто и QR всё ещё могут содержать скрытые дефекты.

### Нейтральные

- Тесты лежат в `tests/` и не входят в production-образ (кроме `COPY .`, см. ADR-0011).
- Часть тестов — «challenger»/adversarial, они документируют попытки взлома.

## Рассмотренные альтернативы

| Альтернатива | Почему отклонена |
|---|---|
| **PHPUnit** | Composer-зависимость, конфиг, больше церемоний; противоречит zero-deps. |
| **Pest** | Строится на PHPUnit и Composer; избыточен. |
| **Codeception** | Тяжёлый, много абстракций; для текущего объёма не нужен. |
| **Playwright/Cypress** | Требуют Node.js и браузеров; проект проверяет UI статически и через API. |
| **Только ручное тестирование** | Не масштабируется и не защищает от регрессий в критичных сценариях. |
| **Только unit-тесты** | Не ловят проблемы интеграции, БД, Docker и доступности. |

## Ссылки

- `tests/run_tests.php`
- `tests/test_api_requests.php`
- `tests/test_frontend_wcag.php`
- `tests/test_e2e_comprehensive.php`
- `tests/e2e_suite/*`
- `tests/test_adversarial_*.php`
- `.github/workflows/ci.yml`
- `TEST_INFRA.md`, `TEST_READY.md`
- `plan/08_TESTING_AND_AUDIT.md`
- `README.md` — раздел «Запуск автоматических тестов».
