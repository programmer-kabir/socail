<?php
declare(strict_types=1);

define('TEST_MODE', true);

/**
 * SocialFlow - Milestone 4 Automated Test Suite
 * Tests: Media Upload (MIME, Security, Storage), Cross-Workspace Media Gateway,
 * Post Drafts, Multi-Target Selection, Platform Captions, Platform Constraints Validation,
 * Sandbox Publishing, and Post Lifecycle (Update/Delete).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/EncryptionService.php';
require_once __DIR__ . '/../src/Services/PublishingService.php';
require_once __DIR__ . '/../src/Controllers/MediaController.php';
require_once __DIR__ . '/../src/Controllers/PostController.php';
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
        $failed++;
    }
}

echo "==========================================================" . PHP_EOL;
echo "  SOCIALFLOW MILESTONE 4: COMPOSER & MEDIA TEST SUITE     " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$pdo = Database::getConnection();

// Clean up any test records from prior runs
$pdo->exec("
    DELETE FROM workspaces 
    WHERE owner_id IN (SELECT id FROM users WHERE email IN ('m4_user_a@test.com', 'm4_user_b@test.com'))
");
$pdo->exec("DELETE FROM users WHERE email IN ('m4_user_a@test.com', 'm4_user_b@test.com')");

// 1. Create Test Tenant A and Tenant B
$pdo->beginTransaction();
$pwd = password_hash('Pass12345!', PASSWORD_BCRYPT);

$pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES ('Tenant A', 'm4_user_a@test.com', :p)")->execute([':p' => $pwd]);
$userAId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO workspaces (owner_id, name, slug) VALUES (:uid, 'Workspace A M4', 'workspace-a-m4')")->execute([':uid' => $userAId]);
$wsAId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (:wid, :uid, 'owner')")->execute([':wid' => $wsAId, ':uid' => $userAId]);

$pdo->prepare("INSERT INTO users (name, email, password_hash) VALUES ('Tenant B', 'm4_user_b@test.com', :p)")->execute([':p' => $pwd]);
$userBId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO workspaces (owner_id, name, slug) VALUES (:uid, 'Workspace B M4', 'workspace-b-m4')")->execute([':uid' => $userBId]);
$wsBId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO workspace_members (workspace_id, user_id, role) VALUES (:wid, :uid, 'owner')")->execute([':wid' => $wsBId, ':uid' => $userBId]);

$pdo->commit();

// Connect Sandbox Accounts in Workspace A (Twitter & YouTube)
$accCtrl = new SocialAccountController();
$resTw = [];
try {
    $accCtrl->connectSandbox([
        'user' => ['id' => $userAId],
        'workspace' => ['id' => $wsAId],
        'body' => ['platform' => 'twitter', 'account_name' => 'Acme Twitter Feed'],
    ]);
} catch (ResponseException $e) { $resTw = json_decode($e->responseBody, true) ?: []; }
$twAccId = $resTw['data']['id'] ?? 0;

$resYt = [];
try {
    $accCtrl->connectSandbox([
        'user' => ['id' => $userAId],
        'workspace' => ['id' => $wsAId],
        'body' => ['platform' => 'youtube', 'account_name' => 'Acme YouTube Channel'],
    ]);
} catch (ResponseException $e) { $resYt = json_decode($e->responseBody, true) ?: []; }
$ytAccId = $resYt['data']['id'] ?? 0;

$mediaCtrl = new MediaController();
$postCtrl = new PostController();

// -----------------------------------------------------------------
// Test 1: Media Upload Validation & Security
// -----------------------------------------------------------------
// Create authentic dummy PNG image
$tempPng = tempnam(sys_get_temp_dir(), 'test_img_') . '.png';
// 1x1 transparent PNG binary bytes
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
file_put_contents($tempPng, $pngBytes);

$uploadReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'files'     => [
        'media' => [
            'name'     => 'banner_graphic.png',
            'type'     => 'image/png',
            'tmp_name' => $tempPng,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen($pngBytes),
        ],
    ],
];

$resUpload = [];
try {
    $mediaCtrl->upload($uploadReq);
} catch (ResponseException $e) {
    $resUpload = json_decode($e->responseBody, true) ?: [];
}

assertTest("Legitimate image uploaded successfully", ($resUpload['success'] ?? false) === true);
$mediaId = $resUpload['data']['id'] ?? 0;
$storageFilename = $resUpload['data']['storage_filename'] ?? '';
assertTest("Storage filename is non-predictable UUID/hash", strlen($storageFilename) > 20 && str_ends_with($storageFilename, '.png'));

// Test 1b: Disguised Executable Rejection (.php.png or script extension)
$tempScript = tempnam(sys_get_temp_dir(), 'test_mal_') . '.php';
file_put_contents($tempScript, '<?php phpinfo(); ?>');

$uploadScriptReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'files'     => [
        'media' => [
            'name'     => 'backdoor.php',
            'type'     => 'text/plain',
            'tmp_name' => $tempScript,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 20,
        ],
    ],
];
$resUploadMal = [];
try {
    $mediaCtrl->upload($uploadScriptReq);
} catch (ResponseException $e) {
    $resUploadMal = json_decode($e->responseBody, true) ?: [];
}
assertTest("Executable file upload rejected by MIME & extension check", ($resUploadMal['success'] ?? true) === false);
@unlink($tempScript);

// Test 1c: Cross-Workspace Media Gateway Isolation
$reqStreamOtherWs = [
    'user'      => ['id' => $userBId],
    'workspace' => ['id' => $wsBId], // Tenant B asking for Tenant A's media
    'params'    => ['filename' => $storageFilename],
];
$resStreamOther = [];
try {
    $mediaCtrl->file($reqStreamOtherWs);
} catch (ResponseException $e) {
    $resStreamOther = json_decode($e->responseBody, true) ?: [];
}
assertTest("Cross-workspace media retrieval rejected with 404", ($resStreamOther['success'] ?? true) === false);

// -----------------------------------------------------------------
// Test 2: Create Post Draft with Media Attachment
// -----------------------------------------------------------------
$createDraftReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'body'      => [
        'caption'            => 'Initial seasonal launch campaign draft.',
        'status'             => 'draft',
        'target_account_ids' => [$twAccId],
        'media_ids'          => [$mediaId],
    ],
];
$resDraft = [];
try {
    $postCtrl->create($createDraftReq);
} catch (ResponseException $e) {
    $resDraft = json_decode($e->responseBody, true) ?: [];
}

assertTest("Post draft created successfully with status = 'draft'", ($resDraft['success'] ?? false) === true);
$postId = $resDraft['data']['post']['id'] ?? 0;
assertTest("Post has valid ID", $postId > 0);

// Verify post details via show()
$reqShow = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'params'    => ['id' => $postId],
];
$resShow = [];
try {
    $postCtrl->show($reqShow);
} catch (ResponseException $e) {
    $resShow = json_decode($e->responseBody, true) ?: [];
}

assertTest("Post retrieval returns associated targets and media attachments", 
    count($resShow['data']['targets'] ?? []) === 1 && 
    count($resShow['data']['media'] ?? []) === 1
);

// -----------------------------------------------------------------
// Test 3: Multi-Target Selection & Platform Custom Caption
// -----------------------------------------------------------------
$createMultiReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'body'      => [
        'caption'            => 'Universal announcement message.',
        'status'             => 'draft',
        'target_account_ids' => [$twAccId, $ytAccId],
        'custom_captions'    => [
            $twAccId => 'Short tailored tweet for X community! #announcement',
            $ytAccId => 'Full detailed description for YouTube viewers including timestamps.',
        ],
    ],
];
$resMulti = [];
try {
    $postCtrl->create($createMultiReq);
} catch (ResponseException $e) {
    $resMulti = json_decode($e->responseBody, true) ?: [];
}
assertTest("Multi-target post created with distinct platform captions", ($resMulti['success'] ?? false) === true);
$multiPostId = $resMulti['data']['post']['id'] ?? 0;

$stmtTargets = $pdo->prepare("SELECT social_account_id, custom_caption FROM post_targets WHERE post_id = :pid");
$stmtTargets->execute([':pid' => $multiPostId]);
$targetRows = $stmtTargets->fetchAll(PDO::FETCH_KEY_PAIR);
assertTest("Twitter target contains custom tweet caption", str_contains($targetRows[$twAccId] ?? '', '#announcement'));
assertTest("YouTube target contains custom YouTube description", str_contains($targetRows[$ytAccId] ?? '', 'timestamps'));

// -----------------------------------------------------------------
// Test 4: Platform Validation Constraints (Character limit & Media requirements)
// -----------------------------------------------------------------
// Attempt to schedule post to Twitter with 350 chars (> 280)
$oversizedTweet = str_repeat("Exceeding Twitter limit. ", 15); // > 300 chars
$badScheduleReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'body'      => [
        'caption'            => $oversizedTweet,
        'status'             => 'scheduled',
        'scheduled_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        'target_account_ids' => [$twAccId],
    ],
];
$resBadSched = [];
try {
    $postCtrl->create($badScheduleReq);
} catch (ResponseException $e) {
    $resBadSched = json_decode($e->responseBody, true) ?: [];
}
assertTest("Publishing to X with > 280 characters rejected by validator (422)", ($resBadSched['success'] ?? true) === false);

// Attempt to publish to YouTube with image only (video required)
$badYtReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'body'      => [
        'caption'            => 'Great video title',
        'status'             => 'publish_now',
        'target_account_ids' => [$ytAccId],
        'media_ids'          => [$mediaId], // only an image attached
    ],
];
$resBadYt = [];
try {
    $postCtrl->create($badYtReq);
} catch (ResponseException $e) {
    $resBadYt = json_decode($e->responseBody, true) ?: [];
}
assertTest("Publishing to YouTube without video rejected by validator (422)", ($resBadYt['success'] ?? true) === false);

// -----------------------------------------------------------------
// Test 5: Immediate Publishing in Sandbox Mode
// -----------------------------------------------------------------
$validPublishReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'body'      => [
        'caption'            => 'Instant Sandbox launch test! Everything is working smoothly.',
        'status'             => 'publish_now',
        'target_account_ids' => [$twAccId],
        'media_ids'          => [$mediaId],
    ],
];
$resPubNow = [];
try {
    $postCtrl->create($validPublishReq);
} catch (ResponseException $e) {
    $resPubNow = json_decode($e->responseBody, true) ?: [];
}

assertTest("Post created with publish_now status", ($resPubNow['success'] ?? false) === true);
$pubPostId = $resPubNow['data']['post']['id'] ?? 0;

// Verify post target status in DB
$stmtTargetCheck = $pdo->prepare("SELECT status, platform_post_id, platform_post_url FROM post_targets WHERE post_id = :pid");
$stmtTargetCheck->execute([':pid' => $pubPostId]);
$targetRow = $stmtTargetCheck->fetch();
assertTest("Target marked 'published' with simulated platform ID", $targetRow['status'] === 'published' && str_starts_with($targetRow['platform_post_id'], 'sim_'));

// Verify post status
$stmtPostCheck = $pdo->prepare("SELECT status FROM posts WHERE id = :pid");
$stmtPostCheck->execute([':pid' => $pubPostId]);
assertTest("Overall post status is 'published'", $stmtPostCheck->fetchColumn() === 'published');

// Verify publishing_logs recorded entry with is_simulated = 1
$stmtLogCheck = $pdo->prepare("SELECT is_simulated, status FROM publishing_logs WHERE post_id = :pid ORDER BY id DESC LIMIT 1");
$stmtLogCheck->execute([':pid' => $pubPostId]);
$logRow = $stmtLogCheck->fetch();
assertTest("Publishing log recorded with is_simulated = true", ($logRow['is_simulated'] ?? 0) == 1 && $logRow['status'] === 'success');

// -----------------------------------------------------------------
// Test 6: Post Update and Deletion
// -----------------------------------------------------------------
$updateReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'params'    => ['id' => $postId],
    'body'      => [
        'caption' => 'Updated draft caption version 2.0',
        'status'  => 'draft',
    ],
];
$resUpdate = [];
try {
    $postCtrl->update($updateReq);
} catch (ResponseException $e) {
    $resUpdate = json_decode($e->responseBody, true) ?: [];
}
assertTest("Draft updated successfully", ($resUpdate['success'] ?? false) === true);

// Delete post
$delReq = [
    'user'      => ['id' => $userAId],
    'workspace' => ['id' => $wsAId],
    'params'    => ['id' => $postId],
];
$resDel = [];
try {
    $postCtrl->delete($delReq);
} catch (ResponseException $e) {
    $resDel = json_decode($e->responseBody, true) ?: [];
}
assertTest("Post deleted successfully", ($resDel['success'] ?? false) === true);

$stmtCheckDel = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE id = :id");
$stmtCheckDel->execute([':id' => $postId]);
assertTest("Post record removed from MySQL database", (int)$stmtCheckDel->fetchColumn() === 0);

// -----------------------------------------------------------------
// Test 7: Cross-Workspace Post Isolation
// -----------------------------------------------------------------
// Tenant B tries to delete Tenant A's post
$crossDelReq = [
    'user'      => ['id' => $userBId],
    'workspace' => ['id' => $wsBId], // Tenant B
    'params'    => ['id' => $pubPostId], // Tenant A's post
];
$resCrossDel = [];
try {
    $postCtrl->delete($crossDelReq);
} catch (ResponseException $e) {
    $resCrossDel = json_decode($e->responseBody, true) ?: [];
}
assertTest("Cross-workspace post deletion rejected with 404", ($resCrossDel['success'] ?? true) === false);

// -----------------------------------------------------------------
// Cleanup Test Data
// -----------------------------------------------------------------
$pdo->exec("
    DELETE FROM workspaces 
    WHERE owner_id IN (SELECT id FROM users WHERE email IN ('m4_user_a@test.com', 'm4_user_b@test.com'))
");
$pdo->exec("DELETE FROM users WHERE email IN ('m4_user_a@test.com', 'm4_user_b@test.com')");

echo "==========================================================" . PHP_EOL;
echo "Results: {$passed} Passed, {$failed} Failed." . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
