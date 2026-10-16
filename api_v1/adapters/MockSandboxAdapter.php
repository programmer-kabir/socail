<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';

/**
 * SocialFlow - Mock Sandbox Adapter
 * Handles development mode, sandbox accounts, and realistic platform validation simulation.
 */
class MockSandboxAdapter implements PlatformAdapterInterface
{
    private string $platform;

    public function __construct(string $platform = 'facebook')
    {
        $this->platform = strtolower($platform);
    }

    public function getPlatformName(): string
    {
        return $this->platform;
    }

    public function isConfigured(): bool
    {
        return true; // Always available for developer simulation
    }

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        return "{$redirectUri}?platform={$this->platform}&state={$state}&code=mock_sandbox_code_" . bin2hex(random_bytes(8));
    }

    public function handleCallback(string $code, string $redirectUri): array
    {
        $randomHex = bin2hex(random_bytes(4));
        return [
            'account_id'   => "sandbox_{$this->platform}_{$randomHex}",
            'account_name' => "Sandbox " . ucfirst($this->platform) . " Account",
            'username'     => "sandbox_{$this->platform}",
            'avatar_url'   => null,
            'account_type' => $this->platform === 'facebook' ? 'page' : ($this->platform === 'youtube' ? 'channel' : 'business'),
            'access_token' => "sandbox_token_{$this->platform}_{$randomHex}",
            'refresh_token'=> "sandbox_refresh_{$this->platform}_{$randomHex}",
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400 * 60), // 60 days
            'scopes'       => 'publish_content,read_insights',
            'metadata'     => [
                'mode'         => 'sandbox',
                'connected_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token' => "sandbox_refreshed_token_" . bin2hex(random_bytes(8)),
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400 * 60),
        ];
    }

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        $length = mb_strlen($caption, 'UTF-8');

        switch ($this->platform) {
            case 'twitter':
                if ($length > 280) {
                    $errors[] = "X (Twitter) posts cannot exceed 280 characters (current: {$length}).";
                }
                break;

            case 'instagram':
                if ($length > 2200) {
                    $errors[] = "Instagram captions cannot exceed 2,200 characters (current: {$length}).";
                }
                break;

            case 'tiktok':
                if ($length > 2200) {
                    $errors[] = "TikTok captions cannot exceed 2,200 characters (current: {$length}).";
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
                break;

            case 'youtube':
                if ($length > 5000) {
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
                break;

            case 'facebook':
                if ($length > 63206) {
                    $errors[] = "Facebook posts cannot exceed 63,206 characters.";
                }
                break;
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
        $validationErrors = $this->validatePost($caption, $mediaFiles);
        if (!empty($validationErrors)) {
            return [
                'success'          => false,
                'is_simulated'     => true,
                'platform_post_id' => null,
                'platform_post_url'=> null,
                'error_message'    => implode(' ', $validationErrors),
                'is_ambiguous'     => false,
                'raw_response'     => ['errors' => $validationErrors],
            ];
        }

        $mockId = 'sim_' . bin2hex(random_bytes(8));
        return [
            'success'          => true,
            'is_simulated'     => true,
            'platform_post_id' => $mockId,
            'platform_post_url'=> "https://sandbox.socialflow.io/{$this->platform}/posts/{$mockId}",
            'error_message'    => null,
            'is_ambiguous'     => false,
            'raw_response'     => [
                'mode'            => 'sandbox_simulation',
                'idempotency_key' => $idempotencyKey,
                'timestamp'       => gmdate('Y-m-d H:i:s'),
            ],
        ];
    }
}
