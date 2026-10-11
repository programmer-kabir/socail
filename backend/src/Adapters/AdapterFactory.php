<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/MockSandboxAdapter.php';
require_once __DIR__ . '/FacebookAdapter.php';
require_once __DIR__ . '/InstagramAdapter.php';
require_once __DIR__ . '/TwitterAdapter.php';
require_once __DIR__ . '/TikTokAdapter.php';
require_once __DIR__ . '/YouTubeAdapter.php';

/**
 * SocialFlow - Adapter Factory
 */
class AdapterFactory
{
    public static function create(string $platform, bool $isSandbox = false): PlatformAdapterInterface
    {
        $platform = strtolower(trim($platform));

        if ($isSandbox) {
            return new MockSandboxAdapter($platform);
        }

        return match ($platform) {
            'facebook'  => new FacebookAdapter(),
            'instagram' => new InstagramAdapter(),
            'twitter'   => new TwitterAdapter(),
            'tiktok'    => new TikTokAdapter(),
            'youtube'   => new YouTubeAdapter(),
            default     => throw new InvalidArgumentException("Unsupported platform: {$platform}"),
        };
    }
}
