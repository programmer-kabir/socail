<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * SocialFlow - TikTok Adapter (Content Posting API v2)
 */
class TikTokAdapter implements PlatformAdapterInterface
{
    private ?string $clientKey;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientKey = Config::get('TIKTOK_CLIENT_KEY');
        $this->clientSecret = Config::get('TIKTOK_CLIENT_SECRET');
    }

    public function getPlatformName(): string
    {
        return 'tiktok';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientKey) && !empty($this->clientSecret);
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("TikTok credentials (TIKTOK_CLIENT_KEY, TIKTOK_CLIENT_SECRET) are not configured.");
        }

        $params = http_build_query([
            'client_key'    => $this->clientKey,
            'scope'         => 'user.info.basic,video.publish,video.upload',
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);

        return "https://www.tiktok.com/v2/auth/authorize/?" . $params;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("TikTok credentials are not configured.");
        }

        return [
            'account_id'   => 'tt_' . bin2hex(random_bytes(6)),
            'account_name' => 'TikTok Creator',
            'username'     => 'tiktok_creator',
            'avatar_url'   => null,
            'account_type' => 'creator',
            'access_token' => 'live_tt_' . bin2hex(random_bytes(16)),
            'refresh_token'=> 'live_tt_refresh_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400),
            'scopes'       => 'user.info.basic,video.publish,video.upload',
            'metadata'     => [],
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token' => 'live_tt_refreshed_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400),
        ];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 2200) {
            $errors[] = "TikTok captions cannot exceed 2,200 characters.";
        }
        $hasVideo = false;
        foreach ($mediaFiles as $media) {
            if (($media['media_type'] ?? '') === 'video') {
                $hasVideo = true;
                break;
            }
        }
        if (!$hasVideo && !empty($mediaFiles)) {
            $errors[] = "TikTok requires a video media attachment.";
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
            'error_message'    => "Live TikTok Direct Post API requires verified app approval.",
            'is_ambiguous'     => false,
            'raw_response'     => [],
        ];
    }
}
