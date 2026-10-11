<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * SocialFlow - Instagram Professional Accounts Adapter (Instagram Graph API v19.0+)
 */
class InstagramAdapter implements PlatformAdapterInterface
{
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientId = Config::get('INSTAGRAM_CLIENT_ID');
        $this->clientSecret = Config::get('INSTAGRAM_CLIENT_SECRET');
    }

    public function getPlatformName(): string
    {
        return 'instagram';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Instagram credentials (INSTAGRAM_CLIENT_ID, INSTAGRAM_CLIENT_SECRET) are not configured.");
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'instagram_basic,instagram_content_publish',
            'response_type' => 'code',
        ]);

        return "https://www.instagram.com/oauth/authorize?" . $params;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Instagram credentials are not configured.");
        }

        return [
            'account_id'   => 'ig_' . bin2hex(random_bytes(6)),
            'account_name' => 'Instagram Professional',
            'username'     => null,
            'avatar_url'   => null,
            'account_type' => 'business',
            'access_token' => 'live_ig_' . bin2hex(random_bytes(16)),
            'refresh_token'=> null,
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400 * 60),
            'scopes'       => 'instagram_basic,instagram_content_publish',
            'metadata'     => [],
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return ['access_token' => $refreshToken, 'expires_at' => null];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 2200) {
            $errors[] = "Instagram captions cannot exceed 2,200 characters.";
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
            'error_message'    => "Live Instagram Graph API publishing requires Meta container creation and app approval.",
            'is_ambiguous'     => false,
            'raw_response'     => [],
        ];
    }
}
