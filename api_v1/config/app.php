<?php
declare(strict_types=1);

/**
 * SocialFlow API v1 - Application Environment Configuration
 */

date_default_timezone_set('UTC');

class Config
{
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(?string $envPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        // Try several standard locations for .env
        $candidates = array_filter([
            $envPath,
            __DIR__ . '/../.env',
            __DIR__ . '/../../.env',
        ]);

        $resolvedPath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $resolvedPath = $candidate;
                break;
            }
        }

        if ($resolvedPath) {
            $lines = file($resolvedPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);

                    // Strip quotes
                    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                        (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                        $value = substr($value, 1, -1);
                    }

                    $lower = strtolower($value);
                    if ($lower === 'true') {
                        $value = true;
                    } elseif ($lower === 'false') {
                        $value = false;
                    } elseif ($lower === 'null') {
                        $value = null;
                    }

                    self::$env[$key] = $value;
                    if (!isset($_ENV[$key])) {
                        $_ENV[$key] = $value;
                    }
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$env[$key] ?? $_ENV[$key] ?? $default;
    }
}
