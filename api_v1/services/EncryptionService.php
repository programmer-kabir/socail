<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

/**
 * SocialFlow - Cryptographic Service
 * Implements authenticated AES-256-GCM encryption for OAuth tokens and credentials.
 */

class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12; // 96-bit IV recommended for GCM

    private static ?string $key = null;

    private static function getKey(): string
    {
        if (self::$key === null) {
            $base64Key = Config::get('APP_ENCRYPTION_KEY');
            if (empty($base64Key)) {
                throw new RuntimeException("APP_ENCRYPTION_KEY is not configured in .env");
            }
            $decoded = base64_decode($base64Key, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException("APP_ENCRYPTION_KEY must be a valid 32-byte base64-encoded key.");
            }
            self::$key = $decoded;
        }

        return self::$key;
    }

    /**
     * Encrypt a string using AES-256-GCM
     * Format: base64(IV [12 bytes] . Tag [16 bytes] . Ciphertext)
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::getKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException("Encryption failed: " . openssl_error_string());
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an AES-256-GCM encrypted payload
     */
    public static function decrypt(string $payload): string
    {
        $key = self::getKey();
        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) < (self::IV_LENGTH + self::TAG_LENGTH)) {
            throw new InvalidArgumentException("Invalid encryption payload format.");
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $tag = substr($decoded, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException("Decryption or authentication tag verification failed.");
        }

        return $plaintext;
    }

    /**
     * Hash an opaque session/verification token (SHA-256)
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Generate a cryptographically secure opaque token (64 hex characters)
     */
    public static function generateOpaqueToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
