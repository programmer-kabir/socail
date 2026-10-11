<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Adapters/AdapterFactory.php';
require_once __DIR__ . '/../Services/EncryptionService.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Social Accounts Management Controller
 */
class SocialAccountController
{
    private const SUPPORTED_PLATFORMS = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];

    /**
     * List all connected social accounts for the active workspace
     */
    public function index(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT id, platform, account_id, account_name, username, avatar_url, 
                   account_type, status, is_sandbox, created_at, updated_at
            FROM social_accounts
            WHERE workspace_id = :wid AND status != 'disconnected'
            ORDER BY platform ASC, created_at DESC
        ");
        $stmt->execute([':wid' => $workspaceId]);
        $accounts = $stmt->fetchAll();

        // Ensure boolean cast for is_sandbox
        foreach ($accounts as &$acc) {
            $acc['is_sandbox'] = (bool)$acc['is_sandbox'];
        }

        Response::json($accounts, 200);
    }

    /**
     * Generate Live OAuth authorization URL with replay-protected state
     */
    public function getOAuthUrl(array $request): void
    {
        $userId = $request['user']['id'];
        $workspaceId = $request['workspace']['id'];
        $platform = strtolower(trim($request['query']['platform'] ?? ''));

        if (!in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            Response::error('Invalid or unsupported social media platform.', 422, [
                'platform' => ['Supported platforms: ' . implode(', ', self::SUPPORTED_PLATFORMS)]
            ]);
        }

        $adapter = AdapterFactory::create($platform, false);
        if (!$adapter->isConfigured()) {
            Response::error(
                "Live API credentials for " . ucfirst($platform) . " are not configured in server environment. Use 'Connect Sandbox' for testing.",
                400,
                ['is_configured' => false, 'platform' => $platform]
            );
        }

        $frontendUrl = Config::get('FRONTEND_URL', 'http://localhost:5173');
        $redirectUri = $request['query']['redirect_uri'] ?? "{$frontendUrl}/accounts/callback";

        // Generate cryptographically secure state token (64 hex characters)
        $rawState = EncryptionService::generateOpaqueToken();
        $stateHash = EncryptionService::hashToken($rawState);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO oauth_states (state_token_hash, user_id, workspace_id, platform, expires_at)
            VALUES (:hash, :uid, :wid, :platform, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
        ");
        $stmt->execute([
            ':hash'     => $stateHash,
            ':uid'      => $userId,
            ':wid'      => $workspaceId,
            ':platform' => $platform,
        ]);

        $oauthUrl = $adapter->getAuthorizationUrl($rawState, $redirectUri);

        Response::json([
            'platform'  => $platform,
            'oauth_url' => $oauthUrl,
            'state'     => $rawState,
        ], 200);
    }

    /**
     * Handle Live OAuth callback and token encryption
     */
    public function handleOAuthCallback(array $request): void
    {
        $userId = $request['user']['id'];
        $workspaceId = $request['workspace']['id'];
        $body = $request['body'] ?? [];

        $platform = strtolower(trim($body['platform'] ?? ''));
        $code = trim($body['code'] ?? '');
        $rawState = trim($body['state'] ?? '');
        $frontendUrl = Config::get('FRONTEND_URL', 'http://localhost:5173');
        $redirectUri = $body['redirect_uri'] ?? "{$frontendUrl}/accounts/callback";

        if (empty($platform) || empty($code) || empty($rawState)) {
            Response::error('Platform, authorization code, and state parameter are required.', 422);
        }

        // Validate state token hash & single use
        $stateHash = EncryptionService::hashToken($rawState);
        $pdo = Database::getConnection();

        $stmtState = $pdo->prepare("
            SELECT id, used_at, expires_at 
            FROM oauth_states 
            WHERE state_token_hash = :hash 
              AND user_id = :uid 
              AND workspace_id = :wid 
              AND platform = :platform
        ");
        $stmtState->execute([
            ':hash'     => $stateHash,
            ':uid'      => $userId,
            ':wid'      => $workspaceId,
            ':platform' => $platform,
        ]);
        $stateRecord = $stmtState->fetch();

        if (!$stateRecord || $stateRecord['used_at'] !== null) {
            Response::error('Invalid, expired, or already used OAuth state parameter.', 400);
        }

        // Mark state used immediately (replay defense)
        $stmtUse = $pdo->prepare("UPDATE oauth_states SET used_at = NOW() WHERE id = :id");
        $stmtUse->execute([':id' => $stateRecord['id']]);

        $adapter = AdapterFactory::create($platform, false);
        try {
            $accountData = $adapter->handleCallback($code, $redirectUri);
        } catch (Throwable $e) {
            Response::error('Failed to complete OAuth authorization with platform: ' . $e->getMessage(), 400);
        }

        // Encrypt credentials with AES-256-GCM
        $encAccessToken = EncryptionService::encrypt($accountData['access_token']);
        $encRefreshToken = !empty($accountData['refresh_token'])
            ? EncryptionService::encrypt($accountData['refresh_token'])
            : null;

        try {
            $pdo->beginTransaction();

            $stmtAcc = $pdo->prepare("
                INSERT INTO social_accounts (workspace_id, platform, account_id, account_name, username, avatar_url, account_type, status, is_sandbox, metadata)
                VALUES (:wid, :platform, :aid, :aname, :uname, :avatar, :atype, 'active', 0, :meta)
                ON DUPLICATE KEY UPDATE 
                    account_name = VALUES(account_name),
                    username = VALUES(username),
                    avatar_url = VALUES(avatar_url),
                    status = 'active',
                    is_sandbox = 0,
                    metadata = VALUES(metadata)
            ");
            $stmtAcc->execute([
                ':wid'      => $workspaceId,
                ':platform' => $platform,
                ':aid'      => $accountData['account_id'],
                ':aname'    => $accountData['account_name'],
                ':uname'    => $accountData['username'],
                ':avatar'   => $accountData['avatar_url'],
                ':atype'    => $accountData['account_type'],
                ':meta'     => json_encode($accountData['metadata'] ?? []),
            ]);

            // Get inserted or existing account ID
            $stmtGetId = $pdo->prepare("
                SELECT id FROM social_accounts 
                WHERE workspace_id = :wid AND platform = :platform AND account_id = :aid
            ");
            $stmtGetId->execute([
                ':wid'      => $workspaceId,
                ':platform' => $platform,
                ':aid'      => $accountData['account_id'],
            ]);
            $accId = (int)$stmtGetId->fetchColumn();

            // Store encrypted tokens
            $stmtToken = $pdo->prepare("
                INSERT INTO social_account_tokens (social_account_id, access_token_encrypted, refresh_token_encrypted, expires_at, scopes)
                VALUES (:aid, :enc_access, :enc_refresh, :expires_at, :scopes)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = VALUES(refresh_token_encrypted),
                    expires_at = VALUES(expires_at),
                    scopes = VALUES(scopes)
            ");
            $stmtToken->execute([
                ':aid'         => $accId,
                ':enc_access'  => $encAccessToken,
                ':enc_refresh' => $encRefreshToken,
                ':expires_at'  => $accountData['expires_at'],
                ':scopes'      => $accountData['scopes'],
            ]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to store account credentials: ' . $e->getMessage(), 500);
        }

        Response::json([
            'id'           => $accId,
            'platform'     => $platform,
            'account_name' => $accountData['account_name'],
            'username'     => $accountData['username'],
            'avatar_url'   => $accountData['avatar_url'],
            'account_type' => $accountData['account_type'],
            'is_sandbox'   => false,
            'status'       => 'active',
        ], 201, "Successfully connected {$accountData['account_name']} (Live).");
    }

    /**
     * Connect a simulated Sandbox account for development & testing
     */
    public function connectSandbox(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $body = $request['body'] ?? [];
        $platform = strtolower(trim($body['platform'] ?? ''));

        if (!in_array($platform, self::SUPPORTED_PLATFORMS, true)) {
            Response::error('Invalid or unsupported social media platform.', 422, [
                'platform' => ['Supported platforms: ' . implode(', ', self::SUPPORTED_PLATFORMS)]
            ]);
        }

        $adapter = AdapterFactory::create($platform, true);
        $mockData = $adapter->handleCallback('mock_code', '');

        // Override custom name/username if supplied
        if (!empty($body['account_name'])) {
            $mockData['account_name'] = trim($body['account_name']);
        }
        if (!empty($body['username'])) {
            $mockData['username'] = trim($body['username']);
        }

        $encAccessToken = EncryptionService::encrypt($mockData['access_token']);
        $encRefreshToken = EncryptionService::encrypt($mockData['refresh_token']);

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmtAcc = $pdo->prepare("
                INSERT INTO social_accounts (workspace_id, platform, account_id, account_name, username, avatar_url, account_type, status, is_sandbox, metadata)
                VALUES (:wid, :platform, :aid, :aname, :uname, :avatar, :atype, 'active', 1, :meta)
                ON DUPLICATE KEY UPDATE 
                    account_name = VALUES(account_name),
                    username = VALUES(username),
                    status = 'active',
                    is_sandbox = 1
            ");
            $stmtAcc->execute([
                ':wid'      => $workspaceId,
                ':platform' => $platform,
                ':aid'      => $mockData['account_id'],
                ':aname'    => $mockData['account_name'],
                ':uname'    => $mockData['username'],
                ':avatar'   => null,
                ':atype'    => $mockData['account_type'],
                ':meta'     => json_encode($mockData['metadata']),
            ]);

            $stmtGetId = $pdo->prepare("
                SELECT id FROM social_accounts 
                WHERE workspace_id = :wid AND platform = :platform AND account_id = :aid
            ");
            $stmtGetId->execute([
                ':wid'      => $workspaceId,
                ':platform' => $platform,
                ':aid'      => $mockData['account_id'],
            ]);
            $accId = (int)$stmtGetId->fetchColumn();

            $stmtToken = $pdo->prepare("
                INSERT INTO social_account_tokens (social_account_id, access_token_encrypted, refresh_token_encrypted, expires_at, scopes)
                VALUES (:aid, :enc_access, :enc_refresh, :expires_at, :scopes)
                ON DUPLICATE KEY UPDATE
                    access_token_encrypted = VALUES(access_token_encrypted),
                    refresh_token_encrypted = VALUES(refresh_token_encrypted),
                    expires_at = VALUES(expires_at)
            ");
            $stmtToken->execute([
                ':aid'         => $accId,
                ':enc_access'  => $encAccessToken,
                ':enc_refresh' => $encRefreshToken,
                ':expires_at'  => $mockData['expires_at'],
                ':scopes'      => $mockData['scopes'],
            ]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to connect sandbox account: ' . $e->getMessage(), 500);
        }

        Response::json([
            'id'           => $accId,
            'platform'     => $platform,
            'account_name' => $mockData['account_name'],
            'username'     => $mockData['username'],
            'avatar_url'   => null,
            'account_type' => $mockData['account_type'],
            'is_sandbox'   => true,
            'status'       => 'active',
        ], 201, "Connected {$mockData['account_name']} (Sandbox Mode).");
    }

    /**
     * Disconnect an account from the active workspace
     */
    public function disconnect(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $accountId = (int)($request['params']['id'] ?? 0);

        $pdo = Database::getConnection();

        // Verify account belongs to current workspace
        $stmtCheck = $pdo->prepare("
            SELECT id, platform, account_name, is_sandbox 
            FROM social_accounts 
            WHERE id = :aid AND workspace_id = :wid
        ");
        $stmtCheck->execute([
            ':aid' => $accountId,
            ':wid' => $workspaceId,
        ]);
        $account = $stmtCheck->fetch();

        if (!$account) {
            Response::error('Social account not found in this workspace.', 404);
        }

        try {
            $pdo->beginTransaction();

            // Delete tokens
            $stmtDelToken = $pdo->prepare("DELETE FROM social_account_tokens WHERE social_account_id = :aid");
            $stmtDelToken->execute([':aid' => $accountId]);

            // Update account status to disconnected
            $stmtUpdate = $pdo->prepare("UPDATE social_accounts SET status = 'disconnected' WHERE id = :aid");
            $stmtUpdate->execute([':aid' => $accountId]);

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to disconnect account: ' . $e->getMessage(), 500);
        }

        Response::json([
            'id'       => $accountId,
            'status'   => 'disconnected',
        ], 200, "Disconnected {$account['account_name']} successfully.");
    }
}
