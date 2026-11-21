# ADR-0006. Нулевые Composer-зависимости и собственные компоненты

- **Статус:** Принято
- **Дата:** 2026-11-22
- **Категория:** Зависимости
- **Связанные ADR:** [ADR-0001](0001-php82-custom-mvc.md), [ADR-0005](0005-hmac-auth-and-rate-limiting.md), [ADR-0007](0007-aes256gcm-and-data-masking.md)

## Контекст

Проект должен запускаться на любом окружении с PHP 8.2 без `composer install`, работать в закрытой сети и не зависеть от сторонних пакетов. При этом нужны QR-коды, HMAC-бейджи, AES-256-GCM, роутинг, логирование, метрики и rate limiting.

## Решение

Composer не используется. В репозитории нет `composer.json`, `vendor/` и composer-автозагрузчика. Компоненты реализованы внутри проекта:

| Компонент | Файл |
|---|---|
| Автозагрузчик | `public/api.php`, `public/index.php` |
| Роутер | `app/Core/Router.php` |
| PDO-модель | `app/Core/Model.php` |
| Логгер | `app/Core/Logger.php` |
| Rate limiter | `app/Core/RateLimiter.php` |
| Prometheus-метрики | `app/Core/MetricsService.php` |
| AES-256-GCM | `app/Services/CryptoService.php` |
| HMAC-бейджи и QR | `app/Services/BadgeService.php` |
| Геоматчинг | `app/Services/GeoMatchingService.php` |
| Маскирование | `app/Services/DataMaskingService.php` |
| Приватность | `app/Services/PrivacyService.php` |
| Watchdog | `app/Services/TaskWatchdogService.php` |

Используются только встроенные расширения PHP: `pdo`, `pdo_sqlite`, `pdo_mysql`, `openssl`, `mbstring`, `json`, `curl`.

## Обоснование

1. Нет сетевых запросов при сборке и развёртывании.
2. Нет риска supply-chain-атак и уязвимых транзитивных зависимостей.
3. Криптография и QR-код прозрачны для аудита.
4. Поведение компонентов не меняется при обновлении внешних пакетов.
5. Docker-образ собирается только из PHP и системных пакетов.

## Последствия

### Положительные

- Простая сборка и отсутствие `vendor/`.
- Полный контроль над кодом.
- Работа в offline/изолированной среде.

### Отрицательные / риски

- Собственные роутер, логгер, метрики и QR нужно тестировать самостоятельно.
- Нет готовых валидаторов, шаблонизатора, PSR-7/PSR-15 и очередей.
- Статический анализ требует отдельной настройки автозагрузки.
- Формат токенов и бейджей несовместим со сторонними JWT-библиотеками.

## Альтернативы

| Альтернатива | Почему не выбрана |
|---|---|
| endroid/qr-code | Composer и GD/Imagick |
| chillerlan/php-qrcode | Тяжёлая зависимость |
| firebase/php-jwt | Не нужен при собственном HMAC-формате |
| monolog/monolog | Избыточен для файлового лога |
| Guzzle | Внешние HTTP-интеграции не являются обязательными |

## Ссылки

- `app/Core/*`
- `app/Services/*`
- `Dockerfile`, `Dockerfile.prod`
- `tests/run_tests.php`
