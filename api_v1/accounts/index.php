<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();

$stmt = $pdo->prepare("
    SELECT id, platform, account_id, account_name, username, avatar_url, 
           account_type, status, is_sandbox, created_at, updated_at
    FROM social_accounts
    WHERE workspace_id = :wid AND status != 'disconnected'
    ORDER BY platform ASC, created_at DESC
");
$stmt->execute([':wid' => $workspaceId]);
$accounts = $stmt->fetchAll();

foreach ($accounts as &$acc) {
    $acc['is_sandbox'] = (bool)$acc['is_sandbox'];
}

json_success($accounts);
