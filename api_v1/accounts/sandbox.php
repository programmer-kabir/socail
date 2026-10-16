<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../adapters/AdapterFactory.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$input = get_json_input();
$platform = strtolower(trim((string)($input['platform'] ?? '')));

$supported = ['facebook', 'instagram', 'twitter', 'tiktok', 'youtube'];
if (!in_array($platform, $supported, true)) {
    json_error('Invalid platform. Supported: ' . implode(', ', $supported), 422);
}

$adapter = AdapterFactory::create($platform, true);
$mockData = $adapter->handleCallback('mock_code', '');

if (!empty($input['account_name'])) {
    $mockData['account_name'] = trim((string)$input['account_name']);
}
if (!empty($input['username'])) {
    $mockData['username'] = trim((string)$input['username']);
}

$encAccessToken = Security::encrypt($mockData['access_token']);
$encRefreshToken = Security::encrypt($mockData['refresh_token']);

$pdo = get_db();

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

    $stmtGetId = $pdo->prepare("SELECT id FROM social_accounts WHERE workspace_id = :wid AND platform = :platform AND account_id = :aid");
    $stmtGetId->execute([':wid' => $workspaceId, ':platform' => $platform, ':aid' => $mockData['account_id']]);
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
        ':expires_at'  => $mockData['expires_at'],
        ':scopes'      => $mockData['scopes'],
    ]);

    $pdo->commit();

    json_success([
        'id'           => $accId,
        'platform'     => $platform,
        'account_name' => $mockData['account_name'],
        'username'     => $mockData['username'],
        'account_type' => $mockData['account_type'],
        'is_sandbox'   => true,
        'status'       => 'active',
    ], "Successfully connected {$mockData['account_name']} (Sandbox).", 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('Failed to connect sandbox account: ' . $e->getMessage(), 500);
}
