<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../services/PublishingService.php';

$user = require_auth();
$userId = (int)$user['id'];
$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];

$body = get_json_input();
$caption = trim((string)($body['caption'] ?? ''));
$requestedStatus = strtolower(trim((string)($body['status'] ?? 'draft')));
$targetAccountIds = array_filter(array_map('intval', (array)($body['target_account_ids'] ?? [])));
$customCaptions = (array)($body['custom_captions'] ?? []);
$mediaIds = array_filter(array_map('intval', (array)($body['media_ids'] ?? [])));
$scheduledAt = !empty($body['scheduled_at']) ? (string)$body['scheduled_at'] : null;

$postStatus = 'draft';
if ($requestedStatus === 'scheduled') {
    if (empty($scheduledAt)) {
        json_error('A valid scheduled date and time is required for scheduling.', 422);
    }
    $postStatus = 'scheduled';
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $stmtPost = $pdo->prepare("
        INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
        VALUES (:wid, :author, :caption, :status, :scheduled)
    ");
    $stmtPost->execute([
        ':wid'       => $workspaceId,
        ':author'    => $userId,
        ':caption'   => $caption,
        ':status'    => $postStatus,
        ':scheduled' => $scheduledAt,
    ]);
    $newPostId = (int)$pdo->lastInsertId();

    // Attach targets
    if (!empty($targetAccountIds)) {
        $stmtTarget = $pdo->prepare("
            INSERT INTO post_targets (post_id, social_account_id, custom_caption, status)
            VALUES (:pid, :aid, :custom, 'pending')
        ");
        foreach ($targetAccountIds as $accId) {
            $custom = $customCaptions[(string)$accId] ?? null;
            $stmtTarget->execute([
                ':pid'    => $newPostId,
                ':aid'    => $accId,
                ':custom' => $custom,
            ]);
        }
    }

    // Attach media
    if (!empty($mediaIds)) {
        $stmtMedia = $pdo->prepare("
            UPDATE post_media 
            SET post_id = :pid, display_order = :order 
            WHERE id = :mid AND workspace_id = :wid
        ");
        $order = 0;
        foreach ($mediaIds as $mid) {
            $stmtMedia->execute([
                ':pid'   => $newPostId,
                ':order' => $order++,
                ':mid'   => $mid,
                ':wid'   => $workspaceId,
            ]);
        }
    }

    $pdo->commit();

    // Instant publish if requested
    if ($requestedStatus === 'publish_now') {
        $publishingService = new PublishingService();
        $results = $publishingService->publishPostNow($newPostId);
        json_success([
            'id'      => $newPostId,
            'status'  => 'published',
            'results' => $results,
        ], 'Post published successfully.', 201);
    }

    json_success([
        'id'           => $newPostId,
        'status'       => $postStatus,
        'scheduled_at' => $scheduledAt,
    ], 'Post saved successfully.', 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('Failed to create post: ' . $e->getMessage(), 500);
}
