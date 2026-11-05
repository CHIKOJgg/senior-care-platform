<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';

use App\Core\Database;
use App\Services\CryptoService;

echo "[MIGRATE] Running non-destructive schema migrations...\n";

$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    die("[MIGRATE] Error: schema.sql not found at {$schemaFile}\n");
}

$pdo = Database::getConnection();

try {
    // Хелпер проверки существования таблицы
    $tableExists = function (string $table) use ($pdo): bool {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :table");
        } else {
            $stmt = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
        }
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    };

    // Хелпер для определения существующих колонок
    $getColumns = function (string $table) use ($pdo): array {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info({$table})");
            $cols = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[strtolower($row['name'])] = true;
            }
            return $cols;
        } else {
            $stmt = $pdo->prepare("
                SELECT LOWER(COLUMN_NAME) as col 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
            ");
            $stmt->execute(['table' => $table]);
            $cols = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[$row['col']] = true;
            }
            return $cols;
        }
    };

    // 1. Предварительное инкрементальное расширение существующих таблиц до применения индексов
    if ($tableExists('help_requests')) {
        $hrCols = $getColumns('help_requests');
        $hrAdditions = [
            'completed_lat' => 'REAL NULL',
            'completed_lon' => 'REAL NULL',
            'distance_to_target' => 'REAL NULL',
            'is_overdue' => 'INTEGER DEFAULT 0',
            'assigned_at' => 'DATETIME NULL',
            'failed_attempts' => 'INTEGER DEFAULT 0',
        ];
        foreach ($hrAdditions as $col => $typeDef) {
            if (!isset($hrCols[strtolower($col)])) {
                $pdo->exec("ALTER TABLE help_requests ADD COLUMN {$col} {$typeDef};");
                echo "[MIGRATE] Added column: help_requests.{$col}\n";
            }
        }
    }

    if ($tableExists('users')) {
        $userCols = $getColumns('users');
        if (!isset($userCols['token_version'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN token_version INTEGER NOT NULL DEFAULT 1;");
            echo "[MIGRATE] Added column: users.token_version\n";
        }
    }

    if ($tableExists('volunteer_profiles')) {
        $vpCols = $getColumns('volunteer_profiles');
        $vpAdditions = [
            'verification_status' => "VARCHAR(50) DEFAULT 'PENDING'",
            'id_provider' => 'VARCHAR(50) NULL',
            'id_provider_user_id' => 'VARCHAR(100) NULL',
            'passport_masked' => 'VARCHAR(50) NULL',
            'police_clearance_status' => "VARCHAR(50) DEFAULT 'NOT_SUBMITTED'",
            'badge_token' => 'VARCHAR(64) NULL',
            'badge_expires_at' => 'DATETIME NULL',
        ];
        foreach ($vpAdditions as $col => $typeDef) {
            if (!isset($vpCols[strtolower($col)])) {
                $pdo->exec("ALTER TABLE volunteer_profiles ADD COLUMN {$col} {$typeDef};");
                echo "[MIGRATE] Added column: volunteer_profiles.{$col}\n";
            }
        }
    }

    // 2. Применение базовой схемы таблиц (CREATE TABLE IF NOT EXISTS & CREATE INDEX IF NOT EXISTS)
    $sql = file_get_contents($schemaFile);
    $pdo->exec($sql);
    echo "[MIGRATE] Base schema, new tables & indexes verified successfully.\n";

    // 3. Проверка колонки revoked_at в user_consents
    if ($tableExists('user_consents')) {
        $ucCols = $getColumns('user_consents');
        if (!isset($ucCols['revoked_at'])) {
            $pdo->exec("ALTER TABLE user_consents ADD COLUMN revoked_at DATETIME NULL;");
            echo "[MIGRATE] Added column: user_consents.revoked_at\n";
        }
    }

    // 4. Гарантия создания индексов (идемпотентно)
    $indices = [
        "CREATE INDEX IF NOT EXISTS idx_password_resets_user_id ON password_resets(user_id);",
        "CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at ON password_resets(expires_at);",
        "CREATE INDEX IF NOT EXISTS idx_user_consents_user_id ON user_consents(user_id);",
        "CREATE INDEX IF NOT EXISTS idx_user_consents_type ON user_consents(consent_type);",
        "CREATE INDEX IF NOT EXISTS idx_sos_alerts_elderly_id ON sos_alerts(elderly_id);",
        "CREATE INDEX IF NOT EXISTS idx_sos_alerts_status ON sos_alerts(status);",
        "CREATE INDEX IF NOT EXISTS idx_sos_alerts_priority ON sos_alerts(priority);",
        "CREATE INDEX IF NOT EXISTS idx_sos_alerts_created_at ON sos_alerts(created_at);",
        "CREATE INDEX IF NOT EXISTS idx_help_requests_status ON help_requests(status);",
        "CREATE INDEX IF NOT EXISTS idx_help_requests_is_overdue ON help_requests(is_overdue);",
        "CREATE INDEX IF NOT EXISTS idx_help_requests_assigned_at ON help_requests(assigned_at);",
        "CREATE INDEX IF NOT EXISTS idx_volunteer_profiles_status ON volunteer_profiles(verification_status);",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_volunteer_profiles_badge_token ON volunteer_profiles(badge_token);",
    ];
    foreach ($indices as $idxSql) {
        $pdo->exec($idxSql);
    }
    echo "[MIGRATE] Indexes verified successfully.\n";

    // 5. Синхронизация статусов существующих волонтёров
    $pdo->exec("
        UPDATE volunteer_profiles 
        SET verification_status = 'VERIFIED' 
        WHERE is_verified = 1 AND (verification_status IS NULL OR verification_status = 'PENDING');
    ");
    $pdo->exec("
        UPDATE volunteer_profiles 
        SET verification_status = 'REJECTED' 
        WHERE is_verified = -1 AND (verification_status IS NULL OR verification_status = 'PENDING');
    ");
    echo "[MIGRATE] Existing volunteer verification statuses synchronized.\n";

    // 6. Шифрование существующих незашифрованных медицинских заметок (mobility_notes)
    $stmt = $pdo->query("SELECT user_id, mobility_notes FROM elderly_profiles WHERE mobility_notes IS NOT NULL AND mobility_notes != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $encryptedCount = 0;
    foreach ($rows as $r) {
        if (!CryptoService::isEncrypted($r['mobility_notes'])) {
            $enc = CryptoService::encrypt($r['mobility_notes']);
            $upd = $pdo->prepare("UPDATE elderly_profiles SET mobility_notes = :enc WHERE user_id = :uid");
            $upd->execute(['enc' => $enc, 'uid' => $r['user_id']]);
            $encryptedCount++;
        }
    }
    if ($encryptedCount > 0) {
        echo "[MIGRATE] Encrypted {$encryptedCount} legacy mobility_notes record(s).\n";
    }

    echo "[MIGRATE] All migrations applied successfully! Database is up to date.\n";
} catch (Throwable $e) {
    echo "[MIGRATE] Failed: " . $e->getMessage() . "\n";
    exit(1);
}
