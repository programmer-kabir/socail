<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$input = get_json_input();
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

if (empty($email) || empty($password)) {
    json_error('Email and password are required.', 422, [
        'email' => empty($email) ? ['Email is required.'] : [],
        'password' => empty($password) ? ['Password is required.'] : [],
    ]);
}

$pdo = get_db();
$stmt = $pdo->prepare("SELECT id, name, email, password_hash, avatar_url, created_at FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
    json_error('Invalid email or password.', 401);
}

$userId = (int)$user['id'];
$session = Auth::createSession($userId);

// Fetch workspaces
$stmtWs = $pdo->prepare("
    SELECT w.id, w.name, w.slug, w.timezone, wm.role
    FROM workspaces w
    JOIN workspace_members wm ON w.id = wm.workspace_id
    WHERE wm.user_id = :uid
    ORDER BY w.id ASC
");
$stmtWs->execute([':uid' => $userId]);
$workspaces = $stmtWs->fetchAll();
$activeWorkspace = $workspaces[0] ?? null;

json_success([
    'user' => [
        'id'         => $userId,
        'name'       => $user['name'],
        'email'      => $user['email'],
        'avatar_url' => $user['avatar_url'],
        'created_at' => $user['created_at'],
    ],
    'active_workspace' => $activeWorkspace,
    'workspaces'       => $workspaces,
    'token'            => $session['token'],
    'csrf_token'       => $session['csrf_token'],
], 'Login successful.');
