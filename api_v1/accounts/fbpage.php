<?php
declare(strict_types=1);

/**
 * SocialFlow API v1 - Facebook Page Management
 * Handles connecting Facebook Pages, selecting active pages, and updating page tokens.
 */

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // List Facebook Pages connected in this workspace
    $stmt = $pdo->prepare("
        SELECT id, account_id, account_name, username, avatar_url, account_type, status, is_sandbox, created_at
        FROM social_accounts
        WHERE workspace_id = :wid AND platform = 'facebook' AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute([':wid' => $workspaceId]);
    $pages = $stmt->fetchAll();

    json_success($pages, 'Facebook pages loaded.');
}

if ($method === 'POST') {
    $input = get_json_input();
    $pageId = trim((string)($input['page_id'] ?? $input['account_id'] ?? ''));
    $pageName = trim((string)($input['page_name'] ?? $input['account_name'] ?? 'Facebook Page'));
    $pageAccessToken = trim((string)($input['access_token'] ?? ''));
    $isSandbox = !empty($input['is_sandbox']) ? 1 : 0;

    if (empty($pageId)) {
        json_error('Page ID is required.', 422, ['page_id' => ['Page ID is required.']]);
    }

    try {
        $pdo->beginTransaction();

        $stmtAcc = $pdo->prepare("
            INSERT INTO social_accounts (workspace_id, platform, account_id, account_name, username, avatar_url, account_type, status, is_sandbox, metadata)
            VALUES (:wid, 'facebook', :aid, :aname, :uname, :avatar, 'page', 'active', :is_sb, :meta)
            ON DUPLICATE KEY UPDATE 
                account_name = VALUES(account_name),
                status = 'active',
                updated_at = NOW()
        ");
        $stmtAcc->execute([
            ':wid'    => $workspaceId,
            ':aid'    => $pageId,
            ':aname'  => $pageName,
            ':uname'  => $input['username'] ?? $pageName,
            ':avatar' => $input['avatar_url'] ?? null,
            ':is_sb'  => $isSandbox,
            ':meta'   => json_encode($input['metadata'] ?? []),
        ]);

        $stmtGetId = $pdo->prepare("SELECT id FROM social_accounts WHERE workspace_id = :wid AND platform = 'facebook' AND account_id = :aid");
        $stmtGetId->execute([':wid' => $workspaceId, ':aid' => $pageId]);
        $accId = (int)$stmtGetId->fetchColumn();

        if (!empty($pageAccessToken)) {
            $encToken = Security::encrypt($pageAccessToken);
            $stmtToken = $pdo->prepare("
                INSERT INTO social_account_tokens (social_account_id, access_token_encrypted, scopes)
                VALUES (:aid, :enc, 'pages_manage_posts,pages_read_engagement')
                ON DUPLICATE KEY UPDATE access_token_encrypted = VALUES(access_token_encrypted)
            ");
            $stmtToken->execute([':aid' => $accId, ':enc' => $encToken]);
        }

        $pdo->commit();

        json_success([
            'id'           => $accId,
            'platform'     => 'facebook',
            'account_id'   => $pageId,
            'account_name' => $pageName,
            'status'       => 'active',
        ], 'Facebook page connected successfully.', 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('Failed to connect Facebook page: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed.', 405);
