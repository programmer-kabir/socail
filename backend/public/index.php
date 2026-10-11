<?php
declare(strict_types=1);

/**
 * SocialFlow - Public API Gateway & Router Entrypoint
 */

// Error handling configuration
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Utils/Response.php';
require_once __DIR__ . '/../src/Utils/Router.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/RateLimiter.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/WorkspaceMiddleware.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/WorkspaceController.php';
require_once __DIR__ . '/../src/Controllers/SocialAccountController.php';
require_once __DIR__ . '/../src/Controllers/DashboardController.php';
require_once __DIR__ . '/../src/Controllers/MediaController.php';
require_once __DIR__ . '/../src/Controllers/PostController.php';

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

// 1. Health Check
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

// 2. Authentication Routes
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/logout', [AuthController::class, 'logout'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/auth/me', [AuthController::class, 'me'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword']);

// 3. Workspace Routes
$router->get('/api/workspaces', [WorkspaceController::class, 'index'], [[AuthMiddleware::class, 'handle']]);
$router->post('/api/workspaces', [WorkspaceController::class, 'create'], [[AuthMiddleware::class, 'handle']]);
$router->get('/api/workspaces/current', [WorkspaceController::class, 'current'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->put('/api/workspaces/current', [WorkspaceController::class, 'update'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);

// 4. Social Accounts Routes
$router->get('/api/accounts', [SocialAccountController::class, 'index'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->get('/api/accounts/oauth/url', [SocialAccountController::class, 'getOAuthUrl'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->post('/api/accounts/oauth/callback', [SocialAccountController::class, 'handleOAuthCallback'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->post('/api/accounts/sandbox/connect', [SocialAccountController::class, 'connectSandbox'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->post('/api/accounts/{id}/disconnect', [SocialAccountController::class, 'disconnect'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);

// 5. Dashboard Routes
$router->get('/api/dashboard/stats', [DashboardController::class, 'stats'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->get('/api/dashboard/activity', [DashboardController::class, 'activity'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);

// 6. Media Routes
$router->post('/api/media/upload', [MediaController::class, 'upload'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->get('/api/media/file/{filename}', [MediaController::class, 'file'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);

// 7. Post & Composer Routes
$router->get('/api/posts', [PostController::class, 'index'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->post('/api/posts', [PostController::class, 'create'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->get('/api/posts/{id}', [PostController::class, 'show'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->put('/api/posts/{id}', [PostController::class, 'update'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->delete('/api/posts/{id}', [PostController::class, 'delete'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);
$router->post('/api/posts/{id}/publish', [PostController::class, 'publish'], [
    [AuthMiddleware::class, 'handle'],
    [WorkspaceMiddleware::class, 'handle']
]);

// Dispatch incoming request
$router->dispatch();
