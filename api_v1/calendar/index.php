<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $startStr = $_GET['start'] ?? date('Y-m-01 00:00:00');
    $endStr = $_GET['end'] ?? date('Y-m-t 23:59:59');

    $start = date('Y-m-d H:i:s', strtotime($startStr));
    $end = date('Y-m-d H:i:s', strtotime($endStr));
    $statusFilter = strtolower(trim((string)($_GET['status'] ?? '')));

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

    $where[] = "(
        (p.scheduled_at IS NOT NULL AND p.scheduled_at BETWEEN :start1 AND :end1) OR
        (p.published_at IS NOT NULL AND p.published_at BETWEEN :start2 AND :end2) OR
        (p.scheduled_at IS NULL AND p.published_at IS NULL AND p.created_at BETWEEN :start3 AND :end3)
    )";

    if (!empty($statusFilter)) {
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

    foreach ($posts as &$post) {
        $pid = (int)$post['id'];

        $stmtT = $pdo->prepare("
            SELECT pt.id, pt.social_account_id, pt.custom_caption, pt.status, 
                   pt.platform_post_id, pt.platform_post_url,
                   sa.platform, sa.account_name, sa.username, sa.is_sandbox
            FROM post_targets pt
            JOIN social_accounts sa ON pt.social_account_id = sa.id
            WHERE pt.post_id = :pid
        ");
        $stmtT->execute([':pid' => $pid]);
        $targets = $stmtT->fetchAll();
        foreach ($targets as &$t) { $t['is_sandbox'] = (bool)$t['is_sandbox']; }
        $post['targets'] = $targets;

        $stmtM = $pdo->prepare("
            SELECT id, storage_filename, original_filename, mime_type, file_size_bytes, media_type, display_order
            FROM post_media
            WHERE post_id = :pid
            ORDER BY display_order ASC
        ");
        $stmtM->execute([':pid' => $pid]);
        $media = $stmtM->fetchAll();
        foreach ($media as &$m) {
            $m['preview_url'] = "/api_v1/media/file.php?filename={$m['storage_filename']}";
        }
        $post['media'] = $media;
    }

    json_success($posts);
}

if ($method === 'PUT' || $method === 'POST') {
    // Reschedule post
    $input = get_json_input();
    $postId = (int)($input['post_id'] ?? $input['id'] ?? ($_GET['id'] ?? 0));
    $newScheduledAt = (string)($input['scheduled_at'] ?? '');

    if ($postId <= 0 || empty($newScheduledAt)) {
        json_error('Post ID and scheduled_at timestamp are required.', 422);
    }

    $formatted = date('Y-m-d H:i:s', strtotime($newScheduledAt));
    $stmt = $pdo->prepare("
        UPDATE posts 
        SET scheduled_at = :sched, status = 'scheduled', updated_at = NOW() 
        WHERE id = :id AND workspace_id = :wid
    ");
    $stmt->execute([':sched' => $formatted, ':id' => $postId, ':wid' => $workspaceId]);

    json_success(['id' => $postId, 'scheduled_at' => $formatted], 'Post successfully rescheduled.');
}

json_error('Method not allowed.', 405);
