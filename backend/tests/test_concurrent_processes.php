<?php
declare(strict_types=1);

/**
 * SocialFlow - Multi-Process Concurrency Test
 * Spawns two independent OS processes running scheduler_worker.php concurrently
 * against a single due job, proving race condition safety.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

Config::load(__DIR__ . '/../.env');
$pdo = Database::getConnection();

echo "==========================================================\n";
echo "    SOCIALFLOW MULTI-PROCESS RACE CONDITION TEST          \n";
echo "==========================================================\n";

// 1. Create a single due scheduled post
$stmtUser = $pdo->query("SELECT id FROM users LIMIT 1");
$userId = (int)$stmtUser->fetchColumn();

$stmtWs = $pdo->query("SELECT id FROM workspaces WHERE slug = 'acme-marketing'");
$workspaceId = (int)$stmtWs->fetchColumn();

$stmtAcc = $pdo->query("SELECT id FROM social_accounts WHERE workspace_id = $workspaceId LIMIT 1");
$accountId = (int)$stmtAcc->fetchColumn();

$stmtPost = $pdo->prepare("
    INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
    VALUES (:wid, :uid, 'Concurrent OS Process Post', 'scheduled', UTC_TIMESTAMP())
");
$stmtPost->execute([':wid' => $workspaceId, ':uid' => $userId]);
$postId = (int)$pdo->lastInsertId();

$stmtTarget = $pdo->prepare("
    INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
    VALUES (:pid, :aid, UUID(), 'pending')
");
$stmtTarget->execute([':pid' => $postId, ':aid' => $accountId]);

$stmtJob = $pdo->prepare("
    INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
    VALUES (:wid, :pid, UTC_TIMESTAMP(), 'queued')
");
$stmtJob->execute([':wid' => $workspaceId, ':pid' => $postId]);
$jobId = (int)$pdo->lastInsertId();

$phpBinary = 'C:\\xampp\\php\\php.exe';
$workerScript = dirname(__DIR__) . '/bin/scheduler_worker.php';

// Launch two parallel processes
$cmd = sprintf('"%s" "%s"', $phpBinary, $workerScript);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];

$proc1 = proc_open($cmd, $descriptors, $pipes1);
$proc2 = proc_open($cmd, $descriptors, $pipes2);

$out1 = stream_get_contents($pipes1[1]);
$out2 = stream_get_contents($pipes2[1]);

fclose($pipes1[0]); fclose($pipes1[1]); fclose($pipes1[2]); proc_close($proc1);
fclose($pipes2[0]); fclose($pipes2[1]); fclose($pipes2[2]); proc_close($proc2);

echo "Process 1 Output:\n" . trim($out1) . "\n";
echo "Process 2 Output:\n" . trim($out2) . "\n";

// Parse stats from outputs
preg_match('/Claimed: (\d+), Completed: (\d+)/', $out1, $m1);
preg_match('/Claimed: (\d+), Completed: (\d+)/', $out2, $m2);

$claimed1 = (int)($m1[1] ?? 0);
$completed1 = (int)($m1[2] ?? 0);
$claimed2 = (int)($m2[1] ?? 0);
$completed2 = (int)($m2[2] ?? 0);

$totalClaimed = $claimed1 + $claimed2;
$totalCompleted = $completed1 + $completed2;

$passed = true;
if ($totalClaimed !== 1) {
    echo " [FAIL] Expected total claimed across both processes to be 1, got $totalClaimed\n";
    $passed = false;
} else {
    echo " [PASS] Concurrency verification: Exactly ONE process claimed the job ($claimed1 vs $claimed2)\n";
}

if ($totalCompleted !== 1) {
    echo " [FAIL] Expected total completed across both processes to be 1, got $totalCompleted\n";
    $passed = false;
} else {
    echo " [PASS] Publication verification: Exactly ONE process completed the job ($completed1 vs $completed2)\n";
}

// Check database job and logs
$stmtCheckJob = $pdo->prepare("SELECT status FROM scheduled_jobs WHERE id = :id");
$stmtCheckJob->execute([':id' => $jobId]);
$finalStatus = $stmtCheckJob->fetchColumn();

if ($finalStatus === 'completed') {
    echo " [PASS] Database job state transitioned cleanly to 'completed'\n";
} else {
    echo " [FAIL] Expected job status 'completed', got '$finalStatus'\n";
    $passed = false;
}

$stmtLogs = $pdo->prepare("SELECT COUNT(*) FROM publishing_logs WHERE post_id = :pid");
$stmtLogs->execute([':pid' => $postId]);
$logCount = (int)$stmtLogs->fetchColumn();

if ($logCount === 1) {
    echo " [PASS] Zero duplicate publication logs recorded (exactly 1 log entry)\n";
} else {
    echo " [FAIL] Expected exactly 1 publishing log, got $logCount\n";
    $passed = false;
}

// Cleanup
$pdo->prepare("DELETE FROM posts WHERE id = :id")->execute([':id' => $postId]);

if (!$passed) {
    exit(1);
}
echo "==========================================================\n";
echo "   MULTI-PROCESS CONCURRENCY TEST COMPLETED: ALL PASS     \n";
echo "==========================================================\n";
