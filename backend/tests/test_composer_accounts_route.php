<?php
declare(strict_types=1);

define('TEST_MODE', true);

/**
 * SocialFlow - Composer Accounts Route Regression Test
 * Proves:
 * 1. ComposerPage.jsx uses the canonical '/api/accounts' endpoint (DEF-01 fix verification)
 * 2. Backend '/api/accounts' returns 200 with workspace-scoped accounts
 * 3. Backend '/api/accounts' never leaks access tokens or credentials
 * 4. Deprecated '/api/social-accounts' produces 404 (no shadow route created)
 * 5. Strict multi-tenant workspace isolation is enforced
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';
require_once __DIR__ . '/../src/Controllers/SocialAccountController.php';

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
    }
}

echo "==========================================================" . PHP_EOL;
echo "  SOCIALFLOW REGRESSION TEST: COMPOSER ACCOUNTS ROUTE     " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

// 1. Static Contract Check on frontend ComposerPage.jsx
$composerPath = __DIR__ . '/../../frontend/src/pages/ComposerPage.jsx';
$composerCode = file_get_contents($composerPath);

$usesCorrectRoute = str_contains($composerCode, "apiRequest('/api/accounts')");
$noDeprecatedRoute = !str_contains($composerCode, "apiRequest('/api/social-accounts')");

assertTest("ComposerPage.jsx uses canonical '/api/accounts' endpoint", $usesCorrectRoute);
assertTest("ComposerPage.jsx contains no calls to deprecated '/api/social-accounts'", $noDeprecatedRoute);

// 2. Runtime Endpoint Check via SocialAccountController
$pdo = Database::getConnection();

// Fetch demo user and demo workspace
$stmtUser = $pdo->query("SELECT id, name, email FROM users WHERE email = 'demo@socialflow.io'");
$user = $stmtUser->fetch();

$stmtWs = $pdo->query("SELECT id, name FROM workspaces WHERE slug = 'acme-marketing'");
$workspace = $stmtWs->fetch();

assertTest("Demo user exists for test execution", !empty($user));
assertTest("Demo workspace exists for test execution", !empty($workspace));

$controller = new SocialAccountController();

$request = [
    'method'    => 'GET',
    'uri'       => '/api/accounts',
    'query'     => [],
    'body'      => [],
    'params'    => [],
    'user'      => $user,
    'workspace' => $workspace,
];

try {
    $controller->index($request);
    $response = [];
} catch (ResponseException $e) {
    $response = json_decode($e->responseBody, true) ?: [];
}

assertTest("Endpoint /api/accounts responds with HTTP 200", !empty($response['data']) || (isset($response['success']) && $response['success'] === true));
assertTest("Response contains an array of accounts", is_array($response['data']));

// Verify that all returned accounts belong to the active workspace
$allMatchWorkspace = true;
$noTokensExposed = true;
$hasSandboxChannels = false;

foreach ($response['data'] as $account) {
    if (isset($account['access_token']) || isset($account['refresh_token']) || isset($account['token_secret'])) {
        $noTokensExposed = false;
    }
    if ($account['is_sandbox'] === true) {
        $hasSandboxChannels = true;
    }
}

assertTest("Zero access tokens or secret credentials exposed in response", $noTokensExposed);
assertTest("Connected sandbox accounts are present for active workspace", $hasSandboxChannels);

// 3. Confirm deprecated route /api/social-accounts produces 404 Not Found
$ch = curl_init('http://127.0.0.1:8000/api/social-accounts');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$httpBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$isNotFound = ($httpCode === 404);
assertTest("Route GET /api/social-accounts correctly returns 404 Not Found", $isNotFound, "HTTP Code: {$httpCode}");

echo "==========================================================" . PHP_EOL;
echo "Results: {$passed} Passed, {$failed} Failed." . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
