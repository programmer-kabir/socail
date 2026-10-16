<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$input = get_json_input();
$postId = (int)($input['post_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));

if ($postId <= 0) {
    json_error('Post ID is required.', 422);
}

$pdo = get_db();
$stmt = $pdo->prepare("
    UPDATE posts 
    SET status = 'draft', scheduled_at = NULL, updated_at = NOW() 
    WHERE id = :id AND workspace_id = :wid AND status = 'scheduled'
");
$stmt->execute([':id' => $postId, ':wid' => $workspaceId]);

if ($stmt->rowCount() === 0) {
    json_error('Scheduled post not found or not in scheduled state.', 400);
}

json_success(['id' => $postId, 'status' => 'draft'], 'Post schedule cancelled and reverted to draft.');
