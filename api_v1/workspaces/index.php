<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$user = require_auth();
$userId = (int)$user['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT w.id, w.name, w.slug, w.timezone, wm.role, wm.created_at as member_since
        FROM workspaces w
        JOIN workspace_members wm ON w.id = wm.workspace_id
        WHERE wm.user_id = :uid
        ORDER BY w.id ASC
    ");
    $stmt->execute([':uid' => $userId]);
    $workspaces = $stmt->fetchAll();

    json_success($workspaces);
}

if ($method === 'POST') {
    $input = get_json_input();
    $name = trim((string)($input['name'] ?? ''));
    $timezone = trim((string)($input['timezone'] ?? 'UTC'));

    if (empty($name)) {
        json_error('Workspace name is required.', 422);
    }

    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);

    try {
        $pdo->beginTransaction();

        $stmtWs = $pdo->prepare("INSERT INTO workspaces (owner_id, name, slug, timezone) VALUES (:owner, :name, :slug, :tz)");
        $stmtWs->execute([':owner' => $userId, ':name' => $name, ':slug' => $slug, ':tz' => $timezone]);
        $wsId = (int)$pdo->lastInsertId();

        $stmtMem = $pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (:wid, :uid, 'owner')");
        $stmtMem->execute([':wid' => $wsId, ':uid' => $userId]);

        $pdo->commit();

        json_success([
            'id'       => $wsId,
            'name'     => $name,
            'slug'     => $slug,
            'timezone' => $timezone,
            'role'     => 'owner',
        ], 'Workspace created successfully.', 201);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_error('Failed to create workspace: ' . $e->getMessage(), 500);
    }
}

json_error('Method not allowed.', 405);
