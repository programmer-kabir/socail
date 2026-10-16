<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/**
 * SocialFlow - Database-Backed Rate Limiter
 * Timezone-bulletproof using MySQL NOW() intervals
 */
class RateLimiter
{
    public static function attempt(string $key, int $maxAttempts = 5, int $decaySeconds = 60): bool
    {
        $pdo = Database::getConnection();
        $keyHash = hash('sha256', $key);

        // Delete expired entries
        $stmtDel = $pdo->prepare("DELETE FROM rate_limits WHERE expires_at < NOW()");
        $stmtDel->execute();

        // Check existing attempts
        $stmt = $pdo->prepare("
            SELECT attempts 
            FROM rate_limits 
            WHERE key_hash = :key_hash AND expires_at >= NOW()
        ");
        $stmt->execute([':key_hash' => $keyHash]);
        $record = $stmt->fetch();

        if ($record) {
            if ((int)$record['attempts'] >= $maxAttempts) {
                return false; // Limit exceeded
            }

            $stmtUp = $pdo->prepare("
                UPDATE rate_limits 
                SET attempts = attempts + 1 
                WHERE key_hash = :key_hash
            ");
            $stmtUp->execute([':key_hash' => $keyHash]);
            return true;
        }

        // Insert new entry with interval
        $stmtIns = $pdo->prepare("
            INSERT INTO rate_limits (key_hash, attempts, expires_at)
            VALUES (:key_hash, 1, DATE_ADD(NOW(), INTERVAL :decay SECOND))
            ON DUPLICATE KEY UPDATE attempts = attempts + 1, expires_at = DATE_ADD(NOW(), INTERVAL :decay_dup SECOND)
        ");
        $stmtIns->execute([
            ':key_hash'  => $keyHash,
            ':decay'     => $decaySeconds,
            ':decay_dup' => $decaySeconds,
        ]);

        return true;
    }

    public static function clear(string $key): void
    {
        $pdo = Database::getConnection();
        $keyHash = hash('sha256', $key);
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE key_hash = :key_hash");
        $stmt->execute([':key_hash' => $keyHash]);
    }
}
