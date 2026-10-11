<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Utils/Response.php';

/**
 * SocialFlow - Secure Media Management Controller
 * Strict MIME inspection, size enforcement, and workspace authorization gateway.
 */
class MediaController
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg'      => ['ext' => 'jpg', 'type' => 'image', 'max_bytes' => 10485760], // 10MB
        'image/png'       => ['ext' => 'png', 'type' => 'image', 'max_bytes' => 10485760], // 10MB
        'image/webp'      => ['ext' => 'webp', 'type' => 'image', 'max_bytes' => 10485760], // 10MB
        'video/mp4'       => ['ext' => 'mp4', 'type' => 'video', 'max_bytes' => 52428800], // 50MB
        'video/quicktime' => ['ext' => 'mov', 'type' => 'video', 'max_bytes' => 52428800], // 50MB
    ];

    /**
     * Upload an image or video file
     */
    public function upload(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $uploaded = $request['files']['media'] ?? $request['files']['file'] ?? null;

        if (!$uploaded || !isset($uploaded['tmp_name']) || empty($uploaded['tmp_name'])) {
            Response::error('No file was uploaded.', 422, ['media' => ['A valid media file is required.']]);
        }

        if ($uploaded['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload failed with error code: ' . $uploaded['error'], 400);
        }

        $tmpPath = $uploaded['tmp_name'];
        $origName = basename($uploaded['name'] ?? 'upload.bin');
        $fileSize = (int)($uploaded['size'] ?? 0);

        // 1. Deep MIME Type inspection using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!$realMime || !isset(self::ALLOWED_MIME_TYPES[$realMime])) {
            Response::error(
                "Unsupported file format ({$realMime}). Allowed formats: JPG, PNG, WebP, MP4, MOV.",
                415,
                ['mime_type' => ["Detected type '{$realMime}' is not supported."]]
            );
        }

        $config = self::ALLOWED_MIME_TYPES[$realMime];

        // 2. Strict file size enforcement
        if ($fileSize > $config['max_bytes']) {
            $maxMb = round($config['max_bytes'] / (1024 * 1024));
            Response::error(
                "File exceeds maximum allowed size of {$maxMb}MB for {$config['type']}s.",
                413,
                ['file_size' => ["File size is {$fileSize} bytes, maximum is {$config['max_bytes']} bytes."]]
            );
        }

        // 3. Prevent dangerous executable script names or polyglots
        if (preg_match('/\.(php|phtml|phar|cgi|pl|exe|sh|bat|cmd|js|html|htm)/i', $origName)) {
            Response::error('Files containing script extensions are rejected for security.', 422);
        }

        // 4. Generate cryptographically random storage filename
        $storageFilename = bin2hex(random_bytes(16)) . '.' . $config['ext'];
        $storageDir = __DIR__ . '/../../storage/uploads';

        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $targetPath = $storageDir . '/' . $storageFilename;
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            // Fallback for CLI / unit testing where move_uploaded_file requires copy
            if (defined('TEST_MODE') && copy($tmpPath, $targetPath)) {
                @unlink($tmpPath);
            } else {
                Response::error('Failed to move uploaded file to secure storage.', 500);
            }
        }

        // 5. Save metadata to MySQL post_media
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            INSERT INTO post_media (workspace_id, storage_filename, original_filename, mime_type, file_size_bytes, media_type)
            VALUES (:wid, :sname, :oname, :mime, :size, :mtype)
        ");
        $stmt->execute([
            ':wid'   => $workspaceId,
            ':sname' => $storageFilename,
            ':oname' => $origName,
            ':mime'  => $realMime,
            ':size'  => $fileSize,
            ':mtype' => $config['type'],
        ]);
        $mediaId = (int)$pdo->lastInsertId();

        $previewUrl = "/api/media/file/{$storageFilename}";

        Response::json([
            'id'                => $mediaId,
            'storage_filename'  => $storageFilename,
            'original_filename' => $origName,
            'mime_type'         => $realMime,
            'file_size_bytes'   => $fileSize,
            'media_type'        => $config['type'],
            'preview_url'       => $previewUrl,
        ], 201, 'Media uploaded successfully.');
    }

    /**
     * Authenticated Media Streaming Gateway
     * Verifies that the file belongs to the user's active workspace.
     */
    public function file(array $request): void
    {
        $workspaceId = $request['workspace']['id'];
        $filename = basename($request['params']['filename'] ?? '');

        if (empty($filename)) {
            Response::error('Invalid filename.', 400);
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT * FROM post_media 
            WHERE storage_filename = :fname AND workspace_id = :wid
        ");
        $stmt->execute([
            ':fname' => $filename,
            ':wid'   => $workspaceId,
        ]);
        $media = $stmt->fetch();

        if (!$media) {
            Response::error('File not found in active workspace.', 404);
        }

        $filePath = __DIR__ . '/../../storage/uploads/' . $filename;
        if (!file_exists($filePath)) {
            Response::error('File binary not found on disk.', 404);
        }

        // Stream file binary
        header("Content-Type: {$media['mime_type']}");
        header("Content-Length: " . filesize($filePath));
        header("X-Content-Type-Options: nosniff");
        header("Cache-Control: private, max-age=86400");
        readfile($filePath);
        exit;
    }
}
