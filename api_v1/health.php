<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$dbStatus = 'disconnected';
$dbVersion = null;

try {
    $pdo = get_db();
    $row = $pdo->query("SELECT VERSION() as v")->fetch();
    $dbStatus = 'connected';
    $dbVersion = $row['v'] ?? 'unknown';
} catch (Throwable $e) {
    $dbStatus = 'error: ' . $e->getMessage();
}

json_success([
    'status'          => 'healthy',
    'app'             => Config::get('APP_NAME', 'SocialFlow API v1'),
    'environment'     => Config::get('APP_ENV', 'development'),
    'php_version'     => PHP_VERSION,
    'database'        => [
        'status'  => $dbStatus,
        'version' => $dbVersion,
    ],
    'server_time_utc' => gmdate('Y-m-d H:i:s'),
], 'SocialFlow API v1 is operational.');
