<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * SocialFlow - Facebook Pages Adapter (Meta Graph API v19.0+)
 */
class FacebookAdapter implements PlatformAdapterInterface
{
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientId = Config::get('FACEBOOK_CLIENT_ID');
        $this->clientSecret = Config::get('FACEBOOK_CLIENT_SECRET');
    }

    public function getPlatformName(): string
    {
        return 'facebook';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Facebook App credentials (FACEBOOK_CLIENT_ID, FACEBOOK_CLIENT_SECRET) are not configured.");
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts',
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/v19.0/dialog/oauth?" . $params;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Facebook App credentials are not configured.");
        }

        // Exchange code for short-lived user token
        $url = 'https://graph.facebook.com/v19.0/oauth/access_token?' . http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($res ?: '', true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $err = $data['error']['message'] ?? 'Failed to exchange Facebook OAuth code.';
            throw new RuntimeException("Facebook OAuth Error: {$err}");
        }

        // Return token and metadata structure
        return [
            'account_id'   => 'fb_' . bin2hex(random_bytes(6)),
            'account_name' => 'Facebook Page',
            'username'     => null,
            'avatar_url'   => null,
            'account_type' => 'page',
            'access_token' => $data['access_token'],
            'refresh_token'=> null,
            'expires_at'   => isset($data['expires_in']) ? gmdate('Y-m-d H:i:s', time() + (int)$data['expires_in']) : null,
            'scopes'       => 'pages_manage_posts,pages_read_engagement',
            'metadata'     => $data,
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        // Facebook uses 60-day exchange tokens
        return ['access_token' => $refreshToken, 'expires_at' => null];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 63206) {
            $errors[] = "Facebook posts cannot exceed 63,206 characters.";
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
        if (!$this->isConfigured()) {
            return [
                'success'          => false,
                'is_simulated'     => false,
                'platform_post_id' => null,
                'platform_post_url'=> null,
                'error_message'    => "Facebook App credentials are not configured on this server.",
                'is_ambiguous'     => false,
                'raw_response'     => [],
            ];
        }

        return [
            'success'          => false,
            'is_simulated'     => false,
            'platform_post_id' => null,
            'platform_post_url'=> null,
            'error_message'    => "Live Facebook API publishing requires approved Meta app review.",
            'is_ambiguous'     => false,
            'raw_response'     => [],
        ];
    }
}
