<?php
declare(strict_types=1);

/**
 * SocialFlow - Standardized REST API JSON Response Utility
 */

class Response
{
    public static function json(
        mixed $data = null,
        int $statusCode = 200,
        string $message = '',
        array $headers = []
    ): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode([
            'success' => $statusCode >= 200 && $statusCode < 300,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(
        string $message,
        int $statusCode = 400,
        mixed $errors = null,
        array $headers = []
    ): void {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo json_encode([
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => $errors,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
