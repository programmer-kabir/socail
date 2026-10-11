<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Dynamic Dashboard Controller
 * Computes live, real database metrics strictly scoped to the active workspace.
 */
class DashboardController
{
    public function stats(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $pdo = Database::getConnection();

        // 1. Account counts
        $stmtAccounts = $pdo->prepare("
            SELECT 
                COUNT(*) as total_active,
                SUM(CASE WHEN is_sandbox = 1 THEN 1 ELSE 0 END) as sandbox_count,
                SUM(CASE WHEN is_sandbox = 0 THEN 1 ELSE 0 END) as live_count
            FROM social_accounts
            WHERE workspace_id = :wid AND status = 'active'
        ");
        $stmtAccounts->execute([':wid' => $workspaceId]);
        $accRow = $stmtAccounts->fetch();

        // 2. Post counts by status
        $stmtPosts = $pdo->prepare("
            SELECT 
                status, 
                COUNT(*) as count
            FROM posts
            WHERE workspace_id = :wid
            GROUP BY status
        ");
        $stmtPosts->execute([':wid' => $workspaceId]);
        $postRows = $stmtPosts->fetchAll();

        $postCounts = [
            'draft'               => 0,
            'scheduled'           => 0,
            'publishing'          => 0,
            'published'           => 0,
            'partially_published' => 0,
            'failed'              => 0,
        ];
        foreach ($postRows as $row) {
            $status = $row['status'];
            if (isset($postCounts[$status])) {
                $postCounts[$status] = (int)$row['count'];
            }
        }

        // 3. Upcoming scheduled posts (limit 5)
        $stmtUpcoming = $pdo->prepare("
            SELECT 
                p.id, 
                p.default_caption, 
                p.scheduled_at, 
                p.status, 
                u.name as author_name,
                (SELECT GROUP_CONCAT(sa.platform SEPARATOR ',') 
                 FROM post_targets pt 
                 JOIN social_accounts sa ON pt.social_account_id = sa.id 
                 WHERE pt.post_id = p.id) as target_platforms
            FROM posts p
            JOIN users u ON p.author_id = u.id
            WHERE p.workspace_id = :wid AND p.status = 'scheduled'
            ORDER BY p.scheduled_at ASC
            LIMIT 5
        ");
        $stmtUpcoming->execute([':wid' => $workspaceId]);
        $upcomingPosts = $stmtUpcoming->fetchAll();

        foreach ($upcomingPosts as &$post) {
            $post['target_platforms'] = !empty($post['target_platforms']) 
                ? explode(',', $post['target_platforms']) 
                : [];
        }

        Response::json([
            'accounts' => [
                'total_active'  => (int)($accRow['total_active'] ?? 0),
                'sandbox_count' => (int)($accRow['sandbox_count'] ?? 0),
                'live_count'    => (int)($accRow['live_count'] ?? 0),
            ],
            'posts' => [
                'draft'     => $postCounts['draft'],
                'scheduled' => $postCounts['scheduled'],
                'published' => $postCounts['published'] + $postCounts['partially_published'],
                'failed'    => $postCounts['failed'],
            ],
            'upcoming_scheduled_posts' => $upcomingPosts,
        ], 200);
    }

    public function activity(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $pdo = Database::getConnection();

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

        Response::json($logs, 200);
    }
}
