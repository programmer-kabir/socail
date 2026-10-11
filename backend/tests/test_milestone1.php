<?php
declare(strict_types=1);

/**
 * SocialFlow - Milestone 1 Verification Test Suite
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';

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

echo "=============================================" . PHP_EOL;
echo "   SOCIALFLOW MILESTONE 1 VERIFICATION TEST  " . PHP_EOL;
echo "=============================================" . PHP_EOL;

// Test 1: Config loading
assertTest("Environment configuration loaded", Config::get('APP_NAME') === 'SocialFlow');
assertTest("APP_ENCRYPTION_KEY exists and is valid length", strlen(base64_decode(Config::get('APP_ENCRYPTION_KEY'), true) ?: '') === 32);

// Test 2: Database Connection
try {
    $pdo = Database::getConnection();
    assertTest("PDO Database connection established", $pdo instanceof PDO);
} catch (Throwable $e) {
    assertTest("PDO Database connection established", false, $e->getMessage());
}

// Test 3: Schema verification
$expectedTables = [
    'migrations',
    'users',
    'workspaces',
    'workspace_members',
    'user_sessions',
    'password_resets',
    'oauth_states',
    'social_accounts',
    'social_account_tokens',
    'posts',
    'post_media',
    'post_targets',
    'scheduled_jobs',
    'publishing_logs',
];

$stmt = $pdo->query("SHOW TABLES");
$actualTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($expectedTables as $table) {
    assertTest("Table '{$table}' exists in database", in_array($table, $actualTables, true));
}

// Test 4: AES-256-GCM Encryption & Decryption
$sampleSecret = "access_token_super_secret_payload_12345!@#$%^&*()";
try {
    $encrypted = EncryptionService::encrypt($sampleSecret);
    $decrypted = EncryptionService::decrypt($encrypted);
    assertTest("AES-256-GCM round-trip encryption/decryption", $decrypted === $sampleSecret);
    assertTest("Ciphertext format is base64 and non-empty", is_string($encrypted) && strlen($encrypted) > 30);
    
    // Tamper test
    $tampered = substr_replace($encrypted, 'X', 10, 1);
    $tamperCaught = false;
    try {
        EncryptionService::decrypt($tampered);
    } catch (Throwable) {
        $tamperCaught = true;
    }
    assertTest("AES-256-GCM detects payload tampering", $tamperCaught);
} catch (Throwable $e) {
    assertTest("AES-256-GCM encryption tests", false, $e->getMessage());
}

// Test 5: Seed Data Integrity
$stmtUser = $pdo->query("SELECT id, name, email FROM users WHERE email = 'demo@socialflow.io'");
$user = $stmtUser->fetch();
assertTest("Demo user exists", !empty($user) && $user['email'] === 'demo@socialflow.io');

$stmtWs = $pdo->query("SELECT id, name, timezone FROM workspaces WHERE slug = 'acme-marketing'");
$ws = $stmtWs->fetch();
assertTest("Demo workspace exists with America/New_York timezone", !empty($ws) && $ws['timezone'] === 'America/New_York');

$stmtAcc = $pdo->query("SELECT COUNT(*) FROM social_accounts WHERE workspace_id = " . (int)($ws['id'] ?? 0));
$accCount = (int)$stmtAcc->fetchColumn();
assertTest("5 Sandbox social accounts seeded", $accCount === 5);

// Test 6: Target-Level Idempotency and Independent Status
$stmtTargets = $pdo->query("SELECT COUNT(*) as cnt, COUNT(DISTINCT idempotency_key) as uniq_keys FROM post_targets");
$targetStats = $stmtTargets->fetch();
assertTest("Post targets have unique idempotency keys", $targetStats['cnt'] > 0 && $targetStats['cnt'] === $targetStats['uniq_keys']);

echo "=============================================" . PHP_EOL;
echo "Results: {$passed} Passed, {$failed} Failed." . PHP_EOL;
echo "=============================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
