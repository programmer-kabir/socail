<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Content Calendar Controller
 * Delivers month, week, and day range queries with workspace multi-tenant isolation.
 */
class CalendarController
{
    /**
     * Retrieve calendar posts within a specified date window with platform and status filtering
     */
    public function index(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $query = $request['query'] ?? [];

        // Parse date bounds (defaults to current month)
        $startStr = $query['start'] ?? date('Y-m-01 00:00:00');
        $endStr = $query['end'] ?? date('Y-m-t 23:59:59');

        $start = date('Y-m-d H:i:s', strtotime($startStr));
        $end = date('Y-m-d H:i:s', strtotime($endStr));

        $platformFilter = strtolower(trim($query['platform'] ?? ''));
        $statusFilter = strtolower(trim($query['status'] ?? ''));

        $pdo = Database::getConnection();

        $where = ["p.workspace_id = :wid"];
        $params = [
            ':wid'    => $workspaceId,
            ':start1' => $start,
            ':end1'   => $end,
            ':start2' => $start,
            ':end2'   => $end,
            ':start3' => $start,
            ':end3'   => $end,
        ];

        // Match posts that fall in this range via scheduled_at OR published_at OR created_at
        $where[] = "(
            (p.scheduled_at IS NOT NULL AND p.scheduled_at BETWEEN :start1 AND :end1) OR
            (p.published_at IS NOT NULL AND p.published_at BETWEEN :start2 AND :end2) OR
            (p.scheduled_at IS NULL AND p.published_at IS NULL AND p.created_at BETWEEN :start3 AND :end3)
        )";

        if (!empty($statusFilter) && in_array($statusFilter, ['draft', 'scheduled', 'publishing', 'published', 'partially_published', 'failed'], true)) {
            $where[] = "p.status = :status";
            $params[':status'] = $statusFilter;
        }

        $whereSql = implode(' AND ', $where);

        $stmt = $pdo->prepare("
            SELECT p.id, p.workspace_id, p.author_id, p.default_caption, p.status, 
                   p.scheduled_at, p.published_at, p.created_at, p.updated_at,
                   u.name as author_name, u.avatar_url as author_avatar
            FROM posts p
            JOIN users u ON p.author_id = u.id
            WHERE {$whereSql}
            ORDER BY COALESCE(p.scheduled_at, p.published_at, p.created_at) ASC
        ");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();

        $filteredPosts = [];

        foreach ($posts as $post) {
            $pid = (int)$post['id'];

            // Fetch targets
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
            unset($t);

            // If platform filter is specified, ensure post has at least one matching target
            if (!empty($platformFilter) && $platformFilter !== 'all') {
                $hasPlatform = false;
                foreach ($targets as $t) {
                    if ($t['platform'] === $platformFilter) {
                        $hasPlatform = true;
                        break;
                    }
                }
                if (!$hasPlatform) {
                    continue;
                }
            }

            // Fetch media attachments
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
            unset($m);

            // Fetch scheduled job info if any
            $stmtJob = $pdo->prepare("
                SELECT id, run_at, status, attempts, max_attempts, locked_at, last_error
                FROM scheduled_jobs
                WHERE post_id = :pid AND workspace_id = :wid
            ");
            $stmtJob->execute([':pid' => $pid, ':wid' => $workspaceId]);
            $job = $stmtJob->fetch() ?: null;

            $post['targets'] = $targets;
            $post['media'] = $media;
            $post['scheduled_job'] = $job;
            $post['calendar_date'] = $post['scheduled_at'] ?: ($post['published_at'] ?: $post['created_at']);

            $filteredPosts[] = $post;
        }

        Response::json([
            'start' => $start,
            'end'   => $end,
            'total' => count($filteredPosts),
            'posts' => $filteredPosts,
        ], 200);
    }
}
