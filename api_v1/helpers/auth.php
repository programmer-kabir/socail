<?php
declare(strict_types=1);

/**
 * SocialFlow API v1 - Authentication & Session Helper
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/response.php';

class Auth
{
    private static ?array $currentUser = null;
    private static ?array $currentSession = null;
    private static ?array $currentWorkspace = null;

    public static function createSession(int $userId): array
    {
        $pdo = get_db();
        $rawToken = Security::generateToken();
        $tokenHash = Security::hashToken($rawToken);

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

        $stmt = $pdo->prepare("
            INSERT INTO user_sessions (user_id, token_hash, ip_address, user_agent, expires_at)
            VALUES (:uid, :thash, :ip, :ua, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute([
            ':uid'   => $userId,
            ':thash' => $tokenHash,
            ':ip'    => $ipAddress,
            ':ua'    => $userAgent,
        ]);

        $appKey = (string)Config::get('APP_KEY', 'default_app_key_for_csrf_signing_32');
        $csrfToken = hash_hmac('sha256', $tokenHash, $appKey);

        self::setCookies($rawToken, $csrfToken);

        return [
            'token'      => $rawToken,
            'csrf_token' => $csrfToken,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 604800),
        ];
    }

    public static function setCookies(string $rawToken, string $csrfToken): void
    {
        if (headers_sent()) {
            return;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $options = [
            'expires'  => time() + 604800,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie('socialflow_session', $rawToken, $options);

        // Readable CSRF cookie for client JS
        $csrfOptions = $options;
        $csrfOptions['httponly'] = false;
        setcookie('socialflow_csrf', $csrfToken, $csrfOptions);
    }

    public static function clearCookies(): void
    {
        if (headers_sent()) {
            return;
        }

        $options = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie('socialflow_session', '', $options);
        $options['httponly'] = false;
        setcookie('socialflow_csrf', '', $options);
    }

    public static function getUser(): ?array
    {
        if (self::$currentUser !== null) {
            return self::$currentUser;
        }

        $rawToken = null;

        // 1. Authorization header: Bearer <token>
        $authHeader = get_header('Authorization');
        if ($authHeader && preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            $rawToken = $matches[1];
        }

        // 2. Cookie fallback
        if (!$rawToken && !empty($_COOKIE['socialflow_session'])) {
            $rawToken = $_COOKIE['socialflow_session'];
        }

        if (!$rawToken) {
            return null;
        }

        $tokenHash = Security::hashToken($rawToken);
        $pdo = get_db();

        $stmt = $pdo->prepare("
            SELECT us.id as session_id, us.user_id, us.token_hash, us.expires_at,
                   u.id, u.name, u.email, u.avatar_url, u.created_at
            FROM user_sessions us
            JOIN users u ON us.user_id = u.id
            WHERE us.token_hash = :hash AND us.expires_at > NOW()
        ");
        $stmt->execute([':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if (!$row) {
            self::clearCookies();
            return null;
        }

        self::$currentUser = [
            'id'         => (int)$row['id'],
            'name'       => $row['name'],
            'email'      => $row['email'],
            'avatar_url' => $row['avatar_url'],
            'created_at' => $row['created_at'],
        ];

        self::$currentSession = [
            'session_id' => (int)$row['session_id'],
            'token_hash' => $row['token_hash'],
            'raw_token'  => $rawToken,
        ];

        return self::$currentUser;
    }

    public static function requireUser(): array
    {
        $user = self::getUser();
        if (!$user) {
            json_error('Authentication required.', 401);
        }
        return $user;
    }

    public static function getWorkspace(?int $userId = null): array
    {
        if (self::$currentWorkspace !== null) {
            return self::$currentWorkspace;
        }

        $user = self::requireUser();
        $uid = $userId ?? $user['id'];
        $pdo = get_db();

        // Check X-Workspace-Id header
        $requestedWsId = get_header('X-Workspace-Id');
        if ($requestedWsId && is_numeric($requestedWsId)) {
            $stmt = $pdo->prepare("
                SELECT w.id, w.name, w.slug, w.timezone, wm.role
                FROM workspaces w
                JOIN workspace_members wm ON w.id = wm.workspace_id
                WHERE wm.user_id = :uid AND w.id = :wid
            ");
            $stmt->execute([':uid' => $uid, ':wid' => (int)$requestedWsId]);
            $ws = $stmt->fetch();
            if ($ws) {
                self::$currentWorkspace = $ws;
                return $ws;
            }
        }

        // Fallback to first workspace
        $stmt = $pdo->prepare("
            SELECT w.id, w.name, w.slug, w.timezone, wm.role
            FROM workspaces w
            JOIN workspace_members wm ON w.id = wm.workspace_id
            WHERE wm.user_id = :uid
            ORDER BY w.id ASC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $uid]);
        $ws = $stmt->fetch();

        if (!$ws) {
            json_error('No active workspace found for user.', 403);
        }

        self::$currentWorkspace = $ws;
        return $ws;
    }

    public static function destroySession(): void
    {
        self::getUser();
        if (self::$currentSession) {
            $pdo = get_db();
            $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token_hash = :hash");
            $stmt->execute([':hash' => self::$currentSession['token_hash']]);
        }
        self::clearCookies();
    }
}

// Global convenience functions
function require_auth(): array
{
    return Auth::requireUser();
}

function get_auth_user(): ?array
{
    return Auth::getUser();
}

function get_active_workspace(): array
{
    return Auth::getWorkspace();
}
