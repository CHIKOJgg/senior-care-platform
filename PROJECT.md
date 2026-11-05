# Project: «ЗаботаРядом» (Senior Care Platform)

## Architecture
- **Environment**: Containerized PHP 8.2. Development uses the built-in server (`senior_care_platform`, port 8000). Production runs Nginx + PHP-FPM behind Caddy, with MySQL 8, Prometheus, Grafana, Loki and Promtail (`docker-compose.prod.yml`).
- **Backend Architecture**: Lightweight, PSR-4 compliant MVC in `app/` without external Composer dependencies.
  - `app/Core/`: Router, Database (SQLite PDO with foreign keys), Model, Controller.
  - `app/Controllers/`: Auth, HelpRequest, Elderly, Volunteer, Admin, SosAlert, Privacy, Identity.
  - `app/Services/`:
    - `CryptoService`: AES-256-GCM authenticated encryption/decryption for sensitive health data.
    - `DataMaskingService`: Law 99-Z compliant masking of phones (`+375 (XX) ***-**-YY`) and apartments (`кв. ***`).
    - `PrivacyService`: Electronic consent tracking and GDPR/Law 99-Z right-to-be-forgotten anonymization.
    - `GeoMatchingService`: Haversine formula ($r = 6\,371\,000$ m, $R_{\text{max}} = 150$ m) proof-of-presence validation.
    - `Identity/`: `IdentityProviderInterface`, `MsiIdentityAdapter`, `GosuslugiIdentityAdapter`.
    - `BadgeService`: Dynamic QR badge generation with HMAC-SHA256 signature ($TTL = 3600$ s) and public verification.
    - `Emergency/`: `MchsEmergencyAdapter` (EMERCOM / 112 mock).
    - `TaskWatchdogService`: Automated check for tasks exceeding category timeouts.
- **Frontend Architecture**: Pure Vanilla JS SPA in `public/` (`index.php`, `js/app.js`, `js/speech.js`, `css/accessibility.css`).
  - Strict WCAG 2.1 AAA standard (21:1 contrast, 60px touch targets, `:focus-visible` 3px).
  - Strict Zero-Emoji policy: 100% inline SVG icons.
- **Testing Architecture**:
  - Unit/Integration tests: `tests/run_tests.php`
  - E2E API tests: `tests/test_api_requests.php`
  - Dedicated E2E Testing Track with 4 tiers + Tier 5 adversarial tests.

## Feature Inventory
| # | Feature | Description | Milestone | Source |
|---|---------|-------------|-----------|--------|
| 1 | Database Migrations | Non-destructive SQLite schema migrations for new tables and columns | M1 | R5 |
| 2 | Sensitive Data Encryption | AES-256-GCM encryption of medical notes in database | M1 | R3 |
| 3 | PII Data Masking | Masking phones and apartments in open request feeds (Law 99-Z) | M1 | R3 |
| 4 | Electronic Consent Ledger | Table and API for recording user consent per Law 99-Z | M1 | R3 |
| 5 | Right to be Forgotten | Endpoint and logic for cryptographically anonymizing user PII | M1 | R3 |
| 6 | Modular Identity Adapters | Interface and drivers for MSI Belarus and Gosuslugi mock | M2 | R2 |
| 7 | Coordinator Volunteer Moderation | Endpoints & workflow for reviewing passport & police clearance | M2 | R2 |
| 8 | Dynamic Digital QR Badge | HMAC-SHA256 signed dynamic badge token with 1h TTL | M2 | R2 |
| 9 | Public Badge Verification | Public endpoint validating volunteer badge without exposing PII | M2 | R2 |
| 10 | Haversine Geofencing Validation | Server-side validation of volunteer proximity ($d \le 150$m) on complete | M3 | R1 |
| 11 | Secret Word Verification | Hardened cryptographic code matching combined with GPS validation | M3 | R1 |
| 12 | Priority 0 SOS Alerting | Emergency SOS endpoint creating urgent alert for TЦСОН coordinator | M3 | R4 |
| 13 | Modular EMERCOM / MChS Adapter | Adapter emulating dispatch to 112 / MChS emergency services | M3 | R4 |
| 14 | Task Watchdog & Stale Monitor | Automated timeout monitoring for in-progress tasks | M3 | R4 |
| 15 | Elderly SOS Screen & Button | High-contrast SOS button with audio/speech confirmation | M4 | R4 |
| 16 | Coordinator SOS Alert Console | Visual pulse banner & Web Audio API siren for incoming SOS | M4 | R4 |
| 17 | Coordinator Watchdog View | Dashboard view of overdue tasks requiring coordinator attention | M4 | R4 |
| 18 | Volunteer GPS Complete Modal | Geolocation capture modal with friendly out-of-range feedback | M4 | R1 |
| 19 | Volunteer Digital Badge Modal | Modal displaying verifiable SVG QR code badge | M4 | R2 |
| 20 | Public Verification View | Web page validating volunteer status via QR scan | M4 | R2 |
| 21 | WCAG 2.1 AAA & Zero-Emoji UI | Accessible UI preservation, 0 emojis, 100% SVG icons | M4 | R5 |
| 22 | Architectural Plan in `/plan` | Comprehensive 8-document architecture blueprint | M4 | R5 |
| 23 | E2E Testing Suite (Tiers 1-4) | Opaque-box requirement-driven test suite with TEST_READY.md | M5 | R5 |
| 24 | Adversarial Hardening & Audit | Tier 5 white-box coverage hardening + Forensic Integrity Audit | M5 | R5 |

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|------|-------|-------------|--------|
| 1 | M1: DB Migrations, Cryptography & Privacy | SQLite migrations, AES-256-GCM encryption, phone/apartment masking, consent logging, anonymization endpoint | None | DONE |
| 2 | M2: Volunteer Verification & QR Badge | Identity adapters (MSI BY, Gosuslugi), moderation queue, HMAC-signed dynamic QR badge, public verification API | M1 | DONE |
| 3 | M3: Geofencing, SOS Protocol & Watchdog | Haversine proof-of-presence validation, secret code verification, SOS endpoint, EMERCOM adapter, task watchdog | M1 | DONE |
| 4 | M4: Frontend UI/UX, WCAG & Plan Docs | Elderly SOS button, Coordinator SOS siren/watchdog, Volunteer GPS modal & QR badge modal, `/plan` architecture docs | M1, M2, M3 | DONE |
| 5 | M5: E2E Acceptance & Adversarial Hardening | Comprehensive test suite (Tiers 1-4), Tier 5 adversarial tests, Forensic Integrity Audit, 100% pass | M1, M2, M3, M4 | DONE |

## Interface Contracts
### Client ↔ API: Complete Help Request (Proof-of-Presence)
- **Endpoint**: `POST /api/requests/{id}/complete`
- **Headers**: `Authorization: Bearer <token>`
- **Payload**:
  ```json
  {
    "secret_code": "яблоко",
    "latitude": 53.9168,
    "longitude": 27.5856
  }
  ```
- **Responses**:
  - `200 OK`: `{"success": true, "message": "Заявка успешно выполнена и подтверждена", "distance_meters": 42.5}`
  - `400 Bad Request`: `{"error": "Неверное секретное слово"}` or `{"error": "Волонтёр находится вне допустимой зоны (расстояние: 320 м > 150 м)"}`

### Client ↔ API: Public Volunteer Badge Verification
- **Endpoint**: `GET /api/volunteers/badge/{token}`
- **Responses**:
  - `200 OK`:
    ```json
    {
      "valid": true,
      "volunteer": {
        "first_name": "Алексей",
        "patronymic": "Сергеевич",
        "last_initial": "С.",
        "organization": "ТЦСОН Советского района г. Минска",
        "verification_status": "VERIFIED",
        "badge_issued_at": 1730732400,
        "expires_at": 1730736000
      }
    }
    ```
  - `404/400`: `{"valid": false, "error": "QR-код устарел или недействителен"}`

### Client ↔ API: Emergency SOS
- **Endpoint**: `POST /api/sos`
- **Headers**: `Authorization: Bearer <token>`
- **Payload**: `{"latitude": 53.9168, "longitude": 27.5856, "reason": "Плохое самочувствие"}`
- **Responses**:
  - `201 Created`: `{"success": true, "sos_id": 1, "status": "ACTIVE", "emercom_notified": true}`

### Client ↔ API: Right to be Forgotten (Privacy)
- **Endpoint**: `POST /api/privacy/anonymize`
- **Headers**: `Authorization: Bearer <token>`
- **Payload**: `{"confirm": true}`
- **Responses**:
  - `200 OK`: `{"success": true, "message": "Персональные данные успешно обезличены в соответствии с Законом РБ № 99-З"}`

## Code Layout
- `app/Core/`: Base framework classes (`Router.php`, `Database.php`, `Model.php`, `Controller.php`)
- `app/Controllers/`: REST controllers (`HelpRequestController.php`, `VolunteerController.php`, `ElderlyController.php`, `AdminController.php`, `SosController.php`, `PrivacyController.php`)
- `app/Services/`:
  - `CryptoService.php` (AES-256-GCM)
  - `DataMaskingService.php` (Law 99-Z masking)
  - `PrivacyService.php` (Consent & Anonymization)
  - `GeoMatchingService.php` (Haversine distance)
  - `BadgeService.php` (Dynamic QR badges)
  - `TaskWatchdogService.php` (Overdue tasks)
  - `Identity/`: `IdentityProviderInterface.php`, `MsiIdentityAdapter.php`, `GosuslugiIdentityAdapter.php`
  - `Emergency/`: `MchsEmergencyAdapter.php`
- `database/`: `schema.sql`, `migrate.php`, `seed.php`, `database.sqlite`
- `public/`: `index.php`, `api.php`, `css/accessibility.css`, `js/app.js`, `js/speech.js`
- `tests/`: `run_tests.php`, `test_api_requests.php`, `test_geofencing.php`, `test_privacy.php`, `test_sos.php`, `test_badge.php`
- `plan/`: Comprehensive architecture documentation (`01_SYSTEM_OVERVIEW.md`, `02_GEOFENCING_AND_SECURITY.md`, `03_VOLUNTEER_VERIFICATION_AND_BADGES.md`, `04_DATA_PROTECTION_AND_PRIVACY.md`, `05_EMERGENCY_SOS_AND_WATCHDOG.md`, `06_DATABASE_MIGRATIONS.md`, `07_REST_API_SPECIFICATION.md`, `08_TESTING_AND_AUDIT.md`)
