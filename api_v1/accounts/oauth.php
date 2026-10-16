<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../adapters/AdapterFactory.php';

$user = require_auth();
$userId = (int)$user['id'];
$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Generate OAuth URL
    $platform = strtolower(trim((string)($_GET['platform'] ?? '')));
    $supported = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];

    if (!in_array($platform, $supported, true)) {
        json_error('Invalid platform. Supported: ' . implode(', ', $supported), 422);
    }

    $adapter = AdapterFactory::create($platform, false);
    if (!$adapter->isConfigured()) {
        json_error("Live API credentials for " . ucfirst($platform) . " are not configured. Use Sandbox instead.", 400, [
            'is_configured' => false,
            'platform' => $platform
        ]);
    }

    $frontendUrl = (string)Config::get('FRONTEND_URL', 'http://localhost:5173');
    $redirectUri = (string)($_GET['redirect_uri'] ?? "{$frontendUrl}/accounts/callback");

    $stateToken = bin2hex(random_bytes(32));
    $stateHash = hash('sha256', $stateToken);

    $stmtState = $pdo->prepare("
        INSERT INTO oauth_states (user_id, workspace_id, platform, state_token_hash, redirect_uri, expires_at)
        VALUES (:uid, :wid, :platform, :hash, :redir, DATE_ADD(NOW(), INTERVAL 15 MINUTE))
    ");
    $stmtState->execute([
        ':uid'      => $userId,
        ':wid'      => $workspaceId,
        ':platform' => $platform,
        ':hash'     => $stateHash,
        ':redir'    => $redirectUri,
    ]);

    $authUrl = $adapter->getAuthorizationUrl($stateToken, $redirectUri);
    json_success(['authorization_url' => $authUrl, 'oauth_url' => $authUrl, 'state' => $stateToken]);
}

if ($method === 'POST') {
    // OAuth Callback handler
    $input = get_json_input();
    $platform = strtolower(trim((string)($input['platform'] ?? '')));
    $code = (string)($input['code'] ?? '');
    $stateToken = (string)($input['state'] ?? '');
    $redirectUri = (string)($input['redirect_uri'] ?? '');

    if (empty($code) || empty($stateToken) || empty($platform)) {
        json_error('Missing required OAuth callback parameters.', 422);
    }

    $stateHash = hash('sha256', $stateToken);
    $stmtState = $pdo->prepare("
        SELECT id, workspace_id, redirect_uri, used_at, expires_at 
        FROM oauth_states 
        WHERE state_token_hash = :hash AND user_id = :uid AND platform = :platform
    ");
    $stmtState->execute([
        ':hash'     => $stateHash,
        ':uid'      => $userId,
        ':platform' => $platform,
    ]);
    $stateRecord = $stmtState->fetch();

    if (!$stateRecord || $stateRecord['used_at'] !== null) {
        json_error('Invalid or expired OAuth state.', 400);
    }

    $workspaceId = (int)$stateRecord['workspace_id'];

    // Mark used
    $pdo->prepare("UPDATE oauth_states SET used_at = NOW() WHERE id = :id")->execute([':id' => $stateRecord['id']]);

    $adapter = AdapterFactory::create($platform, false);
    try {
        $accountData = $adapter->handleCallback($code, $redirectUri ?: $stateRecord['redirect_uri']);
    } catch (Throwable $e) {
        json_error('Failed OAuth authorization: ' . $e->getMessage(), 400);
    }

    $encAccessToken = Security::encrypt($accountData['access_token']);
    $encRefreshToken = !empty($accountData['refresh_token']) ? Security::encrypt($accountData['refresh_token']) : null;

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

        $stmtGetId = $pdo->prepare("SELECT id FROM social_accounts WHERE workspace_id = :wid AND platform = :platform AND account_id = :aid");
        $stmtGetId->execute([':wid' => $workspaceId, ':platform' => $platform, ':aid' => $accountData['account_id']]);
        $accId = (int)$stmtGetId->fetchColumn();

        $stmtToken = $pdo->prepare("
            INSERT INTO social_account_tokens (social_account_id, access_token_encrypted, refresh_token_encrypted, expires_at, scopes)
            VALUES (:aid, :enc_access, :enc_refresh, :expires_at, :scopes)
            ON DUPLICATE KEY UPDATE
                access_token_encrypted = VALUES(access_token_encrypted),
                refresh_token_encrypted = VALUES(refresh_token_encrypted)
        ");
        $stmtToken->execute([
            ':aid'         => $accId,
            ':enc_access'  => $encAccessToken,
            ':enc_refresh' => $encRefreshToken,
            ':expires_at'  => $accountData['expires_at'],
            ':scopes'      => $accountData['scopes'],
        ]);

        $pdo->commit();

        json_success([
            'id'           => $accId,
            'platform'     => $platform,
            'account_name' => $accountData['account_name'],
            'username'     => $accountData['username'],
            'avatar_url'   => $accountData['avatar_url'],
            'account_type' => $accountData['account_type'],
            'is_sandbox'   => false,
            'status'       => 'active',
        ], "Successfully connected {$accountData['account_name']} (Live).", 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('Failed to store account credentials: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed.', 405);
