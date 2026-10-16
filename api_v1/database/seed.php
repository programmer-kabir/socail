<?php
declare(strict_types=1);

/**
 * SocialFlow - Database Seeder
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/EncryptionService.php';

Config::load(__DIR__ . '/../.env');

echo "=== SocialFlow Database Seeder ===" . PHP_EOL;

try {
    $pdo = Database::getConnection();

    // 1. Check if demo user already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute([':email' => 'demo@socialflow.io']);
    $existingUser = $stmt->fetch();

    if ($existingUser) {
        echo "Demo user already exists (User ID: {$existingUser['id']}). Skipping seed." . PHP_EOL;
        exit(0);
    }

    $pdo->beginTransaction();

    // 2. Create Demo User
    $passwordHash = password_hash('Password123!', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("
        INSERT INTO users (name, email, password_hash, email_verified_at)
        VALUES (:name, :email, :password_hash, NOW())
    ");
    $stmt->execute([
        ':name'          => 'Demo Administrator',
        ':email'         => 'demo@socialflow.io',
        ':password_hash' => $passwordHash,
    ]);
    $userId = (int)$pdo->lastInsertId();
    echo "Created Demo User: demo@socialflow.io (ID: {$userId})" . PHP_EOL;

    // 3. Create Default Workspace
    $stmt = $pdo->prepare("
        INSERT INTO workspaces (owner_id, name, slug, timezone)
        VALUES (:owner_id, :name, :slug, :timezone)
    ");
    $stmt->execute([
        ':owner_id' => $userId,
        ':name'     => 'Acme Marketing Agency',
        ':slug'     => 'acme-marketing',
        ':timezone' => 'America/New_York',
    ]);
    $workspaceId = (int)$pdo->lastInsertId();
    echo "Created Workspace: Acme Marketing Agency (ID: {$workspaceId})" . PHP_EOL;

    // 4. Create Workspace Member (Owner)
    $stmt = $pdo->prepare("
        INSERT INTO workspace_members (workspace_id, user_id, role)
        VALUES (:workspace_id, :user_id, 'owner')
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':user_id'      => $userId,
    ]);

    // 5. Create Sandbox Social Accounts
    $sandboxAccounts = [
        [
            'platform'     => 'facebook',
            'account_id'   => 'fb_1029384756',
            'account_name' => 'Acme Global Facebook Page',
            'username'     => 'acmeglobal',
            'account_type' => 'page',
        ],
        [
            'platform'     => 'instagram',
            'account_id'   => 'ig_9876543210',
            'account_name' => 'Acme Official Instagram',
            'username'     => 'acme_official',
            'account_type' => 'business',
        ],
        [
            'platform'     => 'twitter',
            'account_id'   => 'tw_1122334455',
            'account_name' => 'Acme Brand X Feed',
            'username'     => 'acme_updates',
            'account_type' => 'standard',
        ],
        [
            'platform'     => 'youtube',
            'account_id'   => 'yt_UC99887766',
            'account_name' => 'Acme Studios Channel',
            'username'     => 'acmestudios',
            'account_type' => 'channel',
        ],
        [
            'platform'     => 'tiktok',
            'account_id'   => 'tt_5544332211',
            'account_name' => 'Acme TikTok Brand',
            'username'     => 'acme_tiktok',
            'account_type' => 'creator',
        ],
    ];

    $createdAccountIds = [];
    foreach ($sandboxAccounts as $acc) {
        $stmt = $pdo->prepare("
            INSERT INTO social_accounts (workspace_id, platform, account_id, account_name, username, account_type, status, is_sandbox, metadata)
            VALUES (:workspace_id, :platform, :account_id, :account_name, :username, :account_type, 'active', 1, :metadata)
        ");
        $stmt->execute([
            ':workspace_id' => $workspaceId,
            ':platform'     => $acc['platform'],
            ':account_id'   => $acc['account_id'],
            ':account_name' => $acc['account_name'],
            ':username'     => $acc['username'],
            ':account_type' => $acc['account_type'],
            ':metadata'     => json_encode(['connected_by' => $userId, 'mode' => 'sandbox']),
        ]);
        $accId = (int)$pdo->lastInsertId();
        $createdAccountIds[$acc['platform']] = $accId;

        // Encrypt and store mock token
        $encryptedToken = EncryptionService::encrypt('sandbox_token_' . $acc['platform'] . '_' . bin2hex(random_bytes(8)));
        $stmtToken = $pdo->prepare("
            INSERT INTO social_account_tokens (social_account_id, access_token_encrypted, scopes)
            VALUES (:social_account_id, :access_token_encrypted, :scopes)
        ");
        $stmtToken->execute([
            ':social_account_id'      => $accId,
            ':access_token_encrypted' => $encryptedToken,
            ':scopes'                 => 'publish_actions,read_insights',
        ]);
    }
    echo "Created 5 Sandbox Social Accounts with encrypted credentials." . PHP_EOL;

    // 6. Create Sample Posts
    // Post A: Published Post
    $stmt = $pdo->prepare("
        INSERT INTO posts (workspace_id, author_id, default_caption, status, published_at)
        VALUES (:workspace_id, :author_id, :caption, 'published', NOW() - INTERVAL 2 HOUR)
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':author_id'    => $userId,
        ':caption'      => 'Excited to announce our Q4 product roadmap! Stay tuned for groundbreaking updates. 🚀 #innovation #product',
    ]);
    $pubPostId = (int)$pdo->lastInsertId();

    // Targets for Post A
    foreach (['twitter', 'facebook', 'instagram'] as $platform) {
        $accId = $createdAccountIds[$platform];
        $idempotencyKey = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $stmtTarget = $pdo->prepare("
            INSERT INTO post_targets (post_id, social_account_id, idempotency_key, platform_post_id, status, published_at)
            VALUES (:post_id, :social_account_id, :idempotency_key, :platform_post_id, 'published', NOW() - INTERVAL 2 HOUR)
        ");
        $stmtTarget->execute([
            ':post_id'           => $pubPostId,
            ':social_account_id' => $accId,
            ':idempotency_key'   => $idempotencyKey,
            ':platform_post_id'  => 'sim_' . bin2hex(random_bytes(6)),
        ]);

        // Publishing Log
        $stmtLog = $pdo->prepare("
            INSERT INTO publishing_logs (workspace_id, post_id, social_account_id, is_simulated, action, status, message)
            VALUES (:workspace_id, :post_id, :social_account_id, 1, 'publish_post', 'success', 'Successfully published in sandbox environment')
        ");
        $stmtLog->execute([
            ':workspace_id'      => $workspaceId,
            ':post_id'           => $pubPostId,
            ':social_account_id' => $accId,
        ]);
    }

    // Post B: Scheduled Post
    $stmt = $pdo->prepare("
        INSERT INTO posts (workspace_id, author_id, default_caption, status, scheduled_at)
        VALUES (:workspace_id, :author_id, :caption, 'scheduled', NOW() + INTERVAL 1 DAY)
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':author_id'    => $userId,
        ':caption'      => 'Join us tomorrow live for our weekly community Q&A session! Drop your questions in the comments below. 👇',
    ]);
    $schedPostId = (int)$pdo->lastInsertId();

    foreach (['twitter', 'facebook'] as $platform) {
        $accId = $createdAccountIds[$platform];
        $idempotencyKey = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
        $stmtTarget = $pdo->prepare("
            INSERT INTO post_targets (post_id, social_account_id, idempotency_key, status)
            VALUES (:post_id, :social_account_id, :idempotency_key, 'pending')
        ");
        $stmtTarget->execute([
            ':post_id'           => $schedPostId,
            ':social_account_id' => $accId,
            ':idempotency_key'   => $idempotencyKey,
        ]);
    }

    // Scheduled Queue Job
    $stmtJob = $pdo->prepare("
        INSERT INTO scheduled_jobs (workspace_id, post_id, run_at, status)
        VALUES (:workspace_id, :post_id, NOW() + INTERVAL 1 DAY, 'queued')
    ");
    $stmtJob->execute([
        ':workspace_id' => $workspaceId,
        ':post_id'      => $schedPostId,
    ]);

    // Post C: Draft Post
    $stmt = $pdo->prepare("
        INSERT INTO posts (workspace_id, author_id, default_caption, status)
        VALUES (:workspace_id, :author_id, :caption, 'draft')
    ");
    $stmt->execute([
        ':workspace_id' => $workspaceId,
        ':author_id'    => $userId,
        ':caption'      => 'Draft: Upcoming seasonal promotions draft copy for team review.',
    ]);

    $pdo->commit();
    echo "Seed completed successfully!" . PHP_EOL;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo PHP_EOL . "SEED ERROR: " . $e->getMessage() . PHP_EOL;
    echo "File: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
    exit(1);
}
