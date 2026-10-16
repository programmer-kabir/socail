<?php
declare(strict_types=1);

require_once __DIR__ . '/PlatformAdapterInterface.php';
require_once __DIR__ . '/../config/app.php';

/**
 * SocialFlow - Facebook Pages Adapter (Meta Graph API v19.0+)
 *
 * OAuth flow:
 *   1. getAuthorizationUrl() → redirects user to Facebook dialog
 *   2. handleCallback()     → exchanges auth code for user token → fetches Page
 *                             list via /me/accounts → stores Page access token
 *   3. publishPost()        → POSTs to /{page-id}/feed with Page access token
 *
 * Security:
 *   - App Secret (FACEBOOK_CLIENT_SECRET) is never sent to the frontend.
 *   - Page access tokens are stored AES-256-GCM encrypted (handled by caller).
 *   - No credentials are written to logs.
 *
 * Limitations (current):
 *   - When a user manages multiple Pages, only the first Page returned by
 *     /me/accounts is connected per OAuth flow. A Page-selection UI would be
 *     needed to support multi-page workspaces in a future iteration.
 *   - Photo/video publishing is not yet implemented; only text feed posts.
 */
class FacebookAdapter implements PlatformAdapterInterface
{
    private const GRAPH_VERSION = 'v19.0';
    private const GRAPH_BASE    = 'https://graph.facebook.com';  // Token exchange & Graph API
    private const AUTH_BASE     = 'https://www.facebook.com';   // OAuth dialog only

    private ?string $clientId;
    private ?string $clientSecret;

    public function __construct()
    {
        $this->clientId     = Config::get('FACEBOOK_CLIENT_ID');
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

    // ─────────────────────────────────────────────────────────────────────────
    // Authorization URL
    // ─────────────────────────────────────────────────────────────────────────

    public function getAuthorizationUrl(string $state, string $redirectUri): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Facebook App credentials (FACEBOOK_CLIENT_ID, FACEBOOK_CLIENT_SECRET) are not configured.'
            );
        }

        $params = http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'pages_show_list,pages_read_engagement,pages_manage_posts',
            'response_type' => 'code',
        ]);

        return self::AUTH_BASE . '/' . self::GRAPH_VERSION . '/dialog/oauth?' . $params;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Callback: code → user token → page list → page access token
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Exchanges the authorization code for a short-lived user access token,
     * then retrieves the list of Facebook Pages managed by the user and returns
     * the first Page's Page access token.
     *
     * The Page access token (not the user token) is what must be stored and
     * used for all subsequent Page publishing calls.
     *
     * @throws RuntimeException on any API or network failure.
     */
    public function handleCallback(string $code, string $redirectUri): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Facebook App credentials are not configured.');
        }

        // Step 1: Exchange authorization code for short-lived user access token
        $tokenUrl = self::GRAPH_BASE . '/' . self::GRAPH_VERSION . '/oauth/access_token?' . http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $redirectUri,
            'code'          => $code,
        ]);

        $tokenData = $this->graphGet($tokenUrl);

        if (empty($tokenData['access_token'])) {
            $err = $tokenData['error']['message'] ?? 'Failed to exchange authorization code for access token.';
            throw new RuntimeException("Facebook OAuth token exchange failed: {$err}");
        }

        $userToken = $tokenData['access_token'];

        // Step 2: Retrieve Pages managed by this user, including per-Page access tokens
        $pagesUrl = self::GRAPH_BASE . '/' . self::GRAPH_VERSION . '/me/accounts?' . http_build_query([
            'fields'       => 'id,name,access_token,picture{url},link,category',
            'access_token' => $userToken,
        ]);

        $pagesResponse = $this->graphGet($pagesUrl);

        if (isset($pagesResponse['error'])) {
            $err = $pagesResponse['error']['message'] ?? 'Could not retrieve Facebook Pages.';
            throw new RuntimeException("Facebook Pages API error: {$err}");
        }

        $pages = $pagesResponse['data'] ?? [];

        if (empty($pages)) {
            throw new RuntimeException(
                'No Facebook Pages found for this account. ' .
                'Make sure you manage at least one Page and that the ' .
                'pages_show_list permission was granted during authorization.'
            );
        }

        // Use the first Page returned. When a user manages multiple Pages,
        // a page-selection step would be required to let them pick which one
        // to connect. This is the correct architecture for a future UI.
        $page = $pages[0];

        if (empty($page['id']) || empty($page['access_token'])) {
            throw new RuntimeException(
                'Facebook did not return a valid Page ID or Page access token. ' .
                'Ensure pages_manage_posts permission was granted.'
            );
        }

        $avatarUrl = $page['picture']['data']['url'] ?? null;

        return [
            // account_id stores the Facebook Page ID (numeric string)
            'account_id'    => $page['id'],
            'account_name'  => $page['name'],
            // Facebook Pages don't have an @username; store the page link instead
            'username'      => $page['link'] ?? null,
            'avatar_url'    => $avatarUrl,
            'account_type'  => 'page',
            // Store the PAGE access token — never the user token
            'access_token'  => $page['access_token'],
            'refresh_token' => null,
            // Page access tokens do not expire while the user remains connected
            'expires_at'    => null,
            'scopes'        => 'pages_show_list,pages_read_engagement,pages_manage_posts',
            'metadata'      => [
                'page_id'      => $page['id'],
                'page_name'    => $page['name'],
                'page_link'    => $page['link'] ?? null,
                'category'     => $page['category'] ?? null,
                'pages_found'  => count($pages),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Token Refresh (Facebook uses long-lived page tokens; no rotation needed)
    // ─────────────────────────────────────────────────────────────────────────

    public function refreshToken(string $refreshToken): array
    {
        // Facebook Page access tokens do not expire and cannot be refreshed
        // via a standard refresh flow. Return as-is.
        return ['access_token' => $refreshToken, 'expires_at' => null];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Post Validation
    // ─────────────────────────────────────────────────────────────────────────

    public function validatePost(string $caption, array $mediaFiles): array
    {
        $errors = [];
        if (mb_strlen($caption, 'UTF-8') > 63206) {
            $errors[] = 'Facebook posts cannot exceed 63,206 characters.';
        }
        return $errors;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Publish Post
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Publishes a text post to the Facebook Page feed.
     *
     * Uses the Page access token stored in $tokenData['access_token'].
     * The page ID is read from $account['account_id'].
     *
     * Media files: currently only text posts are supported.
     * Photo/video publishing requires additional Graph API calls
     * (upload to /{page-id}/photos, collect media IDs, attach to feed post)
     * and will be implemented in a future phase.
     *
     * IMPORTANT: Do not consider publishing confirmed until an actual live test
     * returns a successful `id` field in the response.
     */
    public function publishPost(
        array $account,
        array $tokenData,
        string $caption,
        array $mediaFiles,
        string $idempotencyKey
    ): array {
        if (!$this->isConfigured()) {
            return $this->errorResult('Facebook App credentials are not configured on this server.');
        }

        $pageId    = $account['account_id'] ?? null;
        $pageToken = $tokenData['access_token'] ?? null;

        if (empty($pageId) || empty($pageToken)) {
            return $this->errorResult('Missing Page ID or Page access token. Please reconnect your Facebook Page.');
        }

        if (empty(trim($caption))) {
            return $this->errorResult('Post caption cannot be empty.');
        }

        // Note media limitation
        $hasMedia = !empty($mediaFiles);
        if ($hasMedia) {
            // Media publishing is not yet implemented for live Facebook posts.
            // Fall through and publish as text-only.
            // TODO: Implement photo upload via POST /{page-id}/photos and
            //       attach media IDs to the feed post.
        }

        // Publish text post to the Page feed
        $endpoint = self::GRAPH_BASE . '/' . self::GRAPH_VERSION . "/{$pageId}/feed";

        $postFields = http_build_query([
            'message'      => $caption,
            'access_token' => $pageToken,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $rawResponse = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return $this->errorResult("Network error while contacting Facebook API: {$curlError}");
        }

        $data = json_decode($rawResponse ?: '', true) ?? [];

        // Success: Graph API returns {"id": "<page-id>_<post-id>"}
        if ($httpCode === 200 && !empty($data['id'])) {
            $postId  = $data['id'];
            $parts   = explode('_', $postId, 2);
            $postUrl = count($parts) === 2
                ? "https://www.facebook.com/{$parts[0]}/posts/{$parts[1]}"
                : "https://www.facebook.com/{$postId}";

            return [
                'success'           => true,
                'is_simulated'      => false,
                'platform_post_id'  => $postId,
                'platform_post_url' => $postUrl,
                'error_message'     => null,
                'is_ambiguous'      => false,
                'raw_response'      => $data,
            ];
        }

        // Error from Graph API
        $errMsg  = $data['error']['message'] ?? "Publishing failed (HTTP {$httpCode}).";
        $errCode = $data['error']['code'] ?? null;

        // Facebook error codes 1 and 2 are "unknown" — the post may or may not
        // have been published. Mark as ambiguous to prevent duplicate attempts.
        $isAmbiguous = in_array($errCode, [1, 2], true);

        return [
            'success'           => false,
            'is_simulated'      => false,
            'platform_post_id'  => null,
            'platform_post_url' => null,
            'error_message'     => "Facebook API Error (code {$errCode}): {$errMsg}",
            'is_ambiguous'      => $isAmbiguous,
            'raw_response'      => $data,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Executes a GET request and returns decoded JSON as an array.
     */
    private function graphGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw  = curl_exec($ch);
        curl_close($ch);

        return json_decode($raw ?: '', true) ?? [];
    }

    /**
     * Returns a standardised error result array.
     */
    private function errorResult(string $message, bool $isAmbiguous = false): array
    {
        return [
            'success'           => false,
            'is_simulated'      => false,
            'platform_post_id'  => null,
            'platform_post_url' => null,
            'error_message'     => $message,
            'is_ambiguous'      => $isAmbiguous,
            'raw_response'      => [],
        ];
    }
}
