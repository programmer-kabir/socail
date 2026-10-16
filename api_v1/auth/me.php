<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$user = require_auth();
$userId = $user['id'];
$pdo = get_db();

// Workspaces
$stmtWs = $pdo->prepare("
    SELECT w.id, w.name, w.slug, w.timezone, wm.role
    FROM workspaces w
    JOIN workspace_members wm ON w.id = wm.workspace_id
    WHERE wm.user_id = :uid
    ORDER BY w.id ASC
");
$stmtWs->execute([':uid' => $userId]);
$workspaces = $stmtWs->fetchAll();

// Active workspace
$activeWorkspace = null;
$requestedWsId = get_header('X-Workspace-Id');
if ($requestedWsId) {
    foreach ($workspaces as $w) {
        if ((int)$w['id'] === (int)$requestedWsId) {
            $activeWorkspace = $w;
            break;
        }
    }
}
if (!$activeWorkspace && !empty($workspaces)) {
    $activeWorkspace = $workspaces[0];
}

json_success([
    'user'             => $user,
    'active_workspace' => $activeWorkspace,
    'workspaces'       => $workspaces,
], 'User profile loaded.');
