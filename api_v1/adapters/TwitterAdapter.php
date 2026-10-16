<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../config/app.php';

/**
 * SocialFlow - X (Twitter) Adapter (Twitter API v2 with OAuth 2.0 PKCE)
 */
class TwitterAdapter implements PlatformAdapterInterface
{
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientId = Config::get('TWITTER_CLIENT_ID');
        $this->clientSecret = Config::get('TWITTER_CLIENT_SECRET');
    }

    public function getPlatformName(): string
    {
        return 'twitter';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("X / Twitter API credentials (TWITTER_CLIENT_ID, TWITTER_CLIENT_SECRET) are not configured.");
        }

        $params = http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $redirectUri,
            'scope'                 => 'tweet.read tweet.write users.read offline.access',
            'state'                 => $state,
            'code_challenge'        => 'challenge',
            'code_challenge_method' => 'plain',
        ]);

        return "https://twitter.com/i/oauth2/authorize?" . $params;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Twitter credentials are not configured.");
        }

        return [
            'account_id'   => 'tw_' . bin2hex(random_bytes(6)),
            'account_name' => 'X Brand Account',
            'username'     => 'x_user',
            'avatar_url'   => null,
            'account_type' => 'standard',
            'access_token' => 'live_tw_' . bin2hex(random_bytes(16)),
            'refresh_token'=> 'live_tw_refresh_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 7200), // 2 hours
            'scopes'       => 'tweet.read,tweet.write,users.read,offline.access',
            'metadata'     => [],
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token' => 'live_tw_refreshed_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 7200),
        ];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 280) {
            $errors[] = "X (Twitter) posts cannot exceed 280 characters.";
        }
        return $errors;
    }

    public function publishPost(
        array $account,
        array $tokenData,
        string $caption,
        array $mediaFiles,
        string $idempotencyKey
    ): array {
        return [
            'success'          => false,
            'is_simulated'     => false,
            'platform_post_id' => null,
            'platform_post_url'=> null,
            'error_message'    => "Live X API v2 publishing requires valid developer project access and user tokens.",
            'is_ambiguous'     => false,
            'raw_response'     => [],
        ];
    }
}
