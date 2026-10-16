<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$workspace = get_active_workspace();
$workspaceId = (int)$workspace['id'];

$uploaded = $_FILES['media'] ?? $_FILES['file'] ?? null;
if (!$uploaded || empty($uploaded['tmp_name'])) {
    json_error('No file was uploaded.', 422);
}

if ($uploaded['error'] !== UPLOAD_ERR_OK) {
    json_error('File upload error code: ' . $uploaded['error'], 400);
}

$tmpPath = $uploaded['tmp_name'];
$origName = basename((string)($uploaded['name'] ?? 'upload.bin'));
$fileSize = (int)($uploaded['size'] ?? 0);

$allowed = [
    'image/jpeg'      => ['ext' => 'jpg', 'type' => 'image', 'max' => 10485760],
    'image/png'       => ['ext' => 'png', 'type' => 'image', 'max' => 10485760],
    'image/webp'      => ['ext' => 'webp', 'type' => 'image', 'max' => 10485760],
    'video/mp4'       => ['ext' => 'mp4', 'type' => 'video', 'max' => 52428800],
    'video/quicktime' => ['ext' => 'mov', 'type' => 'video', 'max' => 52428800],
];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

if (!$realMime || !isset($allowed[$realMime])) {
    json_error("Unsupported format ({$realMime}). Allowed: JPG, PNG, WebP, MP4, MOV.", 415);
}

$config = $allowed[$realMime];
if ($fileSize > $config['max']) {
    json_error("File exceeds maximum allowed size.", 413);
}

// Storage directory
$storageDir = __DIR__ . '/../storage/uploads';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

$storageFilename = bin2hex(random_bytes(16)) . '.' . $config['ext'];
$targetPath = $storageDir . '/' . $storageFilename;

if (!move_uploaded_file($tmpPath, $targetPath)) {
    if (!copy($tmpPath, $targetPath)) {
        json_error('Failed to move uploaded file.', 500);
    }
    @unlink($tmpPath);
}

$pdo = get_db();
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

$previewUrl = "/api_v1/media/file.php?filename={$storageFilename}";

json_success([
    'id'                => $mediaId,
    'storage_filename'  => $storageFilename,
    'original_filename' => $origName,
    'mime_type'         => $realMime,
    'file_size_bytes'   => $fileSize,
    'media_type'        => $config['type'],
    'preview_url'       => $previewUrl,
], 'Media uploaded successfully.', 201);
