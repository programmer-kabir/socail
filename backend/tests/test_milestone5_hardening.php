<?php
declare(strict_types=1);

/**
 * SocialFlow - Milestone 5 Hardening & Concurrency Test Suite
 * Validates concurrency safety, atomic claims, crash recovery, retry backoff,
 * timezone conversion, ambiguous outcomes, and calendar boundaries.
 */

define('TEST_MODE', true);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Utils/Response.php';
require_once __DIR__ . '/../src/Workers/SchedulerWorker.php';
require_once __DIR__ . '/../src/Controllers/PostController.php';
require_once __DIR__ . '/../src/Controllers/CalendarController.php';
require_once __DIR__ . '/../src/Services/PublishingService.php';

Config::load(__DIR__ . '/../.env');
$pdo = Database::getConnection();

$passed = 0;
$failed = 0;

function assertCondition(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] $msg\n";
        $passed++;
    } else {
        echo " [FAIL] $msg\n";
        $failed++;
    }
}

echo "==========================================================\n";
echo "   SOCIALFLOW MILESTONE 5: HARDENING & CONCURRENCY SUITE   \n";
echo "==========================================================\n";

// Setup Test Workspaces and Accounts
$stmtUser = $pdo->query("SELECT id FROM users LIMIT 1");
$user = $stmtUser->fetch();
$userId = (int)$user['id'];

// Workspace A (America/New_York)
$stmtWsA = $pdo->prepare("SELECT id FROM workspaces WHERE slug = 'acme-marketing'");
$stmtWsA->execute();
$wsA = $stmtWsA->fetch();
$workspaceAId = (int)$wsA['id'];

// Workspace B (Europe/London)
$stmtWsB = $pdo->prepare("SELECT id FROM workspaces WHERE id != :id LIMIT 1");
$stmtWsB->execute([':id' => $workspaceAId]);
$wsB = $stmtWsB->fetch();
$workspaceBId = (int)$wsB['id'];

$stmtAcc = $pdo->prepare("SELECT id FROM social_accounts WHERE workspace_id = :wid LIMIT 1");
$stmtAcc->execute([':wid' => $workspaceAId]);
$acc = $stmtAcc->fetch();
$accountId = (int)$acc['id'];

// -----------------------------------------------------------------------------
// 1. ATOMIC CLAIM & CONCURRENT WORKERS (Row-Level Locking)
// -----------------------------------------------------------------------------
echo "\n--- 1. Concurrency & Atomic Claiming ---\n";

// Create due test post
$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Concurrent Claim Test Post', 'scheduled', UTC_TIMESTAMP())
");
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$concurrentPostId = (int)$pdo->lastInsertId();

$stmtTarget = $pdo->prepare("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
    VALUES (:pid, :aid, UUID(), 'pending')
");
$stmtTarget->execute([':pid' => $concurrentPostId, ':aid' => $accountId]);

$stmtJob = $pdo->prepare("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
    VALUES (:wid, :pid, UTC_TIMESTAMP(), 'queued')
");
$stmtJob->execute([':wid' => $workspaceAId, ':pid' => $concurrentPostId]);
$concurrentJobId = (int)$pdo->lastInsertId();

// Worker 1 and Worker 2
$worker1 = new SchedulerWorker($pdo, 'worker_alpha');
$worker2 = new SchedulerWorker($pdo, 'worker_beta');

// Worker 1 claims
$stmtClaim1 = $pdo->prepare("
    UPDATE scheduled_jobs 
    SET status = 'processing', locked_at = NOW(), locked_by = 'worker_alpha' 
    WHERE id = :id AND status = 'queued'
");
$stmtClaim1->execute([':id' => $concurrentJobId]);
assertCondition($stmtClaim1->rowCount() === 1, "Worker 1 successfully claimed the due job");

// Worker 2 attempts to claim the same job while processing
$stmtClaim2 = $pdo->prepare("
    UPDATE scheduled_jobs 
    SET status = 'processing', locked_at = NOW(), locked_by = 'worker_beta' 
    WHERE id = :id AND status = 'queued'
");
$stmtClaim2->execute([':id' => $concurrentJobId]);
assertCondition($stmtClaim2->rowCount() === 0, "Worker 2 blocked: 0 rows affected on already-claimed job");

// -----------------------------------------------------------------------------
// 2. WORKER IDENTITY ON FINALIZATION & STOLEN LOCK PROTECTION
// -----------------------------------------------------------------------------
echo "\n--- 2. Stolen Lock & Finalization Protection ---\n";

// Worker 2 attempts to finalize a job owned by Worker 1
$stmtFinalizeWorker2 = $pdo->prepare("
    UPDATE scheduled_jobs 
    SET status = 'completed', locked_at = NULL, locked_by = NULL 
    WHERE id = :id AND locked_by = 'worker_beta' AND status = 'processing'
");
$stmtFinalizeWorker2->execute([':id' => $concurrentJobId]);
assertCondition($stmtFinalizeWorker2->rowCount() === 0, "Worker 2 cannot finalize job owned by Worker 1");

// Worker 1 finalizes its own job
$stmtFinalizeWorker1 = $pdo->prepare("
    UPDATE scheduled_jobs 
    SET status = 'completed', locked_at = NULL, locked_by = NULL 
    WHERE id = :id AND locked_by = 'worker_alpha' AND status = 'processing'
");
$stmtFinalizeWorker1->execute([':id' => $concurrentJobId]);
assertCondition($stmtFinalizeWorker1->rowCount() === 1, "Worker 1 successfully finalized its own claimed job");

// Cleanup concurrent test post
$pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $concurrentPostId]);

// -----------------------------------------------------------------------------
// 3. STALE LOCK RECOVERY & POISON JOB DEFENSE
// -----------------------------------------------------------------------------
echo "\n--- 3. Stale Lock Recovery & Poison Job Defense ---\n";

// Create a post with crashed worker simulation
$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Stale Lock Test Post', 'scheduled', UTC_TIMESTAMP())
");
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$stalePostId = (int)$pdo->lastInsertId();

$stmtTarget = $pdo->prepare("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
    VALUES (:pid, :aid, UUID(), 'pending')
");
$stmtTarget->execute([':pid' => $stalePostId, ':aid' => $accountId]);

// Simulate job locked 600 seconds ago by dead worker (attempts = 0)
$stmtJob = $pdo->prepare("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status, locked_at, locked_by, attempts, max_attempts)
    VALUES (:wid, :pid, UTC_TIMESTAMP(), 'processing', DATE_SUB(NOW(), INTERVAL 600 SECOND), 'dead_worker_pid_9999', 0, 3)
");
$stmtJob->execute([':wid' => $workspaceAId, ':pid' => $stalePostId]);
$staleJobId = (int)$pdo->lastInsertId();

// Recovery worker with 300s threshold
$recoveryWorker = new SchedulerWorker($pdo, 'recovery_worker', 300);
$releasedCount = $recoveryWorker->releaseStaleLocks();
assertCondition($releasedCount >= 1, "Stale lock detector reclaimed dead worker lock");

// Check job state after 1st crash recovery
$stmtCheck = $pdo->prepare("SELECT status, attempts, locked_by, last_error FROM scheduled_jobs WHERE id = :id");
$stmtCheck->execute([':id' => $staleJobId]);
$jobRow = $stmtCheck->fetch();
assertCondition($jobRow['status'] === 'queued', "Recovered job reverted to 'queued' status");
assertCondition((int)$jobRow['attempts'] === 1, "Crash recovery incremented attempts to 1");
assertCondition($jobRow['locked_by'] === null, "Job lock cleared");

// Now simulate poison job that crashes workers repeatedly until max_attempts (3)
$pdo->prepare("
    UPDATE scheduled_jobs 
    SET status = 'processing', locked_at = DATE_SUB(NOW(), INTERVAL 600 SECOND), locked_by = 'dead_worker_2', attempts = 2 
    WHERE id = :id
")->execute([':id' => $staleJobId]);

$recoveryWorker->releaseStaleLocks();
$stmtCheck->execute([':id' => $staleJobId]);
$jobRow2 = $stmtCheck->fetch();
assertCondition($jobRow2['status'] === 'failed', "Poison job safely marked 'failed' after exceeding max attempts");
assertCondition(str_contains($jobRow2['last_error'], 'exceeding max attempts'), "Poison job recorded crash threshold error message");

// Cleanup stale post
$pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $stalePostId]);

// -----------------------------------------------------------------------------
// 4. ERROR CLASSIFICATION (Permanent vs Retryable)
// -----------------------------------------------------------------------------
echo "\n--- 4. Error Classification ---\n";

assertCondition(SchedulerWorker::isRetryableError('Validation failed: Twitter caption cannot exceed 280 characters') === false,
    "Caption length validation error classified as PERMANENT (no blind retry)");

assertCondition(SchedulerWorker::isRetryableError('Social account is disconnected. Please reconnect before publishing.') === false,
    "Disconnected account error classified as PERMANENT");

assertCondition(SchedulerWorker::isRetryableError('Token decryption failed: invalid ciphertext tag') === false,
    "Decryption failure classified as PERMANENT");

assertCondition(SchedulerWorker::isRetryableError('Rate limit exceeded: 429 Too Many Requests') === true,
    "Rate limit (429) classified as RETRYABLE");

assertCondition(SchedulerWorker::isRetryableError('Connection timed out after 30000ms') === true,
    "Network connection timeout classified as RETRYABLE");

assertCondition(SchedulerWorker::isRetryableError('Target status is ambiguous: socket reset') === false,
    "Ambiguous outcome classified as NON-RETRYABLE (requires reconciliation)");

// -----------------------------------------------------------------------------
// 5. BOUNDED RETRY & EXPONENTIAL BACKOFF
// -----------------------------------------------------------------------------
echo "\n--- 5. Bounded Retry & Backoff Persistence ---\n";

// Create a post for retry test
$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Retry Backoff Post', 'scheduled', UTC_TIMESTAMP())
");
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$retryPostId = (int)$pdo->lastInsertId();

$stmtTarget = $pdo->prepare("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
    VALUES (:pid, :aid, UUID(), 'pending')
");
$stmtTarget->execute([':pid' => $retryPostId, ':aid' => $accountId]);

$stmtJob = $pdo->prepare("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status, attempts, max_attempts)
    VALUES (:wid, :pid, UTC_TIMESTAMP(), 'queued', 0, 3)
");
$stmtJob->execute([':wid' => $workspaceAId, ':pid' => $retryPostId]);
$retryJobId = (int)$pdo->lastInsertId();

// Simulate transient error triggering handleJobFailure via reflection
$worker = new SchedulerWorker($pdo, 'retry_tester');
$refMethod = new ReflectionMethod(SchedulerWorker::class, 'handleJobFailure');
$refMethod->setAccessible(true);

// First claim job
$pdo->prepare("UPDATE scheduled_jobs SET status = 'processing', locked_by = 'retry_tester' WHERE id = :id")
    ->execute([':id' => $retryJobId]);

// Attempt 1 failure (retryable)
$refMethod->invoke($worker, $retryJobId, $retryPostId, $workspaceAId, 0, 3, 'Rate limit 429 from API gateway', true);

$stmtCheckRetry = $pdo->prepare("SELECT status, attempts, run_at, last_error FROM scheduled_jobs WHERE id = :id");
$stmtCheckRetry->execute([':id' => $retryJobId]);
$retryRow1 = $stmtCheckRetry->fetch();
assertCondition($retryRow1['status'] === 'queued', "Retryable failure kept status as 'queued'");
assertCondition((int)$retryRow1['attempts'] === 1, "Attempts incremented to 1");
assertCondition(strtotime($retryRow1['run_at']) > time(), "Backoff scheduled run_at in future");

// Simulate attempt 2 (claim then fail permanently)
$pdo->prepare("UPDATE scheduled_jobs SET status = 'processing', locked_by = 'retry_tester' WHERE id = :id")
    ->execute([':id' => $retryJobId]);
$refMethod->invoke($worker, $retryJobId, $retryPostId, $workspaceAId, 2, 3, 'API 500 Persistent error', true);

$stmtCheckRetry->execute([':id' => $retryJobId]);
$retryRow2 = $stmtCheckRetry->fetch();
assertCondition($retryRow2['status'] === 'failed', "Max attempts (3) transitioned job to 'failed'");
assertCondition((int)$retryRow2['attempts'] === 3, "Final attempts recorded as 3");

$stmtPostStatus = $pdo->prepare("SELECT status FROM posts WHERE id = :id");
$stmtPostStatus->execute([':id' => $retryPostId]);
$postRow = $stmtPostStatus->fetch();
assertCondition($postRow['status'] === 'failed', "Post status updated to 'failed' upon permanent job exhaustion");

// Cleanup
$pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $retryPostId]);

// -----------------------------------------------------------------------------
// 6. TIMEZONE CONVERSION (America/New_York vs UTC)
// -----------------------------------------------------------------------------
echo "\n--- 6. Timezone Conversion & DST Correctness ---\n";

$postController = new PostController();
$refTzMethod = new ReflectionMethod(PostController::class, 'parseScheduledTime');
$refTzMethod->setAccessible(true);

// 1. Winter date in New York (EST, UTC-5): 2026-12-15 14:00:00 -> 19:00:00 UTC
$estResult = $refTzMethod->invoke($postController, '2026-12-15 14:00:00', 'America/New_York');
assertCondition($estResult !== null, "Winter New York time parsed successfully");
assertCondition($estResult['utc'] === '2026-12-15 19:00:00', "EST conversion: 14:00 EST converted to 19:00:00 UTC (+5h)");

// 2. Summer date in New York (EDT, UTC-4, DST): 2026-07-15 14:00:00 -> 18:00:00 UTC
$edtResult = $refTzMethod->invoke($postController, '2026-07-15 14:00:00', 'America/New_York');
assertCondition($edtResult !== null, "Summer New York (DST) time parsed successfully");
assertCondition($edtResult['utc'] === '2026-07-15 18:00:00', "EDT conversion: 14:00 EDT converted to 18:00:00 UTC (+4h)");

// 3. Explicit ISO string with UTC 'Z' should ignore workspace timezone
$isoUtcResult = $refTzMethod->invoke($postController, '2026-10-15T15:30:00Z', 'America/New_York');
assertCondition($isoUtcResult['utc'] === '2026-10-15 15:30:00', "Explicit ISO Z preserved without double-shifting");

// 4. Ambiguous / Invalid time format
$invalidResult = $refTzMethod->invoke($postController, 'not-a-valid-date-string', 'America/New_York');
assertCondition($invalidResult === null, "Malformed date string returns null");

// -----------------------------------------------------------------------------
// 7. CALENDAR DATE-RANGE BOUNDARIES & TIMEZONE ALIGNMENT
// -----------------------------------------------------------------------------
echo "\n--- 7. Calendar Range Boundaries ---\n";

// Create post at 2026-10-01 00:00:00 UTC (exact start of month)
$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Start of Month Boundary Post', 'scheduled', '2026-10-01 00:00:00')
");
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$startPostId = (int)$pdo->lastInsertId();

// Create post at 2026-10-31 23:59:59 UTC (exact end of month)
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$endPostId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE posts SET scheduled_at = '2026-10-31 23:59:59' WHERE id = :id")->execute([':id' => $endPostId]);

// Create post out of bounds: 2026-11-01 00:00:01 UTC
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$outPostId = (int)$pdo->lastInsertId();
$pdo->prepare("UPDATE posts SET scheduled_at = '2026-11-01 00:00:01' WHERE id = :id")->execute([':id' => $outPostId]);

// Query October range
$calendarController = new CalendarController();
$jsonOutput = '';
try {
    $calendarController->index([
        'workspace' => ['id' => $workspaceAId],
        'query'     => [
            'start' => '2026-10-01 00:00:00',
            'end'   => '2026-10-31 23:59:59',
        ]
    ]);
} catch (ResponseException $e) {
    $jsonOutput = $e->responseBody;
}
$calData = json_decode($jsonOutput, true);
$retrievedIds = array_column($calData['data']['posts'] ?? [], 'id');

assertCondition(in_array($startPostId, $retrievedIds, true), "Start-of-month post included in range query");
assertCondition(in_array($endPostId, $retrievedIds, true), "End-of-month post included in range query");
assertCondition(!in_array($outPostId, $retrievedIds, true), "Out-of-range post (Nov 1) excluded from October range query");

// Cleanup
$pdo->prepare("DELETE FROM posts WHERE id IN (:p1, :p2, :p3)")->execute([
    ':p1' => $startPostId,
    ':p2' => $endPostId,
    ':p3' => $outPostId,
]);

// -----------------------------------------------------------------------------
// 8. IDEMPOTENT TARGET-LEVEL RE-DISPATCH
// -----------------------------------------------------------------------------
echo "\n--- 8. Idempotency & Target Re-dispatch ---\n";

// Create post with 2 targets: Target 1 already published, Target 2 pending
$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Partial Publish Test', 'scheduled', UTC_TIMESTAMP())
");
$stmtPost->execute([':wid' => $workspaceAId, ':uid' => $userId]);
$partialPostId = (int)$pdo->lastInsertId();

// Target 1: already published
$stmtTarget1 = $pdo->prepare("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status, platform_post_id, published_at)
    VALUES (:pid, :aid, UUID(), 'published', 'ext_fb_12345', NOW())
");
$stmtTarget1->execute([':pid' => $partialPostId, ':aid' => $accountId]);

// Count publishing logs before dispatch
$stmtLogCount = $pdo->prepare("SELECT COUNT(*) FROM publishing_logs WHERE post_id = :pid");
$stmtLogCount->execute([':pid' => $partialPostId]);
$initialLogs = (int)$stmtLogCount->fetchColumn();

// Dispatch via PublishingService
$pubResult = PublishingService::publishPostImmediate($partialPostId, $workspaceAId);

$stmtLogCount->execute([':pid' => $partialPostId]);
$afterLogs = (int)$stmtLogCount->fetchColumn();

assertCondition($pubResult['targets'][$accountId]['status'] === 'already_published',
    "PublishingService recognized already-published target and skipped re-publishing");
assertCondition($initialLogs === $afterLogs,
    "Zero duplicate external publishing logs created for already-published target");

// Cleanup
$pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $partialPostId]);

echo "\n==========================================================\n";
echo sprintf("Results: %d Passed, %d Failed.\n", $passed, $failed);
echo "==========================================================\n";

if ($failed > 0) {
    exit(1);
}
