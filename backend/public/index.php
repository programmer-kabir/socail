<?php
declare(strict_types=1);

/**
 * SocialFlow - Public API Gateway & Router Entrypoint
 */

// Global Error / Exception handling
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Utils/Response.php';
require_once __DIR__ . '/../src/Utils/Router.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';

Config::load(__DIR__ . '/../.env');

// CORS Configuration
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$frontendUrl = Config::get('FRONTEND_URL', 'http://localhost:5173');

if ($origin && ($origin === $frontendUrl || Config::get('APP_ENV') === 'development')) {
    header("Access-Control-Allow-Origin: {$origin}");
    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Workspace-Id, Accept, Origin");
}

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();

// Health Check Endpoint
$router->get('/api/health', function (array $request) {
    $dbStatus = 'disconnected';
    $dbVersion = null;
    $dbError = null;

    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT VERSION() as v");
        $row = $stmt->fetch();
        $dbStatus = 'connected';
        $dbVersion = $row['v'] ?? 'unknown';
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }

    Response::json([
        'status'       => 'healthy',
        'app'          => Config::get('APP_NAME', 'SocialFlow'),
        'environment'  => Config::get('APP_ENV', 'development'),
        'php_version'  => PHP_VERSION,
        'database'     => [
            'status'  => $dbStatus,
            'version' => $dbVersion,
            'error'   => $dbError,
        ],
        'server_time_utc' => gmdate('Y-m-d H:i:s'),
    ], 200, 'SocialFlow REST API is operational.');
});

// Run Router
$router->dispatch();
