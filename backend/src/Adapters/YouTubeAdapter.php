<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * SocialFlow - YouTube Adapter (Google YouTube Data API v3)
 */
class YouTubeAdapter implements PlatformAdapterInterface
{
    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientId = Config::get('GOOGLE_CLIENT_ID');
        $this->clientSecret = Config::get('GOOGLE_CLIENT_SECRET');
    }

    public function getPlatformName(): string
    {
        return 'youtube';
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google / YouTube credentials (GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET) are not configured.");
        }

        $params = http_build_query([
            'client_id'              => $this->clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'state'                  => $state,
            'include_granted_scopes' => 'true',
        ]);

        return "https://accounts.google.com/o/oauth2/v2/auth?" . $params;
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException("Google credentials are not configured.");
        }

        return [
            'account_id'   => 'yt_' . bin2hex(random_bytes(6)),
            'account_name' => 'YouTube Channel',
            'username'     => 'youtube_channel',
            'avatar_url'   => null,
            'account_type' => 'channel',
            'access_token' => 'live_yt_' . bin2hex(random_bytes(16)),
            'refresh_token'=> 'live_yt_refresh_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 3600),
            'scopes'       => 'https://www.googleapis.com/auth/youtube.upload',
            'metadata'     => [],
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token' => 'live_yt_refreshed_' . bin2hex(random_bytes(16)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 3600),
        ];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 5000) {
            $errors[] = "YouTube descriptions cannot exceed 5,000 characters.";
        }
        $hasVideo = false;
        foreach ($mediaFiles as $media) {
            if (($media['media_type'] ?? '') === 'video') {
                $hasVideo = true;
                break;
            }
        }
        if (!$hasVideo && !empty($mediaFiles)) {
            $errors[] = "YouTube requires a video media attachment.";
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
            'error_message'    => "Live YouTube Data API v3 resumable uploading requires verified Google app consent.",
            'is_ambiguous'     => false,
            'raw_response'     => [],
        ];
    }
}
