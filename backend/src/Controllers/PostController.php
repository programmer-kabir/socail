<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Adapters/AdapterFactory.php';
require_once __DIR__ . '/../Services/PublishingService.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Post Management & Composer Controller
 */
class PostController
{
    /**
     * List posts for the active workspace with filtering
     */
    public function index(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $status = $request['query']['status'] ?? null;
        $search = trim($request['query']['search'] ?? '');
        $page = max(1, (int)($request['query']['page'] ?? 1));
        $perPage = max(1, min(100, (int)($request['query']['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $pdo = Database::getConnection();

        $where = ["p.workspace_id = :wid"];
        $params = [':wid' => $workspaceId];

        if (!empty($status) && in_array($status, ['draft', 'scheduled', 'publishing', 'published', 'partially_published', 'failed'], true)) {
            $where[] = "p.status = :status";
            $params[':status'] = $status;
        }

        if (!empty($search)) {
            $where[] = "p.default_caption LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Count total matching
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereClause}");
        $stmtCount->execute($params);
        $total = (int)$stmtCount->fetchColumn();

        // Fetch posts
        $stmtPosts = $pdo->prepare("
            SELECT p.id, p.workspace_id, p.author_id, p.default_caption, p.status, 
                   p.scheduled_at, p.published_at, p.created_at, p.updated_at,
                   u.name as author_name, u.avatar_url as author_avatar
            FROM posts p
            JOIN users u ON p.author_id = u.id
            WHERE {$whereClause}
            ORDER BY p.created_at DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $stmtPosts->execute($params);
        $posts = $stmtPosts->fetchAll();

        // Attach targets and media to each post
        foreach ($posts as &$post) {
            $pid = (int)$post['id'];

            // Targets
            $stmtT = $pdo->prepare("
                SELECT pt.id, pt.social_account_id, pt.custom_caption, pt.status, 
                       pt.platform_post_id, pt.platform_post_url, pt.error_message,
                       sa.platform, sa.account_name, sa.username, sa.is_sandbox
                FROM post_targets pt
                JOIN social_accounts sa ON pt.social_account_id = sa.id
                WHERE pt.post_id = :pid
            ");
            $stmtT->execute([':pid' => $pid]);
            $targets = $stmtT->fetchAll();
            foreach ($targets as &$t) {
                $t['is_sandbox'] = (bool)$t['is_sandbox'];
            }
            $post['targets'] = $targets;

            // Media
            $stmtM = $pdo->prepare("
                SELECT id, storage_filename, original_filename, mime_type, file_size_bytes, media_type, display_order
                FROM post_media
                WHERE post_id = :pid
                ORDER BY display_order ASC
            ");
            $stmtM->execute([':pid' => $pid]);
            $media = $stmtM->fetchAll();
            foreach ($media as &$m) {
                $m['preview_url'] = "/api/media/file/{$m['storage_filename']}";
            }
            $post['media'] = $media;
        }

        Response::json([
            'posts' => $posts,
            'meta'  => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int)ceil($total / $perPage),
            ],
        ], 200);
    }

    /**
     * Create a post (Draft, Scheduled, or Immediate Publish)
     */
    public function create(array $request): void
    {
        $userId = $request['user']['id'];
        $workspaceId = $request['workspace']['id'];
        $body = $request['body'] ?? [];

        $caption = trim($body['caption'] ?? '');
        $requestedStatus = strtolower(trim($body['status'] ?? 'draft')); // 'draft' | 'scheduled' | 'publish_now'
        $targetAccountIds = array_filter(array_map('intval', (array)($body['target_account_ids'] ?? [])));
        $customCaptions = (array)($body['custom_captions'] ?? []);
        $mediaIds = array_filter(array_map('intval', (array)($body['media_ids'] ?? [])));
        $scheduledAt = !empty($body['scheduled_at']) ? trim($body['scheduled_at']) : null;

        if (empty($caption) && empty($mediaIds)) {
            Response::error('Post must contain a caption or at least one media attachment.', 422, [
                'caption' => ['Caption or media is required.']
            ]);
        }

        $pdo = Database::getConnection();

        // 1. Verify target accounts belong to this active workspace
        $accounts = [];
        if (!empty($targetAccountIds)) {
            $inClause = implode(',', $targetAccountIds);
            $stmtAcc = $pdo->prepare("
                SELECT id, platform, account_name, is_sandbox, status 
                FROM social_accounts 
                WHERE workspace_id = :wid AND id IN ({$inClause})
            ");
            $stmtAcc->execute([':wid' => $workspaceId]);
            $accounts = $stmtAcc->fetchAll();

            if (count($accounts) !== count($targetAccountIds)) {
                Response::error('One or more selected social accounts do not belong to this workspace.', 403);
            }
        } elseif ($requestedStatus !== 'draft') {
            Response::error('At least one social account must be selected to schedule or publish a post.', 422, [
                'target_account_ids' => ['Select at least one social account.']
            ]);
        }

        // 2. Verify media files belong to this active workspace
        $mediaFiles = [];
        if (!empty($mediaIds)) {
            $inMedia = implode(',', $mediaIds);
            $stmtMed = $pdo->prepare("
                SELECT id, storage_filename, original_filename, mime_type, file_size_bytes, media_type 
                FROM post_media 
                WHERE workspace_id = :wid AND id IN ({$inMedia})
            ");
            $stmtMed->execute([':wid' => $workspaceId]);
            $mediaFiles = $stmtMed->fetchAll();

            if (count($mediaFiles) !== count($mediaIds)) {
                Response::error('One or more media attachments do not belong to this workspace.', 403);
            }
        }

        // 3. Platform Validation (Only strict if scheduling or publishing now)
        $validationErrors = [];
        if ($requestedStatus !== 'draft') {
            foreach ($accounts as $acc) {
                $accId = (int)$acc['id'];
                $platCaption = !empty($customCaptions[$accId]) ? trim($customCaptions[$accId]) : $caption;
                $adapter = AdapterFactory::create($acc['platform'], (bool)$acc['is_sandbox']);
                $errs = $adapter->validatePost($platCaption, $mediaFiles);
                if (!empty($errs)) {
                    $validationErrors[$acc['account_name']] = $errs;
                }
            }

            if (!empty($validationErrors)) {
                Response::error('Validation failed for one or more target platforms.', 422, $validationErrors);
            }
        }

        // Determine DB post status
        $wsTimezone = $request['workspace']['timezone'] ?? 'UTC';
        $dbStatus = 'draft';
        $schedUtc = null;
        if ($requestedStatus === 'scheduled') {
            if (empty($scheduledAt)) {
                Response::error('A valid future schedule date and time is required.', 422, [
                    'scheduled_at' => ['Date and time are required for scheduling.']
                ]);
            }
            $parsedSched = $this->parseScheduledTime($scheduledAt, $wsTimezone);
            if (!$parsedSched || $parsedSched['timestamp'] <= time()) {
                Response::error('Scheduled time must be in the future.', 422, [
                    'scheduled_at' => ['Scheduled time must be set in the future.']
                ]);
            }
            $schedUtc = $parsedSched['utc'];
            $dbStatus = 'scheduled';
        } elseif ($requestedStatus === 'publish_now') {
            $dbStatus = 'publishing';
        }

        try {
            $pdo->beginTransaction();

            // Insert Post
            $stmtPost = $pdo->prepare("
                INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
                VALUES (:wid, :uid, :caption, :status, :sched)
            ");
            $stmtPost->execute([
                ':wid'     => $workspaceId,
                ':uid'     => $userId,
                ':caption' => $caption,
                ':status'  => $dbStatus,
                ':sched'   => $schedUtc,
            ]);
            $postId = (int)$pdo->lastInsertId();

            // Link Media Files
            if (!empty($mediaIds)) {
                $inMedia = implode(',', $mediaIds);
                $stmtLinkMedia = $pdo->prepare("
                    UPDATE post_media 
                    SET post_id = :pid 
                    WHERE workspace_id = :wid AND id IN ({$inMedia})
                ");
                $stmtLinkMedia->execute([':pid' => $postId, ':wid' => $workspaceId]);
            }

            // Create Post Targets
            foreach ($accounts as $acc) {
                $accId = (int)$acc['id'];
                $platCaption = !empty($customCaptions[$accId]) ? trim($customCaptions[$accId]) : null;
                $idempotencyKey = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
                );

                $stmtTarget = $pdo->prepare("
                    INSERT INTO post_targets (post_id, social_account_id, custom_caption, idempotency_key, status)
                    VALUES (:pid, :aid, :caption, :ikey, 'pending')
                ");
                $stmtTarget->execute([
                    ':pid'     => $postId,
                    ':aid'     => $accId,
                    ':caption' => $platCaption,
                    ':ikey'    => $idempotencyKey,
                ]);
            }

            // If scheduled, queue job into scheduled_jobs
            if ($dbStatus === 'scheduled' && $schedUtc) {
                $stmtJob = $pdo->prepare("
                    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
                    VALUES (:wid, :pid, :run_at, 'queued')
                    ON DUPLICATE KEY UPDATE run_at = VALUES(run_at), status = 'queued', attempts = 0, locked_at = NULL, locked_by = NULL, last_error = NULL
                ");
                $stmtJob->execute([
                    ':wid'    => $workspaceId,
                    ':pid'    => $postId,
                    ':run_at' => $schedUtc,
                ]);
            }

            $pdo->commit();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to create post: ' . $e->getMessage(), 500);
        }

        // If requested immediate publishing, dispatch right away
        $publishResult = null;
        if ($requestedStatus === 'publish_now') {
            $publishResult = PublishingService::publishPostImmediate($postId, $workspaceId);
        }

        // Fetch created post record
        $stmtShow = $pdo->prepare("
            SELECT p.*, u.name as author_name 
            FROM posts p 
            JOIN users u ON p.author_id = u.id 
            WHERE p.id = :id
        ");
        $stmtShow->execute([':id' => $postId]);
        $postData = $stmtShow->fetch();

        Response::json([
            'post'           => $postData,
            'publish_result' => $publishResult,
        ], 201, $requestedStatus === 'publish_now' ? 'Post published.' : ($requestedStatus === 'scheduled' ? 'Post scheduled successfully.' : 'Draft saved.'));
    }

    /**
     * Show post details, targets, media attachments, and publishing audit logs
     */
    public function show(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $postId = (int)($request['params']['id'] ?? 0);

        $pdo = Database::getConnection();

        $stmtPost = $pdo->prepare("
            SELECT p.*, u.name as author_name 
            FROM posts p 
            JOIN users u ON p.author_id = u.id 
            WHERE p.id = :id AND p.workspace_id = :wid
        ");
        $stmtPost->execute([':id' => $postId, ':wid' => $workspaceId]);
        $post = $stmtPost->fetch();

        if (!$post) {
            Response::error('Post not found in this workspace.', 404);
        }

        // Targets
        $stmtT = $pdo->prepare("
            SELECT pt.*, sa.platform, sa.account_name, sa.username, sa.is_sandbox
            FROM post_targets pt
            JOIN social_accounts sa ON pt.social_account_id = sa.id
            WHERE pt.post_id = :pid
        ");
        $stmtT->execute([':pid' => $postId]);
        $targets = $stmtT->fetchAll();
        foreach ($targets as &$t) {
            $t['is_sandbox'] = (bool)$t['is_sandbox'];
        }

        // Media
        $stmtM = $pdo->prepare("
            SELECT id, storage_filename, original_filename, mime_type, file_size_bytes, media_type, display_order
            FROM post_media
            WHERE post_id = :pid
            ORDER BY display_order ASC
        ");
        $stmtM->execute([':pid' => $postId]);
        $media = $stmtM->fetchAll();
        foreach ($media as &$m) {
            $m['preview_url'] = "/api/media/file/{$m['storage_filename']}";
        }

        // Publishing Logs
        $stmtLogs = $pdo->prepare("
            SELECT pl.*, sa.platform, sa.account_name
            FROM publishing_logs pl
            JOIN social_accounts sa ON pl.social_account_id = sa.id
            WHERE pl.post_id = :pid AND pl.workspace_id = :wid
            ORDER BY pl.created_at DESC
        ");
        $stmtLogs->execute([':pid' => $postId, ':wid' => $workspaceId]);
        $logs = $stmtLogs->fetchAll();
        foreach ($logs as &$l) {
            $l['is_simulated'] = (bool)$l['is_simulated'];
        }

        Response::json([
            'post'    => $post,
            'targets' => $targets,
            'media'   => $media,
            'logs'    => $logs,
        ], 200);
    }

    /**
     * Update post draft or reschedule
     */
    public function update(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $postId = (int)($request['params']['id'] ?? 0);
        $body = $request['body'] ?? [];

        $pdo = Database::getConnection();

        $stmtPost = $pdo->prepare("
            SELECT id, status, default_caption, scheduled_at FROM posts WHERE id = :id AND workspace_id = :wid
        ");
        $stmtPost->execute([':id' => $postId, ':wid' => $workspaceId]);
        $post = $stmtPost->fetch();

        if (!$post) {
            Response::error('Post not found in this workspace.', 404);
        }

        $caption = trim($body['caption'] ?? $post['default_caption']);
        $scheduledAt = isset($body['scheduled_at']) ? trim($body['scheduled_at']) : $post['scheduled_at'];
        $status = strtolower(trim($body['status'] ?? $post['status']));

        $wsTimezone = $request['workspace']['timezone'] ?? 'UTC';
        $schedUtc = null;
        if ($status === 'scheduled') {
            if (empty($scheduledAt)) {
                Response::error('A valid future schedule date and time is required.', 422, [
                    'scheduled_at' => ['Date and time are required for scheduling.']
                ]);
            }
            $parsedSched = $this->parseScheduledTime($scheduledAt, $wsTimezone);
            if (!$parsedSched || $parsedSched['timestamp'] <= time()) {
                Response::error('Scheduled time must be in the future.', 422, [
                    'scheduled_at' => ['Scheduled time must be set in the future.']
                ]);
            }
            $schedUtc = $parsedSched['utc'];
        } elseif ($status === 'draft') {
            $schedUtc = null;
        } else {
            $schedUtc = $post['scheduled_at'];
        }

        $pdo->beginTransaction();
        try {

            $stmtUp = $pdo->prepare("
                UPDATE posts 
                SET default_caption = :caption,
                    status = :status,
                    scheduled_at = :sched
                WHERE id = :id AND workspace_id = :wid
            ");
            $stmtUp->execute([
                ':caption' => $caption,
                ':status'  => $status,
                ':sched'   => $schedUtc,
                ':id'      => $postId,
                ':wid'     => $workspaceId,
            ]);

            if ($status === 'scheduled' && $schedUtc) {
                $stmtJob = $pdo->prepare("
                    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
                    VALUES (:wid, :pid, :run_at, 'queued')
                    ON DUPLICATE KEY UPDATE run_at = VALUES(run_at), status = 'queued', attempts = 0, locked_at = NULL, locked_by = NULL, last_error = NULL
                ");
                $stmtJob->execute([
                    ':wid'    => $workspaceId,
                    ':pid'    => $postId,
                    ':run_at' => $schedUtc,
                ]);
            } elseif ($status === 'draft') {
                $stmtDelJob = $pdo->prepare("DELETE FROM scheduled_jobs WHERE post_id = :pid AND workspace_id = :wid");
                $stmtDelJob->execute([':pid' => $postId, ':wid' => $workspaceId]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update post: ' . $e->getMessage(), 500);
        }

        Response::json(['id' => $postId, 'status' => $status, 'scheduled_at' => $schedUtc ?? null], 200, 'Post updated successfully.');
    }

    /**
     * Cancel a scheduled post and revert it safely to draft without deleting targets or media
     */
    public function cancelSchedule(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $postId = (int)($request['params']['id'] ?? 0);

        $pdo = Database::getConnection();
        $stmtPost = $pdo->prepare("SELECT id, status FROM posts WHERE id = :id AND workspace_id = :wid");
        $stmtPost->execute([':id' => $postId, ':wid' => $workspaceId]);
        $post = $stmtPost->fetch();

        if (!$post) {
            Response::error('Post not found in this workspace.', 404);
        }

        if ($post['status'] !== 'scheduled') {
            Response::error('Only scheduled posts can have their schedule cancelled.', 422);
        }

        $pdo->beginTransaction();
        try {
            $stmtUp = $pdo->prepare("
                UPDATE posts 
                SET status = 'draft', 
                    scheduled_at = NULL 
                WHERE id = :id AND workspace_id = :wid
            ");
            $stmtUp->execute([':id' => $postId, ':wid' => $workspaceId]);

            $stmtJob = $pdo->prepare("
                DELETE FROM scheduled_jobs 
                WHERE post_id = :pid AND workspace_id = :wid
            ");
            $stmtJob->execute([':pid' => $postId, ':wid' => $workspaceId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to cancel scheduled post: ' . $e->getMessage(), 500);
        }

        Response::json(['id' => $postId, 'status' => 'draft'], 200, 'Scheduled post reverted to draft.');
    }

    /**
     * Delete post and clean up relationships
     */
    public function delete(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $postId = (int)($request['params']['id'] ?? 0);

        $pdo = Database::getConnection();

        $stmtPost = $pdo->prepare("SELECT id FROM posts WHERE id = :id AND workspace_id = :wid");
        $stmtPost->execute([':id' => $postId, ':wid' => $workspaceId]);
        if (!$stmtPost->fetch()) {
            Response::error('Post not found in this workspace.', 404);
        }

        // Delete post (foreign keys cascade to post_targets and post_media post_id is set null or deleted)
        $stmtDel = $pdo->prepare("DELETE FROM posts WHERE id = :id AND workspace_id = :wid");
        $stmtDel->execute([':id' => $postId, ':wid' => $workspaceId]);

        Response::json(['id' => $postId], 200, 'Post deleted successfully.');
    }

    /**
     * Trigger immediate publishing for an existing post
     */
    public function publish(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $postId = (int)($request['params']['id'] ?? 0);

        $result = PublishingService::publishPostImmediate($postId, $workspaceId);

        Response::json($result, 200, 'Publishing execution completed.');
    }

    /**
     * Parse and convert a localized scheduled time to UTC timestamp and formatted UTC string
     */
    private function parseScheduledTime(?string $scheduledAt, string $workspaceTimezone): ?array
    {
        if (empty($scheduledAt)) {
            return null;
        }

        try {
            $wsTz = new DateTimeZone($workspaceTimezone ?: 'UTC');
        } catch (Throwable) {
            $wsTz = new DateTimeZone('UTC');
        }

        try {
            if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $scheduledAt)) {
                $dt = new DateTime($scheduledAt);
            } else {
                $dt = new DateTime($scheduledAt, $wsTz);
            }
            $dt->setTimezone(new DateTimeZone('UTC'));
            return [
                'timestamp' => $dt->getTimestamp(),
                'utc'       => $dt->format('Y-m-d H:i:s'),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
