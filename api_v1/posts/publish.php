<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/PublishingService.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$input = get_json_input();
$postId = (int)($input['post_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));

if ($postId <= 0) {
    json_error('Post ID is required.', 422);
}

$pdo = get_db();
$stmt = $pdo->prepare("SELECT id, status FROM posts WHERE id = :id AND workspace_id = :wid");
$stmt->execute([':id' => $postId, ':wid' => $workspaceId]);
$post = $stmt->fetch();

if (!$post) {
    json_error('Post not found in active workspace.', 404);
}

$publishingService = new PublishingService();
$results = $publishingService->publishPostNow($postId);

json_success($results, 'Post published successfully.');
