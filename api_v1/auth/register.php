<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$input = get_json_input();
$name = trim((string)($input['name'] ?? ''));
$email = strtolower(trim((string)($input['email'] ?? '')));
$password = (string)($input['password'] ?? '');

$errors = [];
if (strlen($name) < 2) $errors['name'] = ['Name must be at least 2 characters.'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = ['A valid email address is required.'];
if (strlen($password) < 8) $errors['password'] = ['Password must be at least 8 characters.'];

if (!empty($errors)) {
    json_error('Validation failed.', 422, $errors);
}

$pdo = get_db();
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
$stmt->execute([':email' => $email]);
if ($stmt->fetch()) {
    json_error('An account with this email already exists.', 409, ['email' => ['Email already registered.']]);
}

try {
    $pdo->beginTransaction();

    $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmtUser = $pdo->prepare("INSERT INTO users (name, email, password_hash, email_verified_at) VALUES (:name, :email, :pass, NOW())");
    $stmtUser->execute([':name' => $name, ':email' => $email, ':pass' => $passwordHash]);
    $userId = (int)$pdo->lastInsertId();

    $wsName = $name . "'s Workspace";
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
    $stmtWs = $pdo->prepare("INSERT INTO workspaces (owner_id, name, slug, timezone) VALUES (:owner, :name, :slug, 'UTC')");
    $stmtWs->execute([':owner' => $userId, ':name' => $wsName, ':slug' => $slug]);
    $workspaceId = (int)$pdo->lastInsertId();

    $stmtMem = $pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (:wid, :uid, 'owner')");
    $stmtMem->execute([':wid' => $workspaceId, ':uid' => $userId]);

    $session = Auth::createSession($userId);
    $pdo->commit();

    json_success([
        'user' => [
            'id'    => $userId,
            'name'  => $name,
            'email' => $email,
        ],
        'token'      => $session['token'],
        'csrf_token' => $session['csrf_token'],
    ], 'Account registered successfully.', 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_error('Failed to create account: ' . $e->getMessage(), 500);
}
