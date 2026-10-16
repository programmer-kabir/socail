<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();

$stmtLogs = $pdo->prepare("
    SELECT 
        pl.id, 
        pl.post_id, 
        pl.is_simulated, 
        pl.action, 
        pl.status, 
        pl.message, 
        pl.created_at,
        sa.platform, 
        sa.account_name, 
        sa.is_sandbox as account_is_sandbox,
        p.default_caption
    FROM publishing_logs pl
    JOIN social_accounts sa ON pl.social_account_id = sa.id
    JOIN posts p ON pl.post_id = p.id
    WHERE pl.workspace_id = :wid
    ORDER BY pl.created_at DESC
    LIMIT 10
");
$stmtLogs->execute([':wid' => $workspaceId]);
$logs = $stmtLogs->fetchAll();

foreach ($logs as &$log) {
    $log['is_simulated'] = (bool)$log['is_simulated'];
    $log['account_is_sandbox'] = (bool)$log['account_is_sandbox'];
}

json_success($logs);
