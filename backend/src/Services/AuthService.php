<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/EncryptionService.php';

/**
 * SocialFlow - Authentication & Session Service
 */
class AuthService
{
    private const SESSION_COOKIE = 'socialflow_session';
    private const CSRF_COOKIE = 'socialflow_csrf';
    private const SESSION_LIFETIME = 604800; // 7 days in seconds

    /**
     * Create a new session for a user, rotate existing session, and issue secure cookies.
     */
    public static function createSession(int $userId, ?string $oldTokenHash = null): array
    {
        $pdo = Database::getConnection();

        // Rotate/remove old session if specified
        if ($oldTokenHash) {
            $stmtDel = $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :hash");
            $stmtDel->execute([':hash' => $oldTokenHash]);
        }

        // Generate raw opaque token (64 hex chars)
        $rawToken = EncryptionService::generateOpaqueToken();
        $tokenHash = EncryptionService::hashToken($rawToken);

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
            VALUES (:user_id, :token_hash, :ip_address, :user_agent, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            ':user_id'    => $userId,
            ':token_hash' => $tokenHash,
            ':ip_address' => $ipAddress,
            ':user_agent' => $userAgent,
        ]);

        // Generate CSRF token tied to session hash
        $appKey = Config::get('APP_KEY');
        $csrfToken = hash_hmac('sha256', $tokenHash, $appKey);

        // Issue Cookies
        self::setSessionCookies($rawToken, $csrfToken);

        return [
            'token'      => $rawToken,
            'csrf_token' => $csrfToken,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::SESSION_LIFETIME),
        ];
    }

    /**
     * Set HttpOnly session cookie and readable CSRF cookie
     */
    public static function setSessionCookies(string $rawToken, string $csrfToken): void
    {
        if (headers_sent()) {
            return;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $cookieOptions = [
            'expires'  => time() + self::SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie(self::SESSION_COOKIE, $rawToken, $cookieOptions);

        // CSRF cookie must be readable by JavaScript to send in X-CSRF-Token header
        $csrfCookieOptions = $cookieOptions;
        $csrfCookieOptions['httponly'] = false;
        setcookie(self::CSRF_COOKIE, $csrfToken, $csrfCookieOptions);
    }

    /**
     * Clear all session and CSRF cookies
     */
    public static function clearSessionCookies(): void
    {
        if (headers_sent()) {
            return;
        }

        $cookieOptions = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie(self::SESSION_COOKIE, '', $cookieOptions);

        $cookieOptions['httponly'] = false;
        setcookie(self::CSRF_COOKIE, '', $cookieOptions);
    }

    /**
     * Invalidate a session by raw token or hash
     */
    public static function destroySession(string $tokenHash): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :hash");
        $stmt->execute([':hash' => $tokenHash]);
        self::clearSessionCookies();
    }

    /**
     * Revoke all sessions for a user (used after password reset)
     */
    public static function revokeAllUserSessions(int $userId): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        self::clearSessionCookies();
    }

    /**
     * Compute expected CSRF token for a given session hash
     */
    public static function generateCsrfForSession(string $tokenHash): string
    {
        $appKey = Config::get('APP_KEY');
        return hash_hmac('sha256', $tokenHash, $appKey);
    }
}
