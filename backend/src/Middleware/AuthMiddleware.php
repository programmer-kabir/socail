<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Services/EncryptionService.php';
require_once __DIR__ . '/../Services/AuthService.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Authentication & CSRF Middleware
 */
class AuthMiddleware
{
    public static function handle(array $request): array|bool
    {
        $rawToken = null;
        $isCookieAuth = false;

        // 1. Check Bearer Token header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            $rawToken = $matches[1];
        }

        // 2. Fallback to HttpOnly session cookie
        if (!$rawToken && isset($_COOKIE['socialflow_session']) && !empty($_COOKIE['socialflow_session'])) {
            $rawToken = $_COOKIE['socialflow_session'];
            $isCookieAuth = true;
        }

        if (!$rawToken) {
            Response::error('Authentication required.', 401);
            return false;
        }

        // 3. Resolve session from DB using token hash
        $tokenHash = EncryptionService::hashToken($rawToken);
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT us.id as session_id, us.user_id, us.token_hash, us.expires_at,
                   u.name, u.email, u.avatar_url, u.created_at as user_created_at
            FROM user_sessions us
            JOIN users u ON us.user_id = u.id
            WHERE us.token_hash = :hash AND us.expires_at > NOW()
        ");
        $stmt->execute([':hash' => $tokenHash]);
        $session = $stmt->fetch();

        if (!$session) {
            AuthService::clearSessionCookies();
            Response::error('Session expired or invalid.', 401);
            return false;
        }

        // 4. CSRF Protection for Cookie-based State-Changing Requests
        if ($isCookieAuth && in_array($request['method'], ['POST', 'PUT', 'DELETE'], true)) {
            $providedCsrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $expectedCsrf = AuthService::generateCsrfForSession($session['token_hash']);

            if (empty($providedCsrf) || !hash_equals($expectedCsrf, $providedCsrf)) {
                Response::error('CSRF token missing or invalid.', 403);
                return false;
            }
        }

        // 5. Attach authenticated user and session metadata to request
        $request['user'] = [
            'id'         => (int)$session['user_id'],
            'name'       => $session['name'],
            'email'      => $session['email'],
            'avatar_url' => $session['avatar_url'],
            'created_at' => $session['user_created_at'],
        ];

        $request['session'] = [
            'id'         => (int)$session['session_id'],
            'token_hash' => $session['token_hash'],
            'raw_token'  => $rawToken,
        ];

        return $request;
    }
}
