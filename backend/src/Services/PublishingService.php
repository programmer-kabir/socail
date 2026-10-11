<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Adapters/AdapterFactory.php';
require_once __DIR__ . '/EncryptionService.php';

/**
 * SocialFlow - Publishing Dispatcher Service
 * Orchestrates multi-platform post publishing, independent target tracking, and audit logging.
 */
class PublishingService
{
    public static function publishPostImmediate(int $postId, int $workspaceId): array
    {
        $pdo = Database::getConnection();

        // 1. Fetch post record scoped to workspace
        $stmtPost = $pdo->prepare("
            SELECT id, workspace_id, default_caption, status 
            FROM posts 
            WHERE id = :id AND workspace_id = :wid
        ");
        $stmtPost->execute([':id' => $postId, ':wid' => $workspaceId]);
        $post = $stmtPost->fetch();

        if (!$post) {
            throw new RuntimeException("Post not found in active workspace.");
        }

        // 2. Fetch media attachments
        $stmtMedia = $pdo->prepare("
            SELECT id, storage_filename, original_filename, mime_type, file_size_bytes, media_type, display_order
            FROM post_media
            WHERE post_id = :pid AND workspace_id = :wid
            ORDER BY display_order ASC
        ");
        $stmtMedia->execute([':pid' => $postId, ':wid' => $workspaceId]);
        $mediaFiles = $stmtMedia->fetchAll();

        // 3. Fetch all targets and their account data
        $stmtTargets = $pdo->prepare("
            SELECT 
                pt.id as target_id,
                pt.post_id,
                pt.social_account_id,
                pt.custom_caption,
                pt.idempotency_key,
                pt.status as target_status,
                sa.platform,
                sa.account_id as platform_account_id,
                sa.account_name,
                sa.username,
                sa.is_sandbox,
                sa.status as account_status,
                sat.access_token_encrypted,
                sat.refresh_token_encrypted,
                sat.expires_at as token_expires_at
            FROM post_targets pt
            JOIN social_accounts sa ON pt.social_account_id = sa.id
            LEFT JOIN social_account_tokens sat ON sa.id = sat.social_account_id
            WHERE pt.post_id = :pid AND sa.workspace_id = :wid
        ");
        $stmtTargets->execute([':pid' => $postId, ':wid' => $workspaceId]);
        $targets = $stmtTargets->fetchAll();

        if (empty($targets)) {
            throw new RuntimeException("No target social accounts selected for this post.");
        }

        $allPublished = true;
        $anyPublished = false;
        $targetResults = [];

        foreach ($targets as $target) {
            $targetId = (int)$target['target_id'];
            $accountId = (int)$target['social_account_id'];
            $platform = $target['platform'];
            $isSandbox = (bool)$target['is_sandbox'];
            $idempotencyKey = $target['idempotency_key'];
            $caption = !empty($target['custom_caption']) ? $target['custom_caption'] : $post['default_caption'];

            // Skip if already published (duplicate publishing defense)
            if ($target['target_status'] === 'published') {
                $anyPublished = true;
                $targetResults[$accountId] = ['status' => 'already_published'];
                continue;
            }

            // Check if account is disconnected or error
            if ($target['account_status'] === 'disconnected') {
                self::recordTargetFailure($pdo, $workspaceId, $postId, $targetId, $accountId, $isSandbox, 
                    "Social account is disconnected. Please reconnect before publishing.");
                $allPublished = false;
                $targetResults[$accountId] = ['status' => 'failed', 'error' => 'Account disconnected'];
                continue;
            }

            // Resolve decrypted tokens if live account
            $tokenData = [];
            if (!$isSandbox) {
                if (empty($target['access_token_encrypted'])) {
                    self::recordTargetFailure($pdo, $workspaceId, $postId, $targetId, $accountId, $isSandbox, 
                        "Missing authorization tokens for live {$platform} account.");
                    $allPublished = false;
                    $targetResults[$accountId] = ['status' => 'failed', 'error' => 'Missing tokens'];
                    continue;
                }
                try {
                    $tokenData['access_token'] = EncryptionService::decrypt($target['access_token_encrypted']);
                    if (!empty($target['refresh_token_encrypted'])) {
                        $tokenData['refresh_token'] = EncryptionService::decrypt($target['refresh_token_encrypted']);
                    }
                } catch (Throwable $e) {
                    self::recordTargetFailure($pdo, $workspaceId, $postId, $targetId, $accountId, $isSandbox, 
                        "Token decryption failed: " . $e->getMessage());
                    $allPublished = false;
                    $targetResults[$accountId] = ['status' => 'failed', 'error' => 'Decryption failure'];
                    continue;
                }
            }

            // Call platform adapter
            $adapter = AdapterFactory::create($platform, $isSandbox);
            $accountPayload = [
                'id'           => $accountId,
                'account_id'   => $target['platform_account_id'],
                'account_name' => $target['account_name'],
                'username'     => $target['username'],
                'is_sandbox'   => $isSandbox,
            ];

            try {
                $pubResult = $adapter->publishPost($accountPayload, $tokenData, $caption, $mediaFiles, $idempotencyKey);
            } catch (Throwable $e) {
                $pubResult = [
                    'success'          => false,
                    'is_simulated'     => $isSandbox,
                    'platform_post_id' => null,
                    'platform_post_url'=> null,
                    'error_message'    => $e->getMessage(),
                    'is_ambiguous'     => false,
                    'raw_response'     => [],
                ];
            }

            if ($pubResult['success']) {
                $anyPublished = true;
                $targetStatus = 'published';
                $stmtUp = $pdo->prepare("
                    UPDATE post_targets 
                    SET status = 'published', 
                        platform_post_id = :ppid, 
                        platform_post_url = :purl, 
                        error_message = NULL, 
                        published_at = NOW() 
                    WHERE id = :tid
                ");
                $stmtUp->execute([
                    ':ppid' => $pubResult['platform_post_id'],
                    ':purl' => $pubResult['platform_post_url'],
                    ':tid'  => $targetId,
                ]);

                // Record success log
                $logMsg = $isSandbox 
                    ? "Successfully published to {$target['account_name']} in Sandbox mode."
                    : "Published to {$target['account_name']} on {$platform}.";

                $stmtLog = $pdo->prepare("
                    INSERT INTO publishing_logs (workspace_id, post_id, social_account_id, is_simulated, action, status, message, response_payload)
                    VALUES (:wid, :pid, :aid, :sim, 'publish_post', 'success', :msg, :payload)
                ");
                $stmtLog->execute([
                    ':wid'     => $workspaceId,
                    ':pid'     => $postId,
                    ':aid'     => $accountId,
                    ':sim'     => $isSandbox ? 1 : 0,
                    ':msg'     => $logMsg,
                    ':payload' => json_encode($pubResult['raw_response'] ?? []),
                ]);

                $targetResults[$accountId] = [
                    'status'           => 'published',
                    'platform_post_id' => $pubResult['platform_post_id'],
                    'platform_post_url'=> $pubResult['platform_post_url'],
                    'is_simulated'     => $isSandbox,
                ];
            } else {
                $allPublished = false;
                $targetStatus = !empty($pubResult['is_ambiguous']) ? 'ambiguous' : 'failed';
                $stmtUp = $pdo->prepare("
                    UPDATE post_targets 
                    SET status = :st, 
                        error_message = :err,
                        attempts = attempts + 1
                    WHERE id = :tid
                ");
                $stmtUp->execute([
                    ':st'  => $targetStatus,
                    ':err' => $pubResult['error_message'],
                    ':tid' => $targetId,
                ]);

                // Record failure log
                $stmtLog = $pdo->prepare("
                    INSERT INTO publishing_logs (workspace_id, post_id, social_account_id, is_simulated, action, status, message, response_payload)
                    VALUES (:wid, :pid, :aid, :sim, 'publish_post', :st, :msg, :payload)
                ");
                $stmtLog->execute([
                    ':wid'     => $workspaceId,
                    ':pid'     => $postId,
                    ':aid'     => $accountId,
                    ':sim'     => $isSandbox ? 1 : 0,
                    ':st'      => $targetStatus === 'ambiguous' ? 'ambiguous' : 'failed',
                    ':msg'     => $pubResult['error_message'] ?? 'Publishing failed.',
                    ':payload' => json_encode($pubResult['raw_response'] ?? []),
                ]);

                $targetResults[$accountId] = [
                    'status'       => $targetStatus,
                    'error'        => $pubResult['error_message'],
                    'is_simulated' => $isSandbox,
                ];
            }
        }

        // Determine overall post status
        $finalPostStatus = 'failed';
        if ($allPublished && $anyPublished) {
            $finalPostStatus = 'published';
        } elseif ($anyPublished) {
            $finalPostStatus = 'partially_published';
        }

        if (in_array($finalPostStatus, ['published', 'partially_published'], true)) {
            $stmtPostUp = $pdo->prepare("
                UPDATE posts 
                SET status = :st, 
                    published_at = NOW() 
                WHERE id = :pid
            ");
        } else {
            $stmtPostUp = $pdo->prepare("
                UPDATE posts 
                SET status = :st 
                WHERE id = :pid
            ");
        }
        $stmtPostUp->execute([':st' => $finalPostStatus, ':pid' => $postId]);

        return [
            'post_id'     => $postId,
            'post_status' => $finalPostStatus,
            'targets'     => $targetResults,
        ];
    }

    private static function recordTargetFailure(
        PDO $pdo,
        int $workspaceId,
        int $postId,
        int $targetId,
        int $accountId,
        bool $isSandbox,
        string $errorMessage
    ): void {
        $stmtUp = $pdo->prepare("
            UPDATE post_targets 
            SET status = 'failed', error_message = :err, attempts = attempts + 1 
            WHERE id = :tid
        ");
        $stmtUp->execute([':err' => $errorMessage, ':tid' => $targetId]);

        $stmtLog = $pdo->prepare("
            INSERT INTO publishing_logs (workspace_id, post_id, social_account_id, is_simulated, action, status, message)
            VALUES (:wid, :pid, :aid, :sim, 'publish_post', 'failed', :msg)
        ");
        $stmtLog->execute([
            ':wid' => $workspaceId,
            ':pid' => $postId,
            ':aid' => $accountId,
            ':sim' => $isSandbox ? 1 : 0,
            ':msg' => $errorMessage,
        ]);
    }
}
