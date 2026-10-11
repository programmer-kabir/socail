<?php
declare(strict_types=1);

/**
 * SocialFlow - Common Platform Adapter Interface
 * All social media network adapters implement this contract.
 */
interface PlatformAdapterInterface
{
    /**
     * Unique platform identifier ('facebook', 'instagram', 'twitter', 'tiktok', 'youtube')
     */
    public function getPlatformName(): string;

    /**
     * Whether official OAuth credentials (client id & secret) are configured in environment
     */
    public function isConfigured(): bool;

    /**
     * Generate the official OAuth 2.0 authorization URL
     */
    public function getAuthorizationUrl(string $state, string $redirectUri): string;

    /**
     * Handle OAuth callback: exchange code for access & refresh tokens and account metadata
     * Returns: [
     *   'account_id' => string,
     *   'account_name' => string,
     *   'username' => ?string,
     *   'avatar_url' => ?string,
     *   'account_type' => string,
     *   'access_token' => string,
     *   'refresh_token' => ?string,
     *   'expires_at' => ?string (Y-m-d H:i:s),
     *   'scopes' => ?string,
     *   'metadata' => array,
     * ]
     */
    public function handleCallback(string $code, string $redirectUri): array;

    /**
     * Refresh an expired OAuth access token
     */
    public function refreshToken(string $refreshToken): array;

    /**
     * Validate post caption and media against official platform rules
     * Returns list of validation error messages, or empty array if valid.
     */
    public function validatePost(string $caption, array $mediaFiles): array;

    /**
     * Publish post to the platform
     * Returns: [
     *   'success' => bool,
     *   'is_simulated' => bool,
     *   'platform_post_id' => ?string,
     *   'platform_post_url' => ?string,
     *   'error_message' => ?string,
     *   'is_ambiguous' => bool, // true if network timed out and state cannot be confirmed
     *   'raw_response' => array,
     * ]
     */
    public function publishPost(
        array $account,
        array $tokenData,
        string $caption,
        array $mediaFiles,
        string $idempotencyKey
    ): array;
}
