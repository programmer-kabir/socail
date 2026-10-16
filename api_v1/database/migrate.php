<?php
declare(strict_types=1);

/**
 * SocialFlow - Database Migration Runner
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

Config::load(__DIR__ . '/../.env');

echo "=== SocialFlow Database Migration Runner ===" . PHP_EOL;

$dbHost = Config::get('DB_HOST', '127.0.0.1');
$dbPort = Config::get('DB_PORT', '3306');
$dbName = Config::get('DB_DATABASE', 'socialflow_db');

try {
    // 1. Ensure database exists
    echo "Connecting to MySQL server at {$dbHost}:{$dbPort}..." . PHP_EOL;
    $rootPdo = Database::getRootConnection();
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    echo "Database '{$dbName}' ensured." . PHP_EOL;

    // 2. Connect to the application database
    $pdo = Database::getConnection();

    // 3. Ensure migrations table exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL UNIQUE,
            `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Scan migration files
    $migrationsDir = __DIR__ . '/migrations';
    $files = glob($migrationsDir . '/*.sql');
    sort($files);

    $stmt = $pdo->query("SELECT `migration` FROM `migrations`");
    $appliedMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $count = 0;
    foreach ($files as $filePath) {
        $fileName = basename($filePath);
        if (in_array($fileName, $appliedMigrations, true)) {
            echo "Skipping already applied: {$fileName}" . PHP_EOL;
            continue;
        }

        echo "Applying: {$fileName}... ";
        $sql = file_get_contents($filePath);
        
        // Execute multi-statement SQL
        $pdo->exec($sql);

        $insertStmt = $pdo->prepare("INSERT INTO `migrations` (`migration`) VALUES (:migration)");
        $insertStmt->execute([':migration' => $fileName]);

        echo "DONE." . PHP_EOL;
        $count++;
    }

    echo "Migrations finished successfully! ({$count} applied)." . PHP_EOL;

} catch (Throwable $e) {
    echo PHP_EOL . "MIGRATION ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}
