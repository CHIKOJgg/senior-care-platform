<?php

require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

echo "[MIGRATE] Running schema migrations...\n";

$schemaFile = __DIR__ . '/schema.sql';
if (!file_exists($schemaFile)) {
    die("Error: schema.sql not found at {$schemaFile}\n");
}

$sql = file_get_contents($schemaFile);
$pdo = Database::getConnection();

try {
    $pdo->exec($sql);
    echo "[MIGRATE] Success! All database tables created.\n";
} catch (Exception $e) {
    die("[MIGRATE] Failed: " . $e->getMessage() . "\n");
}
