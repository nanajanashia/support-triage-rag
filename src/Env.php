<?php

declare(strict_types=1);

namespace Triage;

use RuntimeException;

/**
 * Minimal .env loader. Reads KEY=VALUE lines from the project root .env once,
 * without overriding variables already set in the real environment.
 */
final class Env
{
    private static bool $loaded = false;

    public static function get(string $key, ?string $default = null): string
    {
        self::load();
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            if ($default === null) {
                throw new RuntimeException("Missing environment variable {$key}. Copy .env.example to .env and fill it in.");
            }
            return $default;
        }
        return (string) $value;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $file = dirname(__DIR__) . '/.env';
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\"'");
            if (getenv($key) === false) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }
}
