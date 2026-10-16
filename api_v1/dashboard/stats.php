<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();

// 1. Accounts
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

// 2. Post counts
$stmtPosts = $pdo->prepare("
    SELECT status, COUNT(*) as count
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

// 3. Upcoming scheduled posts
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

json_success([
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
]);
