<?php
declare(strict_types=1);

/**
 * SocialFlow API v1 - Security & Cryptographic Helpers
 */

require_once __DIR__ . '/../config/app.php';

class Security
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const IV_LENGTH = 12;

    public static function getEncryptionKey(): string
    {
        $base64Key = Config::get('APP_ENCRYPTION_KEY');
        if (empty($base64Key)) {
            // Fallback default dev key
            $base64Key = '2UYFRwvJbaZHHihnqlcs7YqdfAzIWdKWBEkyxmgq9lU=';
        }
        $decoded = base64_decode($base64Key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            return hash('sha256', $base64Key, true);
        }
        return $decoded;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::getEncryptionKey();
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
            throw new RuntimeException("Encryption failed.");
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::getEncryptionKey();
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
            throw new RuntimeException("Decryption failed.");
        }

        return $plaintext;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
