<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$postId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET') {
    if ($postId > 0) {
        // Single post detail
        $stmt = $pdo->prepare("
            SELECT p.id, p.workspace_id, p.author_id, p.default_caption, p.status, 
                   p.scheduled_at, p.published_at, p.created_at, p.updated_at,
                   u.name as author_name, u.avatar_url as author_avatar
            FROM posts p
            JOIN users u ON p.author_id = u.id
            WHERE p.id = :id AND p.workspace_id = :wid
        ");
        $stmt->execute([':id' => $postId, ':wid' => $workspaceId]);
        $post = $stmt->fetch();

        if (!$post) {
            json_error('Post not found.', 404);
        }

        // Targets
        $stmtT = $pdo->prepare("
            SELECT pt.id, pt.social_account_id, pt.custom_caption, pt.status, 
                   pt.platform_post_id, pt.platform_post_url, pt.error_message,
                   sa.platform, sa.account_name, sa.username, sa.is_sandbox
            FROM post_targets pt
            JOIN social_accounts sa ON pt.social_account_id = sa.id
            WHERE pt.post_id = :pid
        ");
        $stmtT->execute([':pid' => $postId]);
        $targets = $stmtT->fetchAll();
        foreach ($targets as &$t) { $t['is_sandbox'] = (bool)$t['is_sandbox']; }
        $post['targets'] = $targets;

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
            $m['preview_url'] = "/api_v1/media/file.php?filename={$m['storage_filename']}";
        }
        $post['media'] = $media;

        json_success($post);
    }

    // List posts
    $status = $_GET['status'] ?? null;
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;

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

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereClause}");
    $stmtCount->execute($params);
    $total = (int)$stmtCount->fetchColumn();

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

    foreach ($posts as &$post) {
        $pid = (int)$post['id'];

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

    json_success([
        'posts' => $posts,
        'meta'  => [
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => (int)ceil($total / $perPage),
        ],
    ]);
}

if ($method === 'DELETE') {
    if ($postId <= 0) {
        json_error('Post ID is required.', 422);
    }
    $stmt = $pdo->prepare("DELETE FROM posts WHERE id = :id AND workspace_id = :wid");
    $stmt->execute([':id' => $postId, ':wid' => $workspaceId]);
    json_success(null, 'Post deleted successfully.');
}

json_error('Method not allowed.', 405);
