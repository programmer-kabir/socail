<?php
declare(strict_types=1);

/**
 * SocialFlow - Application Environment & Configuration Loader
 */

class Config
{
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($envPath)) {
            throw new RuntimeException("Environment configuration file (.env) not found at: {$envPath}");
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (strpos($line, '=') !== false) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip surrounding quotes
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                // Handle boolean / null strings
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

        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$env[$key] ?? $_ENV[$key] ?? $default;
    }
}
