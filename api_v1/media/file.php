<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

$filename = basename((string)($_GET['filename'] ?? ''));
if (empty($filename)) {
    http_response_code(400);
    exit('Filename is required.');
}

$targetFile = __DIR__ . '/../storage/uploads/' . $filename;

if (!file_exists($targetFile)) {
    http_response_code(404);
    exit('File not found.');
}

$filePath = $targetFile;

if (!$filePath) {
    http_response_code(404);
    exit('File not found.');
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$mimes = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'mp4'  => 'video/mp4',
    'mov'  => 'video/quicktime',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header("Content-Type: {$mime}");
header("Content-Length: " . filesize($filePath));
header("Cache-Control: public, max-age=86400");
readfile($filePath);
exit;
