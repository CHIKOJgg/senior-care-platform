# Исчерпывающая спецификация REST API платформы «ЗаботаРядом»
## Каталог конечных точек, форматы обмена и ролевая матрица доступа (RBAC)

### 1. Общие стандарты взаимодействия и соответствие спецификации OpenAPI 3.0
- **Спецификация OpenAPI**: Архитектура REST API платформы «ЗаботаРядом» спроектирована в строгом соответствии со стандартом **OpenAPI 3.0** (OpenAPI Specification 3.0.3).
- **Базовый URL**: `http://127.0.0.1:8000/api`
- **Кодировка и формат обмена**: `Content-Type: application/json; charset=utf-8`
- **Схема аутентификации (OpenAPI `securitySchemes`)**: HTTP Bearer Authentication (`bearerAuth`, формат JWT / HMAC токен), передача в заголовке `Authorization: Bearer <token>` либо `X-Auth-Token: <token>`.
- **Единый формат успешного ответа**:
  ```json
  {
    "success": true,
    "data": { ... },
    "message": "Операция успешно выполнена"
  }
  ```
- **Единый формат ошибки**:
  ```json
  {
    "error": "Текстовое описание ошибки на русском языке",
    "code": "ERROR_CONSTANT"
  }
  ```

#### 1.1. Базовая декларация спецификации OpenAPI 3.0 (Blueprint)
```yaml
openapi: 3.0.3
info:
  title: API платформы взаимной помощи «ЗаботаРядом»
  version: 1.0.0
  description: Полная спецификация REST API для пожилых людей, волонтёров и координаторов ТЦСОН Советского района г. Минска
servers:
  - url: http://127.0.0.1:8000/api
    description: Локальный сервер платформы
paths:
  /requests:
    $ref: '#/components/paths/Requests'
  /sos:
    $ref: '#/components/paths/Sos'
  /volunteers:
    $ref: '#/components/paths/Volunteers'
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
      bearerFormat: JWT
```

---

### 2. Полный каталог конечных точек REST API (35+ маршрутов)

#### 2.1. Системные и диагностические эндпоинты
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `GET` | `/api/health` | Проверка жизнеспособности (DB, Crypto, Watchdog) | Публичный |

#### 2.2. Аутентификация и профиль пользователя (`AuthController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `POST` | `/api/auth/register` | Регистрация подопечного или волонтёра | Публичный |
| `POST` | `/api/auth/login` | Вход по номеру телефона и паролю | Публичный |
| `GET` | `/api/auth/me` | Получение профиля текущего пользователя | Авторизованный |
| `POST` | `/api/auth/logout` | Завершение сессии на текущем устройстве | Авторизованный |
| `POST` | `/api/auth/logout-all` | Отзыв всех токенов пользователя (все устройства) | Авторизованный |
| `POST` | `/api/auth/password` | Смена пароля с отзывом остальных сессий | Авторизованный |
| `POST` | `/api/auth/reset-password` | Новый пароль по одноразовому коду | Публичный |
| `POST` | `/api/coordinator/reset-code` | Выдача одноразового кода (30 мин) для подопечного/волонтёра | Координатор / администратор |

> Токены содержат версию (`tv`). Смена пароля, выход на всех устройствах и обезличивание увеличивают `users.token_version`, после чего все ранее выданные токены отклоняются с `401`. Для подопечных поддерживается долгий вход на устройстве (`remember: true`, 365 дней), для сотрудников — только сессия вкладки.

#### 2.3. Заявки на взаимную помощь (`HelpRequestController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `GET` | `/api/requests` | Лента открытых заявок (с геосортировкой и маскированием ПД) | Все авторизованные |
| `GET` | `/api/requests/my` | Список заявок текущего подопечного | `elderly` |
| `GET` | `/api/requests/volunteer` | Список активных задач в работе у волонтёра | `volunteer` |
| `GET` | `/api/requests/{id}` | Детальная карточка заявки с контекстным демаскированием | Участники, Координатор |
| `POST` | `/api/requests` | Создание новой заявки (текст или голос) с кодовым словом | `elderly`, `coordinator` |
| `POST` | `/api/requests/{id}/accept` | Принятие заявки в работу верифицированным волонтёром | `volunteer` (Verified) |
| `POST` | `/api/requests/{id}/complete` | Завершение визита с валидацией Haversine и секретного слова | Назначенный `volunteer` |
| `POST` | `/api/requests/{id}/cancel` | Отмена заявки автором или координатором | `elderly`, `coordinator` |
| `GET` | `/api/requests/overdue` | Алиас списка просроченных заявок | `coordinator`, `admin` |

#### 2.4. Верификация волонтёров и цифровые бейджи (`VolunteerController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `POST` | `/api/volunteers/verify-identity` | Проверка личности через драйверы МСИ РБ или Госуслуг | `volunteer` |
| `GET` | `/api/volunteers/my-badge` | Получение данных динамического бейджа (токен, таймер, QR) | `volunteer` (Verified) |
| `GET` | `/api/volunteers/badge/{token}` | Публичная верификация бейджа по сканированию QR | Публичный (без авторизации) |
| `GET` | `/api/volunteers/badge/{token}/svg` | Рендеринг векторной SVG-карточки бейджа | Публичный |
| `GET` | `/api/volunteers/leaderboard` | Таблица лидеров района по добрым делам | Все |

#### 2.5. Конфиденциальность, согласия и право на забвение (`PrivacyController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `POST` | `/api/consent` | Фиксация согласия на обработку ПД по Закону РБ № 99-З | Авторизованный |
| `GET` | `/api/consent` | История и действующие согласия пользователя | Авторизованный |
| `POST` | `/api/privacy/anonymize` | Исполнение «права на забвение» для текущего аккаунта | `elderly`, `volunteer` |
| `POST` | `/api/elderly/{id}/anonymize` | Административное обезличивание аккаунта подопечного | `coordinator`, `admin` |

#### 2.6. Экстренное реагирование SOS и служба МЧС 112 (`SosController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `POST` | `/api/sos` | Экстренный вызов бедствия (Приоритет 0) с вызовом МЧС | `elderly` |
| `GET` | `/api/sos/active` | Список активных экстренных ситуаций | `coordinator`, `admin` |
| `GET` | `/api/sos/{id}` | Детальные данные инцидента SOS | `coordinator`, `admin` |
| `POST` | `/api/sos/{id}/resolve` | Отметка о разрешении тревоги и прибытии помощи | `coordinator`, `admin` |
| `GET` | `/api/coordinator/sos-alerts` | Алиас списка активных SOS-тревог | `coordinator`, `admin` |
| `POST` | `/api/coordinator/sos-alerts/{id}/resolve`| Алиас разрешения тревоги | `coordinator`, `admin` |

#### 2.7. Консоль координатора ТЦСОН (`CoordinatorController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `GET` | `/api/coordinator/stats` | Аналитическая сводка социальной службы района | `coordinator`, `admin` |
| `GET` | `/api/coordinator/volunteers` | Реестр всех волонтёров Советского района | `coordinator`, `admin` |
| `GET` | `/api/coordinator/volunteers/pending` | Очередь анкет волонтёров, ожидающих проверки | `coordinator`, `admin` |
| `GET` | `/api/coordinator/pending-volunteers` | Алиас очереди модерации | `coordinator`, `admin` |
| `POST` | `/api/coordinator/volunteers/{id}/verify` | Утверждение аккредитации волонтёра | `coordinator`, `admin` |
| `POST` | `/api/coordinator/verify-volunteer/{id}` | Алиас утверждения аккредитации | `coordinator`, `admin` |
| `POST` | `/api/coordinator/volunteers/{id}/reject` | Отклонение / отзыв аккредитации с обоснованием | `coordinator`, `admin` |
| `GET` | `/api/coordinator/requests` | Полный список заявок района с фильтрами | `coordinator`, `admin` |
| `POST` | `/api/coordinator/requests/{id}/status` | Принудительная смена статуса заявки | `coordinator`, `admin` |
| `POST` | `/api/coordinator/requests/{id}/reassign` | Возврат зависшей заявки обратно в общую ленту | `coordinator`, `admin` |
| `GET` | `/api/coordinator/alerts` | Оперативные оповещения и тревоги | `coordinator`, `admin` |
| `POST` | `/api/coordinator/alerts` | Публикация районного предупреждения о мошенниках | `coordinator`, `admin` |
| `DELETE`| `/api/coordinator/alerts/{id}` | Деактивация предупреждения | `coordinator`, `admin` |
| `GET` | `/api/coordinator/watchdog` | Мониторинг просроченных задач сторожевым таймером | `coordinator`, `admin` |
| `GET` | `/api/coordinator/tasks/overdue` | Алиас мониторинга просроченных задач | `coordinator`, `admin` |
| `POST` | `/api/tasks/watchdog/run` | Ручной запуск сканирования сторожевого таймера | `coordinator`, `admin` |

#### 2.8. Антифрод и просветительские материалы
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `POST` | `/api/antifraud/check` | Анализ подозрительного текста на схемы мошенников | Все |
| `GET` | `/api/antifraud/alerts` | Лента предупреждений ТЦСОН для подопечных | Все |
| `GET` | `/api/education` | Памятки и инструкции по безопасности | Все |

#### 2.9. Внутриплатформенный чат (`ChatController`)
| Метод | Путь | Назначение | Доступ |
|---|---|---|---|
| `GET` | `/api/chat/{id}` | Сообщения чата по конкретной заявке | Участники, Координатор |
| `POST` | `/api/chat/{id}` | Отправка сообщения в чат | Участники, Координатор |

---

### 3. Матрица прав доступа (RBAC Permission Matrix)
| Роль в системе | Публичные методы | Чтение ленты `/api/requests` | Создание заявки | Принятие заявки | Закрытие заявки | Вызов SOS | Модерация волонтёров | Разрешение SOS / Watchdog |
|---|---|---|---|---|---|---|---|---|
| **Гость (Guest)** | Да | Нет (401) | Нет (401) | Нет (401) | Нет (401) | Нет (401) | Нет (401) | Нет (401) |
| **Подопечный (`elderly`)** | Да | Да (маскированно) | Да | Нет (403) | Нет (403) | Да (P0) | Нет (403) | Нет (403) |
| **Волонтёр (`volunteer`)** | Да | Да (маскированно) | Нет (403) | Да (если верифицирован) | Да (GPS+код) | Нет (403) | Нет (403) | Нет (403) |
| **Координатор (`coordinator`)** | Да | Да (демаскированно) | Да | Нет | Принудительно | От имени подопечного | Да | Да |
| **Администратор (`admin`)** | Да | Да (демаскированно) | Да | Да | Да | Да | Да | Да |

---

### 4. Схемы данных компонентов OpenAPI 3.0 (OpenAPI Components Schemas)

#### 4.1. Схема заявки на помощь (`HelpRequest`)
```yaml
components:
  schemas:
    HelpRequest:
      type: object
      required:
        - id
        - elderly_id
        - category_id
        - title
        - address
        - status
      properties:
        id:
          type: integer
          example: 42
        elderly_id:
          type: integer
          example: 10
        volunteer_id:
          type: integer
          nullable: true
          example: 5
        category_id:
          type: integer
          example: 1
        title:
          type: string
          example: "Доставка медикаментов из аптеки"
        description:
          type: string
          example: "Необходим рецептурный препарат, рецепт на тумбочке"
        address:
          type: string
          example: "Минск, ул. Якуба Коласа, д. 28, кв. 14"
        latitude:
          type: number
          format: float
          example: 53.9168
        longitude:
          type: number
          format: float
          example: 27.5856
        secret_code:
          type: string
          description: "Секретное слово подопечного для подтверждения визита"
          example: "яблоко"
        status:
          type: string
          enum: [open, in_progress, completed, cancelled]
          example: "in_progress"
        is_overdue:
          type: integer
          enum: [0, 1]
          example: 0
        assigned_at:
          type: string
          format: date-time
          nullable: true
        created_at:
          type: string
          format: date-time
```

#### 4.2. Схема экстренного вызова SOS (`SosAlert`)
```yaml
components:
  schemas:
    SosAlert:
      type: object
      required:
        - id
        - elderly_id
        - latitude
        - longitude
        - status
      properties:
        id:
          type: integer
          example: 101
        elderly_id:
          type: integer
          example: 12
        latitude:
          type: number
          format: float
          example: 53.9168
        longitude:
          type: number
          format: float
          example: 27.5856
        reason:
          type: string
          example: "Внезапное ухудшение самочувствия, подозрение на гипертонический криз"
        status:
          type: string
          enum: [ACTIVE, RESOLVED]
          example: "ACTIVE"
        emercom_ticket:
          type: string
          example: "EMERCOM-112-BY-2026-F4B1"
        created_at:
          type: string
          format: date-time
        resolved_at:
          type: string
          format: date-time
          nullable: true
```

#### 4.3. Схема верификации цифрового бейджа (`VolunteerBadge`)
```yaml
components:
  schemas:
    VolunteerBadge:
      type: object
      required:
        - valid
        - volunteer
      properties:
        valid:
          type: boolean
          example: true
        volunteer:
          type: object
          properties:
            first_name:
              type: string
              example: "Алексей"
            patronymic:
              type: string
              example: "Сергеевич"
            last_initial:
              type: string
              example: "С."
            organization:
              type: string
              example: "ТЦСОН Советского района г. Минска"
            verification_status:
              type: string
              enum: [VERIFIED, PENDING, REJECTED]
              example: "VERIFIED"
            badge_issued_at:
              type: integer
              example: 1730732400
            expires_at:
              type: integer
              example: 1730736000
```

#### 4.4. Схема согласия на обработку ПД (`ConsentRecord`)
```yaml
components:
  schemas:
    ConsentRecord:
      type: object
      required:
        - id
        - user_id
        - consent_type
        - granted_at
      properties:
        id:
          type: integer
          example: 1
        user_id:
          type: integer
          example: 10
        consent_type:
          type: string
          example: "LAW_99_Z_GENERAL_DATA_PROCESSING"
        granted_at:
          type: string
          format: date-time
        ip_address:
          type: string
          example: "127.0.0.1"
```

#### 4.5. Схема стандартизированной ошибки (`ErrorResponse`)
```yaml
components:
  schemas:
    ErrorResponse:
      type: object
      required:
        - error
      properties:
        error:
          type: string
          example: "Волонтёр находится вне допустимой зоны (расстояние: 320 м > 150 м)"
        code:
          type: string
          example: "GEOFENCE_OUT_OF_RANGE"
```
