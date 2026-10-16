<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$input = get_json_input();
$accountId = (int)($input['account_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));

if ($accountId <= 0) {
    json_error('Account ID is required.', 422);
}

$pdo = get_db();
$stmt = $pdo->prepare("
    UPDATE social_accounts 
    SET status = 'disconnected', updated_at = NOW() 
    WHERE id = :id AND workspace_id = :wid
");
$stmt->execute([':id' => $accountId, ':wid' => $workspaceId]);

if ($stmt->rowCount() === 0) {
    json_error('Account not found in active workspace.', 404);
}

json_success(null, 'Social account successfully disconnected.');
