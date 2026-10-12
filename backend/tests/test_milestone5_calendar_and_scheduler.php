<?php
declare(strict_types=1);

define('TEST_MODE', true);

/**
 * SocialFlow - Milestone 5: Content Calendar & Scheduling Engine Test Suite
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Services/AuthService.php';
require_once __DIR__ . '/../src/Services/PublishingService.php';
require_once __DIR__ . '/../src/Controllers/PostController.php';
require_once __DIR__ . '/../src/Controllers/CalendarController.php';
require_once __DIR__ . '/../src/Workers/SchedulerWorker.php';

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
echo "  SOCIALFLOW MILESTONE 5: CALENDAR & SCHEDULER TEST SUITE " . PHP_EOL;
echo "==========================================================" . PHP_EOL;

$pdo = Database::getConnection();

// 1. Fetch Demo User and Workspace A
$stmtUser = $pdo->query("SELECT id, name, email FROM users WHERE email = 'demo@socialflow.io'");
$userA = $stmtUser->fetch();
$stmtWs = $pdo->query("SELECT id, name FROM workspaces WHERE slug = 'acme-marketing'");
$wsA = $stmtWs->fetch();

// 2. Fetch or create a second user/workspace for multi-tenant isolation testing
$stmtWsB = $pdo->query("SELECT id, name FROM workspaces WHERE id != " . (int)$wsA['id'] . " LIMIT 1");
$wsB = $stmtWsB->fetch();
if (!$wsB) {
    $pdo->exec("INSERT INTO workspaces (owner_id, name, slug, timezone) VALUES ({$userA['id']}, 'Workspace Isolation Beta', 'ws-beta-test', 'UTC')");
    $wsBId = (int)$pdo->lastInsertId();
    $wsB = ['id' => $wsBId, 'name' => 'Workspace Isolation Beta'];
}

// Fetch social account in Workspace A
$stmtAcc = $pdo->prepare("SELECT id, platform, account_name FROM social_accounts WHERE workspace_id = :wid LIMIT 2");
$stmtAcc->execute([':wid' => $wsA['id']]);
$accountsA = $stmtAcc->fetchAll();

assertTest("Workspace A has accounts for scheduling tests", count($accountsA) >= 1);
$accId1 = (int)$accountsA[0]['id'];

$postController = new PostController();
$calendarController = new CalendarController();

// -------------------------------------------------------------
// Test 1: Past scheduled time rejection
// -------------------------------------------------------------
$pastTime = gmdate('Y-m-d H:i:s', time() - 3600); // 1 hour ago
$pastRequest = [
    'method'    => 'POST',
    'uri'       => '/api/posts',
    'body'      => [
        'caption'            => 'Post scheduled in the past should fail',
        'status'             => 'scheduled',
        'scheduled_at'       => $pastTime,
        'target_account_ids' => [$accId1],
    ],
    'user'      => $userA,
    'workspace' => $wsA,
];

$pastRejected = false;
try {
    $postController->create($pastRequest);
} catch (ResponseException $e) {
    if ($e->statusCode === 422 && str_contains($e->responseBody, 'future')) {
        $pastRejected = true;
    }
}
assertTest("Past scheduled time rejected with 422 error", $pastRejected);

// -------------------------------------------------------------
// Test 2: Valid future schedule creation
// -------------------------------------------------------------
$futureTime = gmdate('Y-m-d H:i:s', time() + 86400 * 5); // 5 days from now
$createSchedRequest = [
    'method'    => 'POST',
    'uri'       => '/api/posts',
    'body'      => [
        'caption'            => 'Milestone 5 Scheduled Campaign Post #M5',
        'status'             => 'scheduled',
        'scheduled_at'       => $futureTime,
        'target_account_ids' => [$accId1],
    ],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $postController->create($createSchedRequest);
    $createResponse = [];
} catch (ResponseException $e) {
    $createResponse = json_decode($e->responseBody, true) ?: [];
}

$createdPostId = (int)($createResponse['data']['post']['id'] ?? 0);
assertTest("Post scheduled successfully in MySQL", $createdPostId > 0);
assertTest("Post status is 'scheduled'", ($createResponse['data']['post']['status'] ?? '') === 'scheduled');

// Verify scheduled_jobs table has matching row with status 'queued'
$stmtJobCheck = $pdo->prepare("SELECT * FROM scheduled_jobs WHERE post_id = :pid AND workspace_id = :wid");
$stmtJobCheck->execute([':pid' => $createdPostId, ':wid' => $wsA['id']]);
$queuedJob = $stmtJobCheck->fetch();

assertTest("Post queued into scheduled_jobs table", !empty($queuedJob));
assertTest("Queued job has status = 'queued'", ($queuedJob['status'] ?? '') === 'queued');
assertTest("Job run_at matches scheduled_at timestamp", substr($queuedJob['run_at'] ?? '', 0, 16) === substr($futureTime, 0, 16));

// -------------------------------------------------------------
// Test 3: Calendar Range Queries (Month, Week, Day)
// -------------------------------------------------------------
// Month query covering 5 days from now
$monthStart = gmdate('Y-m-01 00:00:00', strtotime($futureTime));
$monthEnd = gmdate('Y-m-t 23:59:59', strtotime($futureTime));

$calMonthRequest = [
    'method'    => 'GET',
    'uri'       => '/api/calendar',
    'query'     => ['start' => $monthStart, 'end' => $monthEnd],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $calendarController->index($calMonthRequest);
    $calMonthResponse = [];
} catch (ResponseException $e) {
    $calMonthResponse = json_decode($e->responseBody, true) ?: [];
}

$monthPosts = $calMonthResponse['data']['posts'] ?? [];
$foundInMonth = false;
foreach ($monthPosts as $mp) {
    if ((int)$mp['id'] === $createdPostId) {
        $foundInMonth = true;
        break;
    }
}
assertTest("Calendar month range retrieves scheduled post", $foundInMonth);

// Day query for exact future date
$dayStart = gmdate('Y-m-d 00:00:00', strtotime($futureTime));
$dayEnd = gmdate('Y-m-d 23:59:59', strtotime($futureTime));

$calDayRequest = [
    'method'    => 'GET',
    'uri'       => '/api/calendar',
    'query'     => ['start' => $dayStart, 'end' => $dayEnd],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $calendarController->index($calDayRequest);
    $calDayResponse = [];
} catch (ResponseException $e) {
    $calDayResponse = json_decode($e->responseBody, true) ?: [];
}

$dayPosts = $calDayResponse['data']['posts'] ?? [];
$foundInDay = false;
foreach ($dayPosts as $dp) {
    if ((int)$dp['id'] === $createdPostId) {
        $foundInDay = true;
        break;
    }
}
assertTest("Calendar day query retrieves scheduled post", $foundInDay);

// Out-of-bounds query should exclude post
$calFarRequest = [
    'method'    => 'GET',
    'uri'       => '/api/calendar',
    'query'     => ['start' => '2020-01-01 00:00:00', 'end' => '2020-01-31 23:59:59'],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $calendarController->index($calFarRequest);
    $calFarResponse = [];
} catch (ResponseException $e) {
    $calFarResponse = json_decode($e->responseBody, true) ?: [];
}
assertTest("Calendar out-of-bounds query returns 0 posts", ($calFarResponse['data']['total'] ?? -1) === 0);

// Platform & status filter check
$calFilterRequest = [
    'method'    => 'GET',
    'uri'       => '/api/calendar',
    'query'     => ['start' => $monthStart, 'end' => $monthEnd, 'status' => 'scheduled'],
    'user'      => $userA,
    'workspace' => $wsA,
];
try {
    $calendarController->index($calFilterRequest);
    $calFilterResponse = [];
} catch (ResponseException $e) {
    $calFilterResponse = json_decode($e->responseBody, true) ?: [];
}
assertTest("Calendar status filter 'scheduled' includes post", !empty($calFilterResponse['data']['posts']));

// -------------------------------------------------------------
// Test 4: Workspace Isolation for Calendar
// -------------------------------------------------------------
$calWsBRequest = [
    'method'    => 'GET',
    'uri'       => '/api/calendar',
    'query'     => ['start' => $monthStart, 'end' => $monthEnd],
    'user'      => $userA,
    'workspace' => $wsB,
];

try {
    $calendarController->index($calWsBRequest);
    $calWsBResponse = [];
} catch (ResponseException $e) {
    $calWsBResponse = json_decode($e->responseBody, true) ?: [];
}

$wsBPosts = $calWsBResponse['data']['posts'] ?? [];
$leakedToB = false;
foreach ($wsBPosts as $wp) {
    if ((int)$wp['id'] === $createdPostId) {
        $leakedToB = true;
        break;
    }
}
assertTest("Workspace B cannot view Workspace A scheduled posts (strict isolation)", !$leakedToB);

// -------------------------------------------------------------
// Test 5: Rescheduling & Cancelling Scheduled Post
// -------------------------------------------------------------
// Update scheduled time to 7 days in future
$newFutureTime = gmdate('Y-m-d H:i:s', time() + 86400 * 7);
$updateRequest = [
    'method'    => 'PUT',
    'params'    => ['id' => $createdPostId],
    'body'      => [
        'caption'      => 'Updated Scheduled Campaign Post',
        'status'       => 'scheduled',
        'scheduled_at' => $newFutureTime,
    ],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $postController->update($updateRequest);
} catch (ResponseException $e) {
    //
}

$stmtCheckUpdate = $pdo->prepare("SELECT scheduled_at FROM posts WHERE id = :pid");
$stmtCheckUpdate->execute([':pid' => $createdPostId]);
$updatedSchedAt = $stmtCheckUpdate->fetchColumn();

$stmtCheckJobUp = $pdo->prepare("SELECT run_at FROM scheduled_jobs WHERE post_id = :pid");
$stmtCheckJobUp->execute([':pid' => $createdPostId]);
$updatedJobRunAt = $stmtCheckJobUp->fetchColumn();

assertTest("Post scheduled_at updated in MySQL", substr($updatedSchedAt ?: '', 0, 16) === substr($newFutureTime, 0, 16));
assertTest("scheduled_jobs run_at synchronized with new schedule time", substr($updatedJobRunAt ?: '', 0, 16) === substr($newFutureTime, 0, 16));

// Cancel schedule
$cancelRequest = [
    'method'    => 'POST',
    'params'    => ['id' => $createdPostId],
    'user'      => $userA,
    'workspace' => $wsA,
];

try {
    $postController->cancelSchedule($cancelRequest);
    $cancelResp = [];
} catch (ResponseException $e) {
    $cancelResp = json_decode($e->responseBody, true) ?: [];
}

assertTest("Cancel schedule reverts post to 'draft'", ($cancelResp['data']['status'] ?? '') === 'draft');

$stmtPostAfterCancel = $pdo->prepare("SELECT status, scheduled_at FROM posts WHERE id = :pid");
$stmtPostAfterCancel->execute([':pid' => $createdPostId]);
$postAfterCancel = $stmtPostAfterCancel->fetch();
assertTest("Post in database has status = 'draft' and scheduled_at = NULL", $postAfterCancel['status'] === 'draft' && $postAfterCancel['scheduled_at'] === null);

$stmtJobAfterCancel = $pdo->prepare("SELECT COUNT(*) FROM scheduled_jobs WHERE post_id = :pid");
$stmtJobAfterCancel->execute([':pid' => $createdPostId]);
assertTest("Job removed from scheduled_jobs after cancellation", (int)$stmtJobAfterCancel->fetchColumn() === 0);

// Cross-workspace cancellation rejected
$cancelWsBRequest = [
    'method'    => 'POST',
    'params'    => ['id' => $createdPostId],
    'user'      => $userA,
    'workspace' => $wsB,
];
$wsBCancelRejected = false;
try {
    $postController->cancelSchedule($cancelWsBRequest);
} catch (ResponseException $e) {
    if ($e->statusCode === 404) {
        $wsBCancelRejected = true;
    }
}
assertTest("Cross-workspace cancel request rejected with 404", $wsBCancelRejected);

// Clean up test post
$pdo->exec("DELETE FROM posts WHERE id = {$createdPostId}");

// -------------------------------------------------------------
// Test 6: Scheduler Worker Atomic Claim, Dispatch & Idempotency
// -------------------------------------------------------------
// Create a due scheduled post for testing the worker execution
$dueTime = gmdate('Y-m-d H:i:s', time() - 10); // due 10 seconds ago
$pdo->exec("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES ({$wsA['id']}, {$userA['id']}, 'Worker Auto-Publish Test #M5', 'scheduled', '{$dueTime}')
");
$workerPostId = (int)$pdo->lastInsertId();

// Create target
$idempotencyKey = 'worker_test_' . bin2hex(random_bytes(8));
$pdo->exec("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
    VALUES ({$workerPostId}, {$accId1}, '{$idempotencyKey}', 'pending')
");

// Insert due job
$pdo->exec("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
    VALUES ({$wsA['id']}, {$workerPostId}, '{$dueTime}', 'queued')
");
$workerJobId = (int)$pdo->lastInsertId();

$worker = new SchedulerWorker($pdo, 'test_worker_1');
$workerStats = $worker->run();

assertTest("SchedulerWorker claimed the due job", $workerStats['claimed'] >= 1);
assertTest("SchedulerWorker completed the publishing job", $workerStats['completed'] >= 1);

// Verify post status is now 'published'
$stmtWorkerPost = $pdo->prepare("SELECT status, published_at FROM posts WHERE id = :pid");
$stmtWorkerPost->execute([':pid' => $workerPostId]);
$workerPost = $stmtWorkerPost->fetch();

assertTest("Post status transitioned to 'published'", $workerPost['status'] === 'published');
assertTest("Post published_at timestamp is set", !empty($workerPost['published_at']));

// Verify job status is completed
$stmtWorkerJob = $pdo->prepare("SELECT status FROM scheduled_jobs WHERE id = :jid");
$stmtWorkerJob->execute([':jid' => $workerJobId]);
$workerJobStatus = $stmtWorkerJob->fetchColumn();
assertTest("scheduled_jobs record updated to 'completed'", $workerJobStatus === 'completed');

// Verify publishing_log was written
$stmtWorkerLog = $pdo->prepare("SELECT COUNT(*) FROM publishing_logs WHERE post_id = :pid");
$stmtWorkerLog->execute([':pid' => $workerPostId]);
assertTest("Publishing log recorded for worker dispatch", (int)$stmtWorkerLog->fetchColumn() >= 1);

// -------------------------------------------------------------
// Test 7: Duplicate Publish Prevention & Interrupted Stale Lock Recovery
// -------------------------------------------------------------
// Re-running worker on same job does NOT publish again (duplicate defense)
$workerStats2 = $worker->run();
assertTest("Subsequent worker run finds 0 due jobs", $workerStats2['claimed'] === 0);

// Test stale lock recovery: simulate crashed worker that left status = 'processing' 10 minutes ago
$staleTime = gmdate('Y-m-d H:i:s', time() - 600);
$pdo->exec("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES ({$wsA['id']}, {$userA['id']}, 'Stale Recovery Test Post', 'scheduled', '{$staleTime}')
");
$stalePostId = (int)$pdo->lastInsertId();

$pdo->exec("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status, locked_at, locked_by)
    VALUES ({$wsA['id']}, {$stalePostId}, '{$staleTime}', 'processing', '{$staleTime}', 'crashed_worker_999')
");
$staleJobId = (int)$pdo->lastInsertId();

$recoveringWorker = new SchedulerWorker($pdo, 'recovery_worker_1', 300); // 5 min timeout
$staleReleased = $recoveringWorker->releaseStaleLocks();

assertTest("Worker detected and released stale lock", $staleReleased >= 1);

$stmtCheckRecovered = $pdo->prepare("SELECT status, locked_at, locked_by FROM scheduled_jobs WHERE id = :jid");
$stmtCheckRecovered->execute([':jid' => $staleJobId]);
$recoveredJob = $stmtCheckRecovered->fetch();

assertTest("Stale job status reverted to 'queued'", $recoveredJob['status'] === 'queued');
assertTest("Stale job lock cleared", $recoveredJob['locked_at'] === null && $recoveredJob['locked_by'] === null);

// Clean up worker test records
$pdo->exec("DELETE FROM posts WHERE id IN ({$workerPostId}, {$stalePostId})");

echo "==========================================================" . PHP_EOL;
echo "Results: {$passed} Passed, {$failed} Failed." . PHP_EOL;
echo "==========================================================" . PHP_EOL;

if ($failed > 0) {
    exit(1);
}
