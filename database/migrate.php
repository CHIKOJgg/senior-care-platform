<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Services/CryptoService.php';
require_once __DIR__ . '/../app/Services/RegionService.php';

use App\Core\Database;
use App\Services\CryptoService;

echo "[MIGRATE] Running MySQL schema migrations...\n";

$pdo = Database::getConnection();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'mysql') {
    fwrite(STDERR, "[MIGRATE] Error: only MySQL is supported (got {$driver}).\n");
    exit(1);
}

$schemaFile = __DIR__ . '/schema.mysql.sql';
if (!file_exists($schemaFile)) {
    fwrite(STDERR, "[MIGRATE] Error: schema file not found at {$schemaFile}\n");
    exit(1);
}

// Serialize migrations across replicas. The advisory lock is released by
// MySQL automatically if the process dies.
$mysqlLock = (int)$pdo->query("SELECT GET_LOCK('senior_care_migrate', 30)")->fetchColumn() === 1;
if (!$mysqlLock) {
    fwrite(STDERR, "[MIGRATE] Could not acquire MySQL advisory lock; another migration is running.\n");
    exit(1);
}

try {
    $tableExists = function (string $table) use ($pdo): bool {
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        return (bool)$stmt->fetchColumn();
    };

    $getColumns = function (string $table) use ($pdo): array {
        $stmt = $pdo->prepare(
            'SELECT LOWER(COLUMN_NAME) AS col, CHARACTER_MAXIMUM_LENGTH AS max_len
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $stmt->execute(['table' => $table]);
        $cols = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols[$row['col']] = $row;
        }
        return $cols;
    };

    // 1. Incrementally extend legacy tables before creating indexes.
    if ($tableExists('help_requests')) {
        $hrCols = $getColumns('help_requests');
        $hrAdditions = [
            'completed_lat' => 'DOUBLE NULL',
            'completed_lon' => 'DOUBLE NULL',
            'distance_to_target' => 'DOUBLE NULL',
            'is_overdue' => 'INT DEFAULT 0',
            'assigned_at' => 'DATETIME NULL',
            'failed_attempts' => 'INT DEFAULT 0',
            'region_code' => "VARCHAR(32) DEFAULT 'MINSK_CITY'",
            'rated_at' => 'DATETIME NULL',
        ];
        foreach ($hrAdditions as $col => $typeDef) {
            if (!isset($hrCols[strtolower($col)])) {
                $pdo->exec("ALTER TABLE help_requests ADD COLUMN {$col} {$typeDef}");
                echo "[MIGRATE] Added column: help_requests.{$col}\n";
            }
        }
    }

    if ($tableExists('users')) {
        $userCols = $getColumns('users');
        if (!isset($userCols['token_version'])) {
            $pdo->exec('ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 1');
            echo "[MIGRATE] Added column: users.token_version\n";
        }
        if (!isset($userCols['region_code'])) {
            $pdo->exec("ALTER TABLE users ADD COLUMN region_code VARCHAR(32) NULL");
            echo "[MIGRATE] Added column: users.region_code\n";
        }
    }

    if ($tableExists('elderly_profiles')) {
        $epCols = $getColumns('elderly_profiles');
        if (!isset($epCols['region_code'])) {
            $pdo->exec("ALTER TABLE elderly_profiles ADD COLUMN region_code VARCHAR(32) DEFAULT 'MINSK_CITY'");
            echo "[MIGRATE] Added column: elderly_profiles.region_code\n";
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
            'badge_token' => 'VARCHAR(255) NULL',
            'badge_expires_at' => 'DATETIME NULL',
            'region_code' => "VARCHAR(32) DEFAULT 'MINSK_CITY'",
            'is_restricted' => 'INT DEFAULT 0',
            'restriction_reason' => 'VARCHAR(255) NULL',
            'restricted_at' => 'DATETIME NULL',
        ];
        foreach ($vpAdditions as $col => $typeDef) {
            if (!isset($vpCols[strtolower($col)])) {
                $pdo->exec("ALTER TABLE volunteer_profiles ADD COLUMN {$col} {$typeDef}");
                echo "[MIGRATE] Added column: volunteer_profiles.{$col}\n";
            }
        }

        // Badge tokens are ~235 chars; widen legacy columns only when needed.
        if (isset($vpCols['badge_token']) && (int)($vpCols['badge_token']['max_len'] ?? 0) < 255) {
            $pdo->exec('ALTER TABLE volunteer_profiles MODIFY COLUMN badge_token VARCHAR(255) NULL');
            echo "[MIGRATE] Widened volunteer_profiles.badge_token to VARCHAR(255)\n";
        }
    }

    if ($tableExists('fraud_alerts')) {
        $faCols = $getColumns('fraud_alerts');
        if (!isset($faCols['region_code'])) {
            $pdo->exec("ALTER TABLE fraud_alerts ADD COLUMN region_code VARCHAR(32) NULL");
            echo "[MIGRATE] Added column: fraud_alerts.region_code\n";
        }
    }

    // 2. Apply the base schema. PDO MySQL needs statements executed one by one.
    $sql = (string)file_get_contents($schemaFile);
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    echo "[MIGRATE] Base schema & indexes verified successfully (mysql).\n";

    // Existing coordinator accounts need an explicit operating region. New
    // coordinators are assigned one by an administrator in the access panel.
    $pdo->exec("
        UPDATE users u
        JOIN roles r ON r.id = u.role_id
        SET u.region_code = 'MINSK_CITY'
        WHERE r.code = 'coordinator' AND (u.region_code IS NULL OR u.region_code = '')
    ");

    // 2b. Backfill region_code from coordinates where missing.
    $regionBackfills = [
        'help_requests' => ['id', 'latitude', 'longitude'],
        'elderly_profiles' => ['user_id', 'latitude', 'longitude'],
        'volunteer_profiles' => ['user_id', 'latitude', 'longitude'],
    ];
    foreach ($regionBackfills as $table => [$pk, $latCol, $lonCol]) {
        if (!$tableExists($table)) {
            continue;
        }
        $cols = $getColumns($table);
        if (!isset($cols['region_code'])) {
            continue;
        }
        $rows = $pdo->query("SELECT {$pk} AS pk, {$latCol} AS lat, {$lonCol} AS lon, region_code FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE {$table} SET region_code = :region WHERE {$pk} = :pk");
        $changed = 0;
        foreach ($rows as $row) {
            $current = strtoupper((string)($row['region_code'] ?? ''));
            $target = \App\Services\RegionService::normalize($current !== '' ? $current : null, (float)$row['lat'], (float)$row['lon']);
            if ($current !== $target) {
                $upd->execute(['region' => $target, 'pk' => $row['pk']]);
                $changed++;
            }
        }
        if ($changed > 0) {
            echo "[MIGRATE] Region codes synchronized for {$table}: {$changed}\n";
        }
    }

    // 3. Ensure user_consents.revoked_at exists on legacy databases.
    if ($tableExists('user_consents')) {
        $ucCols = $getColumns('user_consents');
        if (!isset($ucCols['revoked_at'])) {
            $pdo->exec('ALTER TABLE user_consents ADD COLUMN revoked_at DATETIME NULL');
            echo "[MIGRATE] Added column: user_consents.revoked_at\n";
        }
    }

    // 4. Synchronize legacy verification flags.
    $pdo->exec("
        UPDATE volunteer_profiles
        SET verification_status = 'VERIFIED'
        WHERE is_verified = 1 AND (verification_status IS NULL OR verification_status = 'PENDING')
    ");
    $pdo->exec("
        UPDATE volunteer_profiles
        SET verification_status = 'REJECTED'
        WHERE is_verified = -1 AND (verification_status IS NULL OR verification_status = 'PENDING')
    ");
    echo "[MIGRATE] Existing volunteer verification statuses synchronized.\n";

    // 5. Encrypt legacy plaintext medical notes.
    $stmt = $pdo->query("SELECT user_id, mobility_notes FROM elderly_profiles WHERE mobility_notes IS NOT NULL AND mobility_notes != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $encryptedCount = 0;
    foreach ($rows as $r) {
        if (!CryptoService::isEncrypted($r['mobility_notes'])) {
            $enc = CryptoService::encrypt($r['mobility_notes']);
            $upd = $pdo->prepare('UPDATE elderly_profiles SET mobility_notes = :enc WHERE user_id = :uid');
            $upd->execute(['enc' => $enc, 'uid' => $r['user_id']]);
            $encryptedCount++;
        }
    }
    if ($encryptedCount > 0) {
        echo "[MIGRATE] Encrypted {$encryptedCount} legacy mobility_notes record(s).\n";
    }

    echo "[MIGRATE] All migrations applied successfully! Database is up to date.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[MIGRATE] Failed: " . $e->getMessage() . "\n");
    $pdo->query("SELECT RELEASE_LOCK('senior_care_migrate')");
    exit(1);
}

$pdo->query("SELECT RELEASE_LOCK('senior_care_migrate')");
