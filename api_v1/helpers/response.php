<?php
declare(strict_types=1);

/**
 * SocialFlow API v1 - Response & Input Helpers
 */

function json_response(mixed $data = null, int $statusCode = 200, string $message = '', mixed $errors = null): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    $success = ($statusCode >= 200 && $statusCode < 300);

    echo json_encode([
        'success'   => $success,
        'message'   => $message,
        'data'      => $data,
        'errors'    => $errors,
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_success(mixed $data = null, string $message = 'Success', int $statusCode = 200): void
{
    json_response($data, $statusCode, $message, null);
}

function json_error(string $message = 'Error', int $statusCode = 400, mixed $errors = null): void
{
    json_response(null, $statusCode, $message, $errors);
}

function get_json_input(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    // Fallback to standard POST/PUT form variables
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        parse_str($raw, $parsed);
        return is_array($parsed) ? $parsed : [];
    }

    return [];
}

function get_header(string $name): ?string
{
    $formatted = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$formatted])) {
        return (string)$_SERVER[$formatted];
    }

    // Apache fallback
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return (string)$v;
            }
        }
    }

    return null;
}
