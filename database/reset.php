<?php

declare(strict_types=1);

/**
 * Destructive test/dev helper: drop every table in the configured MySQL
 * database so migrate.php + seed.php can rebuild a deterministic state.
 * Never expose this through HTTP.
 */

require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

if ((getenv('APP_ENV') ?: 'local') === 'production' && getenv('ALLOW_DB_RESET') !== 'true') {
    fwrite(STDERR, "[RESET] Refusing to drop tables in production. Set ALLOW_DB_RESET=true explicitly.\n");
    exit(1);
}

$pdo = Database::getConnection();
$db = (string)getenv('DB_DATABASE');
if ($db === '') {
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
}
if ($db === '') {
    fwrite(STDERR, "[RESET] No database selected.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = :type'
);
$stmt->execute(['db' => $db, 'type' => 'BASE TABLE']);
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', (string)$table)) {
        continue;
    }
    $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "[RESET] Dropped " . count($tables) . " table(s) from {$db}.\n";
