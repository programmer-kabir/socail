<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/PublishingService.php';

/**
 * SocialFlow - High-Reliability Scheduling Queue Engine
 * Features:
 * - Atomic job claiming with row-level locking
 * - Recovery from interrupted/crashed worker executions via stale lock detection
 * - Bounded retry policy with exponential backoff
 * - Multi-tenant workspace isolation and target-level idempotency
 */
class SchedulerWorker
{
    private PDO $pdo;
    private string $workerId;
    private int $staleLockTimeoutSeconds;

    public function __construct(?PDO $pdo = null, ?string $workerId = null, int $staleLockTimeoutSeconds = 300)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->workerId = $workerId ?? ('worker_' . getmypid() . '_' . bin2hex(random_bytes(4)));
        $this->staleLockTimeoutSeconds = $staleLockTimeoutSeconds;
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    /**
     * Determine if an error is transient/retryable or permanent
     */
    public static function isRetryableError(string $error): bool
    {
        $errorLower = strtolower($error);

        // Permanent validation, auth, and logic errors - never retry blindly
        if (str_contains($errorLower, 'disconnected') ||
            str_contains($errorLower, 'unauthorized') ||
            str_contains($errorLower, 'invalid token') ||
            str_contains($errorLower, 'missing token') ||
            str_contains($errorLower, 'decryption fail') ||
            str_contains($errorLower, 'cannot exceed') ||
            str_contains($errorLower, 'requires a video') ||
            str_contains($errorLower, 'validation') ||
            str_contains($errorLower, 'not belong to this workspace') ||
            str_contains($errorLower, 'ambiguous') || // Requires manual reconciliation, not blind retry
            str_contains($errorLower, 'not configured')) {
            return false;
        }

        return true;
    }

    /**
     * Release stale locks from crashed or abruptly terminated workers
     */
    public function releaseStaleLocks(): int
    {
        // Requeue stale jobs whose worker crashed, or permanently fail if attempts exceeded max_attempts
        $stmt = $this->pdo->prepare("
            UPDATE scheduled_jobs 
            SET attempts = attempts + 1,
                status = IF(attempts + 1 >= max_attempts, 'failed', 'queued'),
                last_error = IF(attempts + 1 >= max_attempts, 
                    'Worker crashed or timed out repeatedly exceeding max attempts', 
                    'Stale lock released; recovered from interrupted worker'),
                locked_at = NULL, 
                locked_by = NULL 
            WHERE status = 'processing' 
              AND locked_at < DATE_SUB(NOW(), INTERVAL :sec SECOND)
        ");
        $stmt->execute([':sec' => $this->staleLockTimeoutSeconds]);
        return $stmt->rowCount();
    }

    /**
     * Run a processing cycle
     */
    public function run(int $limit = 50): array
    {
        $staleReleased = $this->releaseStaleLocks();

        // 1. Find candidates due for execution
        $stmtFind = $this->pdo->prepare("
            SELECT id, workspace_id, post_id, run_at, attempts, max_attempts
            FROM scheduled_jobs
            WHERE status = 'queued' AND run_at <= NOW()
            ORDER BY run_at ASC
            LIMIT :lim
        ");
        $stmtFind->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmtFind->execute();
        $dueJobs = $stmtFind->fetchAll();

        $stats = [
            'worker_id'      => $this->workerId,
            'stale_released' => $staleReleased,
            'found'          => count($dueJobs),
            'claimed'        => 0,
            'completed'      => 0,
            'retried'        => 0,
            'failed'         => 0,
            'skipped'        => 0,
        ];

        foreach ($dueJobs as $job) {
            $jobId = (int)$job['id'];
            $postId = (int)$job['post_id'];
            $workspaceId = (int)$job['workspace_id'];
            $attempts = (int)$job['attempts'];
            $maxAttempts = (int)$job['max_attempts'];

            // 2. Atomic Claim: Only one concurrent worker can claim this job
            $stmtClaim = $this->pdo->prepare("
                UPDATE scheduled_jobs
                SET status = 'processing',
                    locked_at = NOW(),
                    locked_by = :wid
                WHERE id = :id AND status = 'queued'
            ");
            $stmtClaim->execute([
                ':wid' => $this->workerId,
                ':id'  => $jobId,
            ]);

            if ($stmtClaim->rowCount() === 0) {
                // Another concurrent worker claimed this job; safe race condition bypass
                $stats['skipped']++;
                continue;
            }

            $stats['claimed']++;

            // 3. Pre-flight Check: Verify post still exists and is in 'scheduled' status
            $stmtPost = $this->pdo->prepare("
                SELECT id, status, default_caption FROM posts 
                WHERE id = :pid AND workspace_id = :wid
            ");
            $stmtPost->execute([':pid' => $postId, ':wid' => $workspaceId]);
            $post = $stmtPost->fetch();

            if (!$post) {
                // Post was deleted; remove orphan job
                $stmtDel = $this->pdo->prepare("DELETE FROM scheduled_jobs WHERE id = :id AND locked_by = :wid");
                $stmtDel->execute([':id' => $jobId, ':wid' => $this->workerId]);
                $stats['skipped']++;
                continue;
            }

            if ($post['status'] !== 'scheduled') {
                // User cancelled schedule or post was already published/drafted
                $stmtComplete = $this->pdo->prepare("
                    UPDATE scheduled_jobs 
                    SET status = 'cancelled', 
                        locked_at = NULL, 
                        locked_by = NULL 
                    WHERE id = :id AND locked_by = :wid
                ");
                $stmtComplete->execute([':id' => $jobId, ':wid' => $this->workerId]);
                $stats['skipped']++;
                continue;
            }

            // 4. Dispatch Post Publication via PublishingService
            try {
                $result = PublishingService::publishPostImmediate($postId, $workspaceId);
                $postStatus = $result['post_status'] ?? 'published';

                if (in_array($postStatus, ['published', 'partially_published'], true)) {
                    // Successful dispatch: ensure we still own this lock
                    $stmtSuccess = $this->pdo->prepare("
                        UPDATE scheduled_jobs 
                        SET status = 'completed', 
                            locked_at = NULL, 
                            locked_by = NULL,
                            last_error = NULL
                        WHERE id = :id AND locked_by = :wid AND status = 'processing'
                    ");
                    $stmtSuccess->execute([':id' => $jobId, ':wid' => $this->workerId]);
                    if ($stmtSuccess->rowCount() > 0) {
                        $stats['completed']++;
                    } else {
                        // Lost lock due to timeout/recovery
                        $stats['skipped']++;
                    }
                } else {
                    // Publishing failed or ambiguous
                    $errDesc = 'Platform publishing returned ' . $postStatus . ' status.';
                    $isRetry = self::isRetryableError($errDesc);
                    $this->handleJobFailure($jobId, $postId, $workspaceId, $attempts, $maxAttempts, $errDesc, $isRetry);
                    if ($isRetry) {
                        $stats['retried']++;
                    } else {
                        $stats['failed']++;
                    }
                }

            } catch (Throwable $e) {
                $isRetry = self::isRetryableError($e->getMessage());
                $this->handleJobFailure($jobId, $postId, $workspaceId, $attempts, $maxAttempts, $e->getMessage(), $isRetry);
                if ($isRetry) {
                    $stats['retried']++;
                } else {
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Bounded retry policy with exponential backoff and error classification
     */
    private function handleJobFailure(int $jobId, int $postId, int $workspaceId, int $currentAttempts, int $maxAttempts, string $error, bool $isRetryable = true): void
    {
        $newAttempts = $currentAttempts + 1;

        if ($isRetryable && $newAttempts < $maxAttempts) {
            // Retryable failure: exponential backoff (e.g. attempt 1: 2 min, attempt 2: 6 min)
            $backoffMinutes = max(2, (int)pow(3, $newAttempts - 1) * 2);
            $stmtRetry = $this->pdo->prepare("
                UPDATE scheduled_jobs 
                SET status = 'queued', 
                    attempts = :att, 
                    run_at = DATE_ADD(NOW(), INTERVAL :min MINUTE), 
                    locked_at = NULL, 
                    locked_by = NULL, 
                    last_error = :err 
                WHERE id = :id AND locked_by = :wid AND status = 'processing'
            ");
            $stmtRetry->execute([
                ':att' => $newAttempts,
                ':min' => $backoffMinutes,
                ':err' => substr($error, 0, 500),
                ':id'  => $jobId,
                ':wid' => $this->workerId,
            ]);
        } else {
            // Permanent failure or max attempts reached
            $stmtFail = $this->pdo->prepare("
                UPDATE scheduled_jobs 
                SET status = 'failed', 
                    attempts = :att, 
                    locked_at = NULL, 
                    locked_by = NULL, 
                    last_error = :err 
                WHERE id = :id AND locked_by = :wid AND status = 'processing'
            ");
            $stmtFail->execute([
                ':att' => $newAttempts,
                ':err' => substr($error, 0, 500),
                ':id'  => $jobId,
                ':wid' => $this->workerId,
            ]);

            // Update post status to failed
            $stmtPostFail = $this->pdo->prepare("
                UPDATE posts 
                SET status = 'failed' 
                WHERE id = :pid AND workspace_id = :wid
            ");
            $stmtPostFail->execute([':pid' => $postId, ':wid' => $workspaceId]);
        }
    }
}
