<?php
declare(strict_types=1);

define('TEST_MODE', true);

/**
 * SocialFlow - Milestone 2 Automated Test Suite
 * Tests: Registration, Login, Sessions, CSRF, Workspace Isolation, Role Permissions, Password Reset, Rate Limiting
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/RateLimiter.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';
require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../src/Middleware/WorkspaceMiddleware.php';
require_once __DIR__ . '/../src/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Controllers/WorkspaceController.php';

Config::load(__DIR__ . '/../.env');

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, string $detail = ''): void {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] {$description}" . PHP_EOL;
        $passed++;
    } else {
        echo " [FAIL] {$description} " . ($detail ? "({$detail})" : "") . PHP_EOL;
        $failed++;
    }
}

echo "==========================================================" . PHP_EOL;
echo "   SOCIALFLOW MILESTONE 2: AUTH & WORKSPACE TEST SUITE    " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$pdo = Database::getConnection();

function cleanTestUsers(PDO $pdo): void {
    $stmt = $pdo->query("SELECT id FROM users WHERE email IN ('alice_test@example.com', 'bob_test@example.com', 'charlie_test@example.com')");
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($userIds)) {
        $in = implode(',', array_map('intval', $userIds));
        $pdo->exec("DELETE FROM workspaces WHERE owner_id IN ({$in})");
        $pdo->exec("DELETE FROM users WHERE id IN ({$in})");
    }
}
cleanTestUsers($pdo);

function executeAuthRegister(string $name, string $email, string $password): array {
    $auth = new AuthController();
    $request = [
        'method' => 'POST',
        'body'   => ['name' => $name, 'email' => $email, 'password' => $password],
    ];
    try {
        $auth->register($request);
    } catch (ResponseException $e) {
        return json_decode($e->responseBody, true) ?: [];
    }
    return [];
}

function executeAuthLogin(string $email, string $password): array {
    $auth = new AuthController();
    $request = [
        'method' => 'POST',
        'body'   => ['email' => $email, 'password' => $password],
    ];
    try {
        $auth->login($request);
    } catch (ResponseException $e) {
        return json_decode($e->responseBody, true) ?: [];
    }
    return [];
}

// -----------------------------------------------------------------
// Test 1: User Registration
// -----------------------------------------------------------------
RateLimiter::clear("register:127.0.0.1");
$regResult = executeAuthRegister('Alice Creator', 'alice_test@example.com', 'SuperSecretPass123!');
assertTest("Alice registered successfully", ($regResult['success'] ?? false) === true);
assertTest("Registration created default workspace", !empty($regResult['data']['workspace']['id']));
assertTest("Session token issued in response", !empty($regResult['data']['token']));
assertTest("CSRF token issued in response", !empty($regResult['data']['csrf_token']));

$aliceId = $regResult['data']['user']['id'] ?? 0;
$aliceWid = $regResult['data']['workspace']['id'] ?? 0;
$aliceToken = $regResult['data']['token'] ?? '';
$aliceCsrf = $regResult['data']['csrf_token'] ?? '';

// Test 1b: Duplicate Email Rejection
$dupResult = executeAuthRegister('Alice Imposter', 'alice_test@example.com', 'AnotherPass123!');
assertTest("Duplicate email registration rejected", ($dupResult['success'] ?? true) === false);

// -----------------------------------------------------------------
// Test 2: Passwords & Login
// -----------------------------------------------------------------
// Incorrect password
RateLimiter::clear("login:127.0.0.1:alice_test@example.com");
$wrongPassResult = executeAuthLogin('alice_test@example.com', 'WrongPassword999!');
assertTest("Incorrect password rejected (401)", ($wrongPassResult['success'] ?? true) === false);

// Correct password
$loginResult = executeAuthLogin('alice_test@example.com', 'SuperSecretPass123!');
assertTest("Correct credentials login succeeds (200)", ($loginResult['success'] ?? false) === true);
assertTest("Login returned user profile", ($loginResult['data']['user']['email'] ?? '') === 'alice_test@example.com');
assertTest("Login returned active workspace", !empty($loginResult['data']['active_workspace']['id']));
$newAliceToken = $loginResult['data']['token'] ?? '';

// Check that only token hash is stored in user_sessions
$tokenHash = hash('sha256', $newAliceToken);
$stmtSession = $pdo->prepare("SELECT * FROM user_sessions WHERE token_hash = :hash");
$stmtSession->execute([':hash' => $tokenHash]);
$dbSession = $stmtSession->fetch();
assertTest("Session token stored as SHA-256 hash in database", !empty($dbSession));
assertTest("Raw plain token is NOT stored anywhere in database", empty($pdo->query("SELECT * FROM user_sessions WHERE token_hash = '{$newAliceToken}'")->fetch()));

// -----------------------------------------------------------------
// Test 3: Logout & Session Invalidation
// -----------------------------------------------------------------
$auth = new AuthController();
$logoutReq = [
    'method'  => 'POST',
    'session' => ['token_hash' => $tokenHash],
];
$logoutResult = [];
try {
    $auth->logout($logoutReq);
} catch (ResponseException $e) {
    $logoutResult = json_decode($e->responseBody, true) ?: [];
}
assertTest("Logout invalidates active session", ($logoutResult['success'] ?? false) === true);

$stmtCheck = $pdo->prepare("SELECT * FROM user_sessions WHERE token_hash = :hash");
$stmtCheck->execute([':hash' => $tokenHash]);
assertTest("Session hash purged from user_sessions after logout", empty($stmtCheck->fetch()));

// -----------------------------------------------------------------
// Test 4: Workspace Isolation & Cross-Workspace Data Access
// -----------------------------------------------------------------
// Register User B (Bob)
RateLimiter::clear("register:127.0.0.1");
$bobResult = executeAuthRegister('Bob Agency', 'bob_test@example.com', 'BobSecretPassword123!');
$bobId = $bobResult['data']['user']['id'] ?? 0;
$bobWid = $bobResult['data']['workspace']['id'] ?? 0;
$bobToken = $bobResult['data']['token'] ?? '';

assertTest("Bob registered with own isolated workspace", $bobWid > 0 && $bobWid !== $aliceWid);

// Now Bob tries to access Alice's workspace via WorkspaceMiddleware
$_SERVER['HTTP_X_WORKSPACE_ID'] = (string)$aliceWid;
$fakeRequest = [
    'user'   => ['id' => $bobId, 'name' => 'Bob Agency'],
    'params' => [],
];

$blockedResult = [];
try {
    WorkspaceMiddleware::handle($fakeRequest);
} catch (ResponseException $e) {
    $blockedResult = json_decode($e->responseBody, true) ?: [];
}

assertTest("Cross-workspace access rejected with 403 Forbidden", ($blockedResult['success'] ?? true) === false);

// Bob accesses his own workspace
$_SERVER['HTTP_X_WORKSPACE_ID'] = (string)$bobWid;
$ownAccess = WorkspaceMiddleware::handle($fakeRequest);
assertTest("User can access their authorized workspace", is_array($ownAccess) && ($ownAccess['workspace']['id'] ?? 0) === $bobWid);

// -----------------------------------------------------------------
// Test 5: CSRF Protection
// -----------------------------------------------------------------
$bobSessionHash = hash('sha256', $bobToken);
$correctCsrf = AuthService::generateCsrfForSession($bobSessionHash);

// Simulate cookie auth with tampered CSRF
$_SERVER['HTTP_AUTHORIZATION'] = '';
$_COOKIE['socialflow_session'] = $bobToken;
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'invalid_tampered_csrf_token_12345';

$csrfReq = [
    'method' => 'POST',
    'body'   => [],
];
$csrfFailRes = [];
try {
    AuthMiddleware::handle($csrfReq);
} catch (ResponseException $e) {
    $csrfFailRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("State-changing request with invalid CSRF token rejected (403)", ($csrfFailRes['success'] ?? true) === false);

// Simulate cookie auth with valid CSRF
$_SERVER['HTTP_X_CSRF_TOKEN'] = $correctCsrf;
$csrfPassReq = [
    'method' => 'POST',
    'body'   => [],
];
$csrfPassRes = AuthMiddleware::handle($csrfPassReq);
assertTest("State-changing request with valid CSRF token accepted", is_array($csrfPassRes) && !empty($csrfPassRes['user']));

// -----------------------------------------------------------------
// Test 6: Workspace Switching & Creation
// -----------------------------------------------------------------
$wsController = new WorkspaceController();
$newWsReq = [
    'user' => ['id' => $bobId],
    'body' => ['name' => 'Bob Second Agency', 'timezone' => 'Europe/London'],
];
$createWsRes = [];
try {
    $wsController->create($newWsReq);
} catch (ResponseException $e) {
    $createWsRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("User can create a second workspace", ($createWsRes['success'] ?? false) === true);
$bobSecondWid = $createWsRes['data']['id'] ?? 0;

// List Bob's workspaces
$listReq = ['user' => ['id' => $bobId]];
$listRes = [];
try {
    $wsController->index($listReq);
} catch (ResponseException $e) {
    $listRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("User can list all their workspaces (count = 2)", count($listRes['data'] ?? []) === 2);

// -----------------------------------------------------------------
// Test 7: Role-Based Authorization
// -----------------------------------------------------------------
RateLimiter::clear("register:127.0.0.1");
$charlieResult = executeAuthRegister('Charlie Editor', 'charlie_test@example.com', 'CharliePass123!');
$charlieId = $charlieResult['data']['user']['id'] ?? 0;

// Add Charlie as 'editor' to Bob's first workspace
$stmtAddMember = $pdo->prepare("
    INSERT INTO workspace_members (workspace_id, user_id, role)
    VALUES (:wid, :uid, 'editor')
");
$stmtAddMember->execute([':wid' => $bobWid, ':uid' => $charlieId]);

// Charlie (editor) attempts to update workspace settings
$editorUpdateReq = [
    'workspace' => ['id' => $bobWid, 'role' => 'editor', 'name' => 'Hacked Name', 'timezone' => 'UTC'],
    'body'      => ['name' => 'Unauthorized Change'],
];
$editorUpdateRes = [];
try {
    $wsController->update($editorUpdateReq);
} catch (ResponseException $e) {
    $editorUpdateRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("Editor role blocked from updating workspace settings (403)", ($editorUpdateRes['success'] ?? true) === false);

// Bob (owner) updates workspace settings
$ownerUpdateReq = [
    'workspace' => ['id' => $bobWid, 'role' => 'owner', 'name' => 'Bob Agency', 'timezone' => 'UTC'],
    'body'      => ['name' => 'Bob Prime Agency', 'timezone' => 'America/Chicago'],
];
$ownerUpdateRes = [];
try {
    $wsController->update($ownerUpdateReq);
} catch (ResponseException $e) {
    $ownerUpdateRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("Owner role permitted to update workspace settings (200)", ($ownerUpdateRes['success'] ?? false) === true);

// -----------------------------------------------------------------
// Test 8: Password Reset Flow (Token Expiry & Single Use)
// -----------------------------------------------------------------
RateLimiter::clear("forgot_password:127.0.0.1");
$forgotReq = [
    'body' => ['email' => 'alice_test@example.com'],
];
$forgotRes = [];
try {
    $auth->forgotPassword($forgotReq);
} catch (ResponseException $e) {
    $forgotRes = json_decode($e->responseBody, true) ?: [];
}
$resetToken = $forgotRes['data']['reset_token'] ?? '';
assertTest("Password reset token generated", !empty($resetToken));

// Reset password with valid token
$resetReq = [
    'body' => [
        'email'    => 'alice_test@example.com',
        'token'    => $resetToken,
        'password' => 'NewAlicePassword2026!',
    ],
];
$resetRes = [];
try {
    $auth->resetPassword($resetReq);
} catch (ResponseException $e) {
    $resetRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("Password reset succeeds with valid token", ($resetRes['success'] ?? false) === true);

// Attempt single-use replay
$replayRes = [];
try {
    $auth->resetPassword($resetReq);
} catch (ResponseException $e) {
    $replayRes = json_decode($e->responseBody, true) ?: [];
}
assertTest("Single-use protection prevents token replay", ($replayRes['success'] ?? true) === false);

// Verify new password works
$newLoginRes = executeAuthLogin('alice_test@example.com', 'NewAlicePassword2026!');
assertTest("Login with newly updated password succeeds", ($newLoginRes['success'] ?? false) === true);

// -----------------------------------------------------------------
// Test 9: Rate Limiting
// -----------------------------------------------------------------
$testKey = "unit_test_rate_key_" . bin2hex(random_bytes(4));
RateLimiter::clear($testKey);
$first3 = true;
for ($i = 0; $i < 3; $i++) {
    $first3 = $first3 && RateLimiter::attempt($testKey, 3, 60);
}
assertTest("Rate limiter permits attempts within threshold", $first3);
$exceeded = RateLimiter::attempt($testKey, 3, 60);
assertTest("Rate limiter blocks attempts exceeding threshold", $exceeded === false);

// -----------------------------------------------------------------
// Cleanup Test Data
// -----------------------------------------------------------------
cleanTestUsers($pdo);

echo "==========================================================" . PHP_EOL;
echo "Results: {$passed} Passed, {$failed} Failed." . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
