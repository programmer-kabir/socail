<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];
$pdo = get_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    json_success($workspace);
}

if ($method === 'PUT' || $method === 'POST') {
    $input = get_json_input();
    $name = trim((string)($input['name'] ?? ''));
    $timezone = trim((string)($input['timezone'] ?? ''));

    if (empty($name)) {
        json_error('Workspace name is required.', 422);
    }

    $stmt = $pdo->prepare("UPDATE workspaces SET name = :name, timezone = :tz WHERE id = :id");
    $stmt->execute([':name' => $name, ':tz' => $timezone ?: 'UTC', ':id' => $workspaceId]);

    $workspace['name'] = $name;
    $workspace['timezone'] = $timezone ?: 'UTC';

    json_success($workspace, 'Workspace updated successfully.');
}

json_error('Method not allowed.', 405);
